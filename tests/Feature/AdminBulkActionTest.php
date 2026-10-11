<?php

namespace Tests\Feature;

use App\Admin\Actions\BatchSetStatus;
use App\Admin\Actions\CloneResource;
use App\Admin\Middleware\ResourcePermission;
use App\Admin\Support\PaymentFormCatalog;
use App\Models\Goods;
use App\Models\Pay;
use Dcat\Admin\Admin;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\AdminData;
use Tests\TestCase;

class AdminBulkActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'admin.database.connection' => 'sqlite',
            'admin.route.prefix' => 'admin', 'admin.route.domain' => null, 'admin.permission.enable' => true]);
        DB::purge('sqlite');
        AdminData::create(true);
        $class = config('admin.database.users_model');
        $this->actingAs($class::findOrFail(1), config('admin.auth.guard'));
    }

    private function user(array $permissions)
    {
        $id = DB::table('admin_users')->insertGetId(['username' => 'bulk-user-' . uniqid(), 'name' => 'Bulk user', 'password' => '']);
        $role = DB::table('admin_roles')->insertGetId(['slug' => 'bulk-role-' . $id, 'name' => 'Bulk role']);
        DB::table('admin_role_users')->insert(['role_id' => $role, 'user_id' => $id]);
        foreach ($permissions as $i => $permission) {
            $grant = DB::table('admin_permissions')->insertGetId(['slug' => 'bulk-' . $id . '-' . $i, 'name' => 'Bulk grant',
                'http_method' => $permission[0], 'http_path' => $permission[1]]);
            DB::table('admin_role_permissions')->insert(['role_id' => $role, 'permission_id' => $grant]);
        }
        $class = config('admin.database.users_model');
        $user = $class::findOrFail($id);
        $this->actingAs($user, config('admin.auth.guard'));
        return $user;
    }

    private function action($class, $model, $keys, array $extra = [])
    {
        return $this->post('/admin/dcat-api/action', array_merge(['_action' => (new $class())->makeCalledClass(),
            '_key' => $keys, 'model' => $model], $extra), ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
    }

    private function batch($model, $keys, $state = 0, array $extra = [])
    {
        return $this->action(BatchSetStatus::class, $model, $keys, array_merge(['field' => 'is_open', 'state' => $state], $extra));
    }

    private function putPay($id, array $input)
    {
        return $this->put('/admin/pay/' . $id, $input, ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
    }

    private function snapshot($table): array
    {
        return array_map(function ($row) { return (array) $row; }, DB::table($table)->orderBy('id')->get()->all());
    }

    public function test_http_batch_actions_toggle_only_the_selected_rows_of_all_four_resources(): void
    {
        foreach ([Goods::class => 'goods', Pay::class => 'pays', \App\Models\GoodsGroup::class => 'goods_group',
            \App\Models\Coupon::class => 'coupons'] as $model => $table) {
            $source = (array) DB::table($table)->where('id', 1)->first();
            unset($source['id']);
            if ($table === 'pays') $source['pay_check'] = 'fixture-second';
            $second = DB::table($table)->insertGetId($source);
            $third = $source;
            if ($table === 'pays') $third['pay_check'] = 'fixture-third';
            $untouched = DB::table($table)->insertGetId($third);
            $this->batch($model, ['1', (string) $second])->assertStatus(200)->assertJson(['status' => true]);
            $this->assertSame(0, (int) DB::table($table)->where('id', 1)->value('is_open'));
            $this->assertSame(0, (int) DB::table($table)->where('id', $second)->value('is_open'));
            $this->assertSame((int) $third['is_open'], (int) DB::table($table)->where('id', $untouched)->value('is_open'));
            $this->batch($model, [1, $second], 1)->assertStatus(200)->assertJson(['status' => true]);
            $this->assertSame(2, DB::table($table)->whereIn('id', [1, $second])->where('is_open', 1)->count());
        }
    }

    public function test_bad_selection_keys_are_rejected_without_any_write(): void
    {
        $before = $this->snapshot('goods');
        foreach ([[], '1,2', [0], [-1], [true], [1.5], ['01'], ['1e0'], [['1']], [1, '1'],
            ['9223372036854775808'], range(1, 101)] as $keys) {
            $this->batch(Goods::class, $keys)->assertStatus(422);
            $this->assertSame($before, $this->snapshot('goods'));
        }
    }

    public function test_wrong_models_fields_and_target_states_never_write(): void
    {
        $before = $this->snapshot('goods');
        foreach ([['field' => 'sales_volume'], ['field' => ['is_open']], ['state' => 2], ['state' => true],
            ['state' => 'on'], ['state' => null], ['model' => \App\Models\Carmis::class],
            ['model' => \App\Models\Order::class], ['model' => 'UnknownBulkModel'], ['model' => ['Goods']]] as $extra) {
            $this->batch(Goods::class, [1, 2], 0, $extra)->assertStatus(422);
            $this->assertSame($before, $this->snapshot('goods'));
        }
    }

    public function test_missing_and_soft_deleted_ids_abort_the_entire_batch(): void
    {
        $before = $this->snapshot('goods');
        foreach ([[1, 99999], [1, 3]] as $keys) {
            $this->batch(Goods::class, $keys)->assertStatus(404);
            $this->assertSame($before, $this->snapshot('goods'));
        }
    }

    public function test_failed_later_model_save_rolls_back_an_earlier_update(): void
    {
        $before = $this->snapshot('goods');
        Goods::saving(function ($goods) { if ((int) $goods->id === 2) throw new \RuntimeException('Bulk failure'); });
        try {
            $this->batch(Goods::class, [1, 2])->assertStatus(500);
        } finally {
            Goods::flushEventListeners();
        }
        $this->assertSame($before, $this->snapshot('goods'));
    }

    public function test_one_invalid_payment_account_keeps_every_selected_channel_disabled(): void
    {
        DB::table('pays')->where('id', 1)->update(['pay_handleroute' => 'pay/payjs', 'pay_check' => 'payjswescan',
            'merchant_id' => 'SYNTHETIC-ACCOUNT', 'merchant_pem' => 'SYNTHETIC-KEY', 'is_open' => 0]);
        $row = (array) DB::table('pays')->where('id', 1)->first();
        $row['id'] = 2; $row['pay_handleroute'] = 'pay/wepay'; $row['pay_check'] = 'wescan'; $row['merchant_key'] = '';
        DB::table('pays')->insert($row);
        $before = $this->snapshot('pays');
        $this->batch(Pay::class, [1, 2], 1)->assertStatus(200)->assertJson(['status' => false]);
        $this->assertSame($before, $this->snapshot('pays'));
    }

    public function test_read_only_users_see_no_mutation_buttons_and_signed_requests_return_403(): void
    {
        $this->user([['GET', '/goods,/goods/*,/pay,/pay/*,/goods-group,/goods-group/*,/coupon,/coupon/*']]);
        foreach (['goods' => Goods::class, 'pay' => Pay::class, 'goods-group' => \App\Models\GoodsGroup::class,
            'coupon' => \App\Models\Coupon::class] as $resource => $model) {
            $page = $this->get('/admin/' . $resource)->assertStatus(200)->getContent();
            $this->assertStringNotContainsString('admin-batch-status-', $page);
            $this->assertStringNotContainsString('admin-clone-resource', $page);
            $this->batch($model, [1])->assertStatus(403);
        }
        foreach ([Goods::class, Pay::class] as $model) $this->action(CloneResource::class, $model, 1)->assertStatus(403);
    }

    public function test_per_id_put_permissions_and_named_update_grants_use_original_resource_routes(): void
    {
        $this->user([['GET', '/goods,/goods/*'], ['PUT', '/goods/1']]);
        $this->batch(Goods::class, [1, 2])->assertStatus(403);
        $this->assertSame(1, (int) DB::table('goods')->where('id', 1)->value('is_open'));
        $this->batch(Goods::class, [1])->assertStatus(200)->assertJson(['status' => true]);
        $this->user([['GET', '/goods,/goods/*'], ['PUT', 'goods.update']]);
        $this->batch(Goods::class, [1, 2], 1)->assertStatus(200)->assertJson(['status' => true]);
        $this->assertTrue((new BatchSetStatus(Goods::class, 0, 'Disable'))->allowed());
    }

    public function test_dispatcher_only_grants_and_wrong_methods_do_not_grant_resource_mutations(): void
    {
        foreach ([[['POST,PUT', 'dcat-api.action']], [['PATCH', 'goods.update']], [['PUT', 'pay.update']]] as $permissions) {
            $this->user($permissions);
            $this->batch(Goods::class, [1, 2])->assertStatus(403);
            $this->action(CloneResource::class, Goods::class, 1)->assertStatus(403);
        }
        $this->assertSame(1, (int) DB::table('goods')->where('id', 1)->value('is_open'));
    }

    public function test_clone_requires_original_get_and_target_post_permissions_together(): void
    {
        foreach ([[['GET', '/goods/1']], [['POST', '/goods']], [['GET', '/goods/2'], ['POST', '/goods']]] as $permissions) {
            $this->user($permissions);
            $this->action(CloneResource::class, Goods::class, 1)->assertStatus(403);
        }
        $this->user([['GET', 'goods.show'], ['POST', 'goods.store']]);
        $this->action(CloneResource::class, Goods::class, 1)->assertStatus(200)->assertJson(['status' => true]);
        $this->assertSame(5, DB::table('goods')->count());
    }

    public function test_goods_clone_copies_configuration_but_resets_stock_sales_and_all_relations(): void
    {
        DB::table('goods')->where('id', 1)->update(['picture' => 'images/fixture.jpg', 'sales_volume' => 77,
            'wholesale_price_cnf' => '5=8', 'other_ipu_cnf' => 'account=Account=true', 'buy_prompt' => 'Fixture prompt']);
        $relations = [];
        foreach (['carmis', 'orders', 'coupons', 'coupons_goods'] as $table) $relations[$table] = $this->snapshot($table);
        foreach ([1, 2] as $key) {
            $source = (array) DB::table('goods')->where('id', $key)->first();
            $response = $this->action(CloneResource::class, Goods::class, $key)->assertStatus(200)->assertJson(['status' => true]);
            $id = DB::table('goods')->max('id');
            $copy = (array) DB::table('goods')->where('id', $id)->first();
            foreach (['id', 'gd_name', 'is_open', 'in_stock', 'sales_volume', 'created_at', 'updated_at', 'deleted_at'] as $field) unset($source[$field]);
            foreach ($source as $field => $value) $this->assertEquals($value, $copy[$field], $field);
            foreach (['is_open', 'in_stock', 'sales_volume'] as $field) $this->assertSame(0, (int) $copy[$field]);
            $this->assertSame(admin_url('goods/' . $id . '/edit'), $response->json('data.then.value'));
            $this->assertSame(0, Goods::findOrFail($id)->coupon()->count());
            $this->assertSame(0, Goods::findOrFail($id)->carmis()->count());
        }
        foreach ($relations as $table => $before) $this->assertSame($before, $this->snapshot($table));
    }

    public function test_payment_clones_keep_provider_accounts_use_unique_drafts_and_show_conflicts(): void
    {
        DB::table('pays')->where('id', 1)->update(['pay_handleroute' => 'pay/payjs', 'pay_check' => 'payjswescan',
            'merchant_id' => 'SYNTHETIC-ACCOUNT', 'merchant_pem' => 'SYNTHETIC-KEY']);
        $source = (array) DB::table('pays')->where('id', 1)->first();
        $checks = [];
        for ($i = 0; $i < 2; $i++) {
            $response = $this->action(CloneResource::class, Pay::class, 1)->assertStatus(200)->assertJson(['status' => true]);
            $id = DB::table('pays')->max('id');
            $copy = (array) DB::table('pays')->where('id', $id)->first();
            $this->assertSame(0, (int) $copy['is_open']);
            $this->assertStringStartsWith('draft-', $copy['pay_check']);
            $this->assertLessThanOrEqual(50, strlen($copy['pay_check']));
            $checks[] = $copy['pay_check'];
            foreach (['pay_handleroute', 'merchant_id', 'merchant_key', 'merchant_pem', 'pay_client', 'pay_method'] as $field) $this->assertSame($source[$field], $copy[$field]);
            $this->assertSame(admin_url('pay/' . $id . '/edit'), $response->json('data.then.value'));
            $page = $this->get('/admin/pay/' . $id . '/edit')->assertStatus(200)->getContent();
            $this->assertStringContainsString('待选实际付款方式', $page);
            $copy['pay_check'] = 'payjswescan';
            $this->putPay($id, $copy)->assertStatus(200)->assertJson(['status' => false]);
            $this->assertStringContainsString('占用', PaymentFormCatalog::error(['pay_check' => 'payjswescan'], (array) DB::table('pays')->where('id', $id)->first()));
        }
        $this->assertCount(2, array_unique($checks));
        $this->assertSame($source, (array) DB::table('pays')->where('id', 1)->first());
    }

    public function test_draft_activation_is_blocked_on_batch_native_put_inline_and_full_form_paths(): void
    {
        foreach (['pay/payjs', 'pay/custom-adapter'] as $route) {
            DB::table('pays')->where('id', 1)->update(['pay_handleroute' => $route, 'pay_check' => 'draft-synthetic', 'is_open' => 0,
                'merchant_id' => 'SYNTHETIC-ACCOUNT', 'merchant_pem' => 'SYNTHETIC-KEY']);
            $before = (array) DB::table('pays')->where('id', 1)->first();
            $this->batch(Pay::class, [1], 1)->assertStatus(200)->assertJson(['status' => false]);
            $this->putPay(1, ['is_open' => 1])->assertStatus(200)->assertJson(['status' => false]);
            $this->putPay(1, ['_editable' => 1, 'name' => 'is_open', 'value' => 1, 'pk' => 1])->assertStatus(200)->assertJson(['status' => false]);
            $this->putPay(1, array_merge($before, ['is_open' => 1]))->assertStatus(200)->assertJson(['status' => false]);
            $this->assertSame($before, (array) DB::table('pays')->where('id', 1)->first());
            $this->putPay(1, array_merge($before, ['pay_name' => 'Edited draft']))->assertStatus(200)->assertJson(['status' => true]);
            $this->assertSame(0, (int) DB::table('pays')->where('id', 1)->value('is_open'));
        }
    }

    public function test_draft_can_select_an_available_real_fixed_method_and_then_enable(): void
    {
        DB::table('pays')->where('id', 1)->update(['pay_handleroute' => 'pay/alipay', 'pay_check' => 'aliweb', 'is_open' => 1,
            'merchant_id' => 'SYNTHETIC-APP', 'merchant_key' => 'SYNTHETIC-PUBLIC', 'merchant_pem' => 'SYNTHETIC-PRIVATE']);
        $this->action(CloneResource::class, Pay::class, 1)->assertJson(['status' => true]);
        $id = DB::table('pays')->max('id');
        $copy = (array) DB::table('pays')->where('id', $id)->first();
        $this->putPay($id, array_merge($copy, ['pay_check' => 'aliwap', 'is_open' => 1]))->assertStatus(200)->assertJson(['status' => true]);
        $this->assertSame('aliwap', DB::table('pays')->where('id', $id)->value('pay_check'));
        $this->assertSame(1, (int) DB::table('pays')->where('id', $id)->value('is_open'));
    }

    public function test_callback_urls_use_the_new_id_without_fabricating_paypal_webhook_ids(): void
    {
        config(['dujiaoka.paypal_orders_v2' => true, 'dujiaoka.paypal_reconciliation' => true,
            'dujiaoka.paypal_webhook_ids' => [1 => 'SYNTHETICORIGINALID']]);
        DB::table('pays')->where('id', 1)->update(['pay_handleroute' => 'pay/paypal', 'pay_check' => 'paypal']);
        $this->action(CloneResource::class, Pay::class, 1)->assertJson(['status' => true]);
        $id = DB::table('pays')->max('id');
        $catalog = PaymentFormCatalog::all($id);
        $this->assertSame(url('pay/paypal/webhook/' . $id), $catalog['pay/paypal']['notify']);
        $this->assertSame(url('pay/stripe/webhook/' . $id), $catalog['pay/stripe']['notify']);
        $this->assertSame([1 => 'SYNTHETICORIGINALID'], config('dujiaoka.paypal_webhook_ids'));
        $this->assertStringContainsString('原渠道的 Webhook ID 不自动复用', $catalog['pay/paypal']['note']);
    }

    public function test_legacy_custom_checks_still_edit_and_native_put_enables_valid_existing_accounts(): void
    {
        DB::table('pays')->where('id', 1)->update(['pay_handleroute' => 'pay/payjs', 'pay_check' => 'historical-custom', 'is_open' => 0,
            'merchant_id' => 'SYNTHETIC-ACCOUNT', 'merchant_pem' => 'SYNTHETIC-KEY']);
        $this->putPay(1, ['_editable' => 1, 'name' => 'is_open', 'value' => 1, 'pk' => 1])->assertStatus(200)->assertJson(['status' => true]);
        $this->assertSame('historical-custom', DB::table('pays')->where('id', 1)->value('pay_check'));
        $this->assertSame(1, (int) DB::table('pays')->where('id', 1)->value('is_open'));
    }

    public function test_live_grids_include_native_selection_and_restore_delete_tools_remain_available(): void
    {
        foreach (['goods', 'pay', 'goods-group', 'coupon'] as $resource) {
            $page = $this->get('/admin/' . $resource)->assertStatus(200)->getContent();
            $this->assertStringContainsString('admin-batch-status-' . $resource . '-1', $page);
            $this->assertStringContainsString('admin-batch-status-' . $resource . '-0', $page);
            $this->assertStringContainsString('select-all', $page);
            if (in_array($resource, ['goods', 'pay'], true)) $this->assertStringContainsString('admin-clone-resource', $page);
        }
        $trashed = $this->get('/admin/goods?_scope_=' . urlencode(admin_trans('dujiaoka.trashed')))->assertStatus(200)->getContent();
        $this->assertStringContainsString('BatchRestore', $trashed);
        $this->assertStringNotContainsString('admin-batch-status-', $trashed);
        $this->assertStringNotContainsString('admin-clone-resource', $trashed);
    }

    public function test_missing_deleted_and_disallowed_clone_sources_do_not_create_rows(): void
    {
        $count = DB::table('goods')->count();
        foreach ([99999, 3] as $id) $this->action(CloneResource::class, Goods::class, $id)->assertStatus(404);
        $this->action(CloneResource::class, \App\Models\Coupon::class, 1)->assertStatus(422);
        $this->action(CloneResource::class, Goods::class, [1, 2])->assertStatus(422);
        $this->assertSame($count, DB::table('goods')->count());
    }

    public function test_invalid_vendor_signature_never_mutates(): void
    {
        $signed = (new BatchSetStatus())->makeCalledClass();
        $response = $this->post('/admin/dcat-api/action', ['_action' => $signed . '-tampered', '_key' => [1, 2],
            'model' => Goods::class, 'field' => 'is_open', 'state' => 0], ['Accept' => 'application/json']);
        $response->assertStatus(500);
        $this->assertSame(2, DB::table('goods')->whereIn('id', [1, 2])->where('is_open', 1)->count());
    }
}
