<?php

namespace Tests\Feature;

use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\GatewayTransactionFixture;
use Tests\TestCase;

class BepusdtRouteTest extends TestCase
{
    use GatewayTransactionFixture;

    private const KEY = 'fixture-bep-key';
    private $beforeFulfillment;
    private $notifications = 0;

    protected function setUp(): void
    {
        parent::setUp(); Event::fake();
        $this->withoutMiddleware([\App\Http\Middleware\DujiaoBoot::class, \App\Http\Middleware\InstallCheck::class]);
        config(['dujiaoka.stripe_payment_intents' => false, 'dujiaoka.paypal_orders_v2' => false, 'dujiaoka.payment_quote_freeze' => false]);
        $this->createGatewayTables();
        Pay::forceCreate(['id' => 7, 'pay_check' => 'usdt.trc20', 'pay_handleroute' => 'pay/bepusdt',
            'merchant_id' => self::KEY, 'merchant_key' => '', 'merchant_pem' => 'https://bepusdt.invalid']);
        Order::forceCreate(['id' => 1, 'order_sn' => 'BEFIXTURE', 'pay_id' => 7, 'actual_price' => '7.01', 'status' => Order::STATUS_WAIT_PAY]);
        DB::table('goods')->insert(['id' => 1]); DB::table('carmis')->insert(['goods_id' => 1, 'carmi' => 'bepusdt-local-card']);
        $this->bindGatewayProcessor();
    }

    public function beforeCompletion(): void { if ($this->beforeFulfillment) ($this->beforeFulfillment)(); }
    public function notified(): void { $this->assertSame(0, DB::transactionLevel()); ++$this->notifications; }

    public function testClosedPaidGatewayStillFulfills(): void
    {
        DB::table('pays')->update(['is_open' => 0]); $response = $this->send();
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame(1, $this->notifications);
        $this->assertSame('TXN2029', Order::first()->trade_no);
        $response->assertOk();
    }

    public function testSignedForeignFiatNeverFulfills(): void
    {
        $response = $this->send(['fiat' => 'USD']);
        $this->undelivered(); $this->assertSame('fail', $response->getContent());
    }

    public function testChangedMethodAfterVerificationNeverFulfills(): void
    {
        $this->beforeFulfillment = function () { DB::table('pays')->update(['pay_check' => 'trx.tron']); };
        $response = $this->send();
        $this->undelivered(); $this->assertSame('fail', $response->getContent());
    }

    public function testPaidReceiptDeliversOnceAndAnotherReferenceFails(): void
    {
        $this->ack($this->send()); $this->ack($this->send());
        $this->ack($this->send(['trade_id' => 'DIFFERENT']), 'fail');
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('TXN2029', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_SOLD, (int) Carmis::first()->status);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(1, $this->notifications);
    }

    public function testExpiredPaidReceiptUsesManualFlowWithoutConsumingStock(): void
    {
        DB::table('orders')->update(['status' => Order::STATUS_EXPIRED]);
        $this->ack($this->send());
        $this->assertSame(Order::STATUS_ABNORMAL, (int) Order::first()->status);
        $this->assertSame('TXN2029', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
    }

    /** @dataProvider lifecycleStates */
    #[\PHPUnit\Framework\Attributes\DataProvider('lifecycleStates')]
    public function testValidLifecycleReceiptsAreAcknowledgedWithoutDelivery($state): void
    {
        $this->ack($this->send(['status' => $state])); $this->undelivered();
    }

    public static function lifecycleStates(): array { return [[1], ['1'], [3], ['3']]; }

    /** @dataProvider contexts */
    #[\PHPUnit\Framework\Attributes\DataProvider('contexts')]
    public function testVerifiedSnapshotStillMatchesInsideTransaction(string $change): void
    {
        $this->beforeFulfillment = function () use ($change) {
            if ($change === 'price') DB::table('orders')->update(['actual_price' => '7.02']);
            if ($change === 'gateway') DB::table('orders')->update(['pay_id' => 8]);
            if ($change === 'id') DB::table('pays')->update(['merchant_id' => 'changed-id']);
            if ($change === 'key') DB::table('pays')->update(['merchant_key' => 'changed-key']);
            if ($change === 'pem') DB::table('pays')->update(['merchant_pem' => 'https://changed.invalid']);
            if ($change === 'route') DB::table('pays')->update(['pay_handleroute' => '/pay/stripe']);
        };
        $this->ack($this->send(), 'fail'); $this->undelivered();
    }

    public static function contexts(): array { return [['price'], ['gateway'], ['id'], ['key'], ['pem'], ['route']]; }

    /** @dataProvider modernBindings */
    #[\PHPUnit\Framework\Attributes\DataProvider('modernBindings')]
    public function testModernBindingExcludesLegacyReceipt(string $flag, string $table): void
    {
        config(['dujiaoka.' . $flag => true]);
        \Illuminate\Support\Facades\Schema::create($table, function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->bigIncrements('id'); $t->integer('order_id'); $t->integer('pay_id');
        });
        DB::table($table)->insert(['order_id' => 1, 'pay_id' => 8]);
        $this->ack($this->send(), 'fail'); $this->undelivered();
    }

