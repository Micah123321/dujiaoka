<?php

namespace Tests\Feature;

use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use App\Payments\AlipayClient;
use App\Payments\WechatV2Client;
use App\Payments\PayjsClient;
use App\Service\OrderProcessService;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Concerns\GatewayTransactionFixture;

class CnyGatewayRouteTest extends TestCase
{
    use GatewayTransactionFixture;
    private static $rsa;
    private $requests = [];
    private $notifications = 0;
    private $beforeFulfillment;
    private $httpError = false;
    private const SECRET = '0123456789ABCDEF0123456789ABCDEF';

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->withoutMiddleware([\App\Http\Middleware\DujiaoBoot::class, \App\Http\Middleware\InstallCheck::class]);
        config(['dujiaoka.payment_quote_freeze' => false, 'dujiaoka.paypal_orders_v2' => false,
            'dujiaoka.stripe_payment_intents' => false]);
        if (self::$rsa === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA,
                'config' => base_path('tests/Fixtures/cny-openssl.cnf')]);
            $this->assertNotFalse($key);
            openssl_pkey_export($key, $private, null, ['config' => base_path('tests/Fixtures/cny-openssl.cnf')]);
            self::$rsa = [$private, openssl_pkey_get_details($key)['key']];
        }
        $this->createGatewayTables();
        Pay::forceCreate(['id' => 7, 'pay_check' => 'alipayscan', 'pay_handleroute' => '/pay/alipay',
            'merchant_id' => '2026010100000001', 'merchant_key' => self::$rsa[1], 'merchant_pem' => self::$rsa[0]]);
        Order::forceCreate(['id' => 1, 'order_sn' => 'CNYFIXTURE', 'pay_id' => 7, 'actual_price' => '7.01', 'status' => Order::STATUS_WAIT_PAY]);
        DB::table('goods')->insert(['id' => 1]);
        DB::table('carmis')->insert(['goods_id' => 1, 'carmi' => 'local-cny-card']);
        $this->bindGatewayProcessor();
        $http = new Client(['handler' => HandlerStack::create(function ($request, $options) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('POST', $request->getMethod());
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            if ($request->getUri()->getHost() === 'openapi.alipay.com') {
                parse_str((string) $request->getBody(), $data);
                $signature = base64_decode($data['sign']);
                unset($data['sign']);
                $this->assertSame(1, openssl_verify($this->canonical($data), $signature, self::$rsa[1], OPENSSL_ALGO_SHA256));
                $this->assertArrayNotHasKey('app_cert_sn', $data);
                $this->assertArrayNotHasKey('alipay_root_cert_sn', $data);
                $this->requests[] = json_decode($data['biz_content'], true);
                $result = ['code' => '10000', 'msg' => 'Success', 'out_trade_no' => 'CNYFIXTURE', 'qr_code' => 'https://qr.alipay.com/baxFIXTURE'];
                openssl_sign(json_encode($result, JSON_UNESCAPED_UNICODE), $sign, self::$rsa[0], OPENSSL_ALGO_SHA256);
                $body = json_encode(['alipay_trade_precreate_response' => $result, 'sign' => base64_encode($sign)], JSON_UNESCAPED_UNICODE);
                $type = 'application/json';
            } elseif ($request->getUri()->getHost() === 'payjs.cn') {
                $this->assertSame('/api/native', $request->getUri()->getPath());
                parse_str((string) $request->getBody(), $data);
                $signature = $data['sign']; unset($data['sign']);
                $this->assertSame($this->payjsSign($data), $signature);
                $this->requests[] = $data;
                $result = ['return_code' => 1, 'total_fee' => 701, 'out_trade_no' => 'CNYFIXTURE',
                    'payjs_order_id' => 'TXN2026010101', 'code_url' => 'weixin://wxpay/bizpayurl?pr=FIXTURE'];
                $result['sign'] = $this->payjsSign($result);
                $body = json_encode($result); $type = 'application/json';
            } else {
                $this->assertSame('api.mch.weixin.qq.com', $request->getUri()->getHost());
                $this->assertSame('/pay/unifiedorder', $request->getUri()->getPath());
                $data = [];
                foreach (simplexml_load_string((string) $request->getBody())->children() as $name => $value) $data[$name] = (string) $value;
                $sign = $data['sign']; unset($data['sign']);
                $this->assertSame(strtoupper(md5($this->canonical($data) . '&key=' . self::SECRET)), $sign);
                $this->requests[] = $data;
                $result = ['return_code' => 'SUCCESS', 'result_code' => 'SUCCESS', 'appid' => 'wxfixture', 'mch_id' => '1900000101',
                    'trade_type' => 'NATIVE', 'code_url' => 'weixin://wxpay/bizpayurl?pr=FIXTURE', 'nonce_str' => 'fixtureNonce'];
                $result['sign'] = strtoupper(md5($this->canonical($result) . '&key=' . self::SECRET));
                $body = $this->xml($result); $type = 'application/xml';
            }
            return Create::promiseFor(new Response($this->httpError ? 503 : 200, ['Content-Type' => $type], $this->httpError ? 'PRIVATE-provider-error' : $body));
        })]);
        $this->app->instance(AlipayClient::class, new AlipayClient($http));
        $this->app->instance(WechatV2Client::class, new WechatV2Client($http));
        $this->app->instance(PayjsClient::class, new PayjsClient($http));
    }

    public function beforeCompletion(): void { if ($this->beforeFulfillment) ($this->beforeFulfillment)(); }
    public function notified(): void { $this->assertSame(0, DB::transactionLevel()); ++$this->notifications; }
    public static function providers(): array { return [['alipay'], ['wepay'], ['payjs']]; }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testSignedReceiptAndDuplicateRunActualFulfillmentOnlyOnce(string $provider): void
    {
        $this->select($provider);
        $this->notify($provider)->assertOk();
        $this->notify($provider)->assertOk();
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('TXN2026010101', Order::first()->trade_no);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(Carmis::STATUS_SOLD, (int) Carmis::first()->status);
        $this->assertSame(1, $this->notifications);
        $this->assertCount(0, $this->requests);
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testTamperingFailsRealSignatureBeforeFulfillment(string $provider): void
    {
        $this->select($provider);
        $payload = $this->signed($provider, $this->receipt($provider));
        $payload[$provider === 'alipay' ? 'total_amount' : 'total_fee'] = '1';
        $this->assertAck($this->send($provider, $payload), $provider, false);
        $this->undelivered();
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testAlreadyCompletedOrderRejectsAnotherProviderTransaction(string $provider): void
    {
        $this->select($provider);
        $this->notify($provider);
        $this->assertAck($this->notify($provider, [$provider === 'alipay' ? 'trade_no' : ($provider === 'wepay' ? 'transaction_id' : 'payjs_order_id') => 'DIFFERENT']), $provider, false);
        $this->assertSame('TXN2026010101', Order::first()->trade_no);
        $this->assertSame(1, $this->notifications);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testClosedGatewayStillConfirmsAnAlreadyPaidReceipt(string $provider): void
    {
        $this->select($provider);
        DB::table('pays')->update(['is_open' => 0]);
        $this->assertAck($this->notify($provider), $provider, true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testExpiredPaidOrderIsManualWithoutASecondCardConsumption(string $provider): void
    {
        $this->select($provider);
        DB::table('orders')->update(['status' => Order::STATUS_EXPIRED]);
        $this->assertAck($this->notify($provider), $provider, true);
        $this->assertSame(Order::STATUS_ABNORMAL, (int) Order::first()->status);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
    }

    /** @dataProvider invalidReceipts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidReceipts')]
    public function testSignedButInvalidBusinessReceiptNeverDelivers(string $provider, array $changes, bool $ack): void
    {
        $this->select($provider);
        $this->assertAck($this->notify($provider, $changes), $provider, $ack);
        $this->undelivered();
    }

    public static function invalidReceipts(): array
    {
        return [
            ['alipay', ['trade_status' => 'WAIT_BUYER_PAY'], true], ['alipay', ['trade_status' => 'TRADE_CLOSED'], true],
            ['alipay', ['app_id' => '2026010100000002'], false], ['alipay', ['currency' => 'USD'], false],
            ['alipay', ['total_amount' => '7.00'], false], ['alipay', ['total_amount' => '7.019'], false],
            ['alipay', ['total_amount' => '7.01e0'], false], ['alipay', ['trade_no' => ''], false],
            ['wepay', ['return_code' => 'FAIL'], false], ['wepay', ['result_code' => 'FAIL'], false],
            ['wepay', ['appid' => 'differentApp'], false], ['wepay', ['mch_id' => '1900000102'], false],
            ['wepay', ['trade_type' => 'JSAPI'], false], ['wepay', ['fee_type' => 'USD'], false],
            ['wepay', ['total_fee' => '700'], false], ['wepay', ['total_fee' => '701.9'], false],
            ['wepay', ['total_fee' => '7e2'], false], ['wepay', ['transaction_id' => ''], false],
            ['payjs', ['return_code' => '0'], false], ['payjs', ['mchid' => '1900000102'], false],
            ['payjs', ['fee_type' => 'USD'], false], ['payjs', ['total_fee' => '700'], false],
            ['payjs', ['total_fee' => '701.9'], false], ['payjs', ['payjs_order_id' => ''], false],
        ];
    }

    /** @dataProvider changedContexts */
    #[\PHPUnit\Framework\Attributes\DataProvider('changedContexts')]
    public function testAccountAndSelectionAreRecheckedAfterActualVerification(string $provider, string $change): void
    {
        $this->select($provider);
        $this->beforeFulfillment = function () use ($change) {
            if ($change === 'price') DB::table('orders')->update(['actual_price' => '7.02']);
            if ($change === 'gateway') DB::table('orders')->update(['pay_id' => 8]);
            if ($change === 'credentials') DB::table('pays')->update(['merchant_pem' => 'changedAfterVerification']);
            if ($change === 'route') DB::table('pays')->update(['pay_handleroute' => '/pay/stripe']);
            if ($change === 'merchant') DB::table('pays')->update(['merchant_id' => 'differentMerchant']);
            if ($change === 'method') DB::table('pays')->update(['pay_check' => 'differentMethod']);
        };
        $this->assertAck($this->notify($provider), $provider, false);
        $this->undelivered();
    }

    public static function changedContexts(): array
    {
        $cases = [];
        foreach (['alipay', 'wepay', 'payjs'] as $provider) foreach (['price', 'gateway', 'credentials', 'route', 'merchant', 'method'] as $change) $cases[] = [$provider, $change];
        return $cases;
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testModernBindingToAnotherGatewayStopsLegacyReceipt(string $provider): void
    {
        $this->select($provider);
        config(['dujiaoka.stripe_payment_intents' => true]);
        Schema::create('stripe_payment_intents', function (Blueprint $t) { $t->bigIncrements('id'); $t->integer('order_id'); $t->integer('pay_id'); });
        DB::table('stripe_payment_intents')->insert(['order_id' => 1, 'pay_id' => 8]);
        $this->assertAck($this->notify($provider), $provider, false);
        $this->undelivered();
    }

    public function testAlipaySignaturePreservesFormWhitespaceThroughMiddleware(): void
    {
        $payload = $this->signed('alipay', $this->receipt('alipay') + ['subject' => '  fixture item  ']);
        $response = $this->call('POST', '/pay/alipay/notify_url', $payload, [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($payload, '', '&'));
        $this->assertAck($response, 'alipay', true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
    }

    public function testAlipayPollutedFormOrDifferentContentTypeNeverFulfill(): void
    {
        $payload = $this->signed('alipay', $this->receipt('alipay'));
        $body = http_build_query($payload, '', '&');
        foreach (['&out_trade_no=CNYFIXTURE', '&out%5Ftrade_no=CNYFIXTURE', '&subject%5B%5D=value'] as $extra) {
            $response = $this->call('POST', '/pay/alipay/notify_url', $payload, [], [],
                ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body . $extra);
            $this->assertAck($response, 'alipay', false);
            $this->undelivered();
        }
        $this->assertAck($this->call('POST', '/pay/alipay/notify_url', $payload, [], [],
            ['CONTENT_TYPE' => 'application/json'], $body), 'alipay', false);
        $this->undelivered();
    }

    public function testPayjsOriginalFormValuesAndDuplicateFieldsThroughMiddleware(): void
    {
        $this->select('payjs');
        $payload = $this->signed('payjs', $this->receipt('payjs') + ['attach' => '  fixture + one  ']);
        $body = http_build_query($payload, '', '&');
        $this->assertAck($this->call('POST', '/pay/payjs/notify_url', $payload, [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body . '&mchid=1900000101'), 'payjs', false);
        $this->undelivered();
        $this->assertAck($this->send('payjs', $payload), 'payjs', true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame(1, $this->notifications);
    }

    public function testWechatHmacReceiptHasProperXmlAcknowledgement(): void
    {
        $this->select('wepay');
        $response = $this->notify('wepay', ['sign_type' => 'HMAC-SHA256']);
        $this->assertAck($response, 'wepay', true);
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
    }

    public function testMalformedXmlIsAcknowledgedAsFailureWithoutWarnings(): void
    {
        $this->select('wepay');
        $response = $this->call('POST', '/pay/wepay/notify_url', [], [], [], ['CONTENT_TYPE' => 'application/xml'], '<!DOCTYPE xml [<!ENTITY x SYSTEM "file:///ignored">]><xml><out_trade_no>&x;</out_trade_no></xml>');
        $this->assertAck($response, 'wepay', false);
        $this->undelivered();
    }

    /** @dataProvider checkoutModes */
    #[\PHPUnit\Framework\Attributes\DataProvider('checkoutModes')]
    public function testExistingCheckoutModesUseVerifiedNativeBoundaries(string $provider, string $mode): void
    {
        $this->select($provider, $mode);
        $response = $this->get('/pay/' . $provider . '/' . $mode . '/CNYFIXTURE')->assertOk();
        if (in_array($mode, ['aliweb', 'aliwap'], true)) {
            $response->assertSee('openapi.alipay.com/gateway.do', false)->assertHeader('Cache-Control', 'no-store, private');
            $this->assertCount(0, $this->requests);
        } else {
            $this->assertCount(1, $this->requests);
            $this->assertSame('CNYFIXTURE', $this->requests[0]['out_trade_no']);
            $this->assertSame($provider === 'alipay' ? '7.01' : '701', $this->requests[0][$provider === 'alipay' ? 'total_amount' : 'total_fee']);
        }
        $this->undelivered();
    }

    public static function checkoutModes(): array { return [['alipay', 'alipayscan'], ['alipay', 'zfbf2f'], ['alipay', 'aliweb'], ['alipay', 'aliwap'], ['wepay', 'wescan'], ['payjs', 'payjswescan']]; }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testProviderErrorNeverDisplaysPrivateResponse(string $provider): void
    {
        $this->select($provider);
        $this->httpError = true;
        $mode = $provider === 'alipay' ? 'alipayscan' : ($provider === 'wepay' ? 'wescan' : 'payjswescan');
        $this->get('/pay/' . $provider . '/' . $mode . '/CNYFIXTURE')->assertOk()
            ->assertSeeText(__('dujiaoka.prompt.abnormal_payment_channel'))->assertDontSee('PRIVATE-provider-error');
        $this->undelivered();
    }

    private function select(string $provider, ?string $mode = null): void
    {
        if ($provider === 'wepay') DB::table('pays')->update(['pay_check' => $mode ?? 'wescan', 'pay_handleroute' => '/pay/wepay',
            'merchant_id' => 'wxfixture', 'merchant_key' => '1900000101', 'merchant_pem' => self::SECRET]);
        elseif ($provider === 'payjs') DB::table('pays')->update(['pay_check' => $mode ?? 'payjswescan', 'pay_handleroute' => '/pay/payjs',
            'merchant_id' => '1900000101', 'merchant_key' => '', 'merchant_pem' => self::SECRET]);
        elseif ($mode !== null) DB::table('pays')->update(['pay_check' => $mode]);
    }

    private function receipt(string $provider): array
    {
        if ($provider === 'payjs') return ['return_code' => '1', 'mchid' => '1900000101', 'out_trade_no' => 'CNYFIXTURE',
            'total_fee' => '701', 'payjs_order_id' => 'TXN2026010101'];
        return $provider === 'alipay'
            ? ['app_id' => '2026010100000001', 'out_trade_no' => 'CNYFIXTURE', 'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '7.01', 'trade_no' => 'TXN2026010101', 'sign_type' => 'RSA2']
            : ['appid' => 'wxfixture', 'mch_id' => '1900000101', 'out_trade_no' => 'CNYFIXTURE', 'return_code' => 'SUCCESS', 'result_code' => 'SUCCESS',
                'trade_type' => 'NATIVE', 'fee_type' => 'CNY', 'total_fee' => '701', 'transaction_id' => 'TXN2026010101', 'nonce_str' => 'fixtureNonce'];
    }

    private function signed(string $provider, array $data): array
    {
        $value = $data; unset($value['sign'], $value['sign_type']);
        if ($provider === 'alipay') {
            openssl_sign($this->canonical($value), $sign, self::$rsa[0], OPENSSL_ALGO_SHA256);
            $data['sign'] = base64_encode($sign);
        } elseif ($provider === 'payjs') {
            $data['sign'] = $this->payjsSign($data);
        } else {
            $value = $data; unset($value['sign']);
            $contents = $this->canonical($value) . '&key=' . self::SECRET;
            $data['sign'] = strtoupper(($data['sign_type'] ?? 'MD5') === 'HMAC-SHA256' ? hash_hmac('sha256', $contents, self::SECRET) : md5($contents));
        }
        return $data;
    }

    private function payjsSign(array $data): string
    {
        unset($data['sign']); $data = array_filter($data); ksort($data);
        return strtoupper(md5(urldecode(http_build_query($data, '', '&') . '&key=' . self::SECRET)));
    }

    private function canonical(array $data): string
    {
        ksort($data); $pairs = [];
        foreach ($data as $key => $value) if ($value !== '') $pairs[] = $key . '=' . $value;
        return implode('&', $pairs);
    }

    private function xml(array $data): string
    {
        $xml = '<xml>';
        foreach ($data as $name => $value) $xml .= '<' . $name . '>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $name . '>';
        return $xml . '</xml>';
    }

    private function notify(string $provider, array $changes = []) { return $this->send($provider, $this->signed($provider, array_replace($this->receipt($provider), $changes))); }
    private function send(string $provider, array $data)
    {
        return $provider !== 'wepay' ? $this->call('POST', '/pay/' . $provider . '/notify_url', $data, [], [],
                ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($data, '', '&'))
            : $this->call('POST', '/pay/wepay/notify_url', [], [], [], ['CONTENT_TYPE' => 'application/xml'], $this->xml($data));
    }
    private function assertAck($response, string $provider, bool $success): void
    {
        $response->assertOk();
        $this->assertSame($provider !== 'wepay' ? ($success ? 'success' : 'fail') : ($success ? 'SUCCESS' : 'FAIL'),
            $provider !== 'wepay' ? $response->getContent() : (string) simplexml_load_string($response->getContent())->return_code);
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
