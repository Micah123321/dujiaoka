<?php

namespace Tests\Feature;

use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use App\Models\PaypalOrder;
use App\Payments\PaypalOrdersClient;
use App\Service\OrderProcessService;
use App\Service\PaymentGatewayLock;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaypalReconciliationRouteTest extends TestCase
{
    private $requests = [];
    private $completed = false;
    private $captureError = false;
    private $capturePending = false;
    private $getError = false;
    private $getStatus = null;
    private $verification = 'SUCCESS';
    private $verifyHook;
    private $receiptChange;
    private $capturePost = 0;
    private $notifications = 0;

    protected function setUp(): void
    {
        parent::setUp(); Event::fake(); Carbon::setTestNow('2026-01-01 12:00:00');
        $this->withoutMiddleware([\App\Http\Middleware\DujiaoBoot::class, \App\Http\Middleware\InstallCheck::class]);
        config(['dujiaoka.payment_quote_freeze' => true, 'dujiaoka.paypal_orders_v2' => true,
            'dujiaoka.paypal_orders_mode' => 'sandbox', 'dujiaoka.stripe_payment_intents' => false, 'dujiaoka.paypal_reconciliation' => true,
            'dujiaoka.paypal_webhook_ids' => [7 => '0NH55953DH663215D']]);
        Schema::create('pays', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('pay_check'); $t->string('pay_handleroute'); $t->string('merchant_id');
            $t->string('merchant_key'); $t->string('merchant_pem'); $t->integer('is_open')->default(1); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('goods', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->integer('in_stock')->default(5); $t->integer('sales_volume')->default(0); $t->timestamps(); $t->softDeletes();
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
        require_once base_path('database/migrations/2026_01_01_000003_create_payment_quotes_table.php');
        require_once base_path('database/migrations/2026_01_01_000006_create_paypal_orders_table.php');
        (new \CreatePaymentQuotesTable())->up(); (new \CreatePaypalOrdersTable())->up();
        require_once base_path('database/migrations/2026_01_01_000007_create_paypal_reconciliation_tables.php');
        (new \CreatePaypalReconciliationTables())->up();
        Pay::forceCreate(['id' => 7, 'pay_check' => 'paypal-fixture', 'pay_handleroute' => 'pay/paypal', 'merchant_id' => 'M123456789012',
            'merchant_key' => 'fixtureClient', 'merchant_pem' => 'fixtureSecret']);
        Order::forceCreate(['id' => 1, 'order_sn' => 'PAYPALROUTE', 'pay_id' => 7, 'actual_price' => '70.00', 'status' => Order::STATUS_WAIT_PAY]);
        DB::table('goods')->insert(['id' => 1]); DB::table('carmis')->insert(['goods_id' => 1, 'carmi' => 'synthetic-card']);
        DB::table('payment_quotes')->insert(['order_id' => 1, 'pay_id' => 7, 'base_currency' => 'CNY', 'currency' => 'USD',
            'source' => 'frankfurter', 'cny_amount' => '70.00', 'usd_amount' => '10.00', 'numerator' => '1', 'denominator' => '7',
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(), 'rate_date' => '2026-01-01']);
        $test = $this;
        $processor = new class($test) extends OrderProcessService {
            private $test; public function __construct($test) { parent::__construct(); $this->test = $test; }
            protected function notifyOrder(Order $order): void { $this->test->notified(); }
        };
        $this->app->instance(OrderProcessService::class, $processor);
        $handler = function ($request, $options) {
            $path = $request->getUri()->getPath();
            $this->requests[] = [$request->getMethod(), $path, DB::transactionLevel(), $request->getHeaderLine('PayPal-Request-Id')];
            if ($path === '/v1/oauth2/token') $data = ['access_token' => 'fixtureBearer', 'token_type' => 'Bearer', 'expires_in' => 3600];
            elseif ($path === '/v1/notifications/verify-webhook-signature') {
                if ($this->verifyHook) ($this->verifyHook)();
                if ($this->verification === 'outage') return \GuzzleHttp\Promise\Create::promiseFor(new Response(500, [], 'fixture-private'));
                $data = ['verification_status' => $this->verification];
            } elseif ($request->getMethod() === 'GET' && $this->getError) return \GuzzleHttp\Promise\Create::promiseFor(new Response(500, [], 'fixture-private'));
            elseif ($path === '/v2/payments/captures/3C679366HH908993F') $data = $this->capture();
            elseif (substr($path, -8) === '/capture') {
                ++$this->capturePost; $this->completed = true;
                if ($this->captureError) return \GuzzleHttp\Promise\Create::promiseFor(new Response(500, [], 'fixture-private'));
                $data = $this->receipt('COMPLETED');
            } else $data = $this->receipt($request->getMethod() === 'POST' ? 'CREATED' : ($this->getStatus ?? ($this->completed ? 'COMPLETED' : 'APPROVED')));
            if (isset($data['purchase_units']) && $this->receiptChange) $data = ($this->receiptChange)($data);
            return \GuzzleHttp\Promise\Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($data)));
        };
        $this->app->instance(PaypalOrdersClient::class, new PaypalOrdersClient(new Client(['handler' => HandlerStack::create($handler)])));
    }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    public function notified(): void { ++$this->notifications; }
    private function capture(): array
    {
        return ['id' => '3C679366HH908993F', 'status' => $this->capturePending ? 'PENDING' : 'COMPLETED', 'final_capture' => true,
            'amount' => ['currency_code' => 'USD', 'value' => '10.00'],
            'supplementary_data' => ['related_ids' => ['order_id' => '5O190127TN364715T']]];
    }
    private function receipt(string $status): array
    {
        $r = PaypalOrder::firstOrFail();
        $unit = ['reference_id' => $r->reference, 'custom_id' => $r->reference, 'invoice_id' => 'PAYPALROUTE',
            'payee' => ['merchant_id' => 'M123456789012'], 'amount' => ['currency_code' => 'USD', 'value' => '10.00']];
        if ($status === 'COMPLETED') $unit['payments'] = ['captures' => [$this->capture()]];
        return ['id' => '5O190127TN364715T', 'intent' => 'CAPTURE', 'status' => $status, 'purchase_units' => [$unit],
            'links' => $status === 'CREATED' ? [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T']] : []];
    }
    private function preparePaypalBinding(): PaypalOrder
    {
        app(\App\Service\PaypalOrderService::class)->begin(Order::find(1), Pay::find(7), 'https://fixture.invalid/return', 'https://fixture.invalid/cancel');
        $this->requests = []; return PaypalOrder::firstOrFail();
    }
    private function webhook(string $id = 'WH-FIXTURE-1', string $type = 'CHECKOUT.ORDER.APPROVED', bool $related = true)
    {
        $resource = $type === 'CHECKOUT.ORDER.APPROVED' ? ['id' => '5O190127TN364715T'] : ['id' => '3C679366HH908993F'];
        if ($related && $type !== 'CHECKOUT.ORDER.APPROVED') $resource['supplementary_data'] = ['related_ids' => ['order_id' => '5O190127TN364715T']];
        return $this->call('POST', '/pay/paypal/webhook/7', [], [], [], ['HTTP_PAYPAL_TRANSMISSION_ID' => 'db49fb10-1343-11ef-ac58-e32457403f67',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => '2024-05-16T05:19:23Z', 'HTTP_PAYPAL_TRANSMISSION_SIG' => base64_encode('fixture-signature'),
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-A', 'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'CONTENT_TYPE' => 'application/json'], json_encode(['id' => $id, 'event_type' => $type, 'resource' => $resource]));
    }
    private function runReconciliation(): array { return app(\App\Service\PaypalReconciliationService::class)->reconcileDue(); }
    private function delivered(int $status = Order::STATUS_COMPLETED): void
    {
        $this->assertSame($status, (int) Order::find(1)->status); $this->assertSame('3C679366HH908993F', Order::find(1)->trade_no);
        $this->assertSame($status === Order::STATUS_COMPLETED ? 1 : 0, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame($status === Order::STATUS_COMPLETED ? Carmis::STATUS_SOLD : Carmis::STATUS_UNSOLD, (int) DB::table('carmis')->value('status'));
    }
    public function testVerifiedDurableBeforeAckThenBrowserlessCaptureAndDuplicateOutOfOrderDelivery(): void
    {
        $this->preparePaypalBinding(); $this->webhook()->assertStatus(200); $this->webhook()->assertStatus(200);
        $this->assertSame(1, DB::table('paypal_webhook_events')->count()); $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::find(1)->status);
        $this->assertSame(0, $this->capturePost);
        $counts = $this->runReconciliation(); $this->assertSame(1, $counts['completed']); $this->delivered();
        $this->webhook('WH-OLD-PENDING', 'PAYMENT.CAPTURE.PENDING')->assertStatus(200); $this->runReconciliation(); $this->delivered();
        $this->assertSame(1, $this->capturePost); $this->assertSame(1, $this->notifications);
        foreach ($this->requests as $r) $this->assertSame(0, $r[2]);
    }
    public function testMissedWebhookIsSeededAndConfirmedWithoutBrowser(): void
    {
        $this->preparePaypalBinding(); $this->runReconciliation(); $this->delivered();
        $this->assertSame(1, $this->capturePost); $this->assertSame(0, DB::table('paypal_webhook_events')->count());
    }
    public function testClosedGatewayAlreadyCapturedIsConfirmedAndExpiryDoesNotTakeCard(): void
    {
        $this->preparePaypalBinding(); $this->completed = true;
        DB::table('pays')->where('id', 7)->update(['is_open' => 0]); DB::table('orders')->where('id', 1)->update(['status' => Order::STATUS_EXPIRED]);
        $this->webhook('WH-COMPLETED', 'PAYMENT.CAPTURE.COMPLETED')->assertStatus(200); $this->runReconciliation();
        $this->delivered(Order::STATUS_ABNORMAL); $this->assertSame(0, $this->capturePost);
    }
    public function testUnknownTwentyFiveHourCreationIsRecoveredByServerReferenceWithoutCreateReplay(): void
    {
        $row = $this->preparePaypalBinding(); $row->provider_id = null; $row->created_at = Carbon::now()->subHours(25); $row->save();
        $this->completed = true; $this->runReconciliation();
        $this->assertSame('provider_id_unknown', DB::table('paypal_reconciliations')->value('last_error'));
        $this->webhook('WH-RECOVER', 'PAYMENT.CAPTURE.COMPLETED', false)->assertStatus(200); $this->runReconciliation(); $this->delivered();
        $this->assertSame('5O190127TN364715T', $row->fresh()->provider_id); $this->assertSame(0, $this->capturePost);
        foreach ($this->requests as $r) $this->assertFalse($r[0] === 'POST' && $r[1] === '/v2/checkout/orders');
    }
    public function testRecoveryNeverBindsWrongPayee(): void
    {
        $row = $this->preparePaypalBinding(); $row->provider_id = null; $row->save(); $this->completed = true;
        $this->receiptChange = function ($data) { $data['purchase_units'][0]['payee']['merchant_id'] = 'OTHER00000000'; return $data; };
        $this->webhook()->assertStatus(200); $this->runReconciliation();
        $this->assertNull($row->fresh()->provider_id); $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::find(1)->status); $this->assertSame(0, $this->capturePost);
    }
    public function testCaptureUncertaintyRecoversWithNoSecondPost(): void
    {
        $this->preparePaypalBinding(); $this->captureError = true; $this->runReconciliation();
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::find(1)->status); $this->assertSame(1, $this->capturePost);
        Carbon::setTestNow(Carbon::now()->addMinutes(2)); $this->runReconciliation(); $this->delivered(); $this->assertSame(1, $this->capturePost);
    }
    public function testApiVerificationOutageIsRetryableAndFailureOrMissingHeadersNeverStored(): void
    {
        $this->preparePaypalBinding(); $this->verification = 'FAILURE'; $this->webhook()->assertStatus(400);
        $this->verification = 'outage'; $this->webhook()->assertStatus(503);
        $this->post('/pay/paypal/webhook/7', [])->assertStatus(400);
        $this->assertSame(0, DB::table('paypal_webhook_events')->count()); $this->assertSame(0, $this->capturePost);
    }
    public function testStoreFailureAndAccountOrEndpointChangeDuringVerificationNeverAckSuccess(): void
    {
        $this->preparePaypalBinding(); $this->verifyHook = function () { config(['dujiaoka.paypal_webhook_ids' => [7 => 'DIFFERENT']]); };
        $this->webhook()->assertStatus(503); $this->assertSame(0, DB::table('paypal_webhook_events')->count());
        config(['dujiaoka.paypal_webhook_ids' => [7 => '0NH55953DH663215D']]); $this->verifyHook = null;
        Schema::drop('paypal_webhook_events'); $this->webhook()->assertStatus(503); $this->assertSame(0, $this->capturePost);
    }
    public function testFailureBurstRespectsBindingBudgetAndExplicitRearm(): void
    {
        $row = $this->preparePaypalBinding(); $this->getError = true;
        for ($i = 0; $i < 10; ++$i) $this->webhook('WH-BURST-' . $i)->assertStatus(200);
        $this->requests = []; $this->runReconciliation();
        $this->assertSame(1, (int) DB::table('paypal_reconciliations')->value('failures'));
        $get = array_filter($this->requests, function ($r) { return $r[0] === 'GET'; }); $this->assertCount(1, $get);
        $this->getError = false; DB::table('paypal_reconciliations')->update(['failed_at' => Carbon::now()]);
        $this->webhook('WH-PAUSED')->assertStatus(200); $this->runReconciliation(); $this->assertSame(0, $this->capturePost);
        $this->assertTrue(app(\App\Service\PaypalReconciliationService::class)->retry($row->id)); $this->runReconciliation(); $this->delivered();
    }
    public function testAccountChangesDuringVerificationAndQuoteChangesDuringRecoveryFailClosed(): void
    {
        $row = $this->preparePaypalBinding();
        $this->verifyHook = function () { DB::table('pays')->where('id', 7)->update(['merchant_pem' => 'fixtureChanged']); };
        $this->webhook()->assertStatus(503); $this->assertSame(0, DB::table('paypal_webhook_events')->count());
        $this->verifyHook = null; DB::table('pays')->where('id', 7)->update(['merchant_pem' => 'fixtureSecret']);
        $row->provider_id = null; $row->save(); $this->completed = true;
        $this->receiptChange = function ($data) { DB::table('payment_quotes')->update(['usd_amount' => '11.00']); return $data; };
        $this->webhook('WH-CHANGED-QUOTE')->assertStatus(200); $this->runReconciliation();
        $this->assertNull($row->fresh()->provider_id); $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::find(1)->status);
        $this->assertSame(0, $this->capturePost);
    }
    public function testUnsupportedVerifiedEventDoesNotStoreOrCaptureAndCommandRejectsInvalidLimit(): void
    {
        $this->preparePaypalBinding(); $this->webhook('WH-IGNORED', 'CUSTOMER.DISPUTE.CREATED')->assertStatus(200);
        $this->assertSame(0, DB::table('paypal_webhook_events')->count()); $this->assertSame(0, $this->capturePost);
        $this->artisan('payments:reconcile-paypal', ['--limit' => '0'])->assertExitCode(1);
        $this->assertSame(0, $this->capturePost);
    }
    public function testPendingCaptureKeepsWaitingBeyondTenPollsWithoutFailurePauseOrNewPost(): void
    {
        $this->preparePaypalBinding(); $this->completed = true; $this->capturePending = true;
        $this->webhook('WH-PENDING', 'PAYMENT.CAPTURE.PENDING')->assertStatus(200);
        for ($i = 0; $i < 12; ++$i) {
            $this->runReconciliation();
            $state = DB::table('paypal_reconciliations')->first();
            $this->assertSame(0, (int) $state->failures); $this->assertNull($state->failed_at);
            $this->assertSame('CAPTURE_PENDING', $state->last_status);
            $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::find(1)->status);
            Carbon::setTestNow(Carbon::now()->addMinutes(16));
        }
        $this->assertSame(0, $this->capturePost); $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
        $this->capturePending = false; $this->runReconciliation(); $this->delivered();
        $this->assertSame(0, $this->capturePost); $this->assertNotNull(DB::table('paypal_reconciliations')->value('finished_at'));
    }
    public function testConditionalScheduleAndDefaultOffNeedsNoNewTables(): void
    {
        $schedule = new \Illuminate\Console\Scheduling\Schedule();
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $method = new \ReflectionMethod($kernel, 'schedule'); $method->setAccessible(true); $method->invoke($kernel, $schedule);
        $commands = array_map(function ($event) { return $event->command; }, $schedule->events());
        $this->assertCount(1, array_filter($commands, function ($c) { return strpos($c, 'payments:reconcile-paypal') !== false; }));
        config(['dujiaoka.paypal_reconciliation' => false]); Schema::drop('paypal_webhook_events'); Schema::drop('paypal_reconciliations');
        $this->webhook()->assertStatus(503); $this->assertSame(['checked'=>0,'completed'=>0,'retry'=>0,'manual'=>0,'ignored'=>0], $this->runReconciliation());
        $this->artisan('payments:reconcile-paypal')->assertExitCode(0);
        $off = new \Illuminate\Console\Scheduling\Schedule(); $method->invoke($kernel, $off);
        foreach ($off->events() as $event) $this->assertStringNotContainsString('payments:reconcile-paypal', $event->command);
    }
}
