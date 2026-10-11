<?php

namespace Tests\Feature;

use App\Admin\Support\PaymentFormCatalog;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\AdminData;
use Tests\TestCase;

class AdminGuidedFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'admin.database.connection' => 'sqlite',
            'admin.route.prefix' => 'admin', 'admin.route.domain' => null, 'admin.permission.enable' => true]);
        DB::purge('sqlite');
        AdminData::create(false);
        $class = config('admin.database.users_model');
        $this->actingAs($class::findOrFail(1), config('admin.auth.guard'));
    }

    private function pay(array $changes = []): array
    {
        return array_merge(['pay_name' => 'Guided Payjs', 'pay_handleroute' => 'pay/payjs', 'pay_check' => 'payjswescan',
            'pay_client' => 3, 'pay_method' => 2, 'merchant_id' => '100001', 'merchant_key' => '',
            'merchant_pem' => 'SYNTHETIC-SIGNING-KEY', 'is_open' => 1], $changes);
    }

    private function goods(array $changes = []): array
    {
        return array_merge(['gd_name' => 'Guided Manual Goods', 'group_id' => 1, 'type' => 2,
            'retail_price' => '12.00', 'actual_price' => '10.00', 'in_stock' => 10, 'sales_volume' => 0,
            'buy_limit_num' => 0, 'ord' => 1, 'is_open' => 1, 'gd_description' => '', 'gd_keywords' => '',
            'other_ipu_cnf' => '', 'wholesale_price_cnf' => '', 'api_hook' => ''], $changes);
    }

    private function submit(string $path, array $input)
    {
        return $this->post($path, $input, ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
    }

    public function test_native_pages_render_public_catalog_and_grouped_fields(): void
    {
        $pay = $this->get('/admin/pay/create')->assertStatus(200)->getContent();
        $this->assertStringContainsString('data-admin-form-guide', $pay);
        foreach (['支付服务', '填写账户', '保存与启用', 'Payjs', 'BEpusdt', 'API V2'] as $label) $this->assertStringContainsString($label, $pay);
        $goods = $this->get('/admin/goods/create')->assertStatus(200)->getContent();
        foreach (['基本信息', '发货与库存', '优惠与买家资料', '更多设置', '搜索展示简介', '每单最多购买'] as $label) $this->assertStringContainsString($label, $goods);
    }

    public function test_channel_without_an_unused_key_can_be_created_and_reloaded(): void
    {
        $this->submit('/admin/pay', $this->pay())->assertStatus(200)->assertJson(['status' => true]);
        $id = DB::table('pays')->where('pay_check', 'payjswescan')->value('id');
        $this->assertNotNull($id);
        $this->assertSame('SYNTHETIC-SIGNING-KEY', DB::table('pays')->where('id', $id)->value('merchant_pem'));
        $this->assertStringContainsString('value="pay/payjs" selected', $this->get('/admin/pay/' . $id . '/edit')->assertStatus(200)->getContent());
        $this->assertStringNotContainsString('SYNTHETIC-SIGNING-KEY', $this->get('/admin/pay')->assertStatus(200)->getContent());
        $this->assertStringNotContainsString('SYNTHETIC-SIGNING-KEY', $this->get('/admin/pay/' . $id)->assertStatus(200)->getContent());
    }

    public function test_service_mode_mismatch_duplicate_and_missing_credentials_never_write(): void
    {
        $before = DB::table('pays')->count();
        $this->submit('/admin/pay', $this->pay(['pay_check' => 'wescan']))->assertStatus(200)->assertJson(['status' => false]);
        $this->assertSame($before, DB::table('pays')->count());
        $this->submit('/admin/pay', $this->pay(['merchant_pem' => '']))->assertStatus(200)->assertJson(['status' => false]);
        $this->assertSame($before, DB::table('pays')->count());
        $this->submit('/admin/pay', $this->pay())->assertJson(['status' => true]);
        $this->submit('/admin/pay', $this->pay())->assertJson(['status' => false]);
        $this->assertSame($before + 1, DB::table('pays')->count());
    }

    public function test_disabled_draft_can_be_saved_but_incomplete_activation_is_blocked(): void
    {
        $this->submit('/admin/pay', $this->pay(['merchant_pem' => '', 'merchant_id' => '', 'is_open' => 0]))->assertJson(['status' => true]);
        $id = DB::table('pays')->where('pay_check', 'payjswescan')->value('id');
        $this->submit('/admin/pay/' . $id, $this->pay(['merchant_pem' => '', 'merchant_id' => '', 'is_open' => 1, '_method' => 'PUT']))->assertJson(['status' => false]);
        $this->assertSame(0, (int) DB::table('pays')->where('id', $id)->value('is_open'));
    }

    public function test_historical_route_and_identifiers_survive_normal_editing(): void
    {
        DB::table('pays')->where('id', 1)->update(['pay_handleroute' => 'pay/custom-adapter', 'pay_check' => 'legacy-custom']);
        $page = $this->get('/admin/pay/1/edit')->assertStatus(200)->getContent();
        $this->assertStringContainsString('历史自定义渠道', $page);
        $this->assertStringContainsString('legacy-custom', $page);
        $this->submit('/admin/pay/1', $this->pay(['_method' => 'PUT', 'pay_handleroute' => 'pay/custom-adapter', 'pay_check' => 'legacy-custom']))->assertJson(['status' => true]);
        $this->assertSame('pay/custom-adapter', DB::table('pays')->where('id', 1)->value('pay_handleroute'));
    }

    public function test_minimal_product_autofills_optional_search_text_and_stores_structured_configuration(): void
    {
        $input = $this->goods(['wholesale_price_cnf' => "5=8\n10=7", 'other_ipu_cnf' => "account=充值账号=true=请输入账号\nregion=地区=false"]);
        $this->submit('/admin/goods', $input)->assertStatus(200)->assertJson(['status' => true]);
        $row = DB::table('goods')->where('gd_name', $input['gd_name'])->first();
        $this->assertNotNull($row);
        $this->assertSame($input['gd_name'], $row->gd_description);
        $this->assertSame($input['gd_name'], $row->gd_keywords);
        $this->assertSame('5=8' . PHP_EOL . '10=7', $row->wholesale_price_cnf);
        $this->assertCount(2, format_wholesale_price($row->wholesale_price_cnf));
        $fields = format_charge_input($row->other_ipu_cnf);
        $this->assertCount(2, $fields);
        $this->assertTrue($fields[0]['rule']);
        $this->assertFalse($fields[1]['rule']);
    }

    public function test_invalid_product_configuration_and_negative_values_leave_data_unchanged(): void
    {
        $before = DB::table('goods')->count();
        foreach ([['wholesale_price_cnf' => '5=20'], ['wholesale_price_cnf' => "5=8\n5=7"],
            ['other_ipu_cnf' => 'email=邮箱=true'], ['other_ipu_cnf' => 'account=账号=maybe']] as $change) {
            $this->submit('/admin/goods', $this->goods($change))->assertStatus(200)->assertJson(['status' => false]);
            $this->assertSame($before, DB::table('goods')->count());
        }
        $this->submit('/admin/goods', $this->goods(['actual_price' => '-1']))->assertStatus(422);
        $this->submit('/admin/goods', $this->goods(['in_stock' => '-1']))->assertStatus(422);
        $this->assertSame($before, DB::table('goods')->count());
    }

    public function test_historical_raw_configuration_is_preserved_when_not_changed(): void
    {
        DB::table('goods')->where('id', 2)->update(['wholesale_price_cnf' => 'old-format', 'other_ipu_cnf' => 'CUSTOM-OLD']);
        $input = $this->goods(['_method' => 'PUT', 'actual_price' => '9.00', 'wholesale_price_cnf' => 'old-format', 'other_ipu_cnf' => 'CUSTOM-OLD']);
        $this->submit('/admin/goods/2', $input)->assertJson(['status' => true]);
        $row = DB::table('goods')->where('id', 2)->first();
        $this->assertSame('old-format', $row->wholesale_price_cnf);
        $this->assertSame('CUSTOM-OLD', $row->other_ipu_cnf);
    }

    public function test_lowering_the_price_rechecks_existing_wholesale_prices_without_mutating_the_product(): void
    {
        DB::table('goods')->where('id', 2)->update(['actual_price' => '10.00', 'wholesale_price_cnf' => '5=8']);
        $input = $this->goods(['_method' => 'PUT', 'actual_price' => '6.00', 'wholesale_price_cnf' => '5=8']);
        $this->submit('/admin/goods/2', $input)->assertJson(['status' => false]);
        $this->assertSame(0, bccomp('10.00', (string) DB::table('goods')->where('id', 2)->value('actual_price'), 2));
        $input['wholesale_price_cnf'] = '5=5';
        $this->submit('/admin/goods/2', $input)->assertJson(['status' => true]);
        $this->assertSame('5=5', DB::table('goods')->where('id', 2)->value('wholesale_price_cnf'));
    }

    public function test_edited_configuration_filters_blank_lines_so_runtime_parsers_accept_it(): void
    {
        $this->submit('/admin/goods', $this->goods(['wholesale_price_cnf' => " 5 = 8 \n   \n 10=7 \n",
            'other_ipu_cnf' => "account=账号=true\n   \nregion=地区=false\n"]))->assertJson(['status' => true]);
        $row = DB::table('goods')->where('gd_name', 'Guided Manual Goods')->first();
        $this->assertCount(2, format_wholesale_price($row->wholesale_price_cnf));
        $this->assertCount(2, format_charge_input($row->other_ipu_cnf));
    }

    public function test_unedited_legacy_configuration_retains_trailing_newlines_and_original_line_endings(): void
    {
        $raw = "email=Email=true\r\n";
        DB::table('goods')->where('id', 2)->update(['actual_price' => '10', 'other_ipu_cnf' => $raw]);
        $this->submit('/admin/goods/2', $this->goods(['_method' => 'PUT', 'other_ipu_cnf' => "email=Email=true\n"]))->assertJson(['status' => true]);
        $this->assertSame($raw, DB::table('goods')->where('id', 2)->value('other_ipu_cnf'));
    }

    public function test_setting_secrets_are_not_rendered_and_empty_inputs_preserve_them_and_custom_settings(): void
    {
        $settings = ['driver' => 'array', 'password' => 'SYNTHETIC-SMTP-SECRET', 'server_jiang_token' => 'SYNTHETIC-PUSH-SECRET',
            'is_open_server_jiang' => 1, 'custom_setting' => 'keep-this', 'template' => 'vue3', 'language' => 'zh_CN'];
        \Illuminate\Support\Facades\Cache::put('system-setting', $settings);
        $page = $this->get('/admin/system-setting')->assertStatus(200)->getContent();
        $this->assertStringNotContainsString('SYNTHETIC-SMTP-SECRET', $page);
        $this->assertStringNotContainsString('SYNTHETIC-PUSH-SECRET', $page);
        $response = (new \App\Admin\Forms\SystemSetting())->handle(['title' => 'New Shop', 'password' => '', 'server_jiang_token' => '']);
        $this->assertTrue($response->send()->getData(true)['status']);
        $saved = \Illuminate\Support\Facades\Cache::get('system-setting');
        $this->assertSame($settings['password'], $saved['password']);
        $this->assertSame($settings['server_jiang_token'], $saved['server_jiang_token']);
        $this->assertSame('keep-this', $saved['custom_setting']);
        $this->assertSame('New Shop', $saved['title']);
    }

    public function test_incomplete_service_activation_and_invalid_mail_settings_do_not_change_saved_settings(): void
    {
        $cache = \Illuminate\Support\Facades\Cache::getFacadeRoot();
        $cache->put('system-setting', ['driver' => 'array', 'title' => 'Keep Shop']);
        foreach ([['port' => 70000], ['manage_email' => 'bad-mail'], ['is_open_telegram_push' => 1], ['is_open_geetest' => 1]] as $input) {
            $response = (new \App\Admin\Forms\SystemSetting())->handle($input)->send()->getData(true);
            $this->assertFalse($response['status']);
            $this->assertSame(['driver' => 'array', 'title' => 'Keep Shop'], $cache->get('system-setting'));
        }
    }

    public function test_catalog_routes_match_the_registered_checkout_handlers(): void
    {
        foreach (array_keys(PaymentFormCatalog::all()) as $route) {
            $request = \Illuminate\Http\Request::create('/' . $route . '/mode/order', 'GET');
            $matched = app('router')->getRoutes()->match($request);
            $this->assertStringContainsString('@gateway', $matched->getActionName());
        }
    }
}
