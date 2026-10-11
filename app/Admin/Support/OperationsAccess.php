<?php

namespace App\Admin\Support;

use App\Admin\Middleware\ResourcePermission;
use Dcat\Admin\Admin;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

class OperationsAccess
{
    public static function sections(): array
    {
        return ['overview' => '经营概览', 'stripe' => 'Stripe 对账', 'paypal' => 'PayPal 对账',
            'notifications' => '通知投递', 'quotes' => '冻结报价', 'runtime' => '运行状态'];
    }

    public static function names(string $section, string $method = 'GET'): string
    {
        return admin_route_name('operations.' . $section . (strtoupper($method) === 'POST' ? '.operate' : '.page'));
    }

    public static function url(string $path): string
    {
        $domain = config('admin.route.domain');
        // Same-site links must follow the browser protocol behind HTTPS proxies.
        return $domain ? request()->getScheme() . '://' . $domain . admin_base_path($path) : admin_base_path($path);
    }

    public static function routeTemplate(string $section, string $method = 'GET'): Route
    {
        abort_unless(isset(self::sections()[$section]) && in_array(strtoupper($method), ['GET', 'POST'], true), 404);
        // The page path is the permission authority; API routes must never supply this template.
        $route = new Route(strtoupper($method), trim(admin_base_path('operations/' . $section), '/'), function () {});
        $route->name(self::names($section, $method));
        if (config('admin.route.domain')) {
            $route->domain(config('admin.route.domain'));
        }
        return $route;
    }

    public static function allows(string $section, string $method = 'GET'): bool
    {
        if (!isset(self::sections()[$section]) || !in_array(strtoupper($method), ['GET', 'POST'], true) || !Admin::user()) {
            return false;
        }
        $request = Request::create(self::url('operations/' . $section), strtoupper($method));
        $route = self::routeTemplate($section, $method);
        if (!$route->matches($request)) {
            return false;
        }
        $route->bind($request);
        $request->setRouteResolver(function () use ($route) { return $route; });
        return ResourcePermission::allows(Admin::user(), $request);
    }

    public static function management(): array
    {
        $result = [];
        foreach (['goods' => '商品管理', 'order' => '订单管理', 'carmis' => '卡密管理', 'coupon' => '优惠券',
            'pay' => '支付渠道', 'emailtpl' => '邮件模板', 'system-setting' => '系统设置'] as $path => $label) {
            $request = Request::create(self::url($path), 'GET');
            foreach (app('router')->getRoutes() as $route) {
                if (!$route->isFallback && $route->matches($request)) {
                    $mapped = (clone $route)->bind($request);
                    $request->setRouteResolver(function () use ($mapped) { return $mapped; });
                    if (Admin::user() && ResourcePermission::allows(Admin::user(), $request)) {
                        $result[] = ['key' => $path, 'label' => $label, 'href' => self::url($path)];
                    }
                    break;
                }
            }
        }
        return $result;
    }
}
