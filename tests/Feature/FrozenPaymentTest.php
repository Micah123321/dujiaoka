<?php

namespace Tests\Feature;

use App\Http\Controllers\Pay\PaypalPayController;
use App\Models\Order;
use App\Models\Pay;
use App\Models\PaymentQuote;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Mockery;

use Stripe\ApiRequestor;
use Tests\TestCase;

class FrozenPaymentTest extends TestCase
{
    private $processor;
    private $paypal;
    private $stripe;
    private $history = [];
    private $postKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        Event::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
        config(['dujiaoka.payment_quote_freeze' => true, 'dujiaoka.currency_quote_cache_seconds' => 300]);
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('order_sn'); $t->string('title');
            $t->unsignedBigInteger('pay_id'); $t->decimal('actual_price', 10, 2); $t->integer('status');
            $t->string('trade_no')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('pays', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('pay_check'); $t->string('pay_handleroute');
            $t->string('merchant_id'); $t->string('merchant_key'); $t->string('merchant_pem');
            $t->integer('is_open')->default(1); $t->timestamps(); $t->softDeletes();
        });
        foreach (['coupons', 'goods'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->bigIncrements('id'); $t->softDeletes(); $t->timestamps();
            });
        }
        require_once base_path('database/migrations/2026_01_01_000003_create_payment_quotes_table.php');
        (new \CreatePaymentQuotesTable())->up();
        foreach ([7 => ['card', '/pay/stripe'], 8 => ['paypal', '/pay/paypal']] as $id => $fields) {
            Pay::forceCreate(['id' => $id, 'pay_check' => $fields[0], 'pay_handleroute' => $fields[1],
                'merchant_id' => 'fixture-public', 'merchant_key' => 'fixture-id', 'merchant_pem' => 'fixture-secret']);
        }
        Order::forceCreate(['id' => 1, 'order_sn' => 'quote-order', 'title' => 'Fixture product',
            'pay_id' => 7, 'actual_price' => '100.00', 'status' => Order::STATUS_WAIT_PAY]);
        $this->processor = Mockery::mock();
        $this->app->instance('Service\OrderProcessService', $this->processor);
        $this->paypal = new class extends PaypalPayController {
            public $requests = [];
            public $receipt;
            public $executed;
            public $fetches = 0;
            public $executes = 0;
            protected function createPayment(array $payment, array $credentials): array
            {
                $this->requests[] = $payment;
                return ['links' => [['rel' => 'approval_url', 'href' => 'https://www.paypal.com/checkoutnow?token=EC-FIXTURE']]];
            }
            protected function getPayment(string $id, array $credentials): array
            {
                ++$this->fetches;
                return $this->receipt;
            }
            protected function executePayment(array $payment, string $payerId, array $credentials): array
            {
                ++$this->executes;
                return $this->executed;
            }
        };
        $this->app->instance(PaypalPayController::class, $this->paypal);
        $this->stripe = Mockery::mock(\Stripe\HttpClient\ClientInterface::class);
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        \Stripe\Stripe::setApiKey(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function network(): void
    {
        $bank = ['body' => ['data' => [['ccyNbr' => "\u{7f8e}\u{5143}", 'rtcOfr' => '671.050',
            'ratDat' => "2026\u{5e74}10\u{6708}09\u{65e5}"]]]];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode($bank)), new Response(503, [], 'private-provider-failure'),
        ]));
        $stack->push(Middleware::history($this->history));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));
    }

    private function checkout(): void
    {
        $this->network();
        $r = $this->get('/pay/stripe/card/quote-order')->assertOk();
        $this->assertMatchesRegularExpression('/amount:\s*1490\s*,/', $r->getContent());
        $this->assertSame('14.90', PaymentQuote::first()->usd_amount);
    }

    private function charge(array $changes = [], ?string $source = null): array
    {
        $body = ['id' => 'ch_fixture', 'object' => 'charge', 'status' => 'succeeded', 'paid' => true,
            'captured' => true, 'refunded' => false, 'amount_refunded' => 0, 'amount' => 1490, 'currency' => 'usd'];
        if ($source !== null) {
            $body['source'] = ['id' => $source, 'object' => 'source'];
        }
        return array_merge($body, $changes);
    }

    private function postCharge(array $body, int $times = 1, string $minor = '1490', string $currency = 'usd'): void
    {
        $this->stripe->shouldReceive('request')->times($times)->withArgs(function ($method, $url, $headers, $params) use ($minor, $currency) {
            if ($method !== 'post') { return false; }
            $this->assertSame('https://api.stripe.com/v1/charges', $url);
            $this->assertSame(['Stripe-Version: '], array_values(array_filter($headers, function ($header) { return strpos($header, 'Stripe-Version: ') === 0; })));
            $this->assertSame($minor, $params['amount']);
            $this->assertSame($currency, $params['currency']);
            $keys = array_values(array_filter($headers, function ($header) { return strpos($header, 'Idempotency-Key: ') === 0; }));
            $this->assertCount(1, $keys);
            $this->postKeys[] = $keys[0];
            return true;
        })->andReturn([json_encode($body), 200, []]);
    }

    private function completion(int $times = 1): void
    {
        $this->processor->shouldReceive('completedOrder')->times($times)->withArgs(function ($sn, $cny, $trade, $guard = null) {
            $this->assertIsCallable($guard);
            $this->assertSame('quote-order', $sn);
            $this->assertSame(0, bccomp((string) $cny, '100.00', 2));
            $this->assertSame('ch_fixture', $trade);
            return true;
        });
    }

    public function testDisplayAndChargeUseFrozenPriceAcrossTtlAndOutage(): void
    {
        $this->checkout();
        $row = PaymentQuote::first()->toArray();
        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        $this->postCharge($this->charge());
        $this->completion();
        $this->get('/pay/stripe/charge?orderid=quote-order&stripeToken=tok_fixture')->assertOk()->assertSeeText('success');
        $this->assertCount(1, $this->history);
        $this->assertSame($row, PaymentQuote::first()->toArray());
    }

    public function testPriceMutationAndMissingMigrationStopBeforeCharge(): void
    {
        $this->checkout();
        DB::table('orders')->update(['actual_price' => '101.00']);
        $this->stripe->shouldNotReceive('request');
        $this->processor->shouldNotReceive('completedOrder');
        $this->get('/pay/stripe/charge?orderid=quote-order&stripeToken=tok_fixture')->assertSeeText(__('dujiaoka.prompt.payment_quote_invalid'));
        (new \CreatePaymentQuotesTable())->down();
        $this->get('/pay/stripe/card/quote-order')->assertSee(__('dujiaoka.prompt.payment_quote_unavailable'));
        $this->assertCount(1, $this->history);
    }

    /** @dataProvider badCharges */
    #[\PHPUnit\Framework\Attributes\DataProvider('badCharges')]
    public function testBadChargeReceiptNeverCompletes(array $changes): void
    {
        $this->checkout();
        $this->postCharge($this->charge($changes));
        $this->processor->shouldNotReceive('completedOrder');
        $this->get('/pay/stripe/charge?orderid=quote-order&stripeToken=tok_fixture')
            ->assertOk()->assertSeeText(__('dujiaoka.prompt.payment_quote_invalid'));
    }

    public static function badCharges(): array
    {
        return [[['currency' => 'cny']], [['amount' => 1489]], [['amount' => 1490.5]], [['paid' => false]],
            [['captured' => false]], [['refunded' => true]], [['amount_refunded' => 1]], [['status' => 'pending']], [['id' => '']]];
    }

    private function source(array $changes = [], int $times = 1): void
    {
        $body = array_merge(['id' => 'src_fixture', 'object' => 'source', 'type' => 'wechat', 'currency' => 'usd',
            'amount' => 1490, 'status' => 'chargeable', 'owner' => ['name' => 'quote-order']], $changes);
        $this->stripe->shouldReceive('request')->times($times)->withArgs(function ($method, $url) {
            return $method === 'get' && $url === 'https://api.stripe.com/v1/sources/src_fixture';
        })->andReturn([json_encode($body), 200, []]);
    }

    public function testWechatAndConsumedSourceRetriesRequireRealChargeReceipt(): void
    {
        $this->checkout();
        $this->source(['status' => 'consumed'], 2);
        $this->postCharge($this->charge([], 'src_fixture'), 2);
        $this->completion(2);
        $this->get('/pay/stripe/check?orderid=quote-order&source=src_fixture')->assertSeeText('success');
        $this->get('/pay/stripe/check?orderid=quote-order&source=src_fixture')->assertSeeText('success');
        $this->assertSame($this->postKeys[0], $this->postKeys[1]);
        $this->assertCount(1, $this->history);
    }

    public function testAlipayReturnValidatesCnyReceiptAndCompletesInCny(): void
    {
        $this->checkout();
        $this->source(['type' => 'alipay', 'currency' => 'cny', 'amount' => 10000]);
        $this->postCharge($this->charge(['currency' => 'cny', 'amount' => 10000], 'src_fixture'), 1, '10000', 'cny');
        $this->completion();
        $this->get('/pay/stripe/return_url?orderid=quote-order&source=src_fixture')->assertRedirect('/detail-order-sn/quote-order');
    }

    /** @dataProvider badSources */
    #[\PHPUnit\Framework\Attributes\DataProvider('badSources')]
    public function testMismatchedSourcesStopBeforeCharging(array $changes): void
    {
        $this->checkout();
        $this->source($changes);
        $this->stripe->shouldReceive('request')->withArgs(function ($method) { return $method === 'post'; })->never();
        $this->processor->shouldNotReceive('completedOrder');
        $this->get('/pay/stripe/check?orderid=quote-order&source=src_fixture')->assertSeeText('fail');
    }

    public static function badSources(): array
    {
        return [[['owner' => ['name' => 'another-order']]], [['amount' => 1491]], [['currency' => 'cny']],
            [['type' => 'card']], [['id' => 'src_other']], [['status' => 'pending']]];
    }

    public function testSourceConsumedStateAloneAndOldRetriesNeverComplete(): void
    {
        $this->checkout();
        $this->source(['status' => 'consumed'], 2);
        $this->postCharge($this->charge(['paid' => false], 'src_fixture'));
        $this->processor->shouldNotReceive('completedOrder');
        $this->get('/pay/stripe/check?orderid=quote-order&source=src_fixture')->assertSeeText('fail');
        Carbon::setTestNow(Carbon::now()->addHours(24));
        $this->get('/pay/stripe/check?orderid=quote-order&source=src_fixture')->assertSeeText('fail');
    }

    private function paypalCheckout(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"date":"2026-10-09","base":"CNY","quote":"USD","rate":0.14928}')]));
        $stack->push(Middleware::history($this->history));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));
        $this->get('/pay/paypal/paypal/quote-order')->assertRedirect('https://www.paypal.com/checkoutnow?token=EC-FIXTURE');
        $this->assertSame('14.93', PaymentQuote::first()->usd_amount);
    }

    private function paypalReceipt(array $changes = []): array
    {
        $body = ['id' => 'PAY_fixture', 'intent' => 'sale', 'state' => 'approved', 'transactions' => [[
            'invoice_number' => 'quote-order', 'amount' => ['currency' => 'USD', 'total' => '14.93'],
            'related_resources' => [['sale' => ['id' => 'sale_fixture', 'state' => 'completed', 'parent_payment' => 'PAY_fixture',
                'amount' => ['currency' => 'USD', 'total' => '14.93']]]],
        ]]];
        return array_replace_recursive($body, $changes);
    }

    private function paypalReturn(): void
    {
        $this->get('/pay/paypal/return_url?success=ok&orderSN=quote-order&paymentId=PAY_fixture&PayerID=payer_fixture')
            ->assertRedirect('/detail-order-sn/quote-order');
    }

    public function testPaypalExecutesOnlyMatchingInvoiceAndCompletedSale(): void
    {
        $this->paypalCheckout();
        $body = $this->paypalReceipt();
        $this->paypal->receipt = array_merge($body, ['state' => 'created']);
        $this->paypal->executed = $body;
        $this->processor->shouldReceive('completedOrder')->once()->withArgs(function ($sn, $amount, $trade) {
            return $sn === 'quote-order' && bccomp((string) $amount, '100.00', 2) === 0 && $trade === 'sale_fixture';
        });
        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        $this->paypalReturn();
        $this->assertSame(1, $this->paypal->executes);
        $this->assertCount(1, $this->history);
    }

    /** @dataProvider mismatchedPaypalOrders */
    #[\PHPUnit\Framework\Attributes\DataProvider('mismatchedPaypalOrders')]
    public function testPaypalWrongOrderAndCurrencyStopBeforeExecute(array $changes): void
    {
        $this->paypalCheckout();
        $this->paypal->receipt = $this->paypalReceipt($changes);
        $this->processor->shouldNotReceive('completedOrder');
        $this->paypalReturn();
        $this->assertSame(0, $this->paypal->executes);
        $this->assertSame(1, $this->paypal->fetches);
    }

    public static function mismatchedPaypalOrders(): array
    {
        return [[['id' => 'PAY_other']], [['intent' => 'authorize']],
            [['transactions' => [['invoice_number' => 'another-order']]]],
            [['transactions' => [['amount' => ['currency' => 'CNY']]]]],
            [['transactions' => [['amount' => ['total' => '14.94']]]]]];
    }

    /** @dataProvider badPaypalSales */
    #[\PHPUnit\Framework\Attributes\DataProvider('badPaypalSales')]
    public function testApprovedPaypalPaymentRequiresCompletedSaleAndAmount(array $changes): void
    {
        $this->paypalCheckout();
        $this->paypal->receipt = $this->paypalReceipt($changes);
        $this->processor->shouldNotReceive('completedOrder');
        $this->paypalReturn();
        $this->assertSame(0, $this->paypal->executes);
    }

    public static function badPaypalSales(): array
    {
        return [[['transactions' => [['related_resources' => [['sale' => ['state' => 'pending']]]]]]],
            [['transactions' => [['related_resources' => [['sale' => ['parent_payment' => 'PAY_other']]]]]]],
            [['transactions' => [['related_resources' => [['sale' => ['amount' => ['total' => '14.92']]]]]]]],
            [['transactions' => [['related_resources' => null]]]]];
    }

    public function testPaypalCancellationReturnsWithoutAnySdkCallWithFlagOff(): void
    {
        config(['dujiaoka.payment_quote_freeze' => false]);
        (new \CreatePaymentQuotesTable())->down();
        $this->processor->shouldNotReceive('completedOrder');
        $this->get('/pay/paypal/return_url?success=no&orderSN=quote-order')->assertRedirect('/detail-order-sn/quote-order');
        $this->assertSame(0, $this->paypal->fetches);
        $this->assertSame(0, $this->paypal->executes);
    }
}
