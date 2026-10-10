<?php

// Force fixture settings before either the framework or package discovery reads them.
$root = dirname(__DIR__, 3);
$runtime = $root . '/tests/.runtime/laravel12';
$environment = [
    'APP_BASE_PATH' => $root,
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'APP_URL' => 'http://localhost',
    'APP_CONFIG_CACHE' => 'tests/.runtime/laravel12/config.php',
    'APP_ROUTES_CACHE' => 'tests/.runtime/laravel12/routes.php',
    'APP_PACKAGES_CACHE' => 'tests/.runtime/laravel12/packages.php',
    'APP_SERVICES_CACHE' => 'tests/.runtime/laravel12/services.php',
    'APP_EVENTS_CACHE' => 'tests/.runtime/laravel12/events.php',
    'LARAVEL_STORAGE_PATH' => $runtime . '/storage',
    'COMPOSER_VENDOR_DIR' => __DIR__ . '/vendor',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DATABASE_URL' => '',
    'CACHE_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync', 'MAIL_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    'API_HOOK_OUTBOX' => 'false', 'PAYMENT_MAIL_OUTBOX' => 'false', 'MANUAL_STATUS_MAIL_OUTBOX' => 'false', 'PAYMENT_QUOTE_FREEZE' => 'false',
    'STRIPE_PAYMENT_INTENTS' => 'false', 'STRIPE_RECONCILIATION' => 'false',
    'PAYPAL_ORDERS_V2' => 'false', 'PAYPAL_ORDERS_MODE' => 'sandbox',
    'PAYPAL_RECONCILIATION' => 'false', 'PAYPAL_WEBHOOK_IDS' => '{}',
];
foreach ($environment as $key => $value) {
    putenv($key . '=' . $value); $_ENV[$key] = $value; $_SERVER[$key] = $value;
}
foreach ([$runtime, $runtime . '/storage/framework/views', $runtime . '/storage/framework/cache',
    $runtime . '/storage/framework/sessions', $runtime . '/storage/logs'] as $directory) {
    if (!is_dir($directory)) mkdir($directory, 0777, true);
}
define('DUJIAOKA_FRAMEWORK_PROBE', true);
require __DIR__ . '/vendor/autoload.php';
