<?php
require __DIR__ . '/bootstrap.php';
$cfg = pr_manifest(getenv('PRODUCTION_RELEASE_RUN'));
if (PHP_SAPI !== 'fpm-fcgi' || PHP_VERSION !== '8.5.11' || posix_geteuid() !== 1000
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:' . $cfg['port']) { http_response_code(403); exit('Production release fixture mismatch'); }
pr_environment($cfg);
require_once $cfg['release'].'/vendor/autoload.php';
pr_observe_php_errors();
header('X-Release-Fixture: ' . $cfg['marker']);
register_shutdown_function(function () use ($cfg) {
    $app = Illuminate\Container\Container::getInstance();
    $outside = []; $included = [];
    $observer = [ql_normalize(__FILE__), ql_normalize(__DIR__ . '/bootstrap.php'), ql_normalize(dirname(__DIR__) . '/QueueLifecycle/bootstrap.php')];
    foreach (get_included_files() as $file) {
        $file = ql_normalize(realpath($file) ?: $file); $included[] = $file;
        if (strpos($file, $cfg['release'] . '/') !== 0 && !in_array($file, $observer, true)) $outside[] = $file;
    }
    $autoload = [];
    foreach (Composer\Autoload\ClassLoader::getRegisteredLoaders() as $vendor => $loader) $autoload[] = ql_normalize($vendor);
    $event = ['event' => 'ReleaseRequest', 'request_id' => $_SERVER['HTTP_X_RELEASE_REQUEST'] ?? '',
        'pid' => getmypid(), 'uid' => posix_geteuid(), 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'path' => $_SERVER['REQUEST_URI'] ?? '', 'status' => http_response_code(), 'framework' => $app->version(),
        'base' => $app->basePath(), 'storage' => $app->storagePath(), 'environment' => $app->environment(),
        'db' => $app->make('config')->get('database.connections.sqlite.database'), 'queue'=>$app->make('config')->get('queue.default'), 'timezone'=>$app->make('config')->get('app.timezone'),'failed_driver'=>$app->make('config')->get('queue.failed.driver'), 'config_cached' => $app->configurationIsCached(), 'routes_cached' => $app->routesAreCached(),
        'app_source' => (new ReflectionClass(App\Http\Kernel::class))->getFileName(), 'vendor_source' => (new ReflectionClass(Illuminate\Foundation\Application::class))->getFileName(),
        'outside' => $outside, 'included' => $included, 'loaders' => $autoload, 'dev_loaded' => class_exists('PHPUnit\\Framework\\TestCase', false) || class_exists('Mockery', false) || class_exists('Faker\\Factory', false) || class_exists('Tests\\TestCase', false),
        'index_sha256' => hash_file('sha256', $_SERVER['SCRIPT_FILENAME']), 'php_observer'=>$GLOBALS['pr_php_observer'], 'last_php_error' => error_get_last(), 'time' => microtime(true)];
    file_put_contents($cfg['run'] . '/http-events.jsonl', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
});
