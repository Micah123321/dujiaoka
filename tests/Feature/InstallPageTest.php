<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallPageTest extends TestCase
{
    public function testInstallerSubmitsTheCurrentSessionCsrfToken(): void
    {
        $response = $this->get('/install');

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), 'name="_token"'));
        $response->assertSee('value="' . session()->token() . '"', false);
    }

    public function testContainerInstallerPrefillsPublicSettingsWithoutExposingPassword(): void
    {
        $settings = $this->containerSettings();
        $response = $this->get('/install');

        $response->assertOk();
        foreach (['db_host', 'db_database', 'db_username', 'redis_host', 'app_url'] as $field) {
            $response->assertSee('name="' . $field . '" value="' . $settings[$field] . '"', false);
        }
        $response->assertDontSee($settings['db_password'], false);
    }

    public function testContainerInstallerRejectsDifferentTargetsBeforeConnecting(): void
    {
        $settings = $this->containerSettings();
        \Illuminate\Support\Facades\DB::shouldReceive('purge')->never();

        foreach (array_keys($settings) as $field) {
            $this->post('/do-install', array_replace($settings, [$field => 'other-target']))
                ->assertOk()->assertSee('Docker')->assertSee($field);
        }
    }

    public function testMatchingContainerSettingsReachTheDatabaseWithNormalizedRedisPassword(): void
    {
        $settings = $this->containerSettings();
        $settings['redis_password'] = 'null';
        $settings['app_url'] .= '/';
        \Illuminate\Support\Facades\DB::shouldReceive('purge')->once();
        \Illuminate\Support\Facades\DB::shouldReceive('connection')->once()
            ->andThrow(new \RuntimeException('Fixture database reached'));

        $this->post('/do-install', $settings)->assertOk()->assertSee('Fixture database reached');
    }

    private function containerSettings(): array
    {
        config([
            'dujiaoka.container_installation' => true,
            'database.connections.mysql.host' => 'mysql-fixture',
            'database.connections.mysql.port' => 3306,
            'database.connections.mysql.database' => 'fixture-shop',
            'database.connections.mysql.username' => 'fixture-user',
            'database.connections.mysql.password' => 'fixture-password-hidden',
            'database.redis.default.host' => 'redis-fixture',
            'database.redis.default.port' => 6379,
            'database.redis.default.password' => null,
            'app.url' => 'https://fixture.example',
        ]);

        return [
            'db_host' => 'mysql-fixture', 'db_port' => 3306,
            'db_database' => 'fixture-shop', 'db_username' => 'fixture-user',
            'db_password' => 'fixture-password-hidden',
            'redis_host' => 'redis-fixture', 'redis_port' => 6379,
            'redis_password' => '', 'app_url' => 'https://fixture.example',
        ];
    }
}
