<?php

namespace Tests\Feature;

use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use App\Models\StripePaymentIntent;
use App\Service\OrderProcessService;
use App\Service\PaymentQuoteService;
use App\Service\StripeReconciliationService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stripe\ApiRequestor;
use Tests\TestCase;

class StripeReconciliationTest extends TestCase
{
    private $http;
    private $responses = [];
    private $requests = [];
    private $notifications = 0;
    private $binding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\DujiaoBoot::class, \App\Http\Middleware\InstallCheck::class]);
        Event::fake();
        Carbon::setTestNow('2026-01-01 12:00:00');
        config(['dujiaoka.stripe_reconciliation' => true, 'dujiaoka.stripe_payment_intents' => true, 'dujiaoka.payment_quote_freeze' => true]);
        Schema::create('pays', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('pay_handleroute'); $t->string('merchant_id'); $t->string('merchant_pem');
            $t->string('merchant_key'); $t->integer('is_open')->default(1); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('goods', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('gd_name')->default('Fixture'); $t->integer('in_stock')->default(5);
            $t->integer('sales_volume')->default(0); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('order_sn'); $t->integer('pay_id'); $t->decimal('actual_price', 10, 2);
            $t->integer('status'); $t->string('trade_no')->default(''); $t->integer('goods_id')->default(1);
            $t->integer('type')->default(Order::AUTOMATIC_DELIVERY); $t->integer('buy_amount')->default(1);
            $t->integer('coupon_id')->nullable(); $t->integer('coupon_ret_back')->default(0); $t->text('info')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('carmis', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->integer('goods_id'); $t->string('carmi'); $t->integer('status')->default(Carmis::STATUS_UNSOLD);
            $t->integer('is_loop')->default(0); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('coupons', function (Blueprint $t) { $t->bigIncrements('id'); $t->timestamps(); $t->softDeletes(); });
        foreach (['000004_create_stripe_payment_intents_table', '000005_create_stripe_reconciliation_tables'] as $file) {
            require_once base_path('database/migrations/2026_01_01_' . $file . '.php');
        }
        (new \CreateStripePaymentIntentsTable())->up();
        (new \CreateStripeReconciliationTables())->up();
        Pay::forceCreate(['id' => 7, 'pay_handleroute' => 'pay/stripe', 'merchant_id' => 'pk_test_fixture',
            'merchant_pem' => 'sk_test_fixture', 'merchant_key' => 'whsec_fixture']);
        DB::table('goods')->insert(['id' => 1]);
        DB::table('carmis')->insert(['goods_id' => 1, 'carmi' => 'synthetic-delivery']);
        Order::forceCreate(['id' => 1, 'order_sn' => 'RECON-1', 'pay_id' => 7, 'actual_price' => '70.00', 'status' => Order::STATUS_WAIT_PAY]);
        $this->binding = StripePaymentIntent::forceCreate(['order_id' => 1, 'pay_id' => 7, 'cny_amount' => '70.00', 'currency' => 'cny',
            'amount_minor' => '7000', 'reference' => str_repeat('a', 32), 'account_fingerprint' => hash('sha256', "pk_test_fixture\0sk_test_fixture"),
            'provider_id' => 'pi_fixture']);
        $quotes = \Mockery::mock(PaymentQuoteService::class);
        $quotes->shouldReceive('enabled')->andReturn(true);
        $this->app->instance(PaymentQuoteService::class, $quotes);
        $test = $this;
        $processor = new class($test) extends OrderProcessService {
            private $test;
            public function __construct($test) { parent::__construct(); $this->test = $test; }
            protected function notifyOrder(Order $order): void { $this->test->notified(); }
        };
        $this->app->instance(OrderProcessService::class, $processor);
        $this->http = \Mockery::mock(\Stripe\HttpClient\ClientInterface::class);
        $this->http->shouldReceive('request')->andReturnUsing(function ($method, $url, $headers, $params) {
            $this->requests[] = [$method, $url, DB::transactionLevel()];
            if (!$this->responses) throw new \RuntimeException('Unexpected network fixture');
            $response = array_shift($this->responses);
            if (is_callable($response)) $response = $response();
            if ($response instanceof \Throwable) throw $response;
            return [json_encode($response), 200, []];
        });
        ApiRequestor::setHttpClient($this->http);
    }

    public function notified(): void { ++$this->notifications; }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null); Carbon::setTestNow(); parent::tearDown();
    }

    private function intent(array $extra = []): array
    {
        return array_replace_recursive(['id' => 'pi_fixture', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 7000,
            'amount_received' => 7000, 'currency' => 'cny', 'livemode' => false, 'capture_method' => 'automatic',
            'confirmation_method' => 'automatic', 'payment_method_types' => ['alipay'],
            'metadata' => ['order_sn' => 'RECON-1', 'binding_reference' => str_repeat('a', 32)],
            'latest_charge' => ['id' => 'ch_fixture', 'object' => 'charge', 'payment_intent' => 'pi_fixture', 'amount' => 7000,
                'currency' => 'cny', 'status' => 'succeeded', 'paid' => true, 'captured' => true, 'refunded' => false, 'amount_refunded' => 0]], $extra);
    }

    private function webhook(array $extra = [], ?string $header = null)
    {
        $body = json_encode(array_replace_recursive(['id' => 'evt_fixture', 'object' => 'event', 'type' => 'payment_intent.succeeded',
            'livemode' => false, 'data' => ['object' => ['object' => 'payment_intent', 'id' => 'pi_fixture', 'amount' => 1,
                'client_secret' => 'fixture-private-secret']]], $extra));
        $ts = time();
        $header = $header ?? 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, 'whsec_fixture');
        return $this->call('POST', '/pay/stripe/webhook/7', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $header], $body);
    }

    private function reconcile(): array { return app(StripeReconciliationService::class)->reconcileDue(); }
    private function state() { return DB::table('stripe_reconciliations')->where('intent_id', $this->binding->id)->first(); }

    public function testSignedCallbackCommitsBeforeAckAndDuplicateDoesNotDeliverTwice(): void
    {
        $this->webhook()->assertOk()->assertJson(['received' => true]);
        $this->webhook()->assertOk();
        $this->assertCount(0, $this->requests);
        $this->assertSame(1, DB::table('stripe_webhook_events')->count());
        $this->assertStringNotContainsString('fixture-private-secret', json_encode(DB::table('stripe_webhook_events')->first()));
        $this->responses[] = $this->intent();
        $this->assertSame(1, $this->reconcile()['completed']);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('ch_fixture', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_SOLD, (int) Carmis::first()->status);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(1, $this->notifications);
        $this->webhook()->assertOk(); $this->reconcile();
        $this->assertCount(1, $this->requests);
        $this->assertNotNull(DB::table('stripe_webhook_events')->value('processed_at'));
        $this->assertNotNull($this->state()->finished_at);
    }

    public function testForgedSignatureBadModeAndStorageFailureGetNoSuccessfulAck(): void
    {
        $this->webhook([], 't=1,v1=bad')->assertStatus(400)->assertJson(['received' => false]);
        $this->webhook(['livemode' => true])->assertStatus(503);
        $this->assertSame(0, DB::table('stripe_webhook_events')->count());
        Schema::drop('stripe_webhook_events');
        $this->webhook()->assertStatus(503);
        $this->assertCount(0, $this->requests);
    }

    public function testMissedWebhookStillReconcilesClosedPageAndClosedGateway(): void
    {
        Pay::whereKey(7)->update(['is_open' => 0]);
        $this->responses[] = $this->intent();
        $this->assertSame(1, $this->reconcile()['completed']);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        foreach ($this->requests as $r) { $this->assertSame('get', $r[0]); $this->assertSame(0, $r[2]); }
        $this->assertSame(0, DB::table('stripe_webhook_events')->count());
    }

    public function testOutOfOrderEventUsesFreshStateAndProcessingKeepsScheduled(): void
    {
        $this->webhook(['type' => 'payment_intent.payment_failed'])->assertOk();
        $this->responses[] = $this->intent(['status' => 'processing']);
        $this->assertSame(1, $this->reconcile()['checked']);
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
        $this->assertNull($this->state()->finished_at);
        Carbon::setTestNow(Carbon::now()->addSeconds(301));
        $this->responses[] = $this->intent(); $this->reconcile();
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame(1, $this->notifications);
    }

    public function testTransientFailureRetriesAndLeaseSuppressesSecondWorker(): void
    {
        $this->webhook()->assertOk();
        $this->responses[] = function () {
            $nested = app(StripeReconciliationService::class)->reconcileDue();
            $this->assertSame(0, $nested['completed']);
            return new \RuntimeException('fixture-private-network-body');
        };
        $this->assertSame(1, $this->reconcile()['retry']);
        $this->assertNull(DB::table('stripe_webhook_events')->value('processed_at'));
        $this->assertSame('intent_sync_failed', $this->state()->last_error);
        $this->assertStringNotContainsString('fixture-private', json_encode($this->state()));
        $this->assertCount(1, $this->requests);
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->responses[] = $this->intent(); $this->reconcile();
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
    }

    public function testUnknownIdOlderThanReplayWindowRecoversOnlyFromRetrievedReference(): void
    {
        $this->binding->provider_id = null; $this->binding->created_at = Carbon::now()->subHours(25); $this->binding->save();
        $this->assertSame(1, $this->reconcile()['manual']); $this->assertCount(0, $this->requests);
        $this->webhook(['type' => 'payment_intent.created'])->assertOk();
        $this->responses = [$this->intent(), $this->intent()];
        $this->reconcile();
        $this->assertSame('pi_fixture', $this->binding->fresh()->provider_id);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        foreach ($this->requests as $r) $this->assertSame('get', $r[0]);
    }

    public function testRecoveredCandidateWithWrongBindingNeverCompletes(): void
    {
        $this->binding->provider_id = null; $this->binding->save(); $this->webhook()->assertOk();
        $this->responses[] = $this->intent(['metadata' => ['order_sn' => 'OTHER']]);
        $this->assertSame(1, $this->reconcile()['retry']);
        $this->assertNull($this->binding->fresh()->provider_id);
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
    }

    public function testExpiredOrderBecomesManualWithoutConsumingInventory(): void
    {
        Order::whereKey(1)->update(['status' => Order::STATUS_EXPIRED]);
        $this->responses[] = $this->intent(); $this->reconcile();
        $this->assertSame(Order::STATUS_ABNORMAL, (int) Order::first()->status);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
    }

    public function testCanceledAndUnrelatedSignedEventNeverDeliver(): void
    {
        $this->webhook(['type' => 'customer.created'])->assertOk();
        $this->webhook(['account' => 'acct_fixture'])->assertOk();
        $this->assertSame(0, DB::table('stripe_webhook_events')->count());
        $this->responses[] = $this->intent(['status' => 'canceled']); $this->reconcile();
        $this->assertNotNull($this->state()->finished_at);
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
    }

    /** @dataProvider commitWindowChanges */
    #[\PHPUnit\Framework\Attributes\DataProvider('commitWindowChanges')]
    public function testDecisiveReceiptGuardRunsInFulfillmentTransaction(string $table, string $field, string $value): void
    {
        $processor = new class($table, $field, $value) extends OrderProcessService {
            private $table; private $field; private $value;
            public function __construct($table, $field, $value) { parent::__construct(); $this->table = $table; $this->field = $field; $this->value = $value; }
            public function completedOrder(string $sn, float $amount, string $trade = '', ?callable $guard = null)
            {
                // Interleave a persisted change after the preliminary recheck, before actual fulfillment.
                DB::table($this->table)->update([$this->field => $this->value]);
                return parent::completedOrder($sn, $amount, $trade, $guard);
            }
            protected function notifyOrder(Order $order): void {}
        };
        $this->app->instance(OrderProcessService::class, $processor);
        $this->responses[] = $this->intent();
        $this->assertSame(1, $this->reconcile()['retry']);
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
    }

    public static function commitWindowChanges(): array
    {
        return [['orders', 'pay_id', '8'], ['pays', 'merchant_pem', 'sk_test_rotated'],
            ['stripe_payment_intents', 'provider_id', 'pi_other'], ['stripe_payment_intents', 'amount_minor', '7001']];
    }

    public function testBurstOfDifferentEventsSharesBackoffAndCannotRearmManualFailure(): void
    {
        for ($i = 0; $i < 10; ++$i) $this->webhook(['id' => 'evt_burst_' . $i])->assertOk();
        $this->responses[] = new \RuntimeException('fixture-private-temporary');
        $this->reconcile();
        $this->assertCount(1, $this->requests);
        $this->assertSame(1, (int) $this->state()->failures);
        $this->assertNull($this->state()->failed_at);
        DB::table('stripe_reconciliations')->update(['failures' => 10, 'failed_at' => Carbon::now(), 'last_error' => 'intent_sync_failed']);
        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->reconcile();
        $this->assertCount(1, $this->requests);
        $this->assertNotNull($this->state()->failed_at);
        $this->responses[] = $this->intent();
        $this->assertTrue(app(StripeReconciliationService::class)->retry((int) $this->binding->id));
        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->reconcile();
        $this->assertCount(2, $this->requests);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame(10, DB::table('stripe_webhook_events')->whereNotNull('processed_at')->count());
    }

    public function testLostLeaseCannotAcknowledgeAndReclaimDoesNotRepeatCardDelivery(): void
    {
        $this->responses[] = function () {
            Carbon::setTestNow(Carbon::now()->addSeconds(91));
            return $this->intent();
        };
        $this->assertSame(0, $this->reconcile()['completed']);
        $this->assertNull($this->state()->finished_at);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->responses[] = $this->intent();
        $this->assertSame(1, $this->reconcile()['completed']);
        $this->assertNotNull($this->state()->finished_at);
        $this->assertSame(1, $this->notifications);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
    }

    public function testDuplicateIdWithDifferentIntentIsNotAcknowledgedAndFailedEventCanBeRearmed(): void
    {
        $this->webhook()->assertOk();
        $this->webhook(['data' => ['object' => ['id' => 'pi_other']]])->assertStatus(503);
        Pay::whereKey(7)->update(['merchant_pem' => 'sk_test_rotated']);
        for ($i = 0; $i < 10; ++$i) {
            $this->reconcile(); Carbon::setTestNow(Carbon::now()->addHours(2));
        }
        $this->assertNotNull(DB::table('stripe_webhook_events')->value('failed_at'));
        $this->assertCount(0, $this->requests);
        Pay::whereKey(7)->update(['merchant_pem' => 'sk_test_fixture']);
        $this->responses[] = $this->intent();
        $eventId = DB::table('stripe_webhook_events')->value('id');
        $this->assertSame(0, Artisan::call('payments:reconcile-stripe', ['--retry-event-id' => $eventId, '--retry-id' => $this->binding->id]));
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertNotNull(DB::table('stripe_webhook_events')->value('processed_at'));
    }

    public function testRecoveredCandidateIsRecheckedWhenAccountChangesDuringIo(): void
    {
        $this->binding->provider_id = null; $this->binding->save(); $this->webhook()->assertOk();
        $this->responses[] = function () {
            Pay::whereKey(7)->update(['merchant_pem' => 'sk_test_changed']);
            return $this->intent();
        };
        $this->assertSame(1, $this->reconcile()['retry']);
        $this->assertNull($this->binding->fresh()->provider_id);
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
    }

    public function testSchedulerRegistersOnlyWhenAllRequiredFlagsAreEnabled(): void
    {
        $events = function () {
            $schedule = new \Illuminate\Console\Scheduling\Schedule();
            $method = new \ReflectionMethod(\App\Console\Kernel::class, 'schedule');
            $method->setAccessible(true);
            $method->invoke(app(\Illuminate\Contracts\Console\Kernel::class), $schedule);
            return array_values(array_filter($schedule->events(), function ($event) {
                return strpos($event->command, 'payments:reconcile-stripe') !== false;
            }));
        };
        $enabled = $events();
        $this->assertCount(1, $enabled);
        $this->assertSame('* * * * *', $enabled[0]->expression);
        $this->assertTrue($enabled[0]->withoutOverlapping);
        foreach (['stripe_reconciliation', 'stripe_payment_intents', 'payment_quote_freeze'] as $flag) {
            config(['dujiaoka.' . $flag => false]);
            $this->assertCount(0, $events());
            config(['dujiaoka.' . $flag => true]);
        }
    }

    public function testExhaustedFailureIsRearmedByCommandAndOffModeNeedsNoTables(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->responses[] = $this->intent(['amount_received' => 6999]); $this->reconcile();
            Carbon::setTestNow(Carbon::now()->addHours(2));
        }
        $this->assertNotNull($this->state()->failed_at);
        $this->responses[] = $this->intent();
        $this->assertSame(0, Artisan::call('payments:reconcile-stripe', ['--retry-id' => (string) $this->binding->id]));
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        config(['dujiaoka.stripe_reconciliation' => false]);
        (new \CreateStripeReconciliationTables())->down();
        $this->assertSame(0, Artisan::call('payments:reconcile-stripe'));
        $this->webhook()->assertStatus(503);
    }
}
