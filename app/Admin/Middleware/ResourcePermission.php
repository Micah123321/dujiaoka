<?php

namespace App\Admin\Middleware;

use App\Admin\Actions\Post\BatchRestore;
use App\Admin\Actions\Post\Restore;
use App\Admin\Forms\EmailTest;
use App\Admin\Forms\ImportCarmis;
use App\Admin\Forms\SystemSetting;
use App\Admin\Repositories\TransactionalRepository;
use App\Admin\Support\BulkActionInput;
use App\Admin\Actions\BatchSetStatus;
use App\Admin\Actions\CloneResource;
use App\Models\Carmis;
use App\Models\Coupon;
use App\Models\Emailtpl;
use App\Models\Goods;
use App\Models\GoodsGroup;
use App\Models\Order;
use App\Models\Pay;
use Dcat\Admin\Admin;
use Illuminate\Http\Request;

class ResourcePermission
{
    private const MODELS = [
        Goods::class => 'goods', GoodsGroup::class => 'goods-group',
        Carmis::class => 'carmis', Coupon::class => 'coupon',
        Emailtpl::class => 'emailtpl', Pay::class => 'pay', Order::class => 'order',
    ];

    private const FORMS = [
        ImportCarmis::class => 'import-carmis', SystemSetting::class => 'system-setting',
        EmailTest::class => 'email-test',
    ];

    public function handle(Request $request, \Closure $next)
    {
        $path = trim($request->decodedPath(), '/');
        $prefix = trim((string) config('admin.route.prefix'), '/');
        if ($prefix !== '') {
            if (strpos($path, $prefix . '/') !== 0) {
                return $next($request);
            }
            $path = substr($path, strlen($prefix) + 1);
        }

        $segments = explode('/', $path);
        $resource = $segments[0];
        $deleteKeys = [];
        if ($request->isMethod('DELETE') && count($segments) === 2
            && in_array($resource, array_values(self::MODELS), true)) {
            try {
                $deleteKeys = TransactionalRepository::deletionKeys($segments[1]);
            } catch (\InvalidArgumentException $exception) {
                abort(400, $exception->getMessage());
            }
        }
        $permissionRequest = null;
        if (in_array($resource, array_values(self::MODELS), true)
            || in_array($path, array_values(self::FORMS), true)) {
            $permissionRequest = $request;
        } elseif (in_array($path, ['dcat-api/form', 'dcat-api/form/upload', 'dcat-api/form/destroy-file'], true)) {
            // Identify only our forms; the dispatcher still verifies the original signature.
            $form = $request->get('_form_');
            if (is_string($form)) {
                $class = explode('|', $form, 2)[0];
                foreach (self::FORMS as $known => $page) {
                    if (strcasecmp(ltrim($class, '\\'), $known) === 0) {
                        $permissionRequest = self::requestFor($page, 'POST');
                        break;
                    }
                }
            }
        }

        $user = Admin::user();
        if ($user && $path === 'dcat-api/action') {
            $action = $request->get('_action');
            if (is_string($action)) {
                $class = str_replace('_', '\\', explode('|', $action, 2)[0]);
                if (strcasecmp(ltrim($class, '\\'), Restore::class) === 0
                    || strcasecmp(ltrim($class, '\\'), BatchRestore::class) === 0) {
                    self::authorizeRestore($request->get('model'), (array) $request->get('_key'));
                } elseif ($class === BatchSetStatus::class) {
                    $model = BulkActionInput::model($request->get('model'));
                    $keys = BulkActionInput::keys($request->get('_key'));
                    BulkActionInput::status($request->get('field'), $request->get('state'));
                    self::authorizeUpdate($model, $keys);
                } elseif ($class === CloneResource::class) {
                    $model = BulkActionInput::model($request->get('model'), true);
                    $keys = BulkActionInput::keys([$request->get('_key')]);
                    if (!self::canClone($user, $model, $keys[0])) abort(403, __('admin.deny'));
                }
            }
        }
        if ($permissionRequest && $user && ! self::allows($user, $permissionRequest)) {
            abort(403, __('admin.deny'));
        }
        foreach ($deleteKeys as $key) {
            if ($user && ! self::allows($user, self::requestFor($resource . '/' . $key, 'DELETE'))) {
                abort(403, __('admin.deny'));
            }
        }

        return $next($request);
    }

