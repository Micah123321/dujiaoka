<?php

namespace App\Providers;

use Composer\InstalledVersions;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Mews\Captcha\Captcha;

// Avoid the upstream provider's PHP 8.5 compile-time cast deprecation.
class CanonicalCaptchaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom($this->packageConfig(), 'captcha');
        if (!$this->app->bound(ImageManager::class)) {
            $this->app->singleton(ImageManager::class, function () {
                $driver = config('captcha.driver', 'gd') === 'imagick' ? new ImagickDriver() : new GdDriver();
                return new ImageManager($driver);
            });
        }
        $this->app->bind('captcha', function ($app) {
            return new Captcha(
                $app['Illuminate\Filesystem\Filesystem'],
                $app['Illuminate\Contracts\Config\Repository'],
                $app[ImageManager::class],
                $app['Illuminate\Session\Store'],
                $app['Illuminate\Hashing\BcryptHasher'],
                $app['Illuminate\Support\Str']
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([$this->packageConfig() => config_path('captcha.php')], 'config');
        if (!config('captcha.disable')) {
            $this->app['router']->get('captcha/api/{config?}', '\Mews\Captcha\CaptchaController@getCaptchaApi')->middleware('web');
            $this->app['router']->get('captcha/{config?}', '\Mews\Captcha\CaptchaController@getCaptcha')->middleware('web');
        }
        $this->app['validator']->extend('captcha', function ($attribute, $value, $parameters) {
            return config('captcha.disable') || ($value && captcha_check($value));
        });
        $this->app['validator']->extend('captcha_api', function ($attribute, $value, $parameters) {
            return config('captcha.disable') || ($value && captcha_api_check($value, $parameters[0], $parameters[1] ?? 'default'));
        });
    }

    private function packageConfig(): string
    {
        return InstalledVersions::getInstallPath('mews/captcha') . '/config/captcha.php';
    }
}
