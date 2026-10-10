<?php

namespace Tests\Feature;

use App\Admin\Actions\Post\BatchRestore;
use App\Admin\Actions\Post\Restore;
use App\Admin\Forms\EmailTest;
use App\Admin\Forms\ImportCarmis;
use App\Admin\Forms\SystemSetting;
use App\Admin\Middleware\ResourcePermission;
use App\Models\Goods;
use Dcat\Admin\Admin;
use Dcat\Admin\Http\Controllers\HandleActionController;
use Dcat\Admin\Http\Controllers\HandleFormController;
use Dcat\Admin\Http\Middleware\Permission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AdminMutationPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'admin.database.connection' => 'sqlite',
            'admin.route.prefix' => 'admin', 'admin.permission.enable' => true,
        ]);
        DB::purge('sqlite');
        Schema::create('admin_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username');
            $table->string('name');
            $table->string('password')->default('');
            $table->string('avatar')->nullable();
            $table->string('remember_token')->nullable();
            $table->timestamps();
        });
        Schema::create('admin_roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('admin_permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('parent_id')->default(0);
            $table->integer('order')->default(0);
            $table->string('slug');
            $table->string('name');
            $table->string('http_method');
            $table->text('http_path');
            $table->timestamps();
        });
        Schema::create('admin_menu', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('parent_id')->default(0);
            $table->integer('order')->default(0);
            $table->string('title');
            $table->string('icon')->default('');
            $table->string('uri');
            $table->timestamps();
        });
        foreach ([
            'admin_role_users' => ['role_id', 'user_id'],
            'admin_role_permissions' => ['role_id', 'permission_id'],
            'admin_role_menu' => ['role_id', 'menu_id'],
            'admin_permission_menu' => ['permission_id', 'menu_id'],
        ] as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->integer($column);
                }
                $table->timestamps();
            });
        }
        foreach (self::models() as $data) {
            Schema::create($data[2], function (Blueprint $table) {
                $table->increments('id');
                $table->integer('is_open')->default(1);
                $table->string('gd_name')->default('sample');
                $table->integer('type')->default(1);
                $table->integer('goods_id')->default(1);
                $table->integer('status')->default(1);
                $table->text('carmi')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
            DB::table($data[2])->insert([
                ['id' => 1, 'deleted_at' => null],
                ['id' => 3, 'deleted_at' => '2026-01-01 00:00:00'],
                ['id' => 4, 'deleted_at' => '2026-01-01 00:00:00'],
            ]);
        }
    }

    private function user($slug, array $permissions = [])
    {
        $id = DB::table('admin_roles')->insertGetId(['slug' => $slug, 'name' => $slug]);
        $userId = DB::table('admin_users')->insertGetId(['username' => $slug, 'name' => $slug]);
        DB::table('admin_role_users')->insert(['role_id' => $id, 'user_id' => $userId]);
        foreach ($permissions as $index => $permission) {
            $permissionId = DB::table('admin_permissions')->insertGetId([
                'slug' => $slug . '-' . $index, 'name' => 'Permission',
                'http_method' => $permission[0], 'http_path' => $permission[1],
            ]);
            DB::table('admin_role_permissions')->insert(['role_id' => $id, 'permission_id' => $permissionId]);
        }
        $class = config('admin.database.users_model');
        $user = $class::findOrFail($userId);
        $this->actingAs($user, config('admin.auth.guard'));
        return $user;
    }

    private function role($slug)
    {
        $permissions = [
            'administrator' => [],
            'reader' => [['GET', '/goods,/goods/1,/import-carmis']],
            'goods-editor' => [['GET,PUT,PATCH', '/goods,/goods/*']],
            'importer' => [['GET,POST', '/import-carmis']],
        ];
        return $this->user($slug, $permissions[$slug]);
    }

    private function request($path, $method, array $input = [])
    {
        $request = Request::create('/admin/' . $path, $method, $input);
        $route = $this->app['router']->getRoutes()->match($request);
        $request->setRouteResolver(function () use ($route) { return $route; });
        $this->app->instance('request', $request);
        $this->assertContains(ResourcePermission::class, $route->gatherMiddleware());
        return $request;
    }

    private function guard(Request $request, $next = null)
    {
        return (new ResourcePermission())->handle($request, $next ?: function () { return 'next'; });
    }

    private function assertForbidden(callable $operation)
    {
        try {
            $operation();
            $this->fail('Mutation reached the next handler without its resource permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function signedClass($class)
    {
        return class_exists(\Dcat\Admin\Support\ClassSigner::class)
            ? \Dcat\Admin\Support\ClassSigner::sign($class) : $class;
    }

    private function restoreRequest($key, $model = Goods::class, $action = Restore::class)
    {
        return $this->request('dcat-api/action', 'POST', [
            '_key' => $key, 'model' => $model,
            '_action' => str_replace('\\', '_', $this->signedClass($action)),
        ]);
    }

    public static function models(): array
    {
        return [
            'goods' => [\App\Models\Goods::class, 'goods', 'goods'],
            'groups' => [\App\Models\GoodsGroup::class, 'goods-group', 'goods_group'],
            'cards' => [\App\Models\Carmis::class, 'carmis', 'carmis'],
            'coupons' => [\App\Models\Coupon::class, 'coupon', 'coupons'],
            'email' => [\App\Models\Emailtpl::class, 'emailtpl', 'emailtpls'],
            'payment' => [\App\Models\Pay::class, 'pay', 'pays'],
            'orders' => [\App\Models\Order::class, 'order', 'orders'],
        ];
    }

    public static function rolesAndResources(): array
    {
        $data = [];
        foreach (['administrator', 'reader', 'goods-editor', 'importer'] as $role) {
            foreach (self::models() as $model) {
                $data[$role . '-' . $model[1]] = [$role, $model[1]];
            }
        }
        return $data;
    }

    /** @dataProvider rolesAndResources */
    #[\PHPUnit\Framework\Attributes\DataProvider('rolesAndResources')]
    public function test_real_resource_routes_respect_stored_method_and_path($role, $resource): void
    {
        $this->role($role);
        foreach ([['GET', ''], ['GET', '/1'], ['GET', '/create'], ['GET', '/1/edit'],
            ['POST', ''], ['PUT', '/1'], ['PATCH', '/1'], ['DELETE', '/1']] as $operation) {
            $request = $this->request($resource . $operation[1], $operation[0]);
            $allowed = $role === 'administrator'
                || ($role === 'goods-editor' && $resource === 'goods'
                    && in_array($operation[0], ['GET', 'PUT', 'PATCH'], true))
                || ($role === 'reader' && $resource === 'goods' && $operation[0] === 'GET'
                    && in_array($operation[1], ['', '/1'], true));
            if ($allowed) {
                $this->assertSame('next', $this->guard($request));
            } else {
                $this->assertForbidden(function () use ($request) { $this->guard($request); });
            }
        }
    }

    public static function rolesAndForms(): array
    {
        $data = [];
        foreach (['administrator', 'reader', 'goods-editor', 'importer'] as $role) {
            foreach ([ImportCarmis::class => 'import-carmis', SystemSetting::class => 'system-setting',
                EmailTest::class => 'email-test'] as $form => $page) {
                $data[$role . '-' . $page] = [$role, $form, $page];
            }
        }
        return $data;
    }

    /** @dataProvider rolesAndForms */
    #[\PHPUnit\Framework\Attributes\DataProvider('rolesAndForms')]
    public function test_widget_submit_upload_destroy_require_post_permission($role, $form, $page): void
    {
        $this->role($role);
        $pageRequest = $this->request($page, 'GET');
        if ($role === 'administrator' || ($page === 'import-carmis' && in_array($role, ['reader', 'importer'], true))) {
            $this->assertSame('next', $this->guard($pageRequest));
        } else {
            $this->assertForbidden(function () use ($pageRequest) { $this->guard($pageRequest); });
        }
        foreach (['dcat-api/form', 'dcat-api/form/upload', 'dcat-api/form/destroy-file'] as $endpoint) {
            foreach ([$form, $this->signedClass($form), '\\' . strtolower($form)] as $class) {
                $request = $this->request($endpoint, 'POST', ['_form_' => $class]);
                $called = false;
                $next = function () use (&$called) { $called = true; return 'next'; };
                if ($role === 'administrator' || ($role === 'importer' && $page === 'import-carmis')) {
                    $this->assertSame('next', $this->guard($request, $next));
                    $this->assertTrue($called);
                } else {
                    $this->assertForbidden(function () use ($request, $next) { $this->guard($request, $next); });
                    $this->assertFalse($called);
                }
            }
        }
    }

    public function test_reader_cannot_restore_goods_via_shared_action(): void
    {
        $this->role('reader');
        $action = (new Restore())->setKey(3);
        $request = $this->restoreRequest(3);
        $this->assertForbidden(function () use ($request) { $this->guard($request); });
        $this->assertForbidden(function () use ($action, $request) { $action->handle($request); });
        $this->assertFalse($action->passesAuthorization());
        $this->assertNotNull(Goods::withTrashed()->findOrFail(3)->deleted_at);
    }

    /** @dataProvider models */
    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function test_real_single_and_batch_restores_use_each_resource($model, $resource, $table): void
    {
        $this->user('editor', [['PUT', '/' . $resource . '/3,/' . $resource . '/4']]);
        $controller = new HandleActionController();
        $single = $this->restoreRequest(3, $model);
        $response = $this->guard($single, function ($request) use ($controller) { return $controller->handle($request); });
        $this->assertTrue((bool) $response->getData(true)['status']);
        $this->assertNull(DB::table($table)->where('id', 3)->value('deleted_at'));
        DB::table($table)->where('id', 3)->update(['deleted_at' => '2026-01-01 00:00:00']);
        $batch = $this->restoreRequest([3, 4], $model, BatchRestore::class);
        $response = $this->guard($batch, function ($request) use ($controller) { return $controller->handle($request); });
        $this->assertTrue((bool) $response->getData(true)['status']);
        $this->assertSame(0, DB::table($table)->whereIn('id', [3, 4])->whereNotNull('deleted_at')->count());
    }

    public function test_batch_checks_permission_for_every_id_before_restoring(): void
    {
        $this->user('limited-editor', [['PUT', '/goods/3']]);
        $action = (new BatchRestore())->setKey([3, 4]);
        $request = $this->restoreRequest([3, 4], Goods::class, BatchRestore::class);
        $this->assertForbidden(function () use ($request) { $this->guard($request); });
        $this->assertForbidden(function () use ($action, $request) { $action->handle($request); });
        $this->assertSame(2, Goods::onlyTrashed()->whereIn('id', [3, 4])->count());
    }

    public function test_batch_missing_key_keeps_every_row_deleted(): void
    {
        $this->role('administrator');
        $action = (new BatchRestore())->setKey([3, 999]);
        try {
            $action->handle($this->restoreRequest([3, 999], Goods::class, BatchRestore::class));
            $this->fail('A missing restore key must fail.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(Goods::class, $exception->getModel());
        }
        $this->assertNotNull(Goods::withTrashed()->findOrFail(3)->deleted_at);
    }

    public function test_batch_restore_rolls_back_if_a_later_restore_throws(): void
    {
        $this->role('administrator');
        Goods::restoring(function ($good) {
            if ((int) $good->id === 4) throw new \RuntimeException('restore failure');
        });
        try {
            (new BatchRestore())->setKey([3, 4])->handle($this->restoreRequest([3, 4], Goods::class, BatchRestore::class));
            $this->fail('The failing model event must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('restore failure', $exception->getMessage());
        } finally {
            Goods::flushEventListeners();
        }
        $this->assertSame(2, Goods::onlyTrashed()->whereIn('id', [3, 4])->count());
    }

    public function test_restore_only_accepts_deleted_rows(): void
    {
        $this->role('administrator');
        $this->expectException(ModelNotFoundException::class);
        (new Restore())->setKey(1)->handle($this->restoreRequest(1));
    }

    public function test_unknown_models_never_autoload_even_with_permission_disabled(): void
    {
        $this->role('administrator');
        config(['admin.permission.enable' => false]);
        $autoloads = [];
        $loader = function ($class) use (&$autoloads) {
            if (stripos($class, 'MissingMutationModel') !== false || strpos($class, 'Unknown') !== false) {
                $autoloads[] = $class;
            }
        };
        spl_autoload_register($loader);
        try {
            foreach (['MissingMutationModel', 'missingmutationmodel', \Illuminate\Database\Eloquent\Model::class,
                \App\Models\BaseModel::class, '../Unknown.php', null, ['model']] as $model) {
                foreach ([Restore::class => 3, BatchRestore::class => [3, 4]] as $class => $keys) {
                    $action = (new $class())->setKey($keys);
                    $request = $this->restoreRequest($keys, $model, $class);
                    $this->assertForbidden(function () use ($request) { $this->guard($request); });
                    $this->assertForbidden(function () use ($action, $request) { $action->handle($request); });
                }
            }
        } finally {
            spl_autoload_unregister($loader);
        }
        $this->assertSame([], $autoloads);
        $this->assertSame(2, Goods::onlyTrashed()->whereIn('id', [3, 4])->count());
    }

    public function test_disabled_permission_and_administrator_semantics_are_preserved(): void
    {
        $this->role('reader');
        config(['admin.permission.enable' => false]);
        $this->assertSame('next', $this->guard($this->request('goods/1', 'DELETE')));
        $this->assertSame('next', $this->guard($this->request('dcat-api/form/upload', 'POST', ['_form_' => SystemSetting::class])));
        (new Restore())->setKey(3)->handle($this->restoreRequest(3));
        $this->assertNull(Goods::findOrFail(3)->deleted_at);
    }

    public function test_no_user_and_unrelated_dcat_apis_keep_the_existing_chain(): void
    {
        $this->assertNull(Admin::user());
        $this->assertSame('next', $this->guard($this->request('goods/1', 'PUT')));
        $this->role('reader');
        $this->assertSame('next', $this->guard($this->request('dcat-api/form', 'POST', ['_form_' => 'VendorForm'])));
        $this->assertSame('next', $this->guard($this->request('dcat-api/action', 'POST', ['_action' => 'VendorAction'])));
        $this->assertSame('next', $this->guard($this->request('dcat-api/value', 'POST')));
        $this->assertSame('next', $this->guard($this->request('auth/login', 'GET')));
    }

    public function test_restore_buttons_use_constructor_model_and_server_request_model(): void
    {
        $this->role('reader');
        $this->request('goods', 'GET');
        $this->assertFalse((new Restore(Goods::class))->setKey(3)->allowed());
        $this->assertSame('', (new Restore(Goods::class))->setKey(3)->render());
        $this->assertFalse((new BatchRestore(Goods::class))->allowed());
        $this->assertSame('', (new BatchRestore(Goods::class))->render());
        $this->role('goods-editor');
        $this->assertTrue((new Restore(Goods::class))->setKey(3)->allowed());
        $this->assertTrue((new BatchRestore(Goods::class))->allowed());
        $this->restoreRequest(3, \App\Models\Pay::class);
        $this->assertFalse((new Restore())->setKey(3)->passesAuthorization());
        $this->assertSame(['model' => Goods::class], (new Restore(Goods::class))->parameters());
        $this->assertSame(['model' => Goods::class], (new BatchRestore(Goods::class))->parameters());
    }

    public function test_import_dispatch_uses_real_signature_and_mutates_only_for_importer(): void
    {
        $this->role('reader');
        $request = $this->request('dcat-api/form', 'POST', [
            '_form_' => $this->signedClass(ImportCarmis::class), 'goods_id' => 1,
            'carmis_list' => 'permission-test-card', 'carmis_txt' => null, 'remove_duplication' => 0,
        ]);
        $controller = new HandleFormController();
        $dispatch = function ($request) use ($controller) { return $controller->handle($request); };
        $this->assertForbidden(function () use ($request, $dispatch) { $this->guard($request, $dispatch); });
        $this->assertSame(0, DB::table('carmis')->where('carmi', 'permission-test-card')->count());
        $this->role('importer');
        $response = $this->guard($request, $dispatch);
        $this->assertTrue((bool) $response->getData(true)['status']);
        $this->assertSame(1, DB::table('carmis')->where('carmi', 'permission-test-card')->count());
        $this->assertFalse((bool) config('admin.allow_unsigned_dispatch', false));
    }

    public function test_permission_guard_leaves_signature_validation_to_vendor(): void
    {
        $this->role('importer');
        $request = $this->request('dcat-api/form', 'POST', [
            '_form_' => ImportCarmis::class . '|invalid-signature', 'goods_id' => 1,
            'carmis_list' => 'invalid-signature-card', 'remove_duplication' => 0,
        ]);
        $this->assertSame('next', $this->guard($request));
        try {
            (new HandleFormController())->handle($request);
            $this->fail('Vendor must reject an invalid dispatcher signature or class.');
        } catch (\Dcat\Admin\Exception\AdminException $exception) {
            $this->assertStringContainsString(\Composer\InstalledVersions::isInstalled('dcat-x/laravel-admin')
                ? 'signature verification failed' : 'does not exist', $exception->getMessage());
        }
        if (\Composer\InstalledVersions::isInstalled('dcat-x/laravel-admin')) {
            $request->request->set('_form_', ImportCarmis::class);
            try {
                (new HandleFormController())->handle($request);
                $this->fail('Candidate dispatcher must still reject unsigned classes.');
            } catch (\Dcat\Admin\Exception\AdminException $exception) {
                $this->assertSame('Class signature missing.', $exception->getMessage());
            }
        }
        $this->assertSame(0, DB::table('carmis')->where('carmi', 'invalid-signature-card')->count());
    }

    public function test_widget_guard_reads_the_same_parameter_as_the_dispatcher(): void
    {
        $this->role('reader');
        foreach ([ImportCarmis::class, SystemSetting::class, EmailTest::class] as $form) {
            foreach (['dcat-api/form', 'dcat-api/form/upload', 'dcat-api/form/destroy-file'] as $endpoint) {
                $request = $this->request($endpoint, 'POST', ['_form_' => 'VendorForm']);
                $request->query->set('_form_', $this->signedClass($form));
                $this->assertSame($this->signedClass($form), $request->get('_form_'));
                $this->assertForbidden(function () use ($request) { $this->guard($request); });
            }
        }
    }

    public function test_method_prefixes_in_stored_paths_keep_existing_dcat_semantics(): void
    {
        $this->user('inline-method', [['GET', 'PUT:/goods/3']]);
        $this->assertSame('next', $this->guard($this->request('goods/3', 'PUT')));
        $request = $this->request('goods/3', 'PATCH');
        $this->assertForbidden(function () use ($request) { $this->guard($request); });
    }

    public static function namedDeleteKeys(): array
    {
        return ['single' => ['3'], 'batch' => ['3,4']];
    }

    /** @dataProvider namedDeleteKeys */
    #[\PHPUnit\Framework\Attributes\DataProvider('namedDeleteKeys')]
    public function test_named_resource_permission_allows_single_and_batch_delete($key): void
    {
        $user = $this->user('named-deleter', [['DELETE', 'goods.destroy']]);
        $this->assertFalse($user->isAdministrator());
        $request = $this->request('goods/' . $key, 'DELETE');
        $this->assertSame('dcat.admin.goods.destroy', $request->route()->getName());
        $this->assertTrue(ResourcePermission::allows($user, $request));
        $parameters = $request->route()->parameters();
        $response = $this->guard($request, function ($request) use ($key, $parameters) {
            $this->assertSame($parameters, $request->route()->parameters());
            return (new Permission())->handle($request, function () use ($key) {
                return (new \Dcat\Admin\Form(new \App\Admin\Repositories\Goods(), function () {}))->destroy($key);
            });
        });
        $this->assertTrue((bool) $response->getData(true)['status']);
        $this->assertSame(0, Goods::withTrashed()->whereIn('id', explode(',', $key))->count());
        $this->assertNull(Goods::findOrFail(1)->deleted_at);
        $this->assertSame($request, request());
        $this->assertSame($parameters, $request->route()->parameters());
    }

    public function test_named_resource_permissions_preserve_the_admin_request_host(): void
    {
        $user = $this->user('domain-deleter', [['DELETE', 'goods.destroy']]);
        $router = $this->app['router'];
        $originalRoutes = $router->getRoutes();
        $router->setRoutes(new \Illuminate\Routing\RouteCollection());
        try {
            $router->delete('admin/goods/{id}', function () {})
                ->domain('admin.example.test')->name('dcat.admin.goods.destroy');
            $request = Request::create('https://admin.example.test/admin/goods/3', 'DELETE');
            $route = $router->getRoutes()->match($request);
            $request->setRouteResolver(function () use ($route) { return $route; });
            $this->app->instance('request', $request);
            $this->assertTrue(ResourcePermission::allows($user, $request));
            $this->assertSame('next', $this->guard($request));
            $this->assertSame($request, request());
        } finally {
            $router->setRoutes($originalRoutes);
        }
    }

    public static function namedRestoreActions(): array
    {
        return ['single' => [3, Restore::class], 'batch' => [[3, 4], BatchRestore::class]];
    }

    /** @dataProvider namedRestoreActions */
    #[\PHPUnit\Framework\Attributes\DataProvider('namedRestoreActions')]
    public function test_named_resource_permission_allows_single_and_batch_restore($keys, $action): void
    {
        $user = $this->user('named-updater', [['PUT', 'goods.update']]);
        $this->assertFalse($user->isAdministrator());
        $resourceRequest = $this->request('goods/3', 'PUT');
        $this->assertSame('dcat.admin.goods.update', $resourceRequest->route()->getName());
        $this->assertTrue(ResourcePermission::allows($user, $resourceRequest));
        $request = $this->restoreRequest($keys, Goods::class, $action);
        $this->assertTrue((new $action(Goods::class))->setKey($keys)->allowed());
        $this->assertTrue((new BatchRestore(Goods::class))->allowed());
        $response = $this->guard($request, function ($request) {
            return (new HandleActionController())->handle($request);
        });
        $this->assertTrue((bool) $response->getData(true)['status']);
        $this->assertSame(0, Goods::onlyTrashed()->whereIn('id', (array) $keys)->count());
        $this->assertSame($request, request());
        $this->assertSame('dcat.admin.dcat-api.action', request()->route()->getName());
    }

    public static function namedMutationDenials(): array
    {
        return [
            'delete-method' => ['GET', 'goods.destroy', 'DELETE'],
            'delete-resource' => ['DELETE', 'pay.destroy', 'DELETE'],
            'restore-method' => ['PATCH', 'goods.update', 'PUT'],
            'restore-resource' => ['PUT', 'pay.update', 'PUT'],
        ];
    }

    /** @dataProvider namedMutationDenials */
    #[\PHPUnit\Framework\Attributes\DataProvider('namedMutationDenials')]
    public function test_named_resource_permissions_require_the_mapped_method_and_resource($method, $name, $mutation): void
    {
        $this->user('wrong-grant', [[$method, $name]]);
        foreach ([3, [3, 4]] as $keys) {
            $action = is_array($keys) ? BatchRestore::class : Restore::class;
            $request = $mutation === 'DELETE'
                ? $this->request('goods/' . implode(',', (array) $keys), 'DELETE')
                : $this->restoreRequest($keys, Goods::class, $action);
            $this->assertForbidden(function () use ($request) { $this->guard($request); });
            if ($mutation === 'PUT') {
                $this->assertForbidden(function () use ($action, $keys, $request) {
                    (new $action())->setKey($keys)->handle($request);
                });
            }
            $this->assertSame($request, request());
            $this->assertSame(2, Goods::onlyTrashed()->whereIn('id', [3, 4])->count());
        }
    }

    public function test_batch_delete_still_requires_permission_for_every_mapped_id(): void
    {
        $user = $this->user('limited-deleter', [['DELETE', '/goods/3*']]);
        $request = $this->request('goods/3,4', 'DELETE');
        $parameters = $request->route()->parameters();
        $this->assertTrue(ResourcePermission::allows($user, $request));
        $this->assertForbidden(function () use ($request) { $this->guard($request); });
        $this->assertSame(2, Goods::onlyTrashed()->whereIn('id', [3, 4])->count());
        $this->assertSame($request, request());
        $this->assertSame($parameters, $request->route()->parameters());
    }

    public function test_unmatched_mapped_routes_keep_uri_grants_and_deny_named_grants(): void
    {
        $this->user('uri-updater', [['PUT', '/goods/3']]);
        $request = $this->restoreRequest(3);
        $routes = $this->app['router']->getRoutes();
        $this->app['router']->setRoutes(new \Illuminate\Routing\RouteCollection());
        try {
            $this->assertSame('next', $this->guard($request));
            $this->user('named-only', [['PUT', 'goods.update,dcat-api.action']]);
            $this->assertForbidden(function () use ($request) { $this->guard($request); });
            $this->assertSame($request, request());
            $this->assertNotNull(Goods::withTrashed()->findOrFail(3)->deleted_at);
        } finally {
            $this->app['router']->setRoutes($routes);
        }
    }

    public static function namedDispatchers(): array
    {
        return [
            'widget' => ['POST', 'dcat-api.form', 3],
            'restore' => ['PUT', 'dcat-api.action', 3],
            'batch-restore' => ['PUT', 'dcat-api.action', [3, 4]],
        ];
    }

    /** @dataProvider namedDispatchers */
    #[\PHPUnit\Framework\Attributes\DataProvider('namedDispatchers')]
    public function test_named_api_permission_cannot_substitute_mapped_resource($method, $routeName, $keys): void
    {
        $this->user('api-only', [['GET', '/import-carmis'], [$method, $routeName]]);
        $request = $method === 'POST'
            ? $this->request('dcat-api/form', 'POST', ['_form_' => $this->signedClass(ImportCarmis::class)])
            : $this->restoreRequest($keys, Goods::class, is_array($keys) ? BatchRestore::class : Restore::class);
        $this->assertForbidden(function () use ($request) { $this->guard($request); });
        $this->assertSame($request, request());
        $this->assertSame($request->route()->getName(), request()->route()->getName());
        $this->assertNotNull(Goods::withTrashed()->findOrFail(3)->deleted_at);
    }

    public function test_resource_guard_runs_before_dcat_early_permission_response(): void
    {
        $this->role('reader');
        $request = $this->request('carmis', 'GET');
        $middleware = $request->route()->gatherMiddleware();
        $guard = array_search(ResourcePermission::class, $middleware, true);
        $admin = array_search('admin', $middleware, true);
        $this->assertIsInt($guard);
        $this->assertIsInt($admin);
        $this->assertLessThan($admin, $guard);
        $this->assertForbidden(function () use ($request) { $this->guard($request); });
    }

    public function test_dcat_menu_fallback_does_not_grant_business_write_access(): void
    {
        $user = $this->role('reader');
        $menu = DB::table('admin_menu')->insertGetId(['title' => 'Goods', 'uri' => 'goods']);
        DB::table('admin_role_menu')->insert(['menu_id' => $menu, 'role_id' => $user->roles->first()->id]);
        $request = $this->request('goods/1', 'PUT');
        $called = false;
        $next = function () use (&$called) { $called = true; return 'next'; };
        if (\Composer\InstalledVersions::isInstalled('dcat-x/laravel-admin')) {
            $response = (new Permission())->handle($request, $next);
            $this->assertTrue($called);
            $this->assertSame('next', $response);
        } else {
            try {
                (new Permission())->handle($request, $next);
                $this->fail('Legacy Dcat should deny the PUT request.');
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $exception) {
                $this->assertSame(200, $exception->getResponse()->getStatusCode());
                $this->assertStringContainsString(__('admin.deny'), $exception->getResponse()->getContent());
            }
            $this->assertFalse($called);
        }
        $this->assertForbidden(function () use ($request) { $this->guard($request); });
        $this->assertSame(1, (int) DB::table('goods')->where('id', 1)->value('is_open'));
    }
}
