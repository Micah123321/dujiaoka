<?php

namespace Tests\Feature;

use App\Exceptions\RuleValidationException;
use App\Models\Order;
use App\Models\Pay;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\Concerns\GatewayTransactionFixture;
use Tests\TestCase;

class BepusdtPaymentTest extends TestCase
{
    use GatewayTransactionFixture;
    private $order;
    private $gateway;
    private $processor;
    private $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->order = Mockery::mock(Order::class)->makePartial();
        $this->order->forceFill(['order_sn' => 'test-order', 'actual_price' => '28.88',
            'pay_id' => 7, 'status' => Order::STATUS_WAIT_PAY]);
        $this->order->shouldReceive('save')->andReturn(true);
        $this->createGatewayTables();
        $this->gateway = new Pay();
        $this->gateway->forceFill(['id' => 7, 'merchant_id' => 'test-token', 'merchant_key' => '',
            'merchant_pem' => 'https://gateway.example', 'pay_check' => 'usdt.trc20',
            'pay_handleroute' => 'pay/bepusdt']);
        $this->gateway->save();
        $orders = Mockery::mock();
        $orders->shouldReceive('detailOrderSN')->with('test-order')->andReturn($this->order);
        $orders->shouldReceive('detailOrderSN')->with('missing')->andReturn(null);
        $payments = Mockery::mock();
        $payments->shouldReceive('detail')->with(7)->andReturn($this->gateway);
        $payments->shouldReceive('detailByCheck')->with('usdt.trc20')->andReturn($this->gateway);
        $this->processor = Mockery::mock();
        $this->app->instance('Service\OrderService', $orders);
        $this->app->instance('Service\PayService', $payments);
        $this->app->instance('Service\OrderProcessService', $this->processor);
    }

    private function mockGateway(Response $response): void
    {
        $handler = HandlerStack::create(new MockHandler([$response]));
        $handler->push(Middleware::history($this->history));
        $http = new Client(['handler' => $handler]);
        $this->app->instance(Client::class, $http);
        $this->app->instance(\App\Payments\BepusdtClient::class, new \App\Payments\BepusdtClient($http));
    }

    private function notification(array $changes = []): array
    {
        $data = array_merge(['trade_id' => 'gateway-trade', 'order_id' => 'test-order',
            'amount' => 28.88, 'actual_amount' => 4.25, 'token' => 'wallet-address',
            'block_transaction_id' => 'chain-hash', 'status' => 2], $changes);
        ksort($data);
        $pairs = [];
        foreach ($data as $key => $value) {
            if ($value !== '' && $value !== null) {
                $pairs[] = $key . '=' . $value;
            }
        }
        $data['signature'] = md5(implode('&', $pairs) . 'test-token');
        return $data;
    }

    public function testCreatesSignedTransactionAndRedirects(): void
    {
        $this->mockGateway(new Response(200, [], json_encode(['status_code' => 200,
            'data' => ['payment_url' => 'https://gateway.example/pay/test']])));
        $this->get('/pay/bepusdt/usdt.trc20/test-order')->assertRedirect('https://gateway.example/pay/test');
        $request = $this->history[0]['request'];
        $this->assertSame('https://gateway.example/api/v1/order/create-transaction', (string) $request->getUri());
        $data = json_decode((string) $request->getBody(), true);
        $this->assertSame(28.88, $data['amount']);
        $this->assertSame('CNY', $data['fiat']);
        $this->assertSame('usdt.trc20', $data['trade_type']);
        $this->assertSame('http://localhost/pay/bepusdt/notify_url', $data['notify_url']);
        $this->assertSame(md5('amount=28.88&fiat=CNY&notify_url=http://localhost/pay/bepusdt/notify_url&order_id=test-order&redirect_url=http://localhost/pay/bepusdt/return_url?order_id=test-order&trade_type=usdt.trc20test-token'), $data['signature']);
    }

    public function testUsdtCheckoutAllowsNetworkSelectionAndReceipts(): void
    {
        $this->gateway->pay_check = 'USDT';
        $this->gateway->save();
        $this->app->make('Service\PayService')->shouldReceive('detailByCheck')->with('USDT')->andReturn($this->gateway);
        $this->mockGateway(new Response(200, [], json_encode(['status_code' => 200,
            'data' => ['payment_url' => 'https://gateway.example/pay/test', 'trade_type' => 'usdt.trc20']])));
        $this->get('/pay/bepusdt/USDT/test-order')->assertRedirect('https://gateway.example/pay/test');
        $data = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('https://gateway.example/api/v1/order/create-order', (string) $this->history[0]['request']->getUri());
        $this->assertSame('USDT', $data['currencies']);
        $this->assertArrayNotHasKey('trade_type', $data);
        $this->assertSame(md5('amount=28.88&currencies=USDT&fiat=CNY&notify_url=http://localhost/pay/bepusdt/notify_url&order_id=test-order&redirect_url=http://localhost/pay/bepusdt/return_url?order_id=test-ordertest-token'), $data['signature']);
        $this->processor->shouldReceive('completedOrder')->times(3)->with('test-order', 28.88, 'gateway-trade', Mockery::type('callable'));
        foreach (['usdt.trc20', 'usdt.bep20', 'usdt.polygon'] as $network) {
            $this->postJson('/pay/bepusdt/notify_url', $this->notification(['trade_type' => $network]))->assertOk()->assertSeeText('ok');
        }
        $this->assertSame('fail', $this->postJson('/pay/bepusdt/notify_url', $this->notification(['trade_type' => 'usdc.polygon']))->getContent());
    }

    public function testAcceptsFullEndpoint(): void
    {
        $this->gateway->merchant_pem = 'https://gateway.example/api/v1/order/create-transaction/';
        $this->testCreatesSignedTransactionAndRedirects();
    }

    public function testPaidNotificationDelegatesRepeatedCallsToIdempotentOrderService(): void
    {
        $this->processor->shouldReceive('completedOrder')->twice()->with('test-order', 28.88, 'gateway-trade', Mockery::on(function ($guard) { return is_callable($guard); }));
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/pay/bepusdt/notify_url', $this->notification())->assertOk()->assertSeeText('ok');
        }
    }

    public function testWaitingAndExpiredNotificationsDoNotDeliver(): void
    {
        $this->processor->shouldNotReceive('completedOrder');
        foreach ([1, 3] as $status) {
            $this->postJson('/pay/bepusdt/notify_url', $this->notification(['status' => $status]))
                ->assertOk()->assertSeeText('ok');
        }
    }

    public function testInvalidNotificationsDoNotDeliver(): void
    {
        $this->processor->shouldNotReceive('completedOrder');
        $tampered = $this->notification();
        $tampered['amount'] = 1;
        $nested = $this->notification();
        $nested['extra'] = ['bad'];
        foreach ([[], $tampered, $nested,
            $this->notification(['amount' => 1]),
            $this->notification(['order_id' => 'missing']),
            $this->notification(['status' => 99]),
            $this->notification(['trade_id' => '']),
            $this->notification(['amount' => '28.889'])] as $data) {
            $response = $this->postJson('/pay/bepusdt/notify_url', $data)->assertOk();
            $this->assertSame('fail', $response->getContent());
        }
        $this->gateway->pay_handleroute = 'pay/epusdt';
        $this->gateway->save();
        $this->assertSame('fail', $this->postJson('/pay/bepusdt/notify_url', $this->notification())->getContent());
    }

    public function testProcessingFailureRequestsCallbackRetry(): void
    {
        $this->processor->shouldReceive('completedOrder')->once()->andThrow(new RuleValidationException('failed'));
        $this->assertSame('fail', $this->postJson('/pay/bepusdt/notify_url', $this->notification())->getContent());
    }

    public function testReturnOnlyRedirectsToOrder(): void
    {
        $this->processor->shouldNotReceive('completedOrder');
        $this->get('/pay/bepusdt/return_url?order_id=test-order')->assertRedirect('/detail-order-sn/test-order');
        $this->get('/pay/bepusdt/return_url')->assertStatus(400);
    }

    public function testMalformedGatewayResponseIsHandled(): void
    {
        $this->mockGateway(new Response(200, [], 'not-json'));
        $this->get('/pay/bepusdt/usdt.trc20/test-order')->assertOk()->assertDontSee('test-token');
        $this->assertCount(1, $this->history);
    }
}
