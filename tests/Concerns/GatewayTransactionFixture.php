<?php

namespace Tests\Concerns;

use App\Models\Carmis;
use App\Models\Order;
use App\Service\OrderProcessService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait GatewayTransactionFixture
{
    protected function createGatewayTables(): void
    {
        Schema::create('pays', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('pay_check'); $t->string('pay_handleroute'); $t->string('pay_name')->default('Fixture');
            $t->string('merchant_id'); $t->text('merchant_key'); $t->text('merchant_pem'); $t->integer('is_open')->default(1);
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('goods', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->integer('in_stock')->default(5); $t->integer('sales_volume')->default(0); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('order_sn'); $t->string('title')->default('Local CNY fixture'); $t->string('email')->default('');
            $t->integer('pay_id'); $t->decimal('actual_price', 10, 2); $t->integer('status'); $t->string('trade_no')->default('');
            $t->integer('goods_id')->default(1); $t->integer('type')->default(Order::AUTOMATIC_DELIVERY); $t->integer('buy_amount')->default(1);
            $t->integer('coupon_id')->nullable(); $t->integer('coupon_ret_back')->default(0); $t->text('info')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('carmis', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->integer('goods_id'); $t->string('carmi'); $t->integer('status')->default(Carmis::STATUS_UNSOLD);
            $t->integer('is_loop')->default(0); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('coupons', function (Blueprint $t) { $t->bigIncrements('id'); $t->timestamps(); $t->softDeletes(); });
    }

    protected function bindGatewayProcessor(): void
    {
        $test = $this;
        $processor = new class($test) extends OrderProcessService {
            private $test;
            public function __construct($test) { parent::__construct(); $this->test = $test; }
            public function completedOrder(string $sn, float $amount, string $trade = '', ?callable $guard = null)
            {
                $this->test->beforeCompletion();
                return parent::completedOrder($sn, $amount, $trade, $guard);
            }
            protected function notifyOrder(Order $order): void { $this->test->notified(); }
        };
        $this->app->instance(OrderProcessService::class, $processor);
    }
}
