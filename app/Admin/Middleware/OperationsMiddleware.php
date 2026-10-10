<?php

namespace App\Admin\Middleware;

use App\Admin\Support\OperationsAccess;
use Dcat\Admin\Admin;
use Illuminate\Http\Request;

class OperationsMiddleware
{
    public function handle(Request $request, \Closure $next)
    {
        if (!Admin::user()) {
            return response()->json(['message' => '请先登录管理后台。'], 401);
        }
        $section = $request->route('section');
        abort_unless(isset(OperationsAccess::sections()[$section]), 404);
        if (!OperationsAccess::allows($section, $request->isMethod('POST') ? 'POST' : 'GET')) {
            return response()->json(['message' => '当前账号没有此模块的操作权限。'], 403);
        }
        return $next($request);
    }
}
