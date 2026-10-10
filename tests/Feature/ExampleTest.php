<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function testApplicationUsesIsolatedServices()
    {
        $this->assertSame('testing', $this->app->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('sync', config('queue.default'));
        $compat = defined('DUJIAOKA_FRAMEWORK_PROBE');
        $this->assertSame(base_path($compat ? 'tests/Compatibility/Laravel12' : 'tests'), $this->app->environmentPath());
        $this->assertSame(base_path($compat ? 'tests/.runtime/laravel12/storage' : 'tests/.runtime/storage'), $this->app->storagePath());
        foreach (['Config', 'Routes', 'Packages', 'Services', 'Events'] as $kind) {
            $method = 'getCached' . $kind . 'Path';
            $this->assertSame(base_path('tests/.runtime/' . ($compat ? 'laravel12/' : '') . strtolower($kind) . '.php'), $this->app->$method());
        }
    }
}
