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

class PaypalOrdersRouteTest extends TestCase
{
    private $requests = [];
    private $completed = false;
    private $captureError = false;
    private $capturePost = 0;
    private $notifications = 0;

    protected function setUp(): void
    {
        parent::setUp(); Event::fake(); Carbon::setTestNow('2026-01-01 12:00:00');
        $this->withoutMiddleware([\App\Http\Middleware\DujiaoBoot::class, \App\Http\Middleware\InstallCheck::class]);
        config(['dujiaoka.payment_quote_freeze' => true, 'dujiaoka.paypal_orders_v2' => true,
            'dujiaoka.paypal_orders_mode' => 'sandbox', 'dujiaoka.stripe_payment_intents' => false]);
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
            elseif ($path === '/v2/payments/captures/3C679366HH908993F') $data = $this->capture();
            elseif (substr($path, -8) === '/capture') {
                ++$this->capturePost; $this->completed = true;
                if ($this->captureError) return \GuzzleHttp\Promise\Create::promiseFor(new Response(500, [], 'fixturePrivateError'));
                $data = $this->receipt('COMPLETED');
            } else $data = $this->receipt($request->getMethod() === 'POST' ? 'CREATED' : ($this->completed ? 'COMPLETED' : 'APPROVED'));
            return \GuzzleHttp\Promise\Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($data)));
        };
        $this->app->instance(PaypalOrdersClient::class, new PaypalOrdersClient(new Client(['handler' => HandlerStack::create($handler)])));
    }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    public function notified(): void { ++$this->notifications; }
    private function capture(): array
    {
        return ['id' => '3C679366HH908993F', 'status' => 'COMPLETED', 'final_capture' => true,
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
    private function beginCheckout(): void { $this->get('/pay/paypal/paypal-fixture/PAYPALROUTE')->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T'); }
    private function boundCallbackUrl(string $action = 'return'): string { return PaypalOrder::firstOrFail()->{$action === 'return' ? 'return_url' : 'cancel_url'}; }

    public function testNativeRedirectReturnUsesSavedIdAndRealCardTransactionExactlyOnce(): void
    {
        $this->beginCheckout(); $return = $this->boundCallbackUrl();
        $this->get($return . '&token=OTHER&PayerID=forged')->assertRedirect('/detail-order-sn/PAYPALROUTE');
        $this->get($return . '&token=ANOTHER')->assertRedirect('/detail-order-sn/PAYPALROUTE');
        $this->assertSame(1, $this->capturePost); $this->assertSame(1, $this->notifications);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('3C679366HH908993F', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_SOLD, (int) Carmis::first()->status);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        foreach ($this->requests as $r) $this->assertSame(0, $r[2]);
    }
    public function testApprovedOrderWithNoApprovalLinkIsRecoveredOnGatewayReentry(): void
    {
        $this->beginCheckout();
        $this->get('/pay/paypal/paypal-fixture/PAYPALROUTE')->assertRedirect('/detail-order-sn/PAYPALROUTE');
        $this->assertSame(1, $this->capturePost); $this->assertSame(1, PaypalOrder::count());
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $creates = array_filter($this->requests, function ($r) { return $r[0] === 'POST' && $r[1] === '/v2/checkout/orders'; });
        $this->assertCount(1, $creates);
    }

    public function testInvalidStateAndChangedActionCannotTriggerApiOrCapture(): void
    {
        $this->beginCheckout(); $count = count($this->requests);
        $this->get('/pay/paypal/v2/return?orderid=PAYPALROUTE&state=forged')->assertStatus(503);
        $url = str_replace('/v2/cancel', '/v2/return', $this->boundCallbackUrl('cancel'));
        $this->get($url)->assertStatus(503); $this->assertCount($count, $this->requests); $this->assertSame(0, $this->capturePost);
    }
    public function testCancelChecksOnlyWithoutCreatingChargeOrVoidingBoundOrder(): void
    {
        $this->beginCheckout(); $this->get($this->boundCallbackUrl('cancel'))->assertRedirect('/detail-order-sn/PAYPALROUTE');
        $this->assertSame(0, $this->capturePost); $this->assertSame(1, PaypalOrder::count());
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
    }
    public function testCaptureConnectionFailureThenReturnRetrievesPaidOrderWithoutSecondPost(): void
    {
        $this->beginCheckout(); $this->captureError = true;
        $this->get($this->boundCallbackUrl())->assertStatus(503)->assertDontSee('fixturePrivateError');
        $this->assertSame(1, $this->capturePost); $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
        $this->get($this->boundCallbackUrl())->assertRedirect('/detail-order-sn/PAYPALROUTE');
        $this->assertSame(1, $this->capturePost); $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
    }
    public function testExpiredAlreadyCapturedOrderRecordsCaptureButDoesNotConsumeCard(): void
    {
        $this->beginCheckout(); $return = $this->boundCallbackUrl(); $this->completed = true;
        DB::table('orders')->update(['status' => Order::STATUS_EXPIRED]); Pay::whereKey(7)->update(['is_open' => 0]);
        $this->get($return)->assertRedirect('/detail-order-sn/PAYPALROUTE');
        $this->assertSame(Order::STATUS_ABNORMAL, (int) Order::first()->status); $this->assertSame(0, $this->capturePost);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status); $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
    }
    public function testModernModeBlocksOldExecutionAndUnverifiedNotificationWithoutApi(): void
    {
        $this->get('/pay/paypal/return_url?success=ok&paymentId=OLD&PayerID=forged&orderSN=PAYPALROUTE')->assertStatus(409);
        $this->postJson('/pay/paypal/notify_url', ['id' => 'fixturePrivateEvent'])->assertStatus(409)->assertDontSee('fixturePrivateEvent');
        $this->assertCount(0, $this->requests); $this->assertSame(0, PaypalOrder::count());
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
    }

    public function testOffModeUsesNoModernTablesAndKeepsOldCallbackDefaultBehavior(): void
    {
        config(['dujiaoka.paypal_orders_v2' => false]); Schema::drop('paypal_orders'); Schema::drop('payment_quotes');
        $this->assertFalse(app(PaymentGatewayLock::class)->enabled());
        $this->get('/pay/paypal/v2/return?orderid=PAYPALROUTE&state=invalid')->assertStatus(503);
        $this->get('/pay/paypal/return_url')->assertOk()->assertSee('error');
        $this->assertCount(0, $this->requests);
    }

    public function testBoundStripeStopsPaypalSelectionBeforeNewProviderCreation(): void
    {
        config(['dujiaoka.stripe_payment_intents' => true]);
        require_once base_path('database/migrations/2026_01_01_000004_create_stripe_payment_intents_table.php');
        (new \CreateStripePaymentIntentsTable())->up();
        Pay::forceCreate(['id' => 8, 'pay_check' => 'stripe-fixture', 'pay_handleroute' => 'pay/stripe', 'merchant_id' => 'pk_test_fixture',
            'merchant_key' => 'whsec_fixture', 'merchant_pem' => 'sk_test_fixture']);
        DB::table('orders')->update(['pay_id' => 8]);
        DB::table('stripe_payment_intents')->insert(['order_id' => 1, 'pay_id' => 8, 'cny_amount' => '70.00', 'currency' => 'cny',
            'amount_minor' => '7000', 'reference' => str_repeat('b', 32), 'account_fingerprint' => str_repeat('c', 64)]);
        try { app(PaymentGatewayLock::class)->selectGateway(Order::first(), Pay::find(7)); $this->fail('Expected locked method'); }
        catch (\App\Exceptions\RuleValidationException $e) { $this->assertSame(__('dujiaoka.prompt.payment_method_locked'), $e->getMessage()); }
        $this->assertSame(8, (int) Order::first()->pay_id); $this->assertSame(0, PaypalOrder::count());
        $this->assertCount(0, $this->requests);
    }

    public function testBoundPaypalStopsSelectionOfStripeAndNewProviderUnderSameOrder(): void
    {
        $this->beginCheckout();
        Pay::forceCreate(['id' => 8, 'pay_check' => 'stripe-fixture', 'pay_handleroute' => 'pay/stripe', 'merchant_id' => 'pk_test_fixture',
            'merchant_key' => 'whsec_fixture', 'merchant_pem' => 'sk_test_fixture']);
        try { app(PaymentGatewayLock::class)->selectGateway(Order::first(), Pay::find(8)); $this->fail('Expected locked method'); }
        catch (\App\Exceptions\RuleValidationException $e) { $this->assertSame(__('dujiaoka.prompt.payment_method_locked'), $e->getMessage()); }
        $this->assertSame(7, (int) Order::first()->pay_id); $this->assertSame(1, PaypalOrder::count());
    }
}
