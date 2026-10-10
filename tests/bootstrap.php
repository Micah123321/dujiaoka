<?php

// Keep tests isolated even when a developer has exported production settings.
$environment = [
    'APP_BASE_PATH' => dirname(__DIR__),
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    'APP_URL' => 'http://localhost',
    // Laravel 6 only recognizes slash-prefixed absolute paths; keep these relative on Windows.
    'APP_CONFIG_CACHE' => 'tests/.runtime/config.php',
    'APP_ROUTES_CACHE' => 'tests/.runtime/routes.php',
    'APP_PACKAGES_CACHE' => 'tests/.runtime/packages.php',
    'APP_SERVICES_CACHE' => 'tests/.runtime/services.php',
    'APP_EVENTS_CACHE' => 'tests/.runtime/events.php',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DATABASE_URL' => '',
    'CACHE_DRIVER' => 'array',
    'CACHE_STORE' => 'array',
    'LARAVEL_STORAGE_PATH' => __DIR__ . '/.runtime/storage',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'API_HOOK_OUTBOX' => 'false',
    'PAYMENT_MAIL_OUTBOX' => 'false',
    'MANUAL_STATUS_MAIL_OUTBOX' => 'false',
    'PAYMENT_QUOTE_FREEZE' => 'false',
    'STRIPE_PAYMENT_INTENTS' => 'false',
    'STRIPE_RECONCILIATION' => 'false',
    'PAYPAL_ORDERS_V2' => 'false',
    'PAYPAL_ORDERS_MODE' => 'live',
    'PAYPAL_RECONCILIATION' => 'false',
    'PAYPAL_WEBHOOK_IDS' => '{}',
    'MAIL_DRIVER' => 'array',
    'MAIL_MAILER' => 'array',
];

foreach ($environment as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

if (!is_dir(__DIR__ . '/.runtime')) {
    mkdir(__DIR__ . '/.runtime', 0777, true);
}

foreach (['framework/views', 'framework/cache', 'framework/sessions', 'logs', 'app'] as $directory) {
    $path = __DIR__ . '/.runtime/storage/' . $directory;
    if (!is_dir($path)) mkdir($path, 0777, true);
}

require dirname(__DIR__) . '/vendor/autoload.php';
