<?php

namespace Tests\Feature;

use Tests\TestCase;

class InstallPageTest extends TestCase
{
    public function testInstallerSubmitsTheCurrentSessionCsrfToken(): void
    {
        $response = $this->get('/install');

        $response->assertOk();
        $response->assertSee('name="_token"', false);
        $response->assertSee('value="' . session()->token() . '"', false);
    }
}
