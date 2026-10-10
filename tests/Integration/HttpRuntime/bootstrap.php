<?php
require_once dirname(__DIR__) . '/QueueLifecycle/bootstrap.php';

function hr_manifest($run)
{
    $root = ql_normalize(realpath(dirname(__DIR__, 3)));
    $actual = realpath($run);
    if (!$actual || ql_normalize(dirname($actual)) !== $root . '/tests/.runtime/http-runtime'
        || !preg_match('/^run-[a-zA-Z0-9-]+$/D', basename($actual))) throw new RuntimeException('Owned HTTP runtime required.');
    $cfg = json_decode(file_get_contents($actual . '/run.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($cfg['root'] !== $root || $cfg['run'] !== ql_normalize($actual)
        || !hash_equals(trim(file_get_contents($actual . '/marker')), $cfg['marker'])) throw new RuntimeException('HTTP manifest mismatch.');
    return $cfg;
}

function hr_environment(array $cfg)
{
    $env = ['APP_BASE_PATH' => $cfg['release'], 'APP_ENV' => 'http-runtime-fixture', 'APP_DEBUG' => 'false',
        'APP_KEY' => $cfg['key'], 'APP_URL' => $cfg['url'], 'APP_NAME' => 'HTTP Runtime Fixture',
        'LARAVEL_STORAGE_PATH' => $cfg['run'] . '/storage', 'COMPOSER_VENDOR_DIR' => $cfg['vendor'],
        'VIEW_COMPILED_PATH' => $cfg['run'] . '/storage/framework/views',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $cfg['run'] . '/fixture.sqlite', 'DATABASE_URL' => '',
        'DB_HOST' => '', 'DB_PORT' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => '',
        'CACHE_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'CACHE_PREFIX' => 'http-' . $cfg['marker'],
        'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'http_fixture_' . $cfg['marker'],
        'SESSION_DOMAIN' => '', 'SESSION_SECURE_COOKIE' => 'false',
        'QUEUE_CONNECTION' => 'sync', 'MAIL_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
        'MAIL_HOST' => '', 'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '', 'MAIL_ENCRYPTION' => '',
        'API_HOOK_OUTBOX' => 'false', 'PAYMENT_MAIL_OUTBOX' => 'false', 'MANUAL_STATUS_MAIL_OUTBOX' => 'false',
        'PAYMENT_QUOTE_FREEZE' => 'false', 'STRIPE_PAYMENT_INTENTS' => 'false', 'STRIPE_RECONCILIATION' => 'false',
        'PAYPAL_ORDERS_V2' => 'false', 'PAYPAL_RECONCILIATION' => 'false', 'PAYPAL_WEBHOOK_IDS' => '{}',
        'DUJIAO_ADMIN_LANGUAGE' => 'en', 'ADMIN_HTTPS' => 'false', 'ADMIN_ROUTE_DOMAIN' => '', 'ADMIN_ASSETS_SERVER' => '',
        'LOG_CHANNEL' => 'single', 'BROADCAST_DRIVER' => 'log', 'REDIS_PASSWORD' => '',
        'AWS_ACCESS_KEY_ID' => '', 'AWS_SECRET_ACCESS_KEY' => '', 'MEMCACHED_USERNAME' => '', 'MEMCACHED_PASSWORD' => ''];
    foreach (['CONFIG', 'ROUTES', 'PACKAGES', 'SERVICES', 'EVENTS'] as $cache) $env['APP_' . $cache . '_CACHE'] = $cfg['run'] . '/cache/' . strtolower($cache) . '.php';
    foreach ($env as $key => $value) { putenv($key . '=' . $value); $_ENV[$key] = $value; $_SERVER[$key] = $value; }
    $GLOBALS['hr_config'] = $cfg;
    require_once $cfg['vendor'] . '/autoload.php';
    Illuminate\Http\Middleware\TrustProxies::flushState();
    if ($cfg['trusted']) Illuminate\Http\Middleware\TrustProxies::at(['127.0.0.1']);
}
