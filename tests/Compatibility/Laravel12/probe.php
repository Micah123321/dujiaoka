<?php

require __DIR__ . '/bootstrap.php';
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__);
$app->loadEnvironmentFrom('.env.fixture-not-present');
$app->instance('path.vendor', __DIR__ . '/vendor');
$app->make(
    Illuminate\Foundation\PackageManifest::class
)->build();
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$handler = $app->make(Illuminate\Contracts\Debug\ExceptionHandler::class);
$app->make(App\Http\Middleware\TrustProxies::class);
$app->make(App\Http\Middleware\CheckForMaintenanceMode::class);
echo json_encode([
    'framework' => $app->version(), 'php' => PHP_VERSION,
    'routes' => count($app->make('router')->getRoutes()), 'handler' => get_class($handler),
    'database' => config('database.default'), 'database_name' => config('database.connections.sqlite.database'),
    'cache' => config('cache.default'), 'storage' => $app->storagePath(),
    'admin_package' => Composer\InstalledVersions::getPrettyVersion('dcat-x/laravel-admin'),
    'mail_transport' => get_class($app->make('mail.manager')->mailer()->getSymfonyTransport()),
    'status' => 'PASS bootstrap, routes and middleware classes',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
