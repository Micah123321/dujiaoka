<?php

require dirname(__DIR__) . '/MySqlConcurrency/bootstrap.php';

use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

function smCheck($condition, string $label): void
{
    if (!$condition) throw new RuntimeException('Status MySQL check failed: ' . $label);
}

function smBoot(array $args)
{
    $db = fixtureBoot($args);
    $db->getConnection()->setTablePrefix('sm_');
    $app = app();
    $app['config']->set(['dujiaoka.manual_status_mail_outbox' => true, 'app.url' => 'https://fixture.test']);
    $app->instance('encrypter', new Illuminate\Encryption\Encrypter(str_repeat('s', 32), 'AES-256-CBC'));
    $events = new Illuminate\Events\Dispatcher($app);
    Order::setEventDispatcher($events);
    $events->listen(App\Events\OrderUpdated::class, function ($event) {
        app(App\Listeners\OrderUpdated::class)->handle($event);
    });
    $app->instance('events', $events);
    return $db;
}

function smWait(callable $test, string $label, int $poll = 20000): void
{
    $until = microtime(true) + 15;
    while (!$test()) {
        if (microtime(true) > $until) throw new RuntimeException('Status MySQL wait expired: ' . $label);
        usleep($poll);
    }
}

try {
    $args = array_slice($argv, 1);
    $worker = null;
    $run = null;
    $target = null;
    foreach ($args as $arg) {
        if (preg_match('/^--worker=([01])$/D', $arg, $m)) $worker = (int) $m[1];
        if (strpos($arg, '--run=') === 0) $run = substr($arg, 6);
        if (preg_match('/^--target=([45])$/D', $arg, $m)) $target = (int) $m[1];
    }
    $db = smBoot($args);
    if ($worker !== null) {
        $base = realpath(dirname(__DIR__, 2) . '/.runtime');
        $run = realpath($run ?: '');
        smCheck($run && strpos($run, $base . DIRECTORY_SEPARATOR) === 0 &&
            strpos(basename(dirname($run)), 'admin-status-mysql-') === 0 &&
            is_file($run . '/marker') && file_get_contents($run . '/marker') === 'dujiaoka-status-mysql-v1', 'owned-worker-run');
        smCheck(in_array($target, [4, 5], true), 'worker-target');
        $order = Order::findOrFail(1);
        $ready = ['connection' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
            'loadedStatus' => (int) $order->status, 'php' => PHP_VERSION];
        file_put_contents($run . '/ready-' . $worker . '.json', json_encode($ready));
        smWait(function () use ($run) { return is_file($run . '/go'); }, 'worker-go');
        $order->status = $target;
        $order->save();
        file_put_contents($run . '/result-' . $worker . '.json', json_encode(['accepted' => true,
            'target' => $target, 'transactionLevel' => DB::transactionLevel(), 'connection' => $ready['connection']]));
        exit(0);
    }
    $observer = new PDO('mysql:host=127.0.0.1;port=13316;dbname=dujiaoka_concurrency_fixture', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server = $observer->query('SELECT @@port AS port, @@bind_address AS address, @@datadir AS data_path, VERSION() AS version, @@transaction_isolation AS isolation')->fetch(PDO::FETCH_ASSOC);
    $normal = function ($path) { return strtolower(str_replace(chr(92), '/', $path)); };
    smCheck((int) $server['port'] === 13316 && $server['address'] === '127.0.0.1' &&
        $normal($server['data_path']) === $normal(dirname(__DIR__, 2) . '/.runtime/mysql-fixture-data/'), 'observer-owned-instance');
    $runtime = dirname(__DIR__, 2) . '/.runtime/admin-status-mysql-' . (in_array('--candidate', $args, true) ? 'candidate' : 'root') . '-37-' . bin2hex(random_bytes(4));
    mkdir($runtime, 0777, true);
    file_put_contents($runtime . '/marker', 'dujiaoka-status-mysql-v1');
    $guard = fopen(dirname(__DIR__, 2) . '/.runtime/admin-status-mysql.lock', 'c');
    smCheck(flock($guard, LOCK_EX | LOCK_NB), 'exclusive-status-coordinator');
    $schema = $db->schema();
    foreach (['notification_outbox', 'orders'] as $table) $schema->dropIfExists($table);
    $schema->create('orders', function (Blueprint $table) {
        $table->engine = 'InnoDB'; $table->increments('id'); $table->string('order_sn');
        $table->integer('type'); $table->integer('status'); $table->string('title');
        $table->string('email'); $table->text('info'); $table->decimal('actual_price', 10, 2);
        $table->timestamps(); $table->softDeletes();
    });
    foreach (['000001_create_notification_outbox_table', '000002_add_payload_to_notification_outbox',
        '000008_add_event_key_to_notification_outbox'] as $migration) require_once dirname(__DIR__, 3) . '/database/migrations/2026_01_01_' . $migration . '.php';
    (new CreateNotificationOutboxTable())->up();
    (new AddPayloadToNotificationOutbox())->up();
    $now = date('Y-m-d H:i:s');
    DB::table('notification_outbox')->insert(['order_id' => 1, 'channel' => 'payment-mail', 'payload' => 'legacy-fixture',
        'available_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    (new AddEventKeyToNotificationOutbox())->up();
    smCheck(DB::table('notification_outbox')->value('event_key') === '', 'legacy-default-key');
    smCheck(DB::table('notification_outbox')->value('payload') === 'legacy-fixture', 'legacy-payload-preserved');
    $duplicate = false;
    try { DB::table('notification_outbox')->insert(['order_id' => 1, 'channel' => 'payment-mail', 'available_at' => $now, 'created_at' => $now, 'updated_at' => $now]); }
    catch (Illuminate\Database\QueryException $error) { $duplicate = $error->getCode() === '23000'; }
    smCheck($duplicate, 'legacy-unique-preserved');
    $report = ['php' => PHP_VERSION, 'framework' => Illuminate\Foundation\Application::VERSION,
        'binary' => PHP_BINARY, 'ini' => php_ini_loaded_file(), 'server' => $server, 'prefix' => 'sm_',
        'legacyRowPreserved' => true, 'legacyUniquePreserved' => true, 'groups' => []];
    foreach ([[4, 4], [4, 5]] as $group => $targets) {
        DB::table('notification_outbox')->delete(); DB::table('orders')->delete();
        DB::table('orders')->insert(['id' => 1, 'order_sn' => 'STATUS-MYSQL', 'type' => 2, 'status' => 1,
            'title' => 'Fixture status', 'email' => 'status@example.test', 'info' => 'FIXTURE-STATUS-INFO', 'actual_price' => 10,
            'created_at' => $now, 'updated_at' => $now]);
        $phase = $runtime . '/group-' . $group; mkdir($phase); file_put_contents($phase . '/marker', 'dujiaoka-status-mysql-v1');
        $jobs = [];
        foreach ($targets as $index => $status) {
            $command = [PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--enable-isolated-mysql', '--worker=' . $index, '--target=' . $status, '--run=' . $phase];
            if (in_array('--candidate', $args, true)) $command[] = '--candidate';
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $phase . '/worker-' . $index . '.out', 'w'], 2 => ['file', $phase . '/worker-' . $index . '.err', 'w']], $pipes);
            smCheck(is_resource($process), 'worker-start'); fclose($pipes[0]); $jobs[] = $process;
        }
        smWait(function () use ($phase) { return is_file($phase . '/ready-0.json') && is_file($phase . '/ready-1.json'); }, 'all-ready');
        $ready = [json_decode(file_get_contents($phase . '/ready-0.json'), true), json_decode(file_get_contents($phase . '/ready-1.json'), true)];
        smCheck($ready[0]['loadedStatus'] === 1 && $ready[1]['loadedStatus'] === 1, 'actual-stale-models');
        $ids = array_column($ready, 'connection');
        DB::beginTransaction(); DB::table('orders')->where('id', 1)->lockForUpdate()->first();
        file_put_contents($phase . '/go', 'release-workers');
        $waiting = function () use ($observer, $ids) {
            return (int) $observer->query('SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state=\'LOCK WAIT\' AND trx_mysql_thread_id IN (' . implode(',', $ids) . ')')->fetchColumn();
        };
        smWait(function () use ($waiting) { return $waiting() === 2; }, 'all-InnoDB-lock-wait', 150000);
        $firstWait = $waiting(); usleep(500000); $finalWait = $waiting();
        smCheck($firstWait === 2 && $finalWait === 2, 'held-500ms-lock-waits'); DB::commit();
        foreach ($jobs as $job) smCheck(proc_close($job) === 0, 'worker-success');
        $rows = DB::table('notification_outbox')->orderBy('id')->get();
        smCheck(count($rows) === ($group === 0 ? 1 : 2), 'distinct-transition-intents');
        smCheck(count(array_unique($rows->pluck('event_key')->all())) === count($rows), 'unique-event-keys');
        smCheck($rows->every(function ($row) { return $row->channel === 'order-status-mail' && strlen($row->event_key) === 64 && strpos($row->payload, 'status@example.test') === false; }), 'encrypted-status-channel');
        $down = false; try { (new AddEventKeyToNotificationOutbox())->down(); } catch (RuntimeException $error) { $down = true; }
        smCheck($down && DB::table('notification_outbox')->count() === count($rows), 'status-down-retains-data');
        $report['groups'][] = ['targets' => $targets, 'workers' => 2, 'initialLockWait' => $firstWait, 'heldLockWait' => $finalWait,
            'pollMicros' => 150000, 'intents' => count($rows), 'storedStatus' => (int) DB::table('orders')->value('status'), 'downRetainedData' => true];
    }
    $report['status'] = 'passed';
    file_put_contents($runtime . '/summary.json', json_encode($report, JSON_PRETTY_PRINT) . PHP_EOL);
    echo json_encode(['status' => 'passed', 'report' => $runtime . '/summary.json', 'groups' => 2, 'workers' => 4, 'php' => PHP_VERSION, 'framework' => $report['framework']]), PHP_EOL;
    flock($guard, LOCK_UN); fclose($guard);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
