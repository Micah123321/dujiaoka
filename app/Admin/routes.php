<?php

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Dcat\Admin\Admin;

Admin::routes();

Route::group([
    'prefix'     => config('admin.route.prefix'),
    'namespace'  => config('admin.route.namespace'),
    'middleware' => config('admin.route.middleware'),
], function (Router $router) {
    $router->get('/', 'HomeController@index');
    $router->resource('goods', 'GoodsController');
    $router->resource('goods-group', 'GoodsGroupController');
    $router->resource('carmis', 'CarmisController');
    $router->resource('coupon', 'CouponController');
    $router->resource('emailtpl', 'EmailtplController');
    $router->resource('pay', 'PayController');
    $router->resource('order', 'OrderController');
    $router->get('import-carmis', 'CarmisController@importCarmis');
    $router->get('system-setting', 'SystemSettingController@systemSetting');
    $router->get('email-test', 'EmailTestController@emailTest');
});

$operationsMiddleware = config('admin.route.middleware');
$adminIndex = array_search('admin', $operationsMiddleware, true);
array_splice($operationsMiddleware, $adminIndex === false ? count($operationsMiddleware) : $adminIndex, 0,
    [\App\Admin\Middleware\OperationsMiddleware::class]);
Route::group([
    'prefix' => config('admin.route.prefix'),
    'domain' => config('admin.route.domain'),
    'namespace' => config('admin.route.namespace'),
    'middleware' => $operationsMiddleware,
], function (Router $router) {
    foreach (\App\Admin\Support\OperationsAccess::sections() as $section => $label) {
        $router->get('operations/' . $section, 'OperationsController@page')->defaults('section', $section)
            ->name('operations.' . $section . '.page');
        $router->get('operations/' . $section . '/data', 'OperationsController@data')->defaults('section', $section)
            ->name('dcat-api.operations.' . $section . '.data');
        $router->post('operations/' . $section . '/action', 'OperationsController@action')->defaults('section', $section)
            ->name('dcat-api.operations.' . $section . '.action');
    }
});
