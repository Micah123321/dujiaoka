<?php

namespace Tests\Feature;

use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use App\Payments\PaypalPaymentsClient;
use App\Service\OrderProcessService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaypalPaymentsRouteTest extends TestCase
{
    private $requests = [];
    private $approved = false;
    private $executeError = false;
    private $remainCreated = false;
    private $executeCount = 0;
    private $notifications = 0;
    private $changes = [];
    private $afterRead;
    private $approval = 'https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=EC-FIXTURE';
    private const PAYMENT = 'PAY-1B56960729604235TKQQIYVY';

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Carbon::setTestNow('2026-01-01 12:00:00');
        $this->withoutMiddleware([\App\Http\Middleware\DujiaoBoot::class, \App\Http\Middleware\InstallCheck::class]);
        config(['dujiaoka.payment_quote_freeze' => true, 'dujiaoka.paypal_orders_v2' => false,
            'dujiaoka.stripe_payment_intents' => false]);
        Schema::create('pays', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('pay_check'); $t->string('pay_handleroute'); $t->string('merchant_id');
            $t->string('merchant_key'); $t->string('merchant_pem'); $t->integer('is_open')->default(1); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('goods', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->integer('in_stock')->default(5); $t->integer('sales_volume')->default(0); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('order_sn'); $t->string('title')->default('Fixture legacy product');
            $t->integer('pay_id'); $t->decimal('actual_price', 10, 2); $t->integer('status');
            $t->string('trade_no')->default(''); $t->integer('goods_id')->default(1);
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
        (new \CreatePaymentQuotesTable())->up();
        Pay::forceCreate(['id' => 7, 'pay_check' => 'paypal-fixture', 'pay_handleroute' => '/pay/paypal', 'merchant_id' => 'fixtureMerchant',
            'merchant_key' => 'fixtureClient', 'merchant_pem' => 'fixtureSecret']);
        Order::forceCreate(['id' => 1, 'order_sn' => 'LEGACYPAYPAL', 'pay_id' => 7, 'actual_price' => '70.00', 'status' => Order::STATUS_WAIT_PAY]);
        DB::table('goods')->insert(['id' => 1]);
        DB::table('carmis')->insert(['goods_id' => 1, 'carmi' => 'synthetic-legacy-card']);
        DB::table('payment_quotes')->insert(['order_id' => 1, 'pay_id' => 7, 'base_currency' => 'CNY', 'currency' => 'USD',
            'source' => 'frankfurter', 'cny_amount' => '70.00', 'usd_amount' => '10.00', 'numerator' => '1', 'denominator' => '7',
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(), 'rate_date' => '2026-01-01']);
        $test = $this;
        $processor = new class($test) extends OrderProcessService {
            private $test;
            public function __construct($test) { parent::__construct(); $this->test = $test; }
            protected function notifyOrder(Order $order): void { $this->test->notified(); }
        };
        $this->app->instance(OrderProcessService::class, $processor);
        $handler = function ($request, $options) {
            $path = $request->getUri()->getPath();
            $this->assertSame('api-m.paypal.com', $request->getUri()->getHost());
            $this->requests[] = [$request->getMethod(), $path, DB::transactionLevel(),
                $request->getHeaderLine('PayPal-Request-Id'), json_decode((string) $request->getBody(), true)];
            if ($path === '/v1/oauth2/token') {
                $body = ['access_token' => 'fixtureBearer', 'token_type' => 'Bearer', 'expires_in' => 3600];
            } elseif ($path === '/v1/payments/payment' && $request->getMethod() === 'POST') {
                $body = $this->receipt('created');
                $body['links'] = [['rel' => 'approval_url', 'href' => $this->approval]];
            } elseif ($path === '/v1/payments/payment/' . self::PAYMENT . '/execute') {
                ++$this->executeCount;
                $this->approved = !$this->remainCreated;
                if ($this->executeError) return Create::promiseFor(new Response(503, [], 'PRIVATE-response-fixtureSecret'));
                $body = $this->receipt('approved');
            } elseif ($path === '/v1/payments/payment/' . self::PAYMENT && $request->getMethod() === 'GET') {
                $body = $this->receipt($this->approved ? 'approved' : 'created');
                if ($this->afterRead) ($this->afterRead)();
            } else {
                $this->fail('Unexpected legacy payment I/O: ' . $path);
            }
            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($body)));
        };
        $this->app->instance(PaypalPaymentsClient::class, new PaypalPaymentsClient(new Client(['handler' => HandlerStack::create($handler)])));
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    public function notified(): void { ++$this->notifications; }

    private function receipt(string $state): array
    {
        return array_replace_recursive(['id' => self::PAYMENT, 'intent' => 'sale', 'state' => $state, 'transactions' => [[
            'invoice_number' => 'LEGACYPAYPAL', 'amount' => ['currency' => 'USD', 'total' => '10.00'],
            'related_resources' => $state === 'approved' ? [['sale' => ['id' => '1B56960729604235T', 'state' => 'completed',
                'parent_payment' => self::PAYMENT, 'amount' => ['currency' => 'USD', 'total' => '10.00']]]] : [],
        ]]], $this->changes);
    }

    private function confirm(): void
    {
        $this->get('/pay/paypal/return_url?success=ok&orderSN=LEGACYPAYPAL&paymentId=' . self::PAYMENT . '&PayerID=fixturePayer')
            ->assertRedirect('/detail-order-sn/LEGACYPAYPAL');
    }

    private function undelivered(): void
    {
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, $this->notifications);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
    }

    public function testRealCreateQueryExecuteAndFulfillmentAreCompatibleWithPendingLegacyLinks(): void
    {
        $this->get('/pay/paypal/paypal-fixture/LEGACYPAYPAL')->assertRedirect($this->approval);
        $created = $this->requests[1][4];
        $this->assertSame('10.00', $created['transactions'][0]['amount']['total']);
        $this->assertSame('10.00', $created['transactions'][0]['item_list']['items'][0]['price']);
        $this->assertSame('LEGACYPAYPAL', $created['transactions'][0]['invoice_number']);
        $this->assertStringContainsString('success=ok', $created['redirect_urls']['return_url']);
        $this->confirm();
        $this->confirm();
        $this->assertSame(1, $this->executeCount);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('1B56960729604235T', Order::first()->trade_no);
        $this->assertSame(1, $this->notifications);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(Carmis::STATUS_SOLD, (int) Carmis::first()->status);
        $execute = array_values(array_filter($this->requests, function ($r) { return substr($r[1], -8) === '/execute'; }));
        $this->assertSame(['payer_id' => 'fixturePayer'], $execute[0][4]);
        foreach ($this->requests as $request) $this->assertSame(0, $request[2]);
    }

    public function testLostExecuteResponseRecoversWithGetWithoutAnotherExecute(): void
    {
        $this->executeError = true;
        $this->confirm();
        $this->undelivered();
        $this->assertSame(1, $this->executeCount);
        $this->confirm();
        $this->assertSame(1, $this->executeCount);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame(1, $this->notifications);
    }

    public function testUnknownExecuteAndStillCreatedOnlyQueriesEvenAfterRequestKeyRetention(): void
    {
        $this->executeError = true;
        $this->remainCreated = true;
        $this->confirm();
        $this->undelivered();
        Carbon::setTestNow(Carbon::now()->addDays(3));
        $this->confirm();
        $this->confirm();
        $this->assertSame(1, $this->executeCount);
        $this->assertSame(self::PAYMENT, Order::first()->trade_no);
        $this->undelivered();
        $this->approved = true;
        $this->confirm();
        $this->assertSame(1, $this->executeCount);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame(1, $this->notifications);
    }

    public function testCheckoutReentryDoesNotCreateAnotherObjectAfterUnknownExecute(): void
    {
        $this->executeError = true;
        $this->remainCreated = true;
        $this->confirm();
        $requests = count($this->requests);
        $this->get('/pay/paypal/paypal-fixture/LEGACYPAYPAL')->assertRedirect('/detail-order-sn/LEGACYPAYPAL');
        $this->assertCount($requests, $this->requests);
        $this->assertSame(1, $this->executeCount);
        $this->undelivered();
    }

    public function testMalformedPayerDoesNotReserveAnUnattemptedExecution(): void
    {
        $this->get('/pay/paypal/return_url?success=ok&orderSN=LEGACYPAYPAL&paymentId=' . self::PAYMENT . '&PayerID=bad%0Avalue')
            ->assertRedirect('/detail-order-sn/LEGACYPAYPAL');
        $this->assertSame('', Order::first()->trade_no);
        $this->assertCount(0, $this->requests);
        $this->undelivered();
    }

    public function testAlreadyApprovedLegacyObjectNeedsNoCurrentRateOrNewSchema(): void
    {
        config(['dujiaoka.payment_quote_freeze' => false]);
        Schema::drop('payment_quotes');
        $this->approved = true;
        $this->confirm();
        $this->confirm();
        $this->assertSame(self::PAYMENT, Order::first()->trade_no);
        $this->assertSame(0, $this->executeCount);
        $this->assertSame(1, $this->notifications);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
    }

    /** @dataProvider invalidReceipts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidReceipts')]
    public function testServerReceiptMustMatchOrderAndCompletedSaleEvenWithoutFreeze(array $changes): void
    {
        config(['dujiaoka.payment_quote_freeze' => false]);
        Schema::drop('payment_quotes');
        $this->approved = true;
        $this->changes = $changes;
        $this->confirm();
        $this->undelivered();
        $this->assertSame(0, $this->executeCount);
    }

    public static function invalidReceipts(): array
    {
        return [[['id' => 'PAY-OTHER']], [['intent' => 'authorize']], [['transactions' => [['invoice_number' => 'OTHER']]]],
            [['transactions' => [['amount' => ['currency' => 'EUR']]]]],
            [['transactions' => [['related_resources' => [['sale' => ['state' => 'pending']]]]]]],
            [['transactions' => [['related_resources' => [['sale' => ['amount' => ['total' => '9.99']]]]]]]],
            [['transactions' => [['related_resources' => [['sale' => ['parent_payment' => 'PAY-OTHER']]]]]]],
            [['transactions' => [['related_resources' => null]]]]];
    }

    /** @dataProvider changedBindings */
    #[\PHPUnit\Framework\Attributes\DataProvider('changedBindings')]
    public function testProviderLookupDoesNotPermitChangedOrderOrGatewayDuringFulfillment(string $change): void
    {
        $this->approved = true;
        $this->afterRead = function () use ($change) {
            if ($change === 'price') DB::table('orders')->update(['actual_price' => '71.00']);
            if ($change === 'gateway') DB::table('orders')->update(['pay_id' => 8]);
            if ($change === 'credentials') DB::table('pays')->update(['merchant_pem' => 'changedFixtureSecret']);
            if ($change === 'route') DB::table('pays')->update(['pay_handleroute' => '/pay/stripe']);
            if ($change === 'quote') DB::table('payment_quotes')->update(['usd_amount' => '11.00']);
        };
        $this->confirm();
        $this->undelivered();
    }

    /** @dataProvider changedBindings */
    #[\PHPUnit\Framework\Attributes\DataProvider('changedBindings')]
    public function testChangedContextStopsBeforeReservingOrExecutingCreatedObject(string $change): void
    {
        $this->afterRead = function () use ($change) {
            if ($change === 'price') DB::table('orders')->update(['actual_price' => '71.00']);
            if ($change === 'gateway') DB::table('orders')->update(['pay_id' => 8]);
            if ($change === 'credentials') DB::table('pays')->update(['merchant_pem' => 'changedFixtureSecret']);
            if ($change === 'route') DB::table('pays')->update(['pay_handleroute' => '/pay/stripe']);
            if ($change === 'quote') DB::table('payment_quotes')->update(['usd_amount' => '11.00']);
        };
        $this->confirm();
        $this->assertSame(0, $this->executeCount);
        $this->assertSame('', Order::first()->trade_no);
        $this->undelivered();
    }

    public static function changedBindings(): array { return [['price'], ['gateway'], ['credentials'], ['route'], ['quote']]; }

    public function testClosedGatewayStillConfirmsPaidButExpiredOrderNeverConsumesCard(): void
    {
        $this->approved = true;
        DB::table('pays')->update(['is_open' => 0]);
        DB::table('orders')->update(['status' => Order::STATUS_EXPIRED]);
        $this->confirm();
        $this->assertSame(Order::STATUS_ABNORMAL, (int) Order::first()->status);
        $this->assertSame('1B56960729604235T', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
    }

    /** @dataProvider inactiveOrders */
    #[\PHPUnit\Framework\Attributes\DataProvider('inactiveOrders')]
    public function testCreatedObjectNeverExecutesAfterGatewayClosureOrOrderExpiry(string $state): void
    {
        if ($state === 'closed') DB::table('pays')->update(['is_open' => 0]);
        else DB::table('orders')->update(['status' => Order::STATUS_EXPIRED]);
        $this->confirm();
        $this->assertSame(0, $this->executeCount);
        $this->assertSame('', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, $this->notifications);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
    }

    public static function inactiveOrders(): array { return [['closed'], ['expired']]; }

    public function testPendingSaleIsOnlyQueriedAndDoesNotExecuteAgain(): void
    {
        $this->approved = true;
        $this->changes = ['transactions' => [['related_resources' => [['sale' => ['state' => 'pending']]]]]];
        $this->confirm();
        $this->confirm();
        $this->undelivered();
        $this->assertSame(0, $this->executeCount);
    }

    public function testCancellationAndUnsignedLegacyNotificationHaveNoProviderIoOrFulfillment(): void
    {
        $this->get('/pay/paypal/return_url?success=no&orderSN=LEGACYPAYPAL')->assertRedirect('/detail-order-sn/LEGACYPAYPAL');
        $this->postJson('/pay/paypal/notify_url', ['private' => 'fixtureSecret', 'id' => self::PAYMENT])->assertStatus(200);
        $this->assertCount(0, $this->requests);
        $this->undelivered();
    }

    public function testUnexpectedApprovalHostNeverRedirectsToAnUnrelatedSite(): void
    {
        $this->approval = 'https://www.paypal.com.evil.test/checkoutnow?token=EC-FIXTURE';
        $this->get('/pay/paypal/paypal-fixture/LEGACYPAYPAL')->assertOk()
            ->assertSeeText(__('dujiaoka.prompt.payment_intent_unavailable'))->assertDontSee('evil.test');
        $this->undelivered();
    }
}
