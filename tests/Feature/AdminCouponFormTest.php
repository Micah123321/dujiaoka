<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Fixtures\AdminData;
use Tests\TestCase;

class AdminCouponFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'admin.database.connection' => 'sqlite',
            'admin.route.prefix' => 'admin', 'admin.permission.enable' => true]);
        DB::purge('sqlite');
        AdminData::create(false);
        $third = (array) DB::table('goods')->where('id', 2)->first();
        $third['id'] = 3; $third['gd_name'] = 'Third Form Goods';
        DB::table('goods')->insert($third);
        $model = config('admin.database.users_model');
        $this->actingAs($model::find(1), 'admin');
    }

    private function input(array $changes = [])
    {
        return array_merge(['goods' => [1, 2], 'discount' => '1.25', 'coupon' => 'COUPON-FORM-NEW',
            'ret' => 2, 'is_use' => 1, 'is_open' => 0, '_previous_' => url('/admin/coupon')], $changes);
    }

    private function state()
    {
        $rows = function ($query) { return $query->get()->map(function ($row) { return (array) $row; })->all(); };
        return ['coupons' => $rows(DB::table('coupons')->orderBy('id')),
            'pivot' => $rows(DB::table('coupons_goods')->orderBy('coupons_id')->orderBy('goods_id')),
            'goods' => $rows(DB::table('goods')->orderBy('id'))];
    }

    private function submit($path, array $input, $expectedStatus = 200)
    {
        $response = $this->post($path, $input, ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
        $response->assertStatus($expectedStatus);
        return $response->json();
    }

    public function test_native_coupon_form_creates_replaces_and_clears_goods_relations(): void
    {
        $before = $this->state();
        $created = $this->submit('/admin/coupon', $this->input());
        $this->assertTrue($created['status']);
        $id = DB::table('coupons')->where('coupon', 'COUPON-FORM-NEW')->value('id');
        $this->assertNotNull($id);
        $row = DB::table('coupons')->where('id', $id)->first();
        $this->assertEquals('1.25', $row->discount);
        $this->assertSame(2, (int) $row->ret);
        $this->assertSame(0, (int) $row->is_open);
        $this->assertSame([1,2], DB::table('coupons_goods')->where('coupons_id', $id)->orderBy('goods_id')->pluck('goods_id')->map(function ($v) { return (int) $v; })->all());
        $updated = $this->submit('/admin/coupon/' . $id, $this->input(['_method' => 'PUT', 'goods' => [2,3], 'discount' => '2.50', 'ret' => 0]));
        $this->assertTrue($updated['status']);
        $this->assertSame([2,3], DB::table('coupons_goods')->where('coupons_id', $id)->orderBy('goods_id')->pluck('goods_id')->map(function ($v) { return (int) $v; })->all());
        $this->assertSame(0, (int) DB::table('coupons')->where('id', $id)->value('ret'));
        $this->assertSame(0, bccomp((string) DB::table('coupons')->where('id', $id)->value('discount'), '2.50', 2));
        $cleared = $this->submit('/admin/coupon/' . $id, $this->input(['_method' => 'PUT', 'goods' => [''], 'discount' => '2.50', 'ret' => 0]));
        $this->assertTrue($cleared['status']);
        $this->assertSame(0, DB::table('coupons_goods')->where('coupons_id', $id)->count());
        $this->assertSame(0, bccomp((string) DB::table('coupons')->where('id', $id)->value('discount'), '2.50', 2));
        $this->assertSame($before['goods'], $this->state()['goods']);
        $this->assertSame($before['coupons'][0], (array) DB::table('coupons')->where('id', 1)->first());
        $this->assertSame(1, DB::table('coupons_goods')->where('coupons_id', 1)->where('goods_id', 1)->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_required_coupon_validation_leaves_main_and_relation_tables_unchanged(): void
    {
        $before = $this->state();
        $result = $this->submit('/admin/coupon', $this->input(['coupon' => '']), 422);
        $this->assertFalse($result['status']);
        $this->assertArrayHasKey('coupon', $result['errors']);
        $this->assertSame($before, $this->state());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function failRelationInsert(&$observed)
    {
        $observed = false;
        DB::connection()->getPdo()->sqliteCreateFunction('observe_coupon_failure', function () use (&$observed) { $observed = true; return 1; });
        DB::unprepared("CREATE TRIGGER coupon_relation_failure BEFORE INSERT ON coupons_goods WHEN NEW.goods_id = 3 BEGIN SELECT observe_coupon_failure(); SELECT RAISE(ABORT, 'Injected coupon relation failure'); END");
    }

    public function test_relation_failure_after_create_rolls_back_the_coupon_row(): void
    {
        $before = $this->state();
        $this->failRelationInsert($observed);
        $result = $this->submit('/admin/coupon', $this->input(['goods' => [1,3]]));
        $this->assertFalse($result['status']);
        $this->assertTrue($observed, 'The actual pivot INSERT must reach the injected SQLite trigger.');
        $this->assertSame($before, $this->state());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_relation_failure_after_update_rolls_back_scalar_and_detached_relation(): void
    {
        $before = $this->state();
        $this->failRelationInsert($observed);
        $result = $this->submit('/admin/coupon/1', $this->input(['_method' => 'PUT', 'goods' => [2,3], 'discount' => '8.25', 'coupon' => 'MUST-ROLL-BACK']));
        $this->assertFalse($result['status']);
        $this->assertTrue($observed, 'The actual pivot INSERT must reach the injected SQLite trigger.');
        $this->assertSame($before, $this->state());
        $this->assertSame(0, DB::transactionLevel());
    }
}
