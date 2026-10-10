<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        // Never fall back to the real workspace environment file.
        $app->useEnvironmentPath(defined('DUJIAOKA_FRAMEWORK_PROBE') ? __DIR__ . '/Compatibility/Laravel12' : __DIR__);
        $app->loadEnvironmentFrom(defined('DUJIAOKA_FRAMEWORK_PROBE') ? '.env.fixture-not-present' : '.env.testing');
        $storage = defined('DUJIAOKA_LEGACY_REPLAY') ? 'laravel6/storage'
            : (defined('DUJIAOKA_FRAMEWORK_PROBE') ? 'laravel12/storage' : 'storage');
        $app->useStoragePath(__DIR__ . '/.runtime/' . $storage);

        // Laravel 6 package discovery otherwise reads the modern root vendor.
        if (defined('DUJIAOKA_LEGACY_REPLAY')) {
            $vendor = __DIR__ . '/Compatibility/Laravel6/vendor';
            $app->instance('path.vendor', $vendor);
            $app->make(\Illuminate\Foundation\PackageManifest::class)->vendorPath = $vendor;
        }

        // Refresh test-only discovery after dependency changes without loading shop caches.
        $app->make(\Illuminate\Foundation\PackageManifest::class)->build();
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
