<?php

namespace Tests\Feature;

use App\Admin\Middleware\ResourcePermission;
use App\Admin\Repositories\Goods as GoodsRepository;
use App\Models\Goods;
use Dcat\Admin\Form;
use Dcat\Admin\Form\Builder;
use Dcat\Admin\Http\Middleware\Permission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\AdminData;
use Tests\TestCase;

class AdminDeletionTest extends TestCase
{
    private $disk;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'admin.database.connection' => 'sqlite',
            'admin.route.prefix' => 'admin', 'admin.permission.enable' => true,
        ]);
        DB::purge('sqlite');
        AdminData::create(false);
        $this->disk = 'deletion-test-' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        Storage::fake($this->disk);
    }

    private function login($path = null, $method = 'DELETE')
    {
        if ($path === null) {
            $id = 1;
        } else {
            $id = DB::table('admin_users')->insertGetId(['username' => 'deleter', 'name' => 'Deleter', 'password' => '']);
            $role = DB::table('admin_roles')->insertGetId(['slug' => 'deleter', 'name' => 'Deleter']);
            DB::table('admin_role_users')->insert(['role_id' => $role, 'user_id' => $id]);
            $permission = DB::table('admin_permissions')->insertGetId([
                'slug' => 'delete-test', 'name' => 'Delete', 'http_method' => $method, 'http_path' => $path,
            ]);
            DB::table('admin_role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        $class = config('admin.database.users_model');
        $this->actingAs($class::findOrFail($id), config('admin.auth.guard'));
    }

    private function request($key, $resource = 'goods')
    {
        $request = Request::create('/admin/' . $resource . '/' . $key, 'DELETE');
        $route = $this->app['router']->getRoutes()->match($request);
        $request->setRouteResolver(function () use ($route) { return $route; });
        $this->app->instance('request', $request);
        $this->assertContains(ResourcePermission::class, $route->gatherMiddleware());
        return $request;
    }

    private function form($repository = null)
    {
        return new Form($repository ?: new GoodsRepository(), function (Form $form) {
            if ($form->repository()->model() instanceof Goods) {
                $form->image('picture')->disk($this->disk);
            }
        });
    }

    private function destroy($key, ?Form $form = null): array
    {
        return ($form ?: $this->form())->destroy($key)->getData(true);
    }

    private function rows($table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(function ($row) { return (array) $row; })->all();
    }

    private function picture($id, $trashed = false)
    {
        $path = 'goods-' . $id . '.png';
        Storage::disk($this->disk)->put($path, 'image-test-bytes');
        DB::table('goods')->where('id', $id)->update([
            'picture' => $path, 'deleted_at' => $trashed ? '2026-01-01 00:00:00' : null,
        ]);
        return $path;
    }

    public function test_prefix_permission_does_not_authorize_other_ids_in_csv(): void
    {
        foreach ([3, 4] as $id) {
            $row = (array) DB::table('goods')->where('id', 2)->first();
            $row['id'] = $id;
            DB::table('goods')->insert($row);
        }
        $this->login('/goods/3*');
        $request = $this->request('3,4');
        $before = $this->rows('goods');
        $denied = false;
        try {
            (new ResourcePermission())->handle($request, function () { return $this->destroy('3,4'); });
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $denied = true;
        }
        $this->assertTrue($denied, 'Each DELETE ID must have its own permission.');
        $this->assertSame($before, $this->rows('goods'));
    }

    public function test_second_delete_exception_rolls_back_goods_and_card_listener(): void
    {
        $this->login();
        $this->request('1,2');
        $before = $this->rows('goods');
        $cards = $this->rows('carmis');
        Goods::deleting(function ($model) {
            if ((int) $model->id === 2) throw new \RuntimeException('second delete failed');
        });
        $response = $this->destroy('1,2');
        $this->assertFalse((bool) $response['status']);
        $this->assertSame($before, $this->rows('goods'));
        $this->assertSame($cards, $this->rows('carmis'));
    }

    public function test_false_model_delete_fails_and_rolls_back_the_batch(): void
    {
        $this->login();
        $this->request('1,2');
        $before = $this->rows('goods');
        Goods::deleting(function ($model) { return (int) $model->id === 2 ? false : null; });
        $response = $this->destroy('1,2');
        $this->assertFalse((bool) $response['status']);
        $this->assertSame($before, $this->rows('goods'));
    }

    public function test_failed_batch_preserves_permanent_delete_picture(): void
    {
        $this->login();
        $this->request('1,2');
        $path = $this->picture(1, true);
        $before = $this->rows('goods');
        $cards = $this->rows('carmis');
        Goods::deleting(function ($model) {
            if ((int) $model->id === 2) throw new \RuntimeException('second delete failed');
        });
        $response = $this->destroy('1,2', $this->form(new GoodsRepository(Goods::withTrashed())));
        $this->assertFalse((bool) $response['status']);
        $this->assertStringContainsString('second delete failed', json_encode($response));
        $this->assertTrue(Storage::disk($this->disk)->exists($path));
        $this->assertSame($before, $this->rows('goods'));
        $this->assertSame($cards, $this->rows('carmis'));
    }

    public function test_file_observer_sees_committed_permanent_delete(): void
    {
        $this->login();
        $this->request('1');
        $path = $this->picture(1, true);
        $observed = [];
        $form = $this->form(new GoodsRepository(Goods::withTrashed()));
        $form->fileDeleting(function () use (&$observed) {
            $observed[] = [DB::connection()->transactionLevel(), DB::table('goods')->where('id', 1)->exists()];
        });
        $response = $this->destroy('1', $form);
        $this->assertTrue((bool) $response['status'], json_encode($response));
        $this->assertSame([[0, false]], $observed);
        $this->assertFalse(Storage::disk($this->disk)->exists($path));
    }

    private function prepare($key, $repository = null): array
    {
        $form = $this->form($repository);
        $form->builder()->setResourceId($key);
        $form->builder()->mode(Builder::MODE_DELETE);
        return [$form, $form->repository()->deleting($form)];
    }

    private function cloneRow($table, $id, $trashed = false)
    {
        $row = (array) DB::table($table)->where('id', 1)->first();
        $row['id'] = $id;
        $row['deleted_at'] = $trashed ? '2026-01-01 00:00:00' : null;
        DB::table($table)->insert($row);
    }

    private function assertDenied(Request $request, $status = 403)
    {
        $called = false;
        try {
            (new ResourcePermission())->handle($request, function () use (&$called) { $called = true; });
            $this->fail('Delete request passed the guard.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
        $this->assertFalse($called);
        $this->assertSame($request, request());
    }

    public static function resources(): array
    {
        return [
            'goods' => ['goods', GoodsRepository::class, 'goods'],
            'groups' => ['goods-group', \App\Admin\Repositories\GoodsGroup::class, 'goods_group'],
            'cards' => ['carmis', \App\Admin\Repositories\Carmis::class, 'carmis'],
            'coupons' => ['coupon', \App\Admin\Repositories\Coupon::class, 'coupons'],
            'mail' => ['emailtpl', \App\Admin\Repositories\Emailtpl::class, 'emailtpls'],
            'payments' => ['pay', \App\Admin\Repositories\Pay::class, 'pays'],
            'orders' => ['order', \App\Admin\Repositories\Order::class, 'orders'],
        ];
    }

    /** @dataProvider resources */
    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function test_seven_default_repositories_delete_mixed_batches($resource, $repository, $table): void
    {
        $this->login('/' . $resource . '/*');
        $this->cloneRow($table, 3, true);
        $request = $this->request('3,1', $resource);
        $form = $this->form(new $repository());
        $before = $this->rows($table);
        $response = (new ResourcePermission())->handle($request, function ($request) use ($form) {
            return (new Permission())->handle($request, function () use ($form) {
                return $this->destroy('3,1', $form);
            });
        });
        $this->assertTrue((bool) $response['status'], json_encode($response));
        $this->assertFalse(DB::table($table)->where('id', 3)->exists());
        $this->assertNotNull(DB::table($table)->where('id', 1)->value('deleted_at'));
        $unchanged = array_values(array_filter($before, function ($row) { return ! in_array((int) $row['id'], [1, 3], true); }));
        $this->assertSame($unchanged, array_values(array_filter($this->rows($table), function ($row) {
            return ! in_array((int) $row['id'], [1, 3], true);
        })));
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    /** @dataProvider resources */
    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function test_seven_resources_check_every_prefix_granted_id($resource, $repository, $table): void
    {
        $this->cloneRow($table, 3);
        $this->cloneRow($table, 4, true);
        $this->login('/' . $resource . '/3*');
        $before = $this->rows($table);
        $this->assertDenied($this->request('3,4', $resource));
        $this->assertSame($before, $this->rows($table));
    }

    /** @dataProvider resources */
    #[\PHPUnit\Framework\Attributes\DataProvider('resources')]
    public function test_missing_id_keeps_each_resource_batch_unchanged($resource, $repository, $table): void
    {
        $this->login();
        $this->request('1,999999', $resource);
        $before = $this->rows($table);
        $this->assertFalse((bool) $this->destroy('1,999999', $this->form(new $repository()))['status']);
        $this->assertSame($before, $this->rows($table));
    }

    public function test_exact_individual_grants_do_not_create_a_new_batch_permission(): void
    {
        $this->login('/goods/1,/goods/2');
        $this->assertDenied($this->request('1,2'));
        $this->assertNull(DB::table('goods')->where('id', 1)->value('deleted_at'));
    }

    public function test_read_only_grant_does_not_allow_delete(): void
    {
        $this->login('/goods/*', 'GET');
        $this->assertDenied($this->request('1,2'));
        $this->assertNull(DB::table('goods')->where('id', 1)->value('deleted_at'));
    }

    public static function permissionModes(): array
    {
        return ['administrator' => [false], 'disabled' => [true]];
    }

    /** @dataProvider permissionModes */
    #[\PHPUnit\Framework\Attributes\DataProvider('permissionModes')]
    public function test_administrator_and_disabled_permissions_keep_valid_deletion($disabled): void
    {
        $this->login($disabled ? '/goods/*' : null, 'GET');
        config(['admin.permission.enable' => ! $disabled]);
        $request = $this->request('1,2');
        $response = (new ResourcePermission())->handle($request, function ($request) {
            return (new Permission())->handle($request, function () { return $this->destroy('1,2'); });
        });
        $this->assertTrue((bool) $response['status']);
        $this->assertSame(2, Goods::onlyTrashed()->count());
        $this->assertDenied($this->request('0'), 400);
    }

    public static function invalidKeys(): array
    {
        $cases = [];
        foreach (['', '0', '-1', '01', '1e0', '1.0', '+1', ' 1', '1 ', ',1', '1,', '1,,2', '1,0', '1,-2', '1,1', "1\n"] as $index => $key) {
            $cases['string-' . $index] = [$key];
        }
        return $cases + ['array' => [[1, 2]], 'nested' => [[[1]]], 'null' => [null], 'boolean' => [true], 'float' => [1.0]];
    }

    /** @dataProvider invalidKeys */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeys')]
    public function test_invalid_or_duplicate_repository_keys_never_mutate($key): void
    {
        $this->login();
        $this->request('1');
        $before = $this->rows('goods');
        $cards = $this->rows('carmis');
        $form = $this->form();
        $form->builder()->setResourceId($key);
        $form->builder()->mode(Builder::MODE_DELETE);
        try {
            $form->repository()->delete($form, []);
            $this->fail('Repository accepted malformed delete IDs.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Delete IDs', $exception->getMessage());
        }
        $this->assertFalse((bool) $this->destroy($key)['status']);
        $this->assertSame($before, $this->rows('goods'));
        $this->assertSame($cards, $this->rows('carmis'));
        $this->assertSame(0, DB::connection()->transactionLevel());
    }

    public function test_invalid_csv_is_rejected_before_next_even_when_permissions_disabled(): void
    {
        $this->login();
        config(['admin.permission.enable' => false]);
        foreach (['0', '-1', '1e0', '1,', ',1', '1,,2', '1,0', '1,1'] as $key) {
            $this->assertDenied($this->request($key), 400);
        }
        $this->assertSame(2, Goods::count());
    }

    public function test_row_missing_after_parent_preload_fails_before_any_mutation(): void
    {
        $this->login();
        $this->request('1,2');
        [$form, $data] = $this->prepare('1,2');
        DB::table('goods')->where('id', 2)->delete();
        $before = $this->rows('goods');
        $cards = $this->rows('carmis');
        try {
            $form->repository()->delete($form, $data);
            $this->fail('Locked re-read did not detect the missing ID.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(Goods::class, $exception->getModel());
        }
        $this->assertSame($before, $this->rows('goods'));
        $this->assertSame($cards, $this->rows('carmis'));
    }

    public function test_locked_snapshot_uses_latest_state_and_pre_model_hook_picture(): void
    {
        $this->login();
        $this->request('1,2');
        $live = $this->picture(1);
        $old = $this->picture(2, true);
        foreach (['locked.png', 'event-mutated.png'] as $path) Storage::disk($this->disk)->put($path, 'image');
        $form = $this->form(new GoodsRepository(['group', 'carmis']));
        $form->deleting(function () {
            DB::table('goods')->where('id', 2)->update(['picture' => 'locked.png']);
        });
        Goods::deleting(function ($model) {
            if ((int) $model->id === 2) $model->picture = 'event-mutated.png';
            $this->assertTrue($model->relationLoaded('group'));
            $this->assertTrue($model->relationLoaded('carmis'));
        });
        $observed = [];
        $form->fileDeleting(function ($form, $field) use (&$observed) {
            $observed[] = [$field->original(), DB::connection()->transactionLevel(), DB::table('goods')->where('id', 2)->exists()];
        });
        $response = $this->destroy('1,2', $form);
        $this->assertTrue((bool) $response['status'], json_encode($response));
        $this->assertSame([[['locked.png'], 0, false]], $observed);
        $this->assertFalse(Storage::disk($this->disk)->exists('locked.png'));
        foreach ([$live, $old, 'event-mutated.png'] as $path) $this->assertTrue(Storage::disk($this->disk)->exists($path));
        $this->assertNotNull(DB::table('goods')->where('id', 1)->value('deleted_at'));
        $this->assertNotNull(DB::table('carmis')->where('id', 1)->value('deleted_at'));
    }

    public function test_false_force_delete_throws_and_rolls_back_soft_delete_and_cards(): void
    {
        $this->login();
        $this->request('1,2');
        $live = $this->picture(1);
        $trash = $this->picture(2, true);
        [$form, $data] = $this->prepare('1,2');
        $before = $this->rows('goods');
        $cards = $this->rows('carmis');
        Goods::deleting(function ($model) { return (int) $model->id === 2 ? false : null; });
        try {
            $form->repository()->delete($form, $data);
            $this->fail('A cancelled forceDelete must throw.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cancelled for ID 2', $exception->getMessage());
        }
        $this->assertSame($before, $this->rows('goods'));
        $this->assertSame($cards, $this->rows('carmis'));
        foreach ([$live, $trash] as $path) $this->assertTrue(Storage::disk($this->disk)->exists($path));
    }

    public function test_cleanup_exception_reports_error_after_commit_and_keeps_remaining_files(): void
    {
        $this->login();
        $this->request('1,2');
        $paths = [$this->picture(1, true), $this->picture(2, true)];
        $form = $this->form();
        $form->fileDeleting(function () {
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertSame(0, Goods::withTrashed()->count());
            throw new \RuntimeException('cleanup failed after commit');
        });
        $response = $this->destroy('1,2', $form);
        $this->assertFalse((bool) $response['status']);
        $this->assertStringContainsString('cleanup failed after commit', json_encode($response));
        $this->assertSame(0, Goods::withTrashed()->count());
        $this->assertNotNull(DB::table('carmis')->where('id', 1)->value('deleted_at'));
        foreach ($paths as $path) $this->assertTrue(Storage::disk($this->disk)->exists($path));
    }

    public function test_outer_soft_delete_follows_outer_rollback_without_file_cleanup(): void
    {
        $this->login();
        $this->request('1,2');
        $path = $this->picture(1);
        $before = $this->rows('goods');
        $cards = $this->rows('carmis');
        $form = $this->form();
        $form->fileDeleting(function () { $this->fail('Soft deletes must retain files.'); });
        Goods::deleting(function ($model) { $this->assertSame(2, $model->getConnection()->transactionLevel()); });
        DB::beginTransaction();
        try {
            $this->assertTrue((bool) $this->destroy('1,2', $form)['status']);
            $this->assertSame(1, DB::connection()->transactionLevel());
            $this->assertSame(2, Goods::onlyTrashed()->count());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->rows('goods'));
        $this->assertSame($cards, $this->rows('carmis'));
        $this->assertTrue(Storage::disk($this->disk)->exists($path));
    }

    public function test_outer_transaction_rejects_mixed_permanent_batch_before_sql_changes(): void
    {
        $this->login();
        $this->request('1,2');
        $path = $this->picture(2, true);
        [$form, $data] = $this->prepare('1,2');
        $before = $this->rows('goods');
        $cards = $this->rows('carmis');
        $mutations = [];
        DB::listen(function ($query) use (&$mutations) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) $mutations[] = $query->sql;
        });
        DB::beginTransaction();
        try {
            try {
                $form->repository()->delete($form, $data);
                $this->fail('Permanent batch was accepted inside an outer transaction.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('requires its own database transaction', $exception->getMessage());
            }
            $this->assertSame(1, DB::connection()->transactionLevel());
            $this->assertSame([], $mutations);
            $this->assertSame($before, $this->rows('goods'));
            $this->assertSame($cards, $this->rows('carmis'));
        } finally {
            DB::rollBack();
        }
        $this->assertTrue(Storage::disk($this->disk)->exists($path));
    }

    public function test_group_deletion_preserves_existing_query_cascade_semantics(): void
    {
        $this->login();
        $this->request('1', 'goods-group');
        $path = $this->picture(1);
        $cards = $this->rows('carmis');
        $form = $this->form(new \App\Admin\Repositories\GoodsGroup(['goods']));
        $this->assertTrue((bool) $this->destroy('1', $form)['status']);
        $goods = $this->rows('goods');
        $this->assertSame(2, Goods::onlyTrashed()->count());
        $this->assertSame($cards, $this->rows('carmis'));
        $this->assertTrue((bool) $this->destroy('1', $this->form(new \App\Admin\Repositories\GoodsGroup()))['status']);
        $this->assertFalse(DB::table('goods_group')->where('id', 1)->exists());
        $this->assertSame($goods, $this->rows('goods'));
        $this->assertSame($cards, $this->rows('carmis'));
        $this->assertTrue(Storage::disk($this->disk)->exists($path));
    }

    public function test_model_connection_controls_batch_transaction_instead_of_default_connection(): void
    {
        $this->login();
        $this->request('1,2', 'coupon');
        config(['database.connections.deletion-model' => config('database.connections.sqlite')]);
        DB::purge('deletion-model');
        DB::setDefaultConnection('deletion-model');
        try {
            AdminData::create(false);
            $this->cloneRow('coupons', 2);
            $before = $this->rows('coupons');
        } finally {
            DB::setDefaultConnection('sqlite');
        }
        $default = $this->rows('coupons');
        $form = $this->form(new \App\Admin\Repositories\Coupon(\App\Models\Coupon::on('deletion-model')));
        \App\Models\Coupon::deleting(function ($model) {
            $this->assertSame(1, $model->getConnection()->transactionLevel());
            $this->assertSame(0, DB::connection()->transactionLevel());
            if ((int) $model->id === 2) throw new \RuntimeException('named connection failure');
        });
        $response = $this->destroy('1,2', $form);
        $this->assertFalse((bool) $response['status']);
        $this->assertStringContainsString('named connection failure', json_encode($response));
        $actual = DB::connection('deletion-model')->table('coupons')->orderBy('id')->get()
            ->map(function ($row) { return (array) $row; })->all();
        $this->assertSame($before, $actual);
        $this->assertSame($default, $this->rows('coupons'));
        $this->assertSame(0, DB::connection('deletion-model')->transactionLevel());
    }

    public static function preloadPhases(): array
    {
        return ['live-became-trash' => [false], 'trash-became-live' => [true]];
    }

    /** @dataProvider preloadPhases */
    #[\PHPUnit\Framework\Attributes\DataProvider('preloadPhases')]
    public function test_preloaded_phase_change_aborts_whole_batch_before_any_delete($trashed): void
    {
        $this->login();
        $this->request('1,2');
        $paths = [$this->picture(1), $this->picture(2, $trashed)];
        [$form, $data] = $this->prepare('1,2');
        DB::table('goods')->where('id', 2)->update(['deleted_at' => $trashed ? null : '2026-01-01 00:00:00']);
        $before = $this->rows('goods');
        $cards = $this->rows('carmis');
        $failed = false;
        try {
            $form->repository()->delete($form, $data);
        } catch (\RuntimeException $exception) {
            $failed = true;
            $this->assertStringContainsString('changed', $exception->getMessage());
        }
        $this->assertTrue($failed, 'A stale soft/permanent deletion intent must not change phase.');
        $this->assertSame($before, $this->rows('goods'));
        $this->assertSame($cards, $this->rows('carmis'));
        foreach ($paths as $path) $this->assertTrue(Storage::disk($this->disk)->exists($path));
    }

    public static function cascadeModels(): array
    {
        return ['goods' => [Goods::class, GoodsRepository::class, 'goods'],
            'groups' => [\App\Models\GoodsGroup::class, \App\Admin\Repositories\GoodsGroup::class, 'goods_group']];
    }

    /** @dataProvider cascadeModels */
    #[\PHPUnit\Framework\Attributes\DataProvider('cascadeModels')]
    public function test_named_cascade_rollback_never_mutates_default_database($model, $repository, $table): void
    {
        $this->login();
        $this->request('1,2');
        $default = [$this->rows('goods'), $this->rows('goods_group'), $this->rows('carmis')];
        config(['database.connections.deletion-cascade' => config('database.connections.sqlite')]);
        DB::purge('deletion-cascade');
        DB::setDefaultConnection('deletion-cascade');
        try {
            AdminData::create(false);
            if ($table === 'goods_group') $this->cloneRow($table, 2);
            $before = [$this->rows('goods'), $this->rows('goods_group'), $this->rows('carmis')];
        } finally {
            DB::setDefaultConnection('sqlite');
        }
        $model::deleting(function ($row) {
            if ((int) $row->id === 2) throw new \RuntimeException('cascade rollback');
        });
        $response = $this->destroy('1,2', $this->form(new $repository($model::on('deletion-cascade'))));
        $this->assertFalse((bool) $response['status']);
        $this->assertSame($default, [$this->rows('goods'), $this->rows('goods_group'), $this->rows('carmis')]);
        DB::setDefaultConnection('deletion-cascade');
        try { $this->assertSame($before, [$this->rows('goods'), $this->rows('goods_group'), $this->rows('carmis')]); }
        finally { DB::setDefaultConnection('sqlite'); }
    }

    public static function connectionDrifts(): array
    {
        return ['builder-model' => [true, false], 'plain-model' => [false, false], 'default-connection' => [false, true]];
    }

    /** @dataProvider connectionDrifts */
    #[\PHPUnit\Framework\Attributes\DataProvider('connectionDrifts')]
    public function test_form_hook_connection_drift_never_deletes_another_database($builder, $changeDefault): void
    {
        $this->login();
        $this->request('1');
        $path = $this->picture(1, true);
        $before = $this->rows('goods');
        config(['database.connections.deletion-drift' => config('database.connections.sqlite')]);
        DB::purge('deletion-drift');
        DB::setDefaultConnection('deletion-drift');
        try {
            AdminData::create(false);
            DB::table('goods')->where('id', 1)->update(['picture' => 'other-database.png', 'deleted_at' => $builder ? null : '2026-01-01 00:00:00']);
            Storage::disk($this->disk)->put('other-database.png', 'another image');
            $other = $this->rows('goods');
        } finally {
            DB::setDefaultConnection('sqlite');
        }
        $form = $this->form($builder ? new GoodsRepository(Goods::on('sqlite')->withTrashed()) : new GoodsRepository());
        $form->deleting(function ($form) use ($changeDefault) {
            if ($changeDefault) DB::setDefaultConnection('deletion-drift');
            else $form->repository()->model()->setConnection('deletion-drift');
        });
        try { $response = $this->destroy('1', $form); }
        finally { DB::setDefaultConnection('sqlite'); }
        $this->assertFalse((bool) $response['status'], 'Connection drift must fail before deletion.');
        $this->assertStringContainsString('connection', json_encode($response));
        $this->assertSame($before, $this->rows('goods'));
        $actual = DB::connection('deletion-drift')->table('goods')->orderBy('id')->get()->map(function ($row) { return (array) $row; })->all();
        $this->assertSame($other, $actual);
        $this->assertTrue(Storage::disk($this->disk)->exists($path));
        $this->assertTrue(Storage::disk($this->disk)->exists('other-database.png'));
    }

    public function test_lock_order_overrides_input_and_preconfigured_query_order(): void
    {
        $this->login();
        $this->request('2,1');
        $order = [];
        Goods::deleting(function ($model) use (&$order) { $order[] = (int) $model->id; });
        $form = $this->form(new GoodsRepository(Goods::orderByRaw('case when id = ? then 0 else 1 end', [2])));
        $response = $this->destroy('2,1', $form);
        $this->assertTrue((bool) $response['status'], json_encode($response));
        $this->assertSame([1, 2], $order);
    }

    public function test_second_database_delete_failure_rolls_back_rows_cards_and_keeps_pictures(): void
    {
        $this->login();
        $this->request('1,2');
        $paths = [$this->picture(1, true), $this->picture(2, true)];
        $before = $this->rows('goods');
        $cards = $this->rows('carmis');
        DB::unprepared("CREATE TRIGGER fail_second_goods_delete BEFORE DELETE ON goods WHEN OLD.id = 2 BEGIN SELECT RAISE(ABORT, 'database delete failure'); END");
        $response = $this->destroy('1,2');
        $this->assertFalse((bool) $response['status']);
        $this->assertStringContainsString('database delete failure', json_encode($response));
        $this->assertSame($before, $this->rows('goods'));
        $this->assertSame($cards, $this->rows('carmis'));
        foreach ($paths as $path) $this->assertTrue(Storage::disk($this->disk)->exists($path));
        $this->assertSame(0, DB::connection()->transactionLevel());
    }
}


