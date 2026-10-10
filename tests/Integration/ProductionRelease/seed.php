<?php
require_once __DIR__ . '/bootstrap.php';
$cfg = pr_manifest($argv[1] ?? '');
pr_environment($cfg);
$app = null;
try {
    $loader = require $cfg['release'] . '/vendor/autoload.php';
    $app = require $cfg['release'] . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if ($app->basePath() !== $cfg['release'] || !$app->environment('production') || config('app.debug') !== false
        || config('database.connections.sqlite.database') !== $cfg['release'] . '/storage/fixture.sqlite') throw new RuntimeException('Production release isolation mismatch.');
    foreach (['Tests\\TestCase', 'PHPUnit\\Framework\\TestCase', 'Mockery', 'Faker\\Factory'] as $class) if (class_exists($class)) throw new RuntimeException('Production loaded a development class.');
    $paths = array_values($loader->getClassMap());
    foreach ($loader->getPrefixesPsr4() as $prefix => $directories) {
        if (strpos($prefix, 'Tests\\') === 0) throw new RuntimeException('Production maps tests.');
        $paths = array_merge($paths, $directories);
    }
    foreach ($loader->getPrefixes() as $directories) $paths = array_merge($paths, $directories);
    if ($loader->getFallbackDirsPsr4() || $loader->getFallbackDirs() || $loader->getUseIncludePath()) throw new RuntimeException('Production loader has external fallback.');
    foreach ($paths as $path) if (strpos(ql_normalize(realpath($path) ?: ''), $cfg['release'] . '/') !== 0) throw new RuntimeException('Production autoload path escapes release.');
    if (Illuminate\Support\Facades\DB::select("SELECT name FROM sqlite_master WHERE type='table'")) throw new RuntimeException('Fresh release database required.');
    // Setup-only fixture data is not part of the deployed tree or runtime autoload.
    require dirname(__DIR__, 2) . '/Fixtures/AdminData.php';
    Tests\Fixtures\AdminData::create(false);
    if (!empty($cfg['transactions'])) {
        // Only queue tables are added; the business schema still comes from shipped SQL.
        Illuminate\Support\Facades\Schema::create('jobs', function ($t) {
            $t->bigIncrements('id'); $t->string('queue')->index(); $t->longText('payload');
            $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
        });
        Illuminate\Support\Facades\Schema::create('failed_jobs', function ($t) {
            $t->bigIncrements('id'); $t->string('uuid')->unique(); $t->text('connection'); $t->text('queue');
            $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at')->useCurrent();
        });
        if (config('queue.default') !== 'database' || config('queue.failed.driver') !== 'database-uuids'
            || get_class(app('queue.failer')) !== 'Illuminate\\Queue\\Failed\\DatabaseUuidFailedJobProvider') throw new RuntimeException('Native database queue and matching UUID failure provider required.');
    }
    Illuminate\Support\Facades\Cache::forever('system-setting', ['title' => 'Release Store', 'text_logo' => 'Release Store', 'template' => 'modern', 'language' => 'zh_CN',
        'order_expire_time' => 5, 'driver' => 'array', 'is_open_img_code' => 0, 'is_open_geetest' => 0, 'is_open_server_jiang' => 0,
        'is_open_telegram_push' => 0, 'is_open_bark_push' => 0, 'is_open_qywxbot_push' => 0]);
    ql_json($cfg['run'] . '/seed-proof.json', ['status' => 'PASS', 'framework' => $app->version(), 'namespace' => $app->getNamespace(),
        'environment' => $app->environment(), 'timezone'=>config('app.timezone'), 'queue'=>config('queue.default'), 'failed_driver'=>config('queue.failed.driver'), 'failed_provider'=>get_class(app('queue.failer')), 'autoload_paths_checked' => count($paths), 'app_source' => (new ReflectionClass(App\Http\Kernel::class))->getFileName(),
        'vendor_source' => (new ReflectionClass(Illuminate\Foundation\Application::class))->getFileName(), 'php' => PHP_VERSION]);
    $app->make('db')->disconnect();
    echo 'Production release seed and autoload proof PASS', PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    ql_json($cfg['run'] . '/seed-proof.json', ['status' => 'FAIL', 'failure' => ['class' => get_class($e), 'message' => $e->getMessage()]]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    if ($app) $app->make('db')->disconnect();
    exit(1);
}
