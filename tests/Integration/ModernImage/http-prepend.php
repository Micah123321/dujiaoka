<?php

// This observer is outside /app and never supplies an application or an autoloader.
function mih_json($file, array $value)
{
    $bytes = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($file, $bytes, LOCK_EX) !== strlen($bytes)) throw new RuntimeException('HTTP fixture JSON write failed: ' . $file);
}

function mih_environment(array $cfg)
{
    $env = [
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'HOME' => '/root', 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8', 'PHP_INI_DIR' => '/usr/local/etc/php',
        'IMAGE_HTTP_RUN' => '/probe/http.private', 'APP_BASE_PATH' => '/app', 'APP_ENV' => 'production',
        'APP_DEBUG' => 'false', 'APP_NAME' => 'Original Image HTTP Fixture', 'BCRYPT_ROUNDS' => (string) $cfg['bcrypt_rounds'],
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'APP_URL' => $cfg['url'],
        'COMPOSER_VENDOR_DIR' => '/app/vendor', 'LARAVEL_STORAGE_PATH' => '/app/storage',
        'VIEW_COMPILED_PATH' => '/app/storage/framework/views', 'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => '/app/storage/image-fixture.sqlite', 'DATABASE_URL' => '',
        'DB_HOST' => '', 'DB_PORT' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => '',
        'CACHE_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'CACHE_PREFIX' => 'image-http-' . $cfg['marker'],
        'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'image_http_' . $cfg['marker'],
        'SESSION_DOMAIN' => '', 'SESSION_SECURE_COOKIE' => 'false', 'QUEUE_CONNECTION' => 'sync',
        'MAIL_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'MAIL_HOST' => '', 'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '',
        'API_HOOK_OUTBOX' => 'false', 'PAYMENT_MAIL_OUTBOX' => 'false', 'MANUAL_STATUS_MAIL_OUTBOX' => 'false',
        'PAYMENT_QUOTE_FREEZE' => 'false', 'STRIPE_PAYMENT_INTENTS' => 'false', 'STRIPE_RECONCILIATION' => 'false',
        'PAYPAL_ORDERS_V2' => 'false', 'PAYPAL_RECONCILIATION' => 'false', 'PAYPAL_WEBHOOK_IDS' => '{}',
        'DUJIAO_ADMIN_LANGUAGE' => 'en', 'ADMIN_HTTPS' => 'false', 'ADMIN_ROUTE_DOMAIN' => '', 'ADMIN_ASSETS_SERVER' => '',
        'LOG_CHANNEL' => 'single', 'BROADCAST_DRIVER' => 'log', 'AWS_ACCESS_KEY_ID' => '', 'AWS_SECRET_ACCESS_KEY' => '',
        'REDIS_PASSWORD' => '', 'MEMCACHED_USERNAME' => '', 'MEMCACHED_PASSWORD' => '',
    ];
    foreach (['CONFIG', 'ROUTES', 'PACKAGES', 'SERVICES', 'EVENTS'] as $name) {
        $env['APP_' . $name . '_CACHE'] = '/app/bootstrap/cache/' . strtolower($name) . '.php';
    }
    foreach ($env as $name => $value) { putenv($name . '=' . $value); $_ENV[$name] = $value; $_SERVER[$name] = $value; }
    return $env;
}

function mih_observe()
{
    $GLOBALS['mih_observer'] = ['installed' => false, 'issues' => [], 'applications' => [], 'bound_before_vendor' => !class_exists('Composer\\Autoload\\ClassLoader', false)];
    $watch = null;
    $watch = function ($class) use (&$watch) {
        if (!in_array($class, ['App\\Http\\Kernel', 'App\\Console\\Kernel'], true)) return;
        $app = Illuminate\Container\Container::getInstance();
        $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\HandleExceptions::class, function ($app) {
            $GLOBALS['mih_observer']['installed'] = true;
            $id = spl_object_id($app);
            $GLOBALS['mih_observer']['applications'][] = ['id' => $id, 'base' => $app->basePath(), 'type' => get_class($app)];
            $previous = null;
            $previous = set_error_handler(function ($level, $message, $file, $line) use (&$previous, $id) {
                if ($level & error_reporting()) $GLOBALS['mih_observer']['issues'][] = compact('level', 'message', 'file', 'line', 'id');
                return $previous ? $previous($level, $message, $file, $line) : false;
            });
        });
        spl_autoload_unregister($watch);
    };
    $GLOBALS['mih_watch'] = $watch;
    spl_autoload_register($watch, true, true);
}

function mih_rearm_watch()
{
    // Composer prepends its loader; restore observation ahead of it after vendor initialization.
    spl_autoload_unregister($GLOBALS['mih_watch']);
    spl_autoload_register($GLOBALS['mih_watch'], true, true);
}

