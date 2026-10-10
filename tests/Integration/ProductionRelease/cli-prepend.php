<?php
require_once __DIR__ . '/bootstrap.php';
$cfg = pr_manifest(getenv('PRODUCTION_RELEASE_RUN'));
$label = getenv('PRODUCTION_RELEASE_COMMAND');
if (!preg_match('/^[a-z0-9-]+$/D', $label)) throw new RuntimeException('Owned CLI command label required.');
pr_environment($cfg);
if(is_file($cfg['release'].'/vendor/autoload.php'))require_once $cfg['release'].'/vendor/autoload.php';
pr_observe_php_errors();
register_shutdown_function(function () use ($cfg, $label) {
    $last = error_get_last();
    $identity = null;
    if (class_exists('Illuminate\\Foundation\\Application', false)) {
        $app = Illuminate\Container\Container::getInstance();
        if ($app->bound('config')) {
            $identity = ['base'=>$app->basePath(),'storage'=>$app->storagePath(),'environment'=>$app->environment(),
                'db'=>$app->make('config')->get('database.connections.sqlite.database'),'queue'=>$app->make('config')->get('queue.default'),'timezone'=>$app->make('config')->get('app.timezone'),'failed_driver'=>$app->make('config')->get('queue.failed.driver'),
                'config_cached'=>$app->configurationIsCached(),'routes_cached'=>$app->routesAreCached(),
                'loaders'=>array_keys(Composer\Autoload\ClassLoader::getRegisteredLoaders()),
                'app_source'=>(new ReflectionClass(App\Console\Kernel::class))->getFileName(),
                'vendor_source'=>(new ReflectionClass(Illuminate\Foundation\Application::class))->getFileName()];
        }
    }
    file_put_contents($cfg['run'] . '/' . $label . '.php-proof.json', json_encode(['pid' => getmypid(), 'php' => PHP_VERSION, 'sapi' => PHP_SAPI,
        'last_php_error' => $last, 'php_observer'=>$GLOBALS['pr_php_observer'], 'identity'=>$identity, 'included' => get_included_files()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
});
