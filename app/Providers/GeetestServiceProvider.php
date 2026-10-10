<?php

namespace App\Providers;

use App\Captcha\GeetestService;
use App\Http\Controllers\Home\HomeController;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class GeetestServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(GeetestService::class, function ($app) {
            return new GeetestService($app->make(Client::class));
        });
    }

    public function boot()
    {
        $this->loadViewsFrom(resource_path('views/vendor/geetest'), 'geetest');
        Route::middleware('web')->get('geetest', [HomeController::class, 'geetest']);
        Validator::extend('geetest', function ($attribute, $value, $parameters, $validator) {
            return app(GeetestService::class)->validate(request(), $validator->getData());
        });
        // Preserve the directive in the existing published V3 view.
        Blade::extend(function ($value) {
            return preg_replace('/@define(.+)/', '<?php 1; ?>', $value);
        });
    }
}
