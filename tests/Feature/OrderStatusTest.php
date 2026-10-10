<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        Schema::create('orders', function (Blueprint $table) {
            $table->increments('id');
            $table->string('order_sn')->unique();
            $table->integer('status');
            $table->softDeletes();
        });
    }

    public function testPollingKeepsLegacyResponseCodesWithoutLoadingRelations(): void
    {
        foreach ([
            Order::STATUS_WAIT_PAY => 400000,
            Order::STATUS_EXPIRED => 400001,
            Order::STATUS_PENDING => 200,
            Order::STATUS_PROCESSING => 200,
            Order::STATUS_COMPLETED => 200,
            Order::STATUS_ABNORMAL => 200,
        ] as $status => $code) {
            $sn = 'fixture-' . $status;
            DB::table('orders')->insert(['order_sn' => $sn, 'status' => $status]);
            $this->get('/check-order-status/' . $sn)->assertOk()->assertJson(['code' => $code]);
        }
        $this->get('/check-order-status/missing')->assertOk()->assertJson(['code' => 400001]);
    }
}
