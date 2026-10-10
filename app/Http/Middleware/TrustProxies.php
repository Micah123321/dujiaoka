<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;

// Keep the current middleware entry while the legacy and modern trees coexist.
class_alias(
    class_exists(\Illuminate\Http\Middleware\TrustProxies::class)
        ? \Illuminate\Http\Middleware\TrustProxies::class : \Fideloper\Proxy\TrustProxies::class,
    __NAMESPACE__ . '\\TrustProxiesMiddleware'
);

class TrustProxies extends TrustProxiesMiddleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array|string
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT;
}
