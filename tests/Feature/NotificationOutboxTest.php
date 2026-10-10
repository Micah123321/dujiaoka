<?php

namespace Tests\Feature;

use App\Exceptions\RuleValidationException;
use App\Jobs\DeliverNotification;
use App\Models\Goods;
use App\Models\Order;
use App\Service\GoodsService;
use App\Service\NotificationOutboxService;
use App\Service\OrderProcessService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationOutboxTest extends TestCase
{
    const MIGRATION = 'database/migrations/2026_01_01_000001_create_notification_outbox_table.php';

    private $outbox;
    private $process;
    private $bus;
    private $jobs = [];
    private $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
        config(['dujiaoka.api_hook_outbox' => true]);
        require_once base_path(self::MIGRATION);
        (new \CreateNotificationOutboxTable())->up();
        Schema::create('goods', function (Blueprint $t) {
            $t->increments('id'); $t->string('gd_name')->default('Fixture');
            $t->integer('in_stock')->default(2); $t->integer('sales_volume')->default(0);
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->increments('id'); $t->string('order_sn')->unique(); $t->integer('goods_id');
            $t->integer('type')->default(Order::MANUAL_PROCESSING);
            $t->integer('status')->default(Order::STATUS_WAIT_PAY);
            $t->integer('buy_amount')->default(1); $t->decimal('actual_price', 10, 2)->default(10);
            $t->string('trade_no')->default(''); $t->string('title')->default('Fixture product');
            $t->string('email')->default('buyer@example.test'); $t->text('info')->nullable();
            $t->integer('coupon_id')->nullable(); $t->integer('coupon_ret_back')->default(0);
            $t->timestamps(); $t->softDeletes();
        });
        Goods::unguarded(function () { Goods::create(['id' => 1]); });
        Order::withoutEvents(function () {
            Order::unguarded(function () { Order::create(['order_sn' => 'OUTBOX', 'goods_id' => 1, 'info' => 'Fixture delivery']); });
        });
        $goods = \Mockery::mock(GoodsService::class)->makePartial();
        $goods->shouldReceive('detail')->andReturn((object) [
            'id' => 1, 'gd_name' => 'Fixture product', 'api_hook' => 'https://fixture.invalid/hook',
        ]);
        $this->app->instance('Service\GoodsService', $goods);
        $this->process = new OutboxOnlyOrderProcess();
        $this->outbox = $this->app->make(NotificationOutboxService::class);
        $this->bus = $this->app->make(Dispatcher::class);
        $this->responses([new Response(204)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function responses(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));
    }

    private function captureJobs(): void
    {
        $bus = \Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(function ($job) {
            $this->jobs[] = $job;
            return count($this->jobs);
        });
        $this->app->instance(Dispatcher::class, $bus);
    }

    private function row()
    {
        return DB::table('notification_outbox')->first();
    }

    public function testQueueFailureKeepsPaidOrderAndRecoveryPublishesAfterCommit(): void
    {
        $bus = \Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('PRIVATE-TOKEN https://fixture.invalid/private'));
        $this->app->instance(Dispatcher::class, $bus);
        $order = $this->process->completedOrder('OUTBOX', 10, 'trade');
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(0, (int) $this->row()->attempts);
        $this->assertNull($this->row()->reservation_token);
        $this->assertSame('Queue dispatch failed: RuntimeException', $this->row()->last_error);
        $this->assertSame(1, (int) Goods::find(1)->in_stock);
        $this->process->completedOrder('OUTBOX', 10, 'trade');
        $this->assertSame(1, DB::table('notification_outbox')->count());
        $this->assertSame(1, (int) Goods::find(1)->sales_volume);
        $this->assertCount(0, $this->history);
        $this->app->instance(Dispatcher::class, $this->bus);
        $levels = [];
        $this->app['events']->listen(\Illuminate\Queue\Events\JobProcessing::class, function () use (&$levels) { $levels[] = DB::transactionLevel(); });
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->assertSame(0, Artisan::call('notifications:dispatch'));
        $this->assertStringContainsString('Published: 1; dispatch failed: 0', Artisan::output());
        $this->assertSame([0], $levels);
        $this->assertNotNull($this->row()->delivered_at);
        $this->assertSame(1, (int) $this->row()->attempts);
        $this->assertNull($this->row()->last_error);
        $this->assertCount(1, $this->history);
        $payload = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('OUTBOX', $payload['order_sn']);
        $this->assertSame('Fixture delivery', $payload['order_info']);
    }

    public function testDuplicateQueuedJobsAndPublishersOnlySendOnce(): void
    {
        $this->captureJobs();
        $this->process->completedOrder('OUTBOX', 10, 'trade');
        $this->assertSame(['published' => 0, 'failed' => 0], $this->outbox->publishDue());
        $this->assertCount(1, $this->jobs);
        $this->assertInstanceOf(DeliverNotification::class, $this->jobs[0]);
        $copy = unserialize(serialize($this->jobs[0]));
        $this->jobs[0]->handle($this->outbox);
        $copy->handle($this->outbox);
        $this->assertCount(1, $this->history);
        $this->assertSame(1, (int) $this->row()->attempts);
        $this->assertNotNull($this->row()->delivered_at);
    }

    public function testExpiredLeaseRepublishesWithoutStarvingOlderQueuedCopy(): void
    {
        $this->captureJobs();
        $this->process->completedOrder('OUTBOX', 10, 'trade');
        $oldToken = $this->row()->reservation_token;
        Carbon::setTestNow(Carbon::now()->addSeconds(NotificationOutboxService::LEASE_SECONDS + 1));
        $this->assertSame(['published' => 1, 'failed' => 0], $this->outbox->publishDue());
        $this->assertSame($oldToken, $this->row()->reservation_token);
        $this->jobs[0]->handle($this->outbox);
        $this->assertCount(1, $this->history);
        $this->jobs[1]->handle($this->outbox);
        $this->assertCount(1, $this->history);
        $this->assertNotNull($this->row()->delivered_at);
    }

    public function testQueuedCopyCanStartAfterPublicationLeaseExpires(): void
    {
        $this->captureJobs();
        $this->process->completedOrder('OUTBOX', 10, 'trade');
        Carbon::setTestNow(Carbon::now()->addSeconds(NotificationOutboxService::LEASE_SECONDS + 1));
        $this->jobs[0]->handle($this->outbox);
        $this->assertCount(1, $this->history);
        $this->assertNotNull($this->row()->delivered_at);
    }

    public function testRemovedGoodsOrHookIsRecordedAsFailure(): void
    {
        $this->captureJobs();
        $this->process->completedOrder('OUTBOX', 10, 'trade');
        $goods = \Mockery::mock(GoodsService::class);
        $goods->shouldReceive('detail')->with(1)->andReturn(null, (object) ['api_hook' => '']);
        $this->app->instance('Service\GoodsService', $goods);
        $this->jobs[0]->handle($this->outbox);
        $this->assertNull($this->row()->delivered_at);
        $this->assertSame('Delivery failed: RuntimeException', $this->row()->last_error);
        Carbon::setTestNow(Carbon::parse($this->row()->available_at));
        $this->outbox->publishDue();
        $this->jobs[1]->handle($this->outbox);
        $this->assertNull($this->row()->delivered_at);
        $this->assertSame(2, (int) $this->row()->attempts);
        $this->assertCount(0, $this->history);
    }

    public function testUnconfiguredGoodsDoNotCreateDeliveryIntent(): void
    {
        $goods = \Mockery::mock(GoodsService::class)->makePartial();
        $goods->shouldReceive('detail')->with(1)->andReturn((object) ['api_hook' => '']);
        $this->app->instance('Service\GoodsService', $goods);
        (new OutboxOnlyOrderProcess())->completedOrder('OUTBOX', 10, 'trade');
        $this->assertSame(0, DB::table('notification_outbox')->count());
        $this->assertCount(0, $this->history);
    }

    public function testHttpFailureBacksOffAndDeliveryCanRecover(): void
    {
        $this->responses([new Response(500, [], 'PRIVATE-RESPONSE'), new Response(204)]);
        $this->process->completedOrder('OUTBOX', 10, 'trade');
        $this->assertNull($this->row()->delivered_at);
        $this->assertSame(1, (int) $this->row()->attempts);
        $this->assertSame('Delivery failed: RuntimeException', $this->row()->last_error);
        $this->assertSame(['published' => 0, 'failed' => 0], $this->outbox->publishDue());
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->assertSame(['published' => 1, 'failed' => 0], $this->outbox->publishDue());
        $this->assertSame(2, (int) $this->row()->attempts);
        $this->assertNotNull($this->row()->delivered_at);
        $this->assertNull($this->row()->last_error);
        $this->assertCount(2, $this->history);
    }

    public function testAttemptLimitStopsPersistentDeliveryFailure(): void
    {
        $this->responses(array_fill(0, NotificationOutboxService::MAX_ATTEMPTS, new Response(500)));
        $this->process->completedOrder('OUTBOX', 10, 'trade');
        for ($attempt = 1; $attempt < NotificationOutboxService::MAX_ATTEMPTS; ++$attempt) {
            Carbon::setTestNow(Carbon::parse($this->row()->available_at));
            $this->outbox->publishDue();
        }
        $this->assertSame(NotificationOutboxService::MAX_ATTEMPTS, (int) $this->row()->attempts);
        $this->assertNotNull($this->row()->failed_at);
        $this->assertNull($this->row()->reservation_token);
        Carbon::setTestNow(Carbon::now()->addDay());
        $this->assertSame(['published' => 0, 'failed' => 0], $this->outbox->publishDue());
        $this->assertCount(NotificationOutboxService::MAX_ATTEMPTS, $this->history);
    }

    public function testCrashOnFinalAttemptIsMarkedFailedAfterLeaseExpires(): void
    {
        $this->captureJobs();
        $this->process->completedOrder('OUTBOX', 10, 'trade');
        DB::table('notification_outbox')->update(['attempts' => NotificationOutboxService::MAX_ATTEMPTS]);
        Carbon::setTestNow(Carbon::now()->addSeconds(NotificationOutboxService::LEASE_SECONDS + 1));
        $this->assertSame(['published' => 0, 'failed' => 0], $this->outbox->publishDue());
        $this->assertNotNull($this->row()->failed_at);
        $this->assertCount(1, $this->jobs);
        $this->assertCount(0, $this->history);
    }

    public function testAbandonedRunningAttemptRejectsOldCopyAfterRecovery(): void
    {
        $this->captureJobs();
        $this->process->completedOrder('OUTBOX', 10, 'trade');
        DB::table('notification_outbox')->update(['reservation_token' => str_repeat('A', 32), 'attempts' => 1]);
        Carbon::setTestNow(Carbon::now()->addSeconds(NotificationOutboxService::LEASE_SECONDS + 1));
        $this->outbox->publishDue();
        $this->jobs[0]->handle($this->outbox);
        $this->assertCount(0, $this->history);
        $this->jobs[1]->handle($this->outbox);
        $this->assertCount(1, $this->history);
        $this->assertSame(2, (int) $this->row()->attempts);
        $this->assertNotNull($this->row()->delivered_at);
    }

    public function testOperatorRetryPreservesActiveDeliveryLeaseUntilExpiry(): void
    {
        $this->captureJobs();
        $this->process->completedOrder('OUTBOX', 10, 'trade');
        $id = $this->row()->id;
        $token = str_repeat('A', 32);
        $until = Carbon::now()->addSeconds(NotificationOutboxService::LEASE_SECONDS);
        DB::table('notification_outbox')->where('id', $id)->update([
            'failed_at' => Carbon::now(), 'attempts' => NotificationOutboxService::MAX_ATTEMPTS,
            'reservation_token' => $token, 'reserved_until' => $until,
        ]);
        $before = (array) $this->row();
        $this->assertFalse($this->outbox->retryFailed($id));
        $this->assertSame($before, (array) $this->row());
        Carbon::setTestNow($until);
        $this->assertTrue($this->outbox->retryFailed($id));
        $this->assertNull($this->row()->reservation_token);
        $this->assertNull($this->row()->failed_at);
        $this->assertFalse($this->outbox->retryFailed($id));
        $this->assertCount(0, $this->history);
    }

    public function testOperatorRetryOnlyResetsExhaustedIntent(): void
    {
        $this->responses(array_fill(0, NotificationOutboxService::MAX_ATTEMPTS, new Response(500)));
        $this->process->completedOrder('OUTBOX', 10, 'trade');
        $id = $this->row()->id;
        $this->assertFalse($this->outbox->retryFailed($id));
        for ($attempt = 1; $attempt < NotificationOutboxService::MAX_ATTEMPTS; ++$attempt) {
            Carbon::setTestNow(Carbon::parse($this->row()->available_at));
            $this->outbox->publishDue();
        }
        $this->assertSame(0, Artisan::call('notifications:retry', ['id' => $id]));
        $this->assertNull($this->row()->failed_at);
        $this->assertSame(0, (int) $this->row()->attempts);
        $this->responses([new Response(204)]);
        $this->outbox->publishDue();
        $this->assertNotNull($this->row()->delivered_at);
        $this->assertFalse($this->outbox->retryFailed($id));
        $this->assertCount(NotificationOutboxService::MAX_ATTEMPTS + 1, $this->history);
    }

    public function testEnabledOriginalNotificationPathAvoidsLegacyWebhookDispatch(): void
    {
        Schema::create('carmis', function (Blueprint $t) {
            $t->increments('id'); $t->integer('goods_id'); $t->integer('status'); $t->softDeletes();
        });
        Order::withoutEvents(function () {
            Order::first()->forceFill(['type' => Order::AUTOMATIC_DELIVERY])->save();
        });
        $this->captureJobs();
        (new OrderProcessService())->completedOrder('OUTBOX', 10, 'trade');
        $this->assertSame(Order::STATUS_ABNORMAL, (int) Order::first()->status);
        $this->assertCount(1, $this->jobs);
        $this->assertInstanceOf(DeliverNotification::class, $this->jobs[0]);
        $this->assertSame(1, DB::table('notification_outbox')->count());
    }

    public function testOutboxInsertAndPaymentMutationsRollBackTogether(): void
    {
        $this->app->instance(NotificationOutboxService::class, new class extends NotificationOutboxService {
            public function recordPayment(Order $order): void
            {
                parent::recordPayment($order);
                throw new \RuntimeException('Injected failure after intent insert');
            }
        });
        try {
            $this->process->completedOrder('OUTBOX', 10, 'trade');
            $this->fail('Expected rollback');
        } catch (RuleValidationException $exception) {
            $this->assertSame('Injected failure after intent insert', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('notification_outbox')->count());
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
        $this->assertSame(2, (int) Goods::find(1)->in_stock);
        $this->assertSame(0, (int) Goods::find(1)->sales_volume);
        $this->assertCount(0, $this->history);
    }

    public function testEnabledMissingTableRollsBackInsteadOfSilentlyLosingIntent(): void
    {
        Schema::drop('notification_outbox');
        try {
            $this->process->completedOrder('OUTBOX', 10, 'trade');
            $this->fail('Missing schema accepted');
        } catch (RuleValidationException $exception) {
            $this->assertStringContainsString('notification_outbox', $exception->getMessage());
        }
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
        $this->assertSame(2, (int) Goods::find(1)->in_stock);
        $this->assertSame(0, (int) Goods::find(1)->sales_volume);
    }

    public function testDisabledFeatureDoesNotReadOrRequireNewTable(): void
    {
        config(['dujiaoka.api_hook_outbox' => false]);
        Schema::drop('notification_outbox');
        DB::enableQueryLog();
        $this->process->completedOrder('OUTBOX', 10, 'trade');
        $this->assertSame(0, Artisan::call('notifications:dispatch'));
        $this->assertStringContainsString('Notification outbox is disabled.', Artisan::output());
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('notification_outbox', $query['query']);
        }
        $this->assertSame(Order::STATUS_PENDING, (int) Order::first()->status);
        $this->assertCount(0, $this->history);
    }

    public function testPublicationInsideOuterTransactionIsPrevented(): void
    {
        DB::beginTransaction();
        try {
            $this->outbox->recordPayment(Order::first());
            $this->outbox->publishDue();
            $this->fail('Publication inside transaction accepted');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('committed transaction', $exception->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, DB::table('notification_outbox')->count());
        $this->assertCount(0, $this->history);
    }

    public function testDedicatedMigrationRunsOnceWithoutTouchingExistingOrders(): void
    {
        Schema::drop('notification_outbox');
        $options = ['--path' => [self::MIGRATION], '--force' => true];
        $this->assertSame(0, Artisan::call('migrate', $options));
        $this->assertTrue(Schema::hasTable('notification_outbox'));
        $this->assertSame(1, DB::table('migrations')->count());
        $this->assertSame(0, Artisan::call('migrate', $options));
        $this->assertSame(1, DB::table('migrations')->count());
        $this->assertSame(1, Order::count());
        $this->assertSame(2, (int) Goods::find(1)->in_stock);
    }
}

class OutboxOnlyOrderProcess extends OrderProcessService
{
    protected function notifyOrder(Order $order): void
    {
        // Other notification channels are outside these isolated webhook fixtures.
    }
}
