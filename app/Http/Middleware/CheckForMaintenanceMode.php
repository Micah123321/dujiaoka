<?php

namespace App\Http\Middleware;

class_alias(
    class_exists(\Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class)
        ? \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class
        : \Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode::class,
    __NAMESPACE__ . '\\MaintenanceMiddleware'
);

class CheckForMaintenanceMode extends MaintenanceMiddleware
{
    /**
     * The URIs that should be reachable while maintenance mode is enabled.
     *
     * @var array
     */
    protected $except = [
        //
    ];
}