    public static function modernBindings(): array { return [['stripe_payment_intents', 'stripe_payment_intents'], ['paypal_orders_v2', 'paypal_orders']]; }

    /** @dataProvider invalidBusiness */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBusiness')]
    public function testSignedMalformedBusinessDataDoesNotDeliver(array $changes): void
    {
        $this->ack($this->send($changes), 'fail'); $this->undelivered();
    }

    public static function invalidBusiness(): array
    {
        return [[['fiat' => null]], [['fiat' => '']], [['fiat' => 'CNY ']], [['trade_type' => 'trx.tron']],
            [['trade_type' => null]], [['amount' => '7.00']], [['amount' => '7.019']], [['amount' => '7.01e0']],
            [['amount' => true]], [['amount' => '0']], [['status' => true]], [['status' => 99]], [['trade_id' => '']],
            [['trade_id' => " \t "]], [['trade_id' => true]], [['order_id' => 'missing']]];
    }

    /** @dataProvider validEchoes */
    #[\PHPUnit\Framework\Attributes\DataProvider('validEchoes')]
    public function testLegacyCnyDefaultAndValidMethodEchoesAreRetained(array $changes): void
    {
        $this->ack($this->send($changes)); $this->assertSame(1, $this->notifications);
    }

    public static function validEchoes(): array { return [[[]], [['fiat' => 'CNY']], [['fiat' => 'CNY', 'trade_type' => 'usdt.trc20']], [['status' => '2']]]; }

    /** @dataProvider metadata */
    #[\PHPUnit\Framework\Attributes\DataProvider('metadata')]
    public function testRawSignedMetadataSurvivesWhitespaceNullBooleanAndLength(array $changes): void
    {
        $this->ack($this->send($changes)); $this->assertSame(1, $this->notifications);
    }

    public static function metadata(): array
    {
        return [[['note' => "  + item \r\n  ", 'optional' => null, 'empty' => '', 'zero' => '0', 'numericZero' => 0, 'trueFlag' => true, 'falseFlag' => false]],
            [['note' => str_repeat('x', 8192)]], [['note' => str_repeat('"', 20000)]], [['note' => str_repeat('\\', 20000)]]];
    }

    /** @dataProvider badBodies */
    #[\PHPUnit\Framework\Attributes\DataProvider('badBodies')]
    public function testAmbiguousOrOversizedJsonNeverDelivers(string $variant): void
    {
        $data = $this->signed($this->fields()); $body = json_encode($data);
        if ($variant === 'duplicate') $body = substr($body, 0, -1) . ',"order_id":"BEFIXTURE"}';
        if ($variant === 'escaped') $body = substr($body, 0, -1) . ',"order_\\u0069d":"BEFIXTURE"}';
        if ($variant === 'nested') $body = substr($body, 0, -1) . ',"extra":[]}';
        if ($variant === 'tampered') $body = str_replace('7.01', '7.02', $body);
        if ($variant === 'exponent') $body = str_replace('"amount":7.01', '"amount":7.01e0', $body);
        if ($variant === 'subcent') $body = str_replace('"amount":7.01', '"amount":7.010000000000001', $body);
        if ($variant === 'huge') $body = json_encode($this->signed($this->fields() + ['note' => str_repeat('x', 65536)]));
        $this->ack($this->raw($body), 'fail'); $this->undelivered();
    }

    public static function badBodies(): array { return [['duplicate'], ['escaped'], ['nested'], ['tampered'], ['exponent'], ['subcent'], ['huge']]; }

    public function testQueryCannotOverrideTheSignedJsonBody(): void
    {
        $data = $this->signed($this->fields());
        $this->ack($this->call('POST', '/pay/bepusdt/notify_url?order_id=missing&amount=1&signature=invalid', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode($data)));
        $this->assertSame(1, $this->notifications);
    }

