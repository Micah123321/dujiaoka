<?php

namespace Tests\Feature;

use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use App\Payments\CoinbaseCommerceClient;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\GatewayTransactionFixture;
use Tests\TestCase;

class LegacyGatewayRouteTest extends TestCase
{
    use GatewayTransactionFixture;

    private const SECRET = 'fixture-shared-key';
    private $notifications = 0;
    private $beforeFulfillment;
    private $requests = [];
    private $httpError = false;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->withoutMiddleware([\App\Http\Middleware\DujiaoBoot::class, \App\Http\Middleware\InstallCheck::class]);
        config(['dujiaoka.payment_quote_freeze' => false, 'dujiaoka.paypal_orders_v2' => false,
            'dujiaoka.stripe_payment_intents' => false]);
        $this->createGatewayTables();
        Pay::forceCreate(['id' => 7, 'pay_check' => 'mwx', 'pay_handleroute' => '/pay/mapay',
            'merchant_id' => '1900000101', 'merchant_key' => 'https://mapay.invalid/create?', 'merchant_pem' => self::SECRET]);
        Order::forceCreate(['id' => 1, 'order_sn' => 'LEGACYFIXTURE', 'pay_id' => 7,
            'actual_price' => '7.01', 'status' => Order::STATUS_WAIT_PAY]);
        DB::table('goods')->insert(['id' => 1]);
        DB::table('carmis')->insert(['goods_id' => 1, 'carmi' => 'local-legacy-card']);
        $this->bindGatewayProcessor();
        $http = new Client(['handler' => HandlerStack::create(function ($request, $options) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('api.commerce.coinbase.com', $request->getUri()->getHost());
            $this->assertSame('/charges', $request->getUri()->getPath());
            $this->assertSame('api-fixture-key', $request->getHeaderLine('X-CC-Api-Key'));
            $this->assertSame('2018-03-22', $request->getHeaderLine('X-CC-Version'));
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->requests[] = json_decode((string) $request->getBody(), true);
            return Create::promiseFor(new Response($this->httpError ? 503 : 201, ['Content-Type' => 'application/json'],
                $this->httpError ? 'PRIVATE-provider-error' : json_encode(['data' => ['hosted_url' => 'https://commerce.coinbase.com/charges/TXN2026']])));
        })]);
        $this->app->instance(CoinbaseCommerceClient::class, new CoinbaseCommerceClient($http));
    }

    public function beforeCompletion(): void { if ($this->beforeFulfillment) ($this->beforeFulfillment)(); }
    public function notified(): void { $this->assertSame(0, DB::transactionLevel()); ++$this->notifications; }

    public function testMapayRecordsActualProviderTransactionAndRejectsAnotherReceipt(): void
    {
        $this->sendMapay()->assertOk()->assertSeeText('success');
        $this->assertSame('TXN2026', Order::first()->trade_no);
        $this->sendMapay(['pay_no' => 'DIFFERENT'])->assertOk()->assertSeeText('fail');
        $this->assertSame('TXN2026', Order::first()->trade_no);
        $this->assertSame(1, $this->notifications);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
    }

    public function testCoinbaseConfirmedPaymentIgnoresPendingTail(): void
    {
        $this->coinbase();
        $this->ack($this->sendCoinbase([['status' => 'CONFIRMED', 'value' => ['local' => ['amount' => '7.01', 'currency' => 'CNY']]],
            ['status' => 'PENDING']]), 'coinbase', true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('TXN2026', Order::first()->trade_no);
        $this->assertSame(1, $this->notifications);
    }

    public static function providers(): array { return [['mapay'], ['coinbase']]; }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testDuplicateSignedReceiptFulfillsOnce(string $provider): void
    {
        if ($provider === 'coinbase') $this->coinbase();
        $this->ack($this->notify($provider), $provider, true);
        $this->ack($this->notify($provider), $provider, true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('TXN2026', Order::first()->trade_no);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(Carmis::STATUS_SOLD, (int) Carmis::first()->status);
        $this->assertSame(1, $this->notifications);
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testClosedGatewayRetainsAlreadyPaidReceipt(string $provider): void
    {
        if ($provider === 'coinbase') $this->coinbase();
        DB::table('pays')->update(['is_open' => 0]);
        $this->ack($this->notify($provider), $provider, true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testExpiredReceiptGoesToManualHandlingWithoutStockConsumption(string $provider): void
    {
        if ($provider === 'coinbase') $this->coinbase();
        DB::table('orders')->update(['status' => Order::STATUS_EXPIRED]);
        $this->ack($this->notify($provider), $provider, true);
        $this->assertSame(Order::STATUS_ABNORMAL, (int) Order::first()->status);
        $this->assertSame('TXN2026', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
    }

    /** @dataProvider changedContexts */
    #[\PHPUnit\Framework\Attributes\DataProvider('changedContexts')]
    public function testVerifiedAccountContextIsLockedAtFulfillment(string $provider, string $change): void
    {
        if ($provider === 'coinbase') $this->coinbase();
        $this->beforeFulfillment = function () use ($change) {
            if ($change === 'price') DB::table('orders')->update(['actual_price' => '7.02']);
            if ($change === 'gateway') DB::table('orders')->update(['pay_id' => 8]);
            if ($change === 'key') DB::table('pays')->update(['merchant_key' => 'changed-api-key']);
            if ($change === 'secret') DB::table('pays')->update(['merchant_pem' => 'changed-secret']);
            if ($change === 'merchant') DB::table('pays')->update(['merchant_id' => '0.06']);
            if ($change === 'route') DB::table('pays')->update(['pay_handleroute' => 'pay/stripe']);
            if ($change === 'method') DB::table('pays')->update(['pay_check' => 'different-method']);
        };
        $this->ack($this->notify($provider), $provider, false);
        $this->undelivered();
    }

    public static function changedContexts(): array
    {
        $cases = [];
        foreach (['mapay', 'coinbase'] as $provider) foreach (['price', 'gateway', 'key', 'secret', 'merchant', 'route', 'method'] as $change) $cases[] = [$provider, $change];
        return $cases;
    }

    /** @dataProvider modernBindings */
    #[\PHPUnit\Framework\Attributes\DataProvider('modernBindings')]
    public function testAnotherModernBindingStopsLegacyReceipt(string $provider, string $flag, string $table): void
    {
        if ($provider === 'coinbase') $this->coinbase();
        config(['dujiaoka.' . $flag => true]);
        \Illuminate\Support\Facades\Schema::create($table, function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->bigIncrements('id'); $t->integer('order_id'); $t->integer('pay_id');
        });
        DB::table($table)->insert(['order_id' => 1, 'pay_id' => 8]);
        $this->ack($this->notify($provider), $provider, false);
        $this->undelivered();
    }

    public static function modernBindings(): array
    {
        $cases = [];
        foreach (['mapay', 'coinbase'] as $provider) foreach (['stripe_payment_intents' => 'stripe_payment_intents', 'paypal_orders_v2' => 'paypal_orders'] as $flag => $table) $cases[] = [$provider, $flag, $table];
        return $cases;
    }

    /** @dataProvider invalidMapayReceipts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMapayReceipts')]
    public function testSignedInvalidMapayReceiptDoesNotFulfill(array $changes): void
    {
        $this->ack($this->sendMapay($changes), 'mapay', false);
        $this->undelivered();
    }

    public static function invalidMapayReceipts(): array
    {
        return [[['id' => '1900000102']], [['type' => '2']], [['param' => 'mqq']], [['money' => '7.00']],
            [['money' => '7.019']], [['money' => '7.01e0']], [['pay_no' => '']], [['pay_id' => 'UNKNOWN']]];
    }

    public function testMapayOriginalFormValuesAndPollution(): void
    {
        $this->ack($this->sendMapay(['extra' => "  a + b\r\n  "]), 'mapay', true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->ack($this->sendMapay([], '&pay%5Fid=LEGACYFIXTURE'), 'mapay', false);
        $this->assertSame(1, $this->notifications);
    }

    public function testCoinbaseDifferentChargeOnCompletedOrderFails(): void
    {
        $this->coinbase();
        $payments = $this->confirmed();
        $this->ack($this->sendCoinbase($payments), 'coinbase', true);
        $this->ack($this->sendCoinbase($payments, ['code' => 'DIFFERENT']), 'coinbase', false);
        $this->assertSame('TXN2026', Order::first()->trade_no);
        $this->assertSame(1, $this->notifications);
    }

    public function testCoinbasePendingHeadDoesNotAffectResolvedPayment(): void
    {
        $this->coinbase();
        $this->ack($this->sendCoinbase([['status' => 'PENDING'],
            ['status' => 'resolved', 'value' => ['local' => ['amount' => '7.3605', 'currency' => 'CNY']]]]), 'coinbase', true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('7.01', (string) Order::first()->actual_price);
    }

    /** @dataProvider invalidCoinbaseReceipts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCoinbaseReceipts')]
    public function testSignedButInsufficientCoinbasePaymentDoesNotFulfill(array $payments): void
    {
        $this->coinbase();
        $this->ack($this->sendCoinbase($payments), 'coinbase', false);
        $this->undelivered();
    }

    public static function invalidCoinbaseReceipts(): array
    {
        $cases = [];
        foreach ([['PENDING', '7.01', 'CNY'], ['confirmed', '7.01', 'USD'], ['confirmed', '7.00', 'CNY'],
            ['confirmed', '7.009', 'CNY'], ['confirmed', '7.009999999999999999999', 'CNY'],
            ['confirmed', str_repeat('9', 129), 'CNY'], ['confirmed', '7.01e0', 'CNY'], ['confirmed', 'NaN', 'CNY']] as [$status, $amount, $currency]) {
            $cases[] = [[['status' => $status, 'value' => ['local' => ['amount' => $amount, 'currency' => $currency]]]]];
        }
        $cases[] = [[['status' => 'confirmed', 'value' => ['local' => ['amount' => '3.00', 'currency' => 'CNY']]],
            ['status' => 'confirmed', 'value' => ['local' => ['amount' => '4.01', 'currency' => 'CNY']]]]];
        return $cases;
    }

    public function testCoinbaseRawSignatureIgnoresQueryAndRejectsTamperedBody(): void
    {
        $this->coinbase();
        $this->ack($this->sendCoinbase($this->confirmed(), [], true), 'coinbase', false);
        $this->undelivered();
        $this->ack($this->sendCoinbase($this->confirmed(), ['metadata' => ['customer_id' => 'LEGACYFIXTURE', 'customer_name' => "  item + \r\n  "]]), 'coinbase', true);
        $this->assertSame(1, $this->notifications);
    }

    public function testCoinbaseCheckoutUsesExactOriginalFeeWithoutFulfillment(): void
    {
        $this->coinbase();
        $this->get('/pay/coinbase/coinbase/LEGACYFIXTURE')->assertRedirect('https://commerce.coinbase.com/charges/TXN2026');
        $this->assertCount(1, $this->requests);
        $this->assertSame('LEGACYFIXTURE', $this->requests[0]['metadata']['customer_id']);
        $this->assertSame('CNY', $this->requests[0]['local_price']['currency']);
        $this->assertSame('7.3605', $this->requests[0]['local_price']['amount']);
        $this->assertSame('fixed_price', $this->requests[0]['pricing_type']);
        $this->undelivered();
    }

    public function testCoinbaseCheckoutFailureDoesNotExposePrivateProviderResponse(): void
    {
        $this->coinbase();
        $this->httpError = true;
        $this->get('/pay/coinbase/coinbase/LEGACYFIXTURE')->assertOk()
            ->assertSeeText(__('dujiaoka.prompt.abnormal_payment_channel'))->assertDontSee('PRIVATE-provider-error');
        $this->assertCount(1, $this->requests);
        $this->undelivered();
    }

    /** @dataProvider mapayModes */
    #[\PHPUnit\Framework\Attributes\DataProvider('mapayModes')]
    public function testMapayCheckoutModesRetainSignedBrowserRedirect(string $mode, string $type): void
    {
        DB::table('pays')->update(['pay_check' => $mode]);
        $response = $this->get('/pay/mapay/' . $mode . '/LEGACYFIXTURE')->assertStatus(302);
        $url = $response->headers->get('Location');
        $this->assertSame('mapay.invalid', parse_url($url, PHP_URL_HOST));
        parse_str(parse_url($url, PHP_URL_QUERY), $fields);
        $signature = $fields['sign']; unset($fields['sign']);
        ksort($fields, SORT_STRING);
        $parts = [];
        foreach ($fields as $key => $value) $parts[] = $key . '=' . $value;
        $this->assertSame(md5(implode('&', $parts) . self::SECRET), $signature);
        $this->assertSame('7.01', $fields['price']);
        $this->assertSame('LEGACYFIXTURE', $fields['pay_id']);
        $this->assertSame($mode, $fields['param']);
        $this->assertSame($type, $fields['type']);
        $this->assertArrayNotHasKey('act', $fields);
        $this->assertArrayNotHasKey('pay_type', $fields);
        $this->assertArrayNotHasKey('key', $fields);
        $this->assertCount(0, $this->requests);
        $this->undelivered();
    }

    public static function mapayModes(): array { return [['mqq', '2'], ['mzfb', '1'], ['mwx', '3']]; }

    /** @dataProvider highPrecisionPayments */
    #[\PHPUnit\Framework\Attributes\DataProvider('highPrecisionPayments')]
    public function testHighPrecisionConfirmedAmountPreservesSufficientPaymentPolicy(string $amount): void
    {
        $this->coinbase();
        $this->ack($this->sendCoinbase([['status' => 'confirmed', 'value' => ['local' => ['amount' => $amount, 'currency' => 'CNY']]]]), 'coinbase', true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('TXN2026', Order::first()->trade_no);
    }

    public static function highPrecisionPayments(): array
    {
        return [['7.0100000000000'], ['7.0100000000701'], ['7.010000000000000001']];
    }

    private function confirmed(): array
    {
        return [['status' => 'CONFIRMED', 'value' => ['local' => ['amount' => '7.01', 'currency' => 'CNY']]]];
    }

    private function notify(string $provider)
    {
        return $provider === 'mapay' ? $this->sendMapay() : $this->sendCoinbase($this->confirmed());
    }

    private function ack($response, string $provider, bool $success): void
    {
        $response->assertOk();
        $this->assertSame($success ? ($provider === 'mapay' ? 'success' : '{"status": 200}') : 'fail', $response->getContent());
    }

    private function undelivered(): void
    {
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
        $this->assertSame('', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(0, $this->notifications);
    }

    private function coinbase(): void
    {
        DB::table('pays')->update(['pay_check' => 'coinbase', 'pay_handleroute' => 'pay/coinbase',
            'merchant_id' => '0.05', 'merchant_key' => 'api-fixture-key']);
    }

    private function sendMapay(array $changes = [], string $extra = '')
    {
        $fields = array_replace(['id' => '1900000101', 'pay_id' => 'LEGACYFIXTURE', 'pay_no' => 'TXN2026',
            'money' => '7.01', 'type' => '3', 'param' => 'mwx'], $changes);
        ksort($fields, SORT_STRING);
        $parts = [];
        foreach ($fields as $key => $value) if ($value !== '') $parts[] = $key . '=' . $value;
        $fields['sign'] = md5(implode('&', $parts) . self::SECRET);
        return $this->call('POST', '/pay/mapay/notify_url', $fields, [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields, '', '&') . $extra);
    }

    private function sendCoinbase(array $payments, array $changes = [], bool $tamper = false)
    {
        $data = array_replace(['metadata' => ['customer_id' => 'LEGACYFIXTURE'], 'code' => 'TXN2026', 'payments' => $payments], $changes);
        $body = json_encode(['event' => ['type' => 'charge:confirmed', 'data' => $data]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, self::SECRET);
        if ($tamper) $body = str_replace('7.01', '7.02', $body);
        return $this->call('POST', '/pay/coinbase/notify_url?ignored=query', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CC_WEBHOOK_SIGNATURE' => $signature], $body);
    }
}
