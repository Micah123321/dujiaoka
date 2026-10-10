<?php

namespace Tests\Feature;

use App\Service\GoodsService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['dujiaoka.catalog_cache_seconds' => 10]);
        Schema::create('goods_group', function (Blueprint $t) {
            $t->increments('id'); $t->string('gp_name'); $t->integer('is_open'); $t->integer('ord'); $t->softDeletes();
        });
        Schema::create('goods', function (Blueprint $t) {
            $t->increments('id'); $t->integer('group_id'); $t->string('gd_name'); $t->integer('is_open');
            $t->integer('ord'); $t->integer('type'); $t->integer('in_stock'); $t->decimal('actual_price', 10, 2); $t->softDeletes();
        });
        Schema::create('carmis', function (Blueprint $t) {
            $t->increments('id'); $t->integer('goods_id'); $t->integer('status'); $t->softDeletes();
        });
        Schema::create('coupons', function (Blueprint $t) { $t->increments('id'); $t->softDeletes(); });
        Schema::create('coupons_goods', function (Blueprint $t) { $t->integer('goods_id'); $t->integer('coupons_id'); });
        DB::table('goods_group')->insert(['id' => 1, 'gp_name' => 'Fixture', 'is_open' => 1, 'ord' => 1]);
        DB::table('goods')->insert(['id' => 1, 'group_id' => 1, 'gd_name' => 'Fixture', 'is_open' => 1, 'ord' => 1, 'type' => 1, 'in_stock' => 0, 'actual_price' => 10]);
        DB::table('carmis')->insert(['goods_id' => 1, 'status' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testWarmCatalogAvoidsDatabaseQueriesAndExpires(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
        $service = new GoodsService();
        $first = $service->withGroup();
        DB::enableQueryLog();
        $this->assertSame($first, $service->withGroup());
        $this->assertCount(0, DB::getQueryLog());
        DB::table('goods')->where('id', 1)->update(['gd_name' => 'Updated']);
        $this->assertSame('Fixture', $service->withGroup()[0]['goods'][0]['gd_name']);
        Carbon::setTestNow(Carbon::now()->addSeconds(11));
        $this->assertSame('Updated', $service->withGroup()[0]['goods'][0]['gd_name']);
    }

    public function testDetailAlwaysReadsCurrentStockAndPrice(): void
    {
        $service = new GoodsService();
        $this->assertSame(1, (int) $service->withGroup()[0]['goods'][0]['in_stock']);
        DB::table('carmis')->update(['status' => 2]);
        DB::table('goods')->update(['actual_price' => 20]);
        $detail = $service->detail(1);
        $this->assertSame(0, (int) $detail->in_stock);
        $this->assertSame(20.0, (float) $detail->actual_price);
    }

    public function testTransactionsBypassCacheAndNeverPublishUncommittedData(): void
    {
        $service = new GoodsService();
        $first = $service->withGroup();
        DB::beginTransaction();
        try {
            DB::table('goods')->update(['gd_name' => 'Uncommitted']);
            $this->assertSame('Uncommitted', $service->withGroup()[0]['goods'][0]['gd_name']);
        } finally { DB::rollBack(); }
        $this->assertSame($first, $service->withGroup());
    }

    public function testDisabledCacheAndCacheOutageFallBackToDatabase(): void
    {
        config(['dujiaoka.catalog_cache_seconds' => 0]);
        $service = new GoodsService();
        $service->withGroup();
        DB::table('goods')->update(['gd_name' => 'Fresh']);
        $this->assertSame('Fresh', $service->withGroup()[0]['goods'][0]['gd_name']);
        config(['dujiaoka.catalog_cache_seconds' => 10]);
        Cache::shouldReceive('get')->once()->andThrow(new \RuntimeException('cache offline'));
        Cache::shouldReceive('put')->once()->andThrow(new \RuntimeException('cache offline'));
        $this->assertSame('Fresh', $service->withGroup()[0]['goods'][0]['gd_name']);
    }
}
