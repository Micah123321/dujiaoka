<?php
// Standalone bootstrap deliberately never loads the application or dotenv.
$fixtureCandidate = in_array('--candidate', $argv ?? [], true);
require dirname(__DIR__, 2) . ($fixtureCandidate ? '/Compatibility/Laravel12/bootstrap.php' : '/Compatibility/Laravel6/bootstrap.php');

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\DB;
use App\Models\Order;

function fixtureCheck($condition, string $label): void
{
    if (!$condition) { throw new RuntimeException($label); }
}

function fixtureBoot(array $args): Manager
{
    fixtureCheck(in_array('--enable-isolated-mysql', $args, true), 'explicit-enable-required');
    fixtureCheck(extension_loaded('pdo_mysql') && extension_loaded('bcmath'), 'pdo-mysql-and-bcmath-required');
    $app = new Container();
    Container::setInstance($app);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);
    $app->instance('config', new Illuminate\Config\Repository(['dujiaoka' => ['api_hook_outbox' => false, 'payment_mail_outbox' => false, 'manual_status_mail_outbox' => false]]));
    $db = new Manager($app);
    $db->addConnection([
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 13316,
        'database' => 'dujiaoka_concurrency_fixture', 'username' => 'dujiaoka_fixture',
        'password' => '', 'prefix' => 'mc_', 'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
        'options' => [PDO::ATTR_TIMEOUT => 5, PDO::ATTR_EMULATE_PREPARES => false],
    ]);
    $pdo = $db->getConnection()->getPdo();
    fixtureCheck($pdo->query('SELECT DATABASE()')->fetchColumn() === 'dujiaoka_concurrency_fixture', 'database-guard');
    fixtureCheck(strpos($pdo->query('SELECT VERSION()')->fetchColumn(), '8.4.') === 0, 'mysql-84-required');
    $markers = $pdo->query('SELECT value FROM fixture_marker')->fetchAll(PDO::FETCH_COLUMN);
    fixtureCheck($markers === ['dujiaoka-isolated-concurrency-v1'], 'instance-marker-guard');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 12');
    $db->setAsGlobal();
    $db->bootEloquent();
    Order::unsetEventDispatcher();
    $app->instance('db', $db->getDatabaseManager());
    $app->bind('db.schema', function () use ($db) { return $db->schema(); });
    $app->instance('translator', new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader(), 'en'));
    $app->instance('cache', new Illuminate\Cache\Repository(new Illuminate\Cache\ArrayStore()));
    $goods = Mockery::mock(App\Service\GoodsService::class)->makePartial();
    $goods->shouldReceive('detail')->andReturnUsing(function ($id) {
        return (object) ['id' => $id, 'gd_name' => 'Fixture', 'api_hook' => 'https://fixture.invalid/hook'];
    });
    $app->instance('Service\GoodsService', $goods);
    $app->instance('Service\CouponService', new App\Service\CouponService());
    $app->instance('Service\CarmisService', new App\Service\CarmisService());
    $app->instance('Service\EmailtplService', new stdClass());
    $app->instance('Service\OrderService', new App\Service\OrderService());
    $app->instance('Service\PayService', new App\Service\PayService());
    $app->instance('log', new Psr\Log\NullLogger());
    $app->instance('redirect', new Illuminate\Routing\Redirector(new Illuminate\Routing\UrlGenerator(
        new Illuminate\Routing\RouteCollection(), Illuminate\Http\Request::create('https://fixture.test'))));
    $bus = Mockery::mock(Illuminate\Contracts\Bus\Dispatcher::class);
    $bus->shouldReceive('dispatch')->andReturnUsing(function ($job) {
        fixtureCheck($job instanceof App\Jobs\DeliverNotification, 'unexpected-job');
        return DB::table('queue_probe')->insertGetId(['outbox_id' => $job->outboxId, 'token' => $job->reservationToken]);
    });
    $app->instance(Illuminate\Contracts\Bus\Dispatcher::class, $bus);
    $stack = GuzzleHttp\HandlerStack::create(new GuzzleHttp\Handler\MockHandler([new GuzzleHttp\Psr7\Response(204)]));
    $stack->push(GuzzleHttp\Middleware::tap(function () {
        DB::table('send_probe')->insert(['kind' => 'mock-http']);
    }));
    $app->instance(GuzzleHttp\Client::class, new GuzzleHttp\Client(['handler' => $stack]));
    return $db;
}

class FixtureOrderProcess extends App\Service\OrderProcessService
{
    protected function notifyOrder(Order $order): void
    {
        // Suppress legacy mail/events, retaining real business transactions.
    }
}

function fixtureWait(callable $predicate, string $label, float $seconds = 15, int $pollMicros = 20000): void
{
    $until = microtime(true) + $seconds;
    do {
        clearstatcache();
        if ($predicate()) { return; }
        usleep($pollMicros);
    } while (microtime(true) < $until);
    throw new RuntimeException($label);
}
