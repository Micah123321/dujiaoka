<?php

namespace App\Captcha;

use Illuminate\Support\Facades\Facade;

class Geetest extends Facade
{
    protected static function getFacadeAccessor()
    {
        return GeetestService::class;
    }
}
