<?php

namespace Tests\Feature;

use Tests\TestCase;

class CaptchaImageTest extends TestCase
{
    public function testDiscoveredProviderKeepsItsIdentityAndPublishResources(): void
    {
        $name = 'Mews\Captcha\CaptchaServiceProvider';
        $provider = app()->getProvider($name);
        $this->assertNotNull($provider);
        $this->assertSame($name, get_class($provider));
        $this->assertSame($provider, app()->register($name));
        $this->assertCount(1, app()->getProviders($name));
        $paths = \Illuminate\Support\ServiceProvider::pathsToPublish($name, 'config');
        $this->assertCount(1, $paths);
        $this->assertSame([config_path('captcha.php')], array_values($paths));
        $this->assertFileExists(array_keys($paths)[0]);
    }

    public function testApiCaptchaUsesTheOriginalHasherAndConsumesTheKey(): void
    {
        $hash = app('hash')->make('a9b7zq21');
        app('cache')->put('captcha_' . md5($hash), true, 60);
        $rule = 'captcha_api:' . $hash . ',default';
        $this->assertTrue(app('validator')->make(['answer' => 'A9B7ZQ21'], ['answer' => $rule])->passes());
        $this->assertFalse(app('validator')->make(['answer' => 'A9B7ZQ21'], ['answer' => $rule])->passes());
        $hash = app('hash')->make('different-answer');
        app('cache')->put('captcha_' . md5($hash), true, 60);
        $this->assertFalse(app('validator')->make(['answer' => 'wrong-answer'], ['answer' => 'captcha_api:' . $hash . ',default'])->passes());
    }

    public function testCaptchaValidationUsesOriginalHasherAndConsumesCorrectAnswer(): void
    {
        app('session')->start();
        $hash = app('hash')->make('a9b7zq21');
        app('session')->put('captcha', ['key' => $hash, 'sensitive' => false, 'encrypt' => false]);
        app('cache')->put('captcha_' . md5($hash), true, 60);
        $this->assertTrue(app('validator')->make(['answer' => 'A9B7ZQ21'], ['answer' => 'captcha'])->passes());
        $this->assertFalse(app('validator')->make(['answer' => 'A9B7ZQ21'], ['answer' => 'captcha'])->passes());
    }

    public function testCaptchaValidationRejectsAnIncorrectAnswer(): void
    {
        app('session')->start();
        $hash = app('hash')->make('a9b7zq21');
        app('session')->put('captcha', ['key' => $hash, 'sensitive' => false, 'encrypt' => false]);
        app('cache')->put('captcha_' . md5($hash), true, 60);
        $this->assertFalse(app('validator')->make(['answer' => 'wrong-answer'], ['answer' => 'captcha'])->passes());
    }

    public function testRealCaptchaRendererProducesDecodableImage(): void
    {
        app('session')->start();
        $response = app('captcha')->create('default');
        $this->assertSame(200, $response->getStatusCode());
        $image = getimagesizefromstring($response->getContent());
        $this->assertIsArray($image);
        $this->assertSame(120, $image[0]);
        $this->assertSame(36, $image[1]);
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
    }
}