function mih_record(array $cfg)
{
    $last = error_get_last();
    $event = [
        'event' => 'OriginalImageRequest', 'marker' => $cfg['marker'], 'request_id' => $_SERVER['HTTP_X_IMAGE_REQUEST'] ?? '',
        'pid' => getmypid(), 'uid' => posix_geteuid(), 'gid' => posix_getegid(), 'php' => PHP_VERSION, 'binary' => PHP_BINARY,
        'sapi' => PHP_SAPI, 'method' => $_SERVER['REQUEST_METHOD'] ?? '', 'path' => $_SERVER['REQUEST_URI'] ?? '',
        'status' => http_response_code(), 'script' => $_SERVER['SCRIPT_FILENAME'] ?? '',
        'index_sha256' => hash_file('sha256', '/app/public/index.php'), 'last_php_error' => $last,
        'php_observer' => $GLOBALS['mih_observer'], 'included' => [], 'outside' => [], 'sourceIncludes' => [],
        'loaders' => [], 'loader_types' => [], 'loader_paths_outside' => [], 'dev_loaded' => [], 'dev_mapped' => [],
        'inspection_error' => null, 'time' => microtime(true),
    ];
    try {
        foreach (get_included_files() as $file) {
            $file = realpath($file) ?: $file;
            $event['included'][] = $file;
            $event['sourceIncludes'][$file] = hash_file('sha256', $file);
            if (strpos($file, '/app/') !== 0 && $file !== '/probe/http-prepend.php') $event['outside'][] = $file;
        }
        foreach (['Tests\\Fixtures\\AdminData', 'Tests\\TestCase', 'PHPUnit\\Framework\\TestCase', 'Mockery', 'Faker\\Factory'] as $class) {
            if (class_exists($class, false)) $event['dev_loaded'][] = $class;
        }
        if (!class_exists('Composer\\Autoload\\ClassLoader', false) || !class_exists('Illuminate\\Container\\Container', false)) {
            throw new RuntimeException('Original HTTP bootstrap did not load vendor/container.');
        }
        foreach (Composer\Autoload\ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            $event['loaders'][] = $vendor;
            $event['loader_types'][] = get_class($loader);
            $paths = array_values($loader->getClassMap());
            foreach ($loader->getPrefixesPsr4() as $prefix => $directories) {
                if (strpos($prefix, 'Tests\\') === 0) $event['dev_mapped'][] = $prefix;
                $paths = array_merge($paths, $directories);
            }
            foreach ($loader->getPrefixes() as $directories) $paths = array_merge($paths, $directories);
            foreach ($paths as $path) if (strpos(realpath($path) ?: '', '/app/') !== 0) $event['loader_paths_outside'][] = $path;
            if ($loader->getFallbackDirsPsr4() || $loader->getFallbackDirs() || $loader->getUseIncludePath()) $event['loader_paths_outside'][] = 'fallback/include-path';
        }
        $app = Illuminate\Container\Container::getInstance();
        $config = $app->make('config');
        $event += [
            'framework' => $app->version(), 'app_type' => get_class($app), 'base' => $app->basePath(), 'storage' => $app->storagePath(),
            'environment' => $app->environment(), 'debug' => $config->get('app.debug'), 'timezone' => $config->get('app.timezone'),
            'db' => $config->get('database.connections.sqlite.database'), 'db_default' => $config->get('database.default'),
            'session' => $config->get('session.driver'), 'session_files' => $config->get('session.files'),
            'cache' => $config->get('cache.default'), 'queue' => $config->get('queue.default'),
            'config_cached' => $app->configurationIsCached(), 'routes_cached' => $app->routesAreCached(),
            'kernel_type' => get_class($app->make(Illuminate\Contracts\Http\Kernel::class)),
            'app_source' => (new ReflectionClass(App\Http\Kernel::class))->getFileName(),
            'vendor_source' => (new ReflectionClass(Illuminate\Foundation\Application::class))->getFileName(),
            'installed_dev' => json_decode(file_get_contents('/app/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR)['dev'],
        ];
    } catch (Throwable $e) { $event['inspection_error'] = ['class' => get_class($e), 'message' => $e->getMessage()]; }
    $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($cfg['run'] . '/http-events.jsonl', $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
        error_log('Original image HTTP observation write failed');
    }
}

if (PHP_SAPI === 'fpm-fcgi') {
    // Register before public/index.php requires vendor, including shutdown evidence for failed boots.
    $cfg = json_decode(file_get_contents('/probe/http.private/runtime.json'), true, 512, JSON_THROW_ON_ERROR);
    mih_observe();
    register_shutdown_function(function () use ($cfg) { mih_record($cfg); });
    if (getenv('IMAGE_HTTP_RUN') !== '/probe/http.private' || $cfg['run'] !== '/probe/http.private'
        || !hash_equals(trim(file_get_contents($cfg['run'] . '/marker')), $cfg['marker'])
        || PHP_VERSION !== '8.5.11' || posix_geteuid() !== 0 || posix_getegid() !== 0
        || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
        || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:' . $cfg['http_port']
        || ($_SERVER['SCRIPT_FILENAME'] ?? '') !== '/app/public/index.php'
        || !preg_match('/^' . preg_quote($cfg['marker'], '/') . ':[0-9]{3}$/D', $_SERVER['HTTP_X_IMAGE_REQUEST'] ?? '')) {
        http_response_code(403); exit('Original image HTTP fixture mismatch');
    }
    mih_environment($cfg);
    require_once '/app/vendor/autoload.php';
    mih_rearm_watch();
    header('X-Image-Fixture: ' . $cfg['marker']);
    header('X-Image-Request: ' . $_SERVER['HTTP_X_IMAGE_REQUEST']);
}
