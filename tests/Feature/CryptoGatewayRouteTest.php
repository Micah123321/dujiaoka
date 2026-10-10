<?php

namespace Tests\Feature;

use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\GatewayTransactionFixture;
use Tests\TestCase;

class CryptoGatewayRouteTest extends TestCase
{
    use GatewayTransactionFixture;

    private const SECRET = 'fixture-crypto-key';
    private $notifications = 0;
    private $beforeFulfillment;
    private $requests = [];
    private $httpError = false;

    protected function setUp(): void
    {
        parent::setUp(); Event::fake();
        $this->withoutMiddleware([\App\Http\Middleware\DujiaoBoot::class, \App\Http\Middleware\InstallCheck::class]);
        config(['dujiaoka.payment_quote_freeze' => false, 'dujiaoka.paypal_orders_v2' => false, 'dujiaoka.stripe_payment_intents' => false]);
        $this->createGatewayTables();
        Pay::forceCreate(['id' => 7, 'pay_check' => 'epusdt', 'pay_handleroute' => 'pay/epusdt',
            'merchant_id' => self::SECRET, 'merchant_key' => '', 'merchant_pem' => 'https://epusdt.invalid/api/v1/order/create-transaction']);
        Order::forceCreate(['id' => 1, 'order_sn' => 'CRYPTOFIXTURE', 'pay_id' => 7, 'actual_price' => '7.01', 'status' => Order::STATUS_WAIT_PAY]);
        DB::table('goods')->insert(['id' => 1]);
        DB::table('carmis')->insert(['goods_id' => 1, 'carmi' => 'local-crypto-card']);
        $this->bindGatewayProcessor();
        $http = new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(function ($request, $options) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertTrue($options['verify']); $this->assertFalse($options['allow_redirects']);
            $data = json_decode((string) $request->getBody(), true);
            $this->requests[] = ['body' => (string) $request->getBody(), 'data' => $data];
            if ($request->getUri()->getHost() === 'epusdt.invalid') {
                $this->assertSame('/api/v1/order/create-transaction', $request->getUri()->getPath());
                $response = ['status_code' => 200, 'data' => ['payment_url' => 'https://checkout.invalid/pay/TXN2028',
                    'order_id' => 'CRYPTOFIXTURE', 'amount' => 7.01]];
            } else {
                $this->assertSame('tokenpay.invalid', $request->getUri()->getHost());
                $this->assertSame('/nested/CreateOrder', $request->getUri()->getPath());
                $response = ['success' => true, 'data' => 'https://checkout.invalid/pay/TXN2028', 'info' => [
                    'OutOrderId' => 'CRYPTOFIXTURE', 'ActualAmount' => '7.01', 'BaseCurrency' => 'CNY', 'Currency' => $data['Currency']]];
            }
            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response($this->httpError ? 503 : 200,
                ['Content-Type' => 'application/json'], $this->httpError ? 'PRIVATE-provider-failure' : json_encode($response)));
        })]);
        $this->app->instance(\App\Payments\EpusdtLegacyClient::class, new \App\Payments\EpusdtLegacyClient($http));
        $this->app->instance(\App\Payments\TokenPayLegacyClient::class, new \App\Payments\TokenPayLegacyClient($http));
    }

    public function beforeCompletion(): void { if ($this->beforeFulfillment) ($this->beforeFulfillment)(); }
    public function notified(): void { $this->assertSame(0, DB::transactionLevel()); ++$this->notifications; }

    /** @dataProvider unpaidStates */
    #[\PHPUnit\Framework\Attributes\DataProvider('unpaidStates')]
    public function testSignedUnpaidOrExpiredStateDoesNotFulfill(string $provider, $state): void
    {
        $this->select($provider);
        $this->ack($this->send($provider, [$provider === 'epusdt' ? 'status' : 'Status' => $state]), false);
        $this->undelivered();
    }

    public static function unpaidStates(): array { return [['epusdt', 1], ['epusdt', 3], ['tokenpay', 0], ['tokenpay', 2]]; }

    public static function providers(): array { return [['epusdt'], ['tokenpay']]; }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testPaidReceiptFulfillsOnceAndAnotherReferenceFails(string $provider): void
    {
        $this->select($provider);
        $this->ack($this->send($provider), true); $this->ack($this->send($provider), true);
        $this->ack($this->send($provider, [$provider === 'epusdt' ? 'trade_id' : 'Id' => 'DIFFERENT']), false);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('TXN2028', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_SOLD, (int) Carmis::first()->status);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(1, $this->notifications);
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testClosedPaidGatewayStillRecoversReceipt(string $provider): void
    {
        $this->select($provider); DB::table('pays')->update(['is_open' => 0]);
        $this->ack($this->send($provider), true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testExpiredOrderIsRecordedForManualHandlingWithoutConsumingStock(string $provider): void
    {
        $this->select($provider); DB::table('orders')->update(['status' => Order::STATUS_EXPIRED]);
        $this->ack($this->send($provider), true);
        $this->assertSame(Order::STATUS_ABNORMAL, (int) Order::first()->status);
        $this->assertSame('TXN2028', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
    }

    /** @dataProvider changedContexts */
    #[\PHPUnit\Framework\Attributes\DataProvider('changedContexts')]
    public function testVerifiedContextMustStillMatchInsideRealTransaction(string $provider, string $change): void
    {
        $this->select($provider);
        $this->beforeFulfillment = function () use ($change) {
            if ($change === 'price') DB::table('orders')->update(['actual_price' => '7.02']);
            if ($change === 'gateway') DB::table('orders')->update(['pay_id' => 8]);
            if ($change === 'id') DB::table('pays')->update(['merchant_id' => 'changed-id']);
            if ($change === 'key') DB::table('pays')->update(['merchant_key' => 'changed-key']);
            if ($change === 'pem') DB::table('pays')->update(['merchant_pem' => 'https://changed.invalid']);
            if ($change === 'route') DB::table('pays')->update(['pay_handleroute' => '/pay/stripe']);
            if ($change === 'method') DB::table('pays')->update(['pay_check' => 'different-method']);
        };
        $this->ack($this->send($provider), false); $this->undelivered();
    }

    public static function changedContexts(): array
    {
        $cases = [];
        foreach (['epusdt', 'tokenpay'] as $provider) foreach (['price', 'gateway', 'id', 'key', 'pem', 'route', 'method'] as $change) $cases[] = [$provider, $change];
        return $cases;
    }

    /** @dataProvider modernBindings */
    #[\PHPUnit\Framework\Attributes\DataProvider('modernBindings')]
    public function testExistingModernBindingExcludesAnotherLegacyReceipt(string $provider, string $flag, string $table): void
    {
        $this->select($provider); config(['dujiaoka.' . $flag => true]);
        \Illuminate\Support\Facades\Schema::create($table, function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->bigIncrements('id'); $t->integer('order_id'); $t->integer('pay_id');
        });
        DB::table($table)->insert(['order_id' => 1, 'pay_id' => 8]);
        $this->ack($this->send($provider), false); $this->undelivered();
    }

    public static function modernBindings(): array
    {
        $cases = [];
        foreach (['epusdt', 'tokenpay'] as $provider) foreach (['stripe_payment_intents' => 'stripe_payment_intents', 'paypal_orders_v2' => 'paypal_orders'] as $flag => $table) $cases[] = [$provider, $flag, $table];
        return $cases;
    }

    /** @dataProvider invalidBusiness */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBusiness')]
    public function testSignedInvalidFiatAssetOrReferenceNeverFulfills(string $provider, array $changes): void
    {
        $this->select($provider); $this->ack($this->send($provider, $changes), false); $this->undelivered();
    }

    public static function invalidBusiness(): array
    {
        $cases = [['epusdt', ['trade_id' => '']], ['tokenpay', ['Id' => '']], ['tokenpay', ['BaseCurrency' => 'USD']],
            ['tokenpay', ['Currency' => 'USDT_TRC20']], ['tokenpay', ['BaseCurrency' => null]], ['tokenpay', ['Currency' => null]],
            ['epusdt', ['status' => true]], ['tokenpay', ['Status' => true]]];
        foreach (['epusdt', 'tokenpay'] as $provider) foreach (['7.00', '7.019', '7.01e0', '0', '-7.01'] as $amount) $cases[] = [$provider, [$provider === 'epusdt' ? 'amount' : 'ActualAmount' => $amount]];
        return $cases;
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testRawJsonSignaturePreservesOpaqueWhitespaceAndIgnoresQuery(string $provider): void
    {
        $this->select($provider);
        $this->ack($this->send($provider, ['metadata' => "  item + \r\n  ", 'zero' => '0', 'empty' => '', 'optional' => null], '?ignored=unsigned'), true);
        $this->assertSame(1, $this->notifications);
    }

    /** @dataProvider longMetadata */
    #[\PHPUnit\Framework\Attributes\DataProvider('longMetadata')]
    public function testLargeSignedMetadataWithinBodyLimitStillFulfills(string $provider, string $text): void
    {
        $this->select($provider);
        $this->ack($this->send($provider, [$provider === 'epusdt' ? 'metadata' : 'PassThroughInfo' => $text]), true);
        $this->assertSame(1, $this->notifications);
    }

    public static function longMetadata(): array
    {
        $cases = [];
        foreach (['epusdt', 'tokenpay'] as $provider) foreach ([str_repeat('x', 8192), str_repeat('"', 20000), str_repeat('\\', 20000)] as $text) $cases[] = [$provider, $text];
        return $cases;
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testDuplicateEscapedNestedAndTamperedJsonNeverFulfills(string $provider): void
    {
        $this->select($provider);
        $fields = $this->sign($provider, $this->fields($provider));
        $body = json_encode($fields); $reference = $provider === 'epusdt' ? 'order_id' : 'OutOrderId';
        foreach ([substr($body, 0, -1) . ',"' . $reference . '":"CRYPTOFIXTURE"}',
            substr($body, 0, -1) . ',"' . substr($reference, 0, -1) . '\\u' . sprintf('%04x', ord(substr($reference, -1))) . '":"CRYPTOFIXTURE"}',
            substr($body, 0, -1) . ',"extra":[]}', str_replace('7.01', '7.02', $body)] as $changed) {
            $this->ack($this->raw($provider, $changed), false); $this->undelivered();
        }
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testRawNumericExponentAndSubcentAreRejectedBeforeFloatConversion(string $provider): void
    {
        $this->select($provider);
        $fields = $this->sign($provider, $this->fields($provider));
        $name = $provider === 'epusdt' ? 'amount' : 'ActualAmount';
        $body = json_encode($fields);
        $needle = '"' . $name . '":' . ($provider === 'epusdt' ? '7.01' : '"7.01"');
        foreach (['7.01e0', '7.010000000000001'] as $number) {
            $this->ack($this->raw($provider, str_replace($needle, '"' . $name . '":' . $number, $body)), false);
            $this->undelivered();
        }
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testNumericStringStateAndLegacyOptionalMetadataRemainCompatible(string $provider): void
    {
        $this->select($provider); $changes = [$provider === 'epusdt' ? 'status' : 'Status' => $provider === 'epusdt' ? '2' : '1'];
        if ($provider === 'tokenpay') $changes += ['OrderUserKey' => null, 'PassThroughInfo' => ''];
        $this->ack($this->send($provider, $changes), true);
        $this->assertSame(1, $this->notifications);
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testUntrustedReturnPageOnlyRedirects(string $provider): void
    {
        $this->select($provider);
        $this->get('/pay/' . $provider . '/return_url?order_id=CRYPTOFIXTURE')->assertRedirect(url('detail-order-sn', ['orderSN' => 'CRYPTOFIXTURE']));
        $this->undelivered();
    }

    /** @dataProvider checkoutModes */
    #[\PHPUnit\Framework\Attributes\DataProvider('checkoutModes')]
    public function testOriginalApiCheckoutModesUseNumericFiatAndSelectedCrypto(string $provider, string $mode, string $currency): void
    {
        $this->select($provider);
        if ($provider === 'tokenpay') DB::table('pays')->update(['pay_check' => $mode, 'merchant_id' => $currency]);
        $this->get('/pay/' . $provider . '/' . $mode . '/CRYPTOFIXTURE')->assertRedirect('https://checkout.invalid/pay/TXN2028');
        $this->assertCount(1, $this->requests); $fields = $this->requests[0]['data'];
        $amountName = $provider === 'epusdt' ? 'amount' : 'ActualAmount';
        $this->assertStringContainsString('"' . $amountName . '":7.01', $this->requests[0]['body']);
        $this->assertSame(7.01, $fields[$amountName]);
        $signatureName = $provider === 'epusdt' ? 'signature' : 'Signature';
        $expected = $this->sign($provider, $fields);
        $this->assertSame($expected[$signatureName], $fields[$signatureName]);
        $this->assertSame('CRYPTOFIXTURE', $fields[$provider === 'epusdt' ? 'order_id' : 'OutOrderId']);
        if ($provider === 'tokenpay') {
            $this->assertSame($currency, $fields['Currency']);
            $email = (string) Order::first()->email;
            $this->assertSame(trim($email) === '' ? 'CRYPTOFIXTURE' : $email, $fields['OrderUserKey']);
            $this->assertNotSame('', $fields['OrderUserKey']);
        }
        $this->undelivered();
    }

    public static function checkoutModes(): array
    {
        return [['epusdt', 'epusdt', ''], ['tokenpay', 'tokenpay-trx', 'TRX'], ['tokenpay', 'tokenpay-usdt-trc', 'USDT_TRC20'],
            ['tokenpay', 'tokenpay-eth', 'EVM_ETH_ETH'], ['tokenpay', 'tokenpay-usdt-eth', 'EVM_ETH_USDT_ERC20'],
            ['tokenpay', 'tokenpay-usdc-eth', 'EVM_ETH_USDC_ERC20'], ['tokenpay', 'tokenpay-bnb', 'EVM_BSC_BNB'],
            ['tokenpay', 'tokenpay-usdt-bsc', 'EVM_BSC_USDT_BEP20'], ['tokenpay', 'tokenpay-usdc-bsc', 'EVM_BSC_USDC_BEP20'],
            ['tokenpay', 'tokenpay-matic', 'EVM_Polygon_MATIC'], ['tokenpay', 'tokenpay-usdt-polygon', 'EVM_Polygon_USDT_ERC20'],
            ['tokenpay', 'tokenpay-usdc-polygon', 'EVM_Polygon_USDC_ERC20']];
    }

    /** @dataProvider emailKeys */
    #[\PHPUnit\Framework\Attributes\DataProvider('emailKeys')]
    public function testOriginalApiHasRequiredUserKeyForOptionalShopEmail(string $email): void
    {
        DB::table('orders')->update(['email' => $email]);
        $this->testOriginalApiCheckoutModesUseNumericFiatAndSelectedCrypto('tokenpay', 'tokenpay-trx', 'TRX');
    }

    public static function emailKeys(): array { return [['buyer@fixture.invalid'], [" \t "]]; }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testCheckoutFailureRedactsProviderBody(string $provider): void
    {
        $this->select($provider); $this->httpError = true;
        $this->get('/pay/' . $provider . '/' . ($provider === 'epusdt' ? 'epusdt' : 'tokenpay-trx') . '/CRYPTOFIXTURE')
            ->assertOk()->assertSeeText(__('dujiaoka.prompt.abnormal_payment_channel'))->assertDontSee('PRIVATE-provider-failure');
        $this->assertCount(1, $this->requests); $this->undelivered();
    }

    public function testCryptoQuantitiesNeverReplaceAuthenticatedFiat(): void
    {
        $this->select('tokenpay');
        $this->ack($this->send('tokenpay', ['Amount' => '999.999999', 'PayAmount' => '999.999999']), true);
        $this->assertSame('7.01', (string) Order::first()->actual_price);
        $this->assertSame(1, $this->notifications);
    }

    private function raw(string $provider, string $body)
    {
        return $this->call('POST', '/pay/' . $provider . '/notify_url', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
    }

    private function select(string $provider): void
    {
        if ($provider === 'tokenpay') DB::table('pays')->update(['pay_check' => 'tokenpay-trx', 'pay_handleroute' => 'pay/tokenpay',
            'merchant_id' => 'TRX', 'merchant_key' => self::SECRET, 'merchant_pem' => 'https://tokenpay.invalid/nested']);
    }

    private function fields(string $provider): array
    {
        if ($provider === 'epusdt') return ['order_id' => 'CRYPTOFIXTURE', 'trade_id' => 'TXN2028', 'amount' => 7.01,
            'actual_amount' => 1.02, 'token' => 'fixture-receiving-address', 'status' => 2];
        return ['OutOrderId' => 'CRYPTOFIXTURE', 'Id' => 'TXN2028', 'ActualAmount' => '7.01', 'Amount' => '20.001',
            'BaseCurrency' => 'CNY', 'Currency' => 'TRX', 'Status' => 1];
    }

    private function sign(string $provider, array $fields): array
    {
        ksort($fields, SORT_STRING); $parts = [];
        foreach ($fields as $name => $value) {
            if ($name === ($provider === 'epusdt' ? 'signature' : 'Signature')) continue;
            if ($provider === 'epusdt' && ($value === '' || $value === null || $value === false || $value === 0 || $value === 0.0)) continue;
            $parts[] = $name . '=' . $value;
        }
        $fields[$provider === 'epusdt' ? 'signature' : 'Signature'] = md5(implode('&', $parts) . self::SECRET);
        return $fields;
    }

    private function send(string $provider, array $changes = [], string $query = '')
    {
        $fields = $this->sign($provider, array_replace($this->fields($provider), $changes));
        return $this->call('POST', '/pay/' . $provider . '/notify_url' . $query, $fields, [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function ack($response, bool $success): void
    {
        $response->assertOk();
        $this->assertSame($success ? 'ok' : 'fail', $response->getContent());
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