    public function testMalformedJsonAndUnexpectedContentTypeAreOrdinaryFailures(): void
    {
        foreach (['', '[]', '{', '{"order_id":'] as $body) { $this->ack($this->raw($body), 'fail'); $this->undelivered(); }
        $this->ack($this->call('POST', '/pay/bepusdt/notify_url', [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($this->signed($this->fields()))), 'fail');
        $this->undelivered();
    }

    public function testOriginalCryptoQuantityDoesNotReplaceFiatAmount(): void
    {
        $this->ack($this->send(['actual_amount' => 9999.25]));
        $this->assertSame('7.01', (string) Order::first()->actual_price);
    }

    public function testZeroTransactionReferenceIsPreservedAndCompared(): void
    {
        $this->ack($this->send(['trade_id' => '0'])); $this->ack($this->send(['trade_id' => '0']));
        $this->ack($this->send(['trade_id' => 'OTHER']), 'fail');
        $this->assertSame('0', Order::first()->trade_no); $this->assertSame(1, $this->notifications);
    }

    public function testGatewayDeletedAfterPaymentProducesOrdinaryFailure(): void
    {
        DB::table('pays')->delete(); $this->ack($this->send(), 'fail'); $this->undelivered();
    }

    public function testReturnHasNoPaymentSideEffectAndStillRejectsMissingOrder(): void
    {
        $this->get('/pay/bepusdt/return_url?order_id=BEFIXTURE')->assertRedirect(url('detail-order-sn', ['orderSN' => 'BEFIXTURE']));
        $this->get('/pay/bepusdt/return_url')->assertStatus(400); $this->undelivered();
    }

    /** @dataProvider checkoutModes */
    #[\PHPUnit\Framework\Attributes\DataProvider('checkoutModes')]
    public function testOriginalConfiguredModesAndEndpointFormsCreateExactSignedCheckout(string $method, string $endpoint): void
    {
        DB::table('pays')->update(['pay_check' => $method, 'merchant_pem' => $endpoint]);
        $history = []; $http = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(function ($request, $options) use ($method, &$history) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('/nested/api/v1/order/create-transaction', $request->getUri()->getPath());
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertTrue($options['verify']); $this->assertFalse($options['allow_redirects']);
            $body = (string) $request->getBody(); $data = json_decode($body, true); $history[] = $data;
            $this->assertStringContainsString('"amount":7.01', $body);
            $this->assertSame('CNY', $data['fiat']); $this->assertSame($method, $data['trade_type']);
            $this->assertSame('BEFIXTURE', $data['order_id']);
            $this->assertSame(url('pay/bepusdt/notify_url'), $data['notify_url']);
            $this->assertSame(route('bepusdt-return', ['order_id' => 'BEFIXTURE']), $data['redirect_url']);
            $this->assertSame($this->signed($data)['signature'], $data['signature']);
            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                'status_code' => 200, 'data' => ['payment_url' => 'https://checkout.invalid/pay?trade_id=TXN2029',
                    'order_id' => 'BEFIXTURE', 'amount' => '7.01', 'fiat' => 'CNY', 'trade_type' => $method, 'status' => 1]])));
        })]);
        $this->app->instance(\App\Payments\BepusdtClient::class, new \App\Payments\BepusdtClient($http));
        $precision = ini_get('serialize_precision'); ini_set('serialize_precision', '17');
        try { $this->get('/pay/bepusdt/' . $method . '/BEFIXTURE')->assertRedirect('https://checkout.invalid/pay?trade_id=TXN2029'); }
        finally { ini_set('serialize_precision', $precision); }
        $this->assertCount(1, $history); $this->undelivered();
    }

    public static function checkoutModes(): array
    {
        $rows = [];
        foreach (['usdt.trc20', 'tron.trx', 'usdc.polygon', 'custom.method'] as $method) {
            foreach (['https://bepusdt.invalid/nested', 'https://bepusdt.invalid/nested/api/v1/order/create-transaction',
                'https://bepusdt.invalid/nested/api/v1/order/create-transaction/'] as $endpoint) $rows[] = [$method, $endpoint];
        }
        return $rows;
    }

    public function testHttpFailureIsRedactedAndDoesNotReplayOrFulfill(): void
    {
        $requests = 0;
        $http = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(function () use (&$requests) {
            ++$requests;
            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(503, [], 'PRIVATE-provider-failure'));
        })]);
        $this->app->instance(\App\Payments\BepusdtClient::class, new \App\Payments\BepusdtClient($http));
        $response = $this->get('/pay/bepusdt/usdt.trc20/BEFIXTURE'); $response->assertOk();
        $this->assertStringNotContainsString(self::KEY, $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-provider-failure', $response->getContent());
        $this->assertSame(1, $requests); $this->undelivered();
    }

    private function raw(string $body)
    {
        return $this->call('POST', '/pay/bepusdt/notify_url', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }

    private function ack($response, string $expected = 'ok'): void { $response->assertOk(); $this->assertSame($expected, $response->getContent()); }

    private function fields(): array
    {
        return ['order_id' => 'BEFIXTURE', 'trade_id' => 'TXN2029', 'amount' => 7.01,
            'actual_amount' => 1.02, 'token' => 'fixture-receiving-address', 'status' => 2];
    }

    private function signed(array $fields): array
    {
        ksort($fields, SORT_STRING); $pairs = [];
        foreach ($fields as $name => $value) {
            if ($name === 'signature' || $value === '' || $value === null) continue;
            $pairs[] = $name . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : $value);
        }
        $fields['signature'] = md5(implode('&', $pairs) . self::KEY);
        return $fields;
    }

    private function send(array $changes = [])
    {
        $data = $this->signed(array_replace($this->fields(), $changes));
        return $this->call('POST', '/pay/bepusdt/notify_url', $data, [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function undelivered(): void
    {
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
        $this->assertSame('', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(0, $this->notifications);
    }
}
