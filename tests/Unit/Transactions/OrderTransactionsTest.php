<?php

namespace Tests\Unit\Transactions;

use App\Jobs\CouponBack;
use App\Jobs\OrderExpired;
use App\Models\Carmis;
use App\Models\Coupon;
use App\Models\Goods;
use App\Models\Order;
use App\Service\CarmisService;
use App\Service\CouponService;
use App\Service\GoodsService;
use App\Service\OrderProcessService;
use App\Service\OrderService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class OrderTransactionsTest extends TestCase
{
    private $db;
    private $app;
    private $previousApp;
    private $process;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Container::getInstance();
        $this->app = new Container();
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
        $this->db = new Manager($this->app);
        $this->db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->db->setAsGlobal();
        $this->db->bootEloquent();
        Order::unsetEventDispatcher();
        $this->app->instance('db', $this->db->getDatabaseManager());
        $this->app->instance('translator', new \Illuminate\Translation\Translator(new \Illuminate\Translation\ArrayLoader(), 'en'));
        $this->app->instance('cache', new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()));
        $this->app->instance('Service\GoodsService', new GoodsService());
        $this->app->instance('Service\CouponService', new CouponService());
        $this->app->instance('Service\CarmisService', new CarmisService());
        $this->app->instance('Service\EmailtplService', new \stdClass());
        $this->app->instance('Service\OrderService', new OrderService());
        $this->process = new RecordingOrderProcess();
        $schema = $this->db->schema();
        $schema->create('goods', function (Blueprint $t) {
            $t->increments('id');
            $t->string('gd_name')->default('Fixture');
            $t->integer('in_stock')->default(2);
            $t->integer('sales_volume')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('orders', function (Blueprint $t) {
            $t->increments('id');
            $t->string('order_sn')->unique();
            $t->integer('goods_id');
            $t->integer('type')->default(Order::AUTOMATIC_DELIVERY);
            $t->integer('status')->default(Order::STATUS_WAIT_PAY);
            $t->integer('buy_amount')->default(1);
            $t->decimal('actual_price', 10, 2)->default(10);
            $t->string('trade_no')->default('');
            $t->string('title')->default('Fixture');
            $t->string('search_pwd')->nullable();
            $t->string('email')->nullable();
            $t->integer('pay_id')->nullable();
            $t->decimal('goods_price', 10, 2)->nullable();
            $t->string('buy_ip')->nullable();
            $t->decimal('coupon_discount_price', 10, 2)->default(0);
            $t->decimal('wholesale_discount_price', 10, 2)->default(0);
            $t->decimal('total_price', 10, 2)->default(0);
            $t->text('info')->nullable();
            $t->integer('coupon_id')->nullable();
            $t->integer('coupon_ret_back')->default(Order::COUPON_BACK_WAIT);
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('carmis', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('goods_id');
            $t->integer('status')->default(Carmis::STATUS_UNSOLD);
            $t->integer('is_loop')->default(0);
            $t->string('carmi');
            $t->timestamps();
            $t->softDeletes();
        });
        $schema->create('coupons', function (Blueprint $t) {
            $t->increments('id');
            $t->string('coupon')->unique();
            $t->integer('ret')->default(1);
            $t->integer('is_open')->default(Coupon::STATUS_OPEN);
            $t->integer('is_use')->default(Coupon::STATUS_UNUSED);
            $t->timestamps();
            $t->softDeletes();
        });
        Goods::unguarded(function () { Goods::create(['id' => 1]); });
    }

    protected function tearDown(): void
    {
        $this->db->getConnection()->disconnect();
        Facade::clearResolvedInstances();
        Container::setInstance($this->previousApp);
        Facade::setFacadeApplication($this->previousApp);
        parent::tearDown();
    }

    private function order(string $sn, array $attributes = []): Order
    {
        return Order::unguarded(function () use ($sn, $attributes) {
            return Order::create(array_merge(['order_sn' => $sn, 'goods_id' => 1, 'created_at' => \Carbon\Carbon::now()->subMinutes(10)], $attributes))->fresh();
        });
    }

    private function card(bool $loop = false): void
    {
        Carmis::unguarded(function () use ($loop) {
            Carmis::create(['goods_id' => 1, 'carmi' => 'secret-card', 'is_loop' => $loop]);
        });
    }

    private function coupon(): Coupon
    {
        return Coupon::unguarded(function () { return Coupon::create(['coupon' => 'ONLY-ONE'])->fresh(); });
    }

    private function prepareCreation(?Coupon $coupon = null): void
    {
        $goods = Goods::find(1);
        $goods->type = Order::AUTOMATIC_DELIVERY;
        $goods->actual_price = 10;
        $this->process->setGoods($goods);
        $this->process->setCoupon($coupon);
        $this->process->setBuyAmount(1);
        $this->process->setEmail('fixture@example.test');
        $this->process->setBuyIP('127.0.0.1');
        $this->process->setPayID(1);
        $this->process->setSearchPwd('fixture');
        $this->process->setOtherIpt('');
    }

    public function testStatusPollingUsesOneNarrowQueryAndHidesDeletedOrders(): void
    {
        $order = $this->order('polling');
        $service = $this->app->make('Service\OrderService');
        $connection = $this->db->getConnection();
        $connection->enableQueryLog();
        self::assertSame(Order::STATUS_WAIT_PAY, $service->statusByOrderSN('polling'));
        $queries = $connection->getQueryLog();
        self::assertCount(1, $queries);
        self::assertStringContainsString('select "status"', $queries[0]['query']);
        $order->delete();
        self::assertNull($service->statusByOrderSN('polling'));
        self::assertNull($service->statusByOrderSN('missing'));
    }

    public function testEarlyExpiryDoesNotExpireUnpaidOrder(): void
    {
        $order = $this->order('early', ['created_at' => \Carbon\Carbon::now()]);
        (new OrderExpired('early'))->handle();
        self::assertSame(Order::STATUS_WAIT_PAY, (int) $order->fresh()->status);
        (new OrderExpired('early', \Carbon\Carbon::now()->addMinutes(5)->getTimestamp()))->handle();
        self::assertSame(Order::STATUS_WAIT_PAY, (int) $order->fresh()->status);
    }

    public function testSynchronousQueueRunsAfterCommitWithoutExpiringNewOrder(): void
    {
        $this->prepareCreation();
        $events = new \Illuminate\Events\Dispatcher($this->app);
        $this->app->instance('events', $events);
        $this->app->instance(\Illuminate\Contracts\Events\Dispatcher::class, $events);
        $processed = 0;
        $events->listen(\Illuminate\Queue\Events\JobProcessed::class, function () use (&$processed) { ++$processed; });
        $level = null;
        $events->listen(\Illuminate\Queue\Events\JobProcessing::class, function () use (&$level) {
            $level = $this->db->getConnection()->transactionLevel();
        });
        $this->app->instance(\Illuminate\Contracts\Container\Container::class, $this->app);
        $queue = new \Illuminate\Queue\SyncQueue();
        $queue->setContainer($this->app);
        $bus = new \Illuminate\Bus\Dispatcher($this->app, function () use ($queue) { return $queue; });
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
        $order = $this->process->createOrder();
        self::assertSame(1, $processed);
        self::assertSame(0, $level);
        self::assertSame(Order::STATUS_WAIT_PAY, (int) $order->fresh()->status);
    }

    public function testQueueFailureKeepsCommittedOrderForExpirySweep(): void
    {
        $this->prepareCreation();
        $bus = new \Illuminate\Bus\Dispatcher($this->app, function () {
            throw new \RuntimeException('Injected queue failure');
        });
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus);
        $order = $this->process->createOrder();
        self::assertSame(Order::STATUS_WAIT_PAY, (int) $order->fresh()->status);
        self::assertSame(0, $this->db->getConnection()->transactionLevel());
        $order->created_at = \Carbon\Carbon::now()->subMinutes(10);
        $order->save();
        self::assertTrue($this->app->make('Service\OrderService')->expiredOrderSN($order->order_sn));
    }

    public function testExpirySweepOnlyChangesOverdueUnpaidOrders(): void
    {
        $old = $this->order('sweep-old');
        $recent = $this->order('sweep-recent', ['created_at' => \Carbon\Carbon::now()]);
        $paid = $this->order('sweep-paid', ['status' => Order::STATUS_COMPLETED]);
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $command = new \App\Console\Commands\ExpireOrders();
        $command->setOutput(new \Illuminate\Console\OutputStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]), $output
        ));
        self::assertSame(0, $command->handle($this->app->make('Service\OrderService')));
        self::assertStringContainsString('Expired: 1; failed: 0', $output->fetch());
        self::assertSame(Order::STATUS_EXPIRED, (int) $old->fresh()->status);
        self::assertSame(Order::STATUS_WAIT_PAY, (int) $recent->fresh()->status);
        self::assertSame(Order::STATUS_COMPLETED, (int) $paid->fresh()->status);
    }

    public function testExhaustedCouponRollsBackNewOrder(): void
    {
        $coupon = $this->coupon();
        $coupon->ret = 0;
        $coupon->save();
        $coupon->discount = 1;
        $this->prepareCreation($coupon);
        try {
            $this->process->createOrder();
            self::fail('Expected coupon exhaustion');
        } catch (\App\Exceptions\RuleValidationException $e) {
            self::assertSame(0, Order::count());
            self::assertSame(0, $this->db->getConnection()->transactionLevel());
        }
    }

    public function testManualDuplicateCallbackAndExpiryDoNotRepeatEffects(): void
    {
        $this->order('manual', ['type' => Order::MANUAL_PROCESSING]);
        $first = $this->process->completedOrder('manual', 10, 'trade');
        $again = $this->process->completedOrder('manual', 10, 'trade');
        (new OrderExpired('manual'))->handle();
        self::assertSame(Order::STATUS_PENDING, (int) $first->status);
        self::assertSame($first->id, $again->id);
        self::assertSame(Order::STATUS_PENDING, (int) $again->fresh()->status);
        self::assertSame(1, (int) Goods::find(1)->in_stock);
        self::assertSame(1, (int) Goods::find(1)->sales_volume);
        self::assertSame(1, $this->process->notifications);
    }

    public function testAutomaticCallbackConsumesCardOnceAndOtherOrderCannotClaimIt(): void
    {
        $this->card();
        $this->order('first');
        $this->order('second');
        self::assertSame(Order::STATUS_COMPLETED, (int) $this->process->completedOrder('first', 10, 'a')->status);
        $this->process->completedOrder('first', 10, 'a');
        self::assertSame(Order::STATUS_ABNORMAL, (int) $this->process->completedOrder('second', 10, 'b')->status);
        self::assertSame(Carmis::STATUS_SOLD, (int) Carmis::first()->status);
        self::assertSame('secret-card', Order::where('order_sn', 'first')->first()->info);
        self::assertNotSame('secret-card', Order::where('order_sn', 'second')->first()->info);
        self::assertSame(2, $this->process->notifications);
    }

    public function testLoopCardRemainsReusable(): void
    {
        $this->card(true);
        foreach (['a', 'b'] as $sn) {
            $this->order($sn);
            self::assertSame(Order::STATUS_COMPLETED, (int) $this->process->completedOrder($sn, 10, $sn)->status);
        }
        self::assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
    }

    public function testCouponDecrementIsConditionalAndReturnIsIdempotent(): void
    {
        $coupon = $this->coupon();
        $service = $this->app->make('Service\CouponService');
        self::assertSame(1, $service->retDecr($coupon->coupon));
        self::assertSame(0, $service->retDecr($coupon->coupon));
        self::assertSame(0, (int) $coupon->fresh()->ret);
        $order = $this->order('expire', ['coupon_id' => $coupon->id]);
        (new CouponBack($order))->handle();
        self::assertSame(0, (int) $coupon->fresh()->ret);
        (new OrderExpired('expire'))->handle();
        (new OrderExpired('expire'))->handle();
        (new CouponBack($order))->handle();
        (new CouponBack($order))->handle();
        self::assertSame(1, (int) $coupon->fresh()->ret);
        self::assertSame(Order::COUPON_BACK_OK, (int) $order->fresh()->coupon_ret_back);
    }

    public function testDisabledCouponCannotBeConsumed(): void
    {
        $coupon = $this->coupon();
        $coupon->is_open = Coupon::STATUS_CLOSE;
        $coupon->save();
        self::assertSame(0, (new CouponService())->retDecr($coupon->coupon));
        self::assertSame(1, (int) $coupon->fresh()->ret);
    }

    public function testLatePaymentIsRecordedForReviewWithoutDeliveryOrReusingCoupon(): void
    {
        $coupon = $this->coupon();
        (new CouponService())->retDecr($coupon->coupon);
        $this->card();
        $this->order('late', ['coupon_id' => $coupon->id]);
        (new OrderExpired('late'))->handle();
        $order = $this->process->completedOrder('late', 10, 'late-trade');
        $this->process->completedOrder('late', 10, 'late-trade');
        self::assertSame(Order::STATUS_ABNORMAL, (int) $order->status);
        self::assertSame('late-trade', $order->trade_no);
        self::assertStringContainsString('manual reconciliation/refund', $order->info);
        self::assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        self::assertSame(0, (int) Goods::find(1)->sales_volume);
        self::assertSame(1, (int) $coupon->fresh()->ret);
        self::assertSame(1, $this->process->notifications);
    }

    public function testNotificationFailureLeavesCommittedPaymentAndDuplicateSucceeds(): void
    {
        $this->card();
        $this->order('notification');
        $this->process->failNotification = true;
        $order = $this->process->completedOrder('notification', 10, 'trade');
        self::assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        self::assertSame(0, $this->process->notificationTransactionLevel);
        $this->process->completedOrder('notification', 10, 'trade');
        self::assertSame(1, $this->process->notifications);
    }

    public function testFailureAfterClaimRollsBackCardAndOrder(): void
    {
        $this->card();
        $order = $this->order('rollback');
        $this->app->instance('Service\GoodsService', new class extends GoodsService {
            public function salesVolumeIncr(int $id, int $number = 1): bool
            {
                throw new \RuntimeException('Injected database failure');
            }
        });
        $process = new RecordingOrderProcess();
        try {
            $process->completedOrder('rollback', 10, 'trade');
            self::fail('Expected failure');
        } catch (\App\Exceptions\RuleValidationException $e) {
            self::assertSame('Injected database failure', $e->getMessage());
        }
        self::assertSame(Order::STATUS_WAIT_PAY, (int) $order->fresh()->status);
        self::assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        self::assertSame(0, $process->notifications);
    }

    public function testExpiredLegacyOrderReturnsCouponBeforeRecordingLatePayment(): void
    {
        $coupon = $this->coupon();
        (new CouponService())->retDecr($coupon->coupon);
        $order = $this->order('legacy-late', ['coupon_id' => $coupon->id, 'status' => Order::STATUS_EXPIRED]);
        $this->process->completedOrder('legacy-late', 10, 'late-trade');
        (new CouponBack($order))->handle();
        self::assertSame(1, (int) $coupon->fresh()->ret);
        self::assertSame(Order::COUPON_BACK_OK, (int) $order->fresh()->coupon_ret_back);
    }

    public function testCouponReturnFailureRollsBackExpiration(): void
    {
        $order = $this->order('missing-coupon', ['coupon_id' => 999]);
        try {
            (new OrderExpired('missing-coupon'))->handle();
            self::fail('Expected missing coupon failure');
        } catch (\RuntimeException $e) {
            self::assertSame('Coupon return failed.', $e->getMessage());
        }
        self::assertSame(Order::STATUS_WAIT_PAY, (int) $order->fresh()->status);
        self::assertSame(Order::COUPON_BACK_WAIT, (int) $order->fresh()->coupon_ret_back);
    }

    public function testManualStockExhaustionDoesNotGoNegative(): void
    {
        Goods::whereKey(1)->update(['in_stock' => 0]);
        $this->order('no-stock', ['type' => Order::MANUAL_PROCESSING]);
        $order = $this->process->completedOrder('no-stock', 10, 'trade');
        $this->process->completedOrder('no-stock', 10, 'trade');
        self::assertSame(Order::STATUS_ABNORMAL, (int) $order->status);
        self::assertSame(0, (int) Goods::find(1)->in_stock);
        self::assertSame(1, (int) Goods::find(1)->sales_volume);
    }

    public function testNestedPaymentDoesNotNotifyBeforeTheOuterCommit(): void
    {
        $order = $this->order('nested');
        $this->db->getConnection()->beginTransaction();
        try {
            $this->process->completedOrder('nested', 10, 'trade');
            self::fail('Expected independent transaction requirement');
        } catch (\App\Exceptions\RuleValidationException $e) {
            self::assertStringContainsString('own transaction', $e->getMessage());
        } finally {
            $this->db->getConnection()->rollBack();
        }
        self::assertSame(Order::STATUS_WAIT_PAY, (int) $order->fresh()->status);
        self::assertSame(0, $this->process->notifications);
    }

    public function testDuplicateStillValidatesAmountAndTransaction(): void
    {
        $this->order('validate', ['type' => Order::MANUAL_PROCESSING]);
        $this->process->completedOrder('validate', 10, 'trade');
        foreach ([[11, 'trade'], [10, 'different']] as $input) {
            try {
                $this->process->completedOrder('validate', $input[0], $input[1]);
                self::fail('Invalid duplicate accepted');
            } catch (\App\Exceptions\RuleValidationException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame(1, (int) Goods::find(1)->sales_volume);
    }
}

class RecordingOrderProcess extends OrderProcessService
{
    public $notifications = 0;
    public $failNotification = false;
    public $notificationTransactionLevel;

    protected function notifyOrder(Order $order): void
    {
        $this->notifications++;
        $this->notificationTransactionLevel = \Illuminate\Support\Facades\DB::transactionLevel();
        if ($this->failNotification) {
            throw new \RuntimeException('Injected notification failure');
        }
    }
}
