<?php

namespace Tests\Feature;

use App\Http\Controllers\Pay\PaypalPayController;
use App\Models\Order;
use App\Models\Pay;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mockery;

use Stripe\ApiRequestor;
use Tests\TestCase;

class PaymentCurrencyTest extends TestCase
{
    private $order;
    private $gateway;
    private $processor;
    private $paypal;
    private $stripe;
    private $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
        config(['dujiaoka.currency_quote_cache_seconds' => 300]);
        $this->order = Mockery::mock(Order::class)->makePartial();
        $this->order->forceFill(['order_sn' => 'fixture-order', 'title' => 'Fixture product', 'actual_price' => '100.00',
            'pay_id' => 7, 'status' => Order::STATUS_WAIT_PAY]);
        $this->order->shouldReceive('save')->andReturn(true);
        $gateway = new Pay();
        $gateway->forceFill(['id' => 7, 'merchant_key' => 'fixture-id', 'merchant_pem' => 'fixture-private',
            'merchant_id' => 'fixture-public', 'pay_handleroute' => '/pay/stripe']);
        $orders = Mockery::mock();
        $orders->shouldReceive('detailOrderSN')->with('fixture-order')->andReturn($this->order);
        $payments = Mockery::mock();
        $payments->shouldReceive('detail')->with(7)->andReturn($gateway);
        $payments->shouldReceive('detailByCheck')->andReturn($gateway);
        $this->gateway = $gateway;
        $this->processor = Mockery::mock();
        $this->app->instance('Service\OrderService', $orders);
        $this->app->instance('Service\PayService', $payments);
        $this->app->instance('Service\OrderProcessService', $this->processor);
        $this->paypal = new class extends PaypalPayController {
            public $requests = [];
            protected function createPayment(array $payment, array $credentials): array
            {
                $this->requests[] = $payment;
                return ['links' => [['rel' => 'approval_url', 'href' => 'https://www.paypal.com/checkoutnow?token=EC-FIXTURE']]];
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

    private function network(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));
    }

    private function frank(): Response
    {
        return new Response(200, [], '{"date":"2026-10-09","base":"CNY","quote":"USD","rate":0.14928}');
    }

    private function bank(?array $rows = null): Response
    {
        $rows = $rows ?? [['ccyNbr' => "\u{7f8e}\u{5143}", 'rtcOfr' => '671.050', 'ratDat' => "2026\u{5e74}10\u{6708}09\u{65e5}"]];
        return new Response(200, [], json_encode(['body' => ['data' => $rows]]));
    }

    public function testPaypalUsesTheSameRoundedAmountForEverySdkField(): void
    {
        $this->gateway->pay_handleroute = '/pay/paypal';
        $this->network([$this->frank()]);
        $this->processor->shouldNotReceive('completedOrder');
        $this->get('/pay/paypal/paypal/fixture-order')->assertRedirect('https://www.paypal.com/checkoutnow?token=EC-FIXTURE');
        $this->assertCount(1, $this->paypal->requests);
        $transaction = $this->paypal->requests[0]['transactions'][0];
        $this->assertSame('USD', $transaction['amount']['currency']);
        $this->assertSame('14.93', $transaction['amount']['total']);
        $this->assertSame('14.93', $transaction['amount']['details']['subtotal']);
        $this->assertSame('14.93', $transaction['item_list']['items'][0]['price']);
        $this->assertSame('fixture-order', $transaction['invoice_number']);
        $this->assertCount(1, $this->history);
    }

    public function testPaypalQuoteFailureDoesNotCreateASdkPayment(): void
    {
        $this->gateway->pay_handleroute = '/pay/paypal';
        $this->network([new Response(503, [], 'sensitive-upstream-body')]);
        $this->processor->shouldNotReceive('completedOrder');
        $this->get('/pay/paypal/paypal/fixture-order')->assertOk()
            ->assertSee(__('dujiaoka.prompt.currency_rate_unavailable'))
            ->assertDontSee('sensitive-upstream-body');
        $this->assertCount(0, $this->paypal->requests);
    }

    public function testPaypalBelowCentAmountDoesNotCreateAZeroCharge(): void
    {
        $this->gateway->pay_handleroute = '/pay/paypal';
        $this->order->actual_price = '0.01';
        $this->network([$this->frank()]);
        $this->processor->shouldNotReceive('completedOrder');
        $this->get('/pay/paypal/paypal/fixture-order')->assertOk()->assertSee(__('dujiaoka.prompt.currency_amount_invalid'));
        $this->assertCount(0, $this->paypal->requests);
    }

    public function testStripeCheckoutDisplaysPreciseIntegerCents(): void
    {
        $this->network([$this->bank()]);
        $this->stripe->shouldNotReceive('request');
        $this->processor->shouldNotReceive('completedOrder');
        $response = $this->get('/pay/stripe/card/fixture-order')->assertOk();
        $this->assertMatchesRegularExpression('/amount:\s*1490\s*,/', $response->getContent());
        $response->assertSee('http://localhost/pay/stripe/return_url?orderid=fixture-order', false);
        $this->assertSame('100.00', $this->order->actual_price);
        $this->assertCount(1, $this->history);
    }

    public function testStripeChargeUsesPreciseCentsAndKeepsTheOrderAmountInCny(): void
    {
        $this->network([$this->bank()]);
        $this->stripe->shouldReceive('request')->once()->withArgs(function ($method, $url, $headers, $params, $hasFile) {
            $this->assertSame('post', $method);
            $this->assertSame('https://api.stripe.com/v1/charges', $url);
            $this->assertSame('1490', $params['amount']);
            $this->assertSame('usd', $params['currency']);
            $this->assertSame('fixture-card-token', $params['source']);
            return true;
        })->andReturn(['{"id":"fixture-charge","object":"charge","status":"succeeded","amount":1490,"currency":"usd"}', 200, []]);
        $this->processor->shouldReceive('completedOrder')->once()->with('fixture-order', '100.00', 'fixture-card-token');
        $this->get('/pay/stripe/charge?orderid=fixture-order&stripeToken=fixture-card-token')->assertOk()->assertSeeText('success');
        $this->assertCount(1, $this->history);
    }

    public function testStripeMissingDollarQuoteNeverFallsBackToAFixedRate(): void
    {
        $this->network([$this->bank([['ccyNbr' => 'EUR', 'rtcOfr' => '700.00']])]);
        $this->stripe->shouldNotReceive('request');
        $this->processor->shouldNotReceive('completedOrder');
        $this->get('/pay/stripe/charge?orderid=fixture-order&stripeToken=fixture-card-token')
            ->assertOk()->assertSeeText(__('dujiaoka.prompt.currency_rate_unavailable'));
    }

    public function testStripeCheckoutQuoteFailureRendersThePublicError(): void
    {
        $this->network([new Response(503, [], 'private-bank-response')]);
        $this->stripe->shouldNotReceive('request');
        $this->processor->shouldNotReceive('completedOrder');
        $this->get('/pay/stripe/card/fixture-order')->assertOk()
            ->assertSee(__('dujiaoka.prompt.currency_rate_unavailable'))->assertDontSee('private-bank-response');
    }
}
