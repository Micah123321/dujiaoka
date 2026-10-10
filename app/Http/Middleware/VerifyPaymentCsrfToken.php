<?php

namespace App\Http\Middleware;

class VerifyPaymentCsrfToken extends VerifyCsrfToken
{
    // Legacy provider callbacks are exempt; customer intent creation is not.
    protected $except = [];
}
