<?php
require_once dirname(__DIR__) . '/QueueLifecycle/bootstrap.php';

function cv_boot($run, $tree)
{
    $root = ql_normalize(realpath(dirname(__DIR__, 3)));
    $actual = realpath($run);
    if (!$actual || ql_normalize(dirname($actual)) !== $root . '/tests/.runtime/cross-version-state'
        || !preg_match('/^run-[a-zA-Z0-9-]+$/D', basename($actual)) || !in_array($tree, ['root', 'candidate'], true)) throw new RuntimeException('Owned cross-version run required.');
    $run = ql_normalize($actual);
    $cfg = json_decode(file_get_contents($run . '/run.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($cfg['root'] !== $root || $cfg['run'] !== $run || !hash_equals(trim(file_get_contents($run . '/marker')), $cfg['marker'])) throw new RuntimeException('Cross-version marker mismatch.');
    $runtime = $cfg['runtimes'][$tree];
    if (ql_normalize(realpath(PHP_BINARY)) !== $runtime['binary'] || ql_normalize(php_ini_loaded_file()) !== $runtime['ini']) throw new RuntimeException('Cross-version binary/INI mismatch.');
    $vendor = $tree === 'root' ? $root . '/tests/Compatibility/Laravel6/vendor' : $root . '/tests/Compatibility/Laravel12/vendor';
    $relative = 'tests/.runtime/cross-version-state/' . basename($run) . '/' . $tree;
    $env = ['APP_BASE_PATH' => $root, 'APP_ENV' => 'cross-version-fixture', 'APP_DEBUG' => 'false',
        'APP_KEY' => $cfg['key'], 'APP_NAME' => 'Cross Version State', 'APP_URL' => 'http://state.fixture.invalid',
        'COMPOSER_VENDOR_DIR' => $vendor, 'LARAVEL_STORAGE_PATH' => $run . '/storage',
        'VIEW_COMPILED_PATH' => $run . '/' . $tree . '/views',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $run . '/fixture.sqlite', 'DATABASE_URL' => '',
        'DB_HOST' => '', 'DB_PORT' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => '',
        'CACHE_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'CACHE_PREFIX' => 'cross-version-' . $cfg['marker'],
        'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'cross_version_' . $cfg['marker'], 'SESSION_DOMAIN' => '',
        'SESSION_SECURE_COOKIE' => 'false', 'QUEUE_CONNECTION' => 'sync',
        'MAIL_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'MAIL_HOST' => '', 'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '',
        'API_HOOK_OUTBOX' => 'false', 'PAYMENT_MAIL_OUTBOX' => 'false', 'MANUAL_STATUS_MAIL_OUTBOX' => 'false',
        'PAYMENT_QUOTE_FREEZE' => 'false', 'STRIPE_PAYMENT_INTENTS' => 'false', 'STRIPE_RECONCILIATION' => 'false',
        'PAYPAL_ORDERS_V2' => 'false', 'PAYPAL_RECONCILIATION' => 'false', 'PAYPAL_WEBHOOK_IDS' => '{}',
        'DUJIAO_ADMIN_LANGUAGE' => 'en', 'ADMIN_HTTPS' => 'false', 'ADMIN_ROUTE_DOMAIN' => '', 'ADMIN_ASSETS_SERVER' => '',
        'LOG_CHANNEL' => 'single', 'BROADCAST_DRIVER' => 'log', 'REDIS_PASSWORD' => '',
        'AWS_ACCESS_KEY_ID' => '', 'AWS_SECRET_ACCESS_KEY' => '', 'MEMCACHED_USERNAME' => '', 'MEMCACHED_PASSWORD' => ''];
    foreach (['CONFIG', 'ROUTES', 'PACKAGES', 'SERVICES', 'EVENTS'] as $cache) $env['APP_' . $cache . '_CACHE'] = $relative . '/' . strtolower($cache) . '.php';
    foreach ($env as $key => $value) { putenv($key . '=' . $value); $_ENV[$key] = $value; $_SERVER[$key] = $value; }
    require $vendor . '/autoload.php';
    $app = require $root . '/bootstrap/app.php';
    $app->useEnvironmentPath($run)->loadEnvironmentFrom('.env.fixture-not-present');
    $app->useStoragePath($run . '/storage');
    $app->instance('path.vendor', $vendor);
    $manifest = $app->make(Illuminate\Foundation\PackageManifest::class);
    $manifest->vendorPath = $vendor;
    $manifest->build();
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (config('dujiaoka.manual_status_mail_outbox') !== false || is_file($app->environmentFilePath()) || $app->storagePath() !== $run . '/storage'
        || config('database.connections.sqlite.database') !== $run . '/fixture.sqlite'
        || config('session.driver') !== 'file' || config('cache.default') !== 'file' || $app->environment() === 'testing') throw new RuntimeException('Cross-version isolation mismatch.');
    return [$app, $cfg];
}

function cv_types($value)
{
    if (!is_array($value)) return gettype($value);
    $types = [];
    foreach ($value as $key => $item) $types[$key] = cv_types($item);
    return ['array' => $types];
}

function cv_payload()
{
    return ['integer-zero' => 0, 'string-zero' => '0', 'float-zero' => 0.0, 'whole-float' => 9.0, 'bool' => true, 'null' => null,
        'money' => '12.50', 'text' => "\u{4e2d}\u{6587}\u{72b6}\u{6001}", 'nested' => [['id' => 7, 'amount' => '0.01']]];
}