    public static function allows($user, Request $request): bool
    {
        if (! config('admin.permission.enable') || ($user && $user->isAdministrator())) {
            return true;
        }
        if (! $user) {
            return false;
        }
        $original = app('request');
        // Dcat also reads the global route name; mapped checks must use the mapped request.
        app()->instance('request', $request);
        try {
            return (bool) $user->allPermissions()->first(function ($permission) use ($request) {
                return $permission->shouldPassThrough($request);
            });
        } finally {
            app()->instance('request', $original);
        }
    }

    private static function requestFor(string $path, string $method): Request
    {
        $origin = app('request')->getSchemeAndHttpHost();
        $request = Request::create($origin . '/' . trim(admin_base_path($path), '/'), $method);
        foreach (app('router')->getRoutes() as $route) {
            if (! $route->isFallback && $route->matches($request)) {
                // Preserve the original batch route's parameters during per-ID checks.
                $resourceRoute = (clone $route)->bind($request);
                $request->setRouteResolver(function () use ($resourceRoute) { return $resourceRoute; });
                break;
            }
        }
        return $request;
    }

    public static function canUpdate($user, string $model, array $keys, bool $display = false): bool
    {
        if (!isset(BulkActionInput::RESOURCES[$model])) return false;
        $resource = BulkActionInput::RESOURCES[$model];
        if (!$keys && $display) return self::allows($user, self::requestFor($resource . '/*', 'PUT'));
        if (!$keys) return false;
        foreach ($keys as $key) {
            if (!self::allows($user, self::requestFor($resource . '/' . $key, 'PUT'))) return false;
        }
        return true;
    }

    public static function authorizeUpdate(string $model, array $keys): void
    {
        if (!self::canUpdate(Admin::user(), $model, $keys)) abort(403, __('admin.deny'));
    }

    public static function canClone($user, string $model, $key): bool
    {
        if (!in_array($model, [Goods::class, Pay::class], true) || !is_scalar($key)
            || !preg_match('/^[1-9][0-9]*$/D', (string) $key)) return false;
        $resource = BulkActionInput::RESOURCES[$model];
        return self::allows($user, self::requestFor($resource . '/' . $key, 'GET'))
            && self::allows($user, self::requestFor($resource, 'POST'));
    }

    public static function restoreModel($model): ?string
    {
        if (is_string($model)) {
            foreach (self::MODELS as $known => $resource) {
                if (strcasecmp(ltrim($model, '\\'), $known) === 0) {
                    return $known;
                }
            }
        }
        return null;
    }

    public static function canRestore($user, $model, array $keys, bool $display = false): bool
    {
        $model = self::restoreModel($model);
        if (! $model) {
            return false;
        }
        // Batch rendering has no selected IDs yet; a wildcard PUT grant can show the button.
        if (! $keys && $display) {
            return self::allows($user, self::requestFor(self::MODELS[$model] . '/*', 'PUT'));
        }
        if (! $keys) {
            return false;
        }
        foreach ($keys as $key) {
            if ((! is_int($key) && ! is_string($key)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $key)
                || ! self::allows($user, self::requestFor(self::MODELS[$model] . '/' . $key, 'PUT'))) {
                return false;
            }
        }
        return true;
    }

    public static function authorizeRestore($model, array $keys): string
    {
        if (! self::canRestore(Admin::user(), $model, $keys)) {
            abort(403, __('admin.deny'));
        }
        return self::restoreModel($model);
    }
}
