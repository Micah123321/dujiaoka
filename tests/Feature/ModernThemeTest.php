<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\ThemeData;
use Tests\TestCase;

class ModernThemeTest extends TestCase
{
    public function testAllThemePagesRenderWithoutSerializingPrivateFields(): void
    {
        Cache::put('system-setting', ['template' => 'modern', 'text_logo' => 'Fixture shop', 'notice' => '', 'is_open_img_code' => 0, 'is_open_geetest' => 0, 'is_open_search_pwd' => 1], 60);
        foreach (ThemeData::pages() as $page => $data) {
            $html = view('modern.static_pages.' . $page, array_merge($data, ['page_title' => 'Fixture']))->render();
            $this->assertStringContainsString('id="modern-data"', $html, 'Build frontend assets before running theme integration tests: ' . $page);
            $this->assertStringNotContainsString('PRIVATE-PAYMENT-MARKER', $html, $page);
            $this->assertStringNotContainsString('PRIVATE-SEARCH-MARKER', $html, $page);
        }
        $html = view('modern.errors.error', ['title' => 'Fixture error', 'content' => '<script>alert(1)</script>', 'url' => ''])->render();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }
}
