<?php

namespace Tests\Feature;

use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\GatewayTransactionFixture;
use Tests\TestCase;

class FormGatewayRouteTest extends TestCase
{
    use GatewayTransactionFixture;

    private const SECRET = 'fixture-shared-key';
    private $notifications = 0;
    private $beforeFulfillment;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        $this->withoutMiddleware([\App\Http\Middleware\DujiaoBoot::class, \App\Http\Middleware\InstallCheck::class]);
        config(['dujiaoka.payment_quote_freeze' => false, 'dujiaoka.paypal_orders_v2' => false,
            'dujiaoka.stripe_payment_intents' => false]);
        $this->createGatewayTables();
        Pay::forceCreate(['id' => 7, 'pay_check' => 'alipay', 'pay_handleroute' => '/pay/yipay',
            'merchant_id' => '1900000101', 'merchant_key' => 'https://epay.invalid/submit.php', 'merchant_pem' => self::SECRET]);
        Order::forceCreate(['id' => 1, 'order_sn' => 'FORMFIXTURE', 'pay_id' => 7,
            'actual_price' => '7.01', 'status' => Order::STATUS_WAIT_PAY]);
        DB::table('goods')->insert(['id' => 1]);
        DB::table('carmis')->insert(['goods_id' => 1, 'carmi' => 'local-form-card']);
        $this->bindGatewayProcessor();
    }

    public function beforeCompletion(): void { if ($this->beforeFulfillment) ($this->beforeFulfillment)(); }
    public function notified(): void { $this->assertSame(0, DB::transactionLevel()); ++$this->notifications; }

    /** @dataProvider originalBusinessDefects */
    #[\PHPUnit\Framework\Attributes\DataProvider('originalBusinessDefects')]
    public function testSignedInvalidBusinessReceiptDoesNotDeliver(string $provider, array $changes): void
    {
        $this->select($provider);
        $response = $this->send($provider, $changes)->assertOk();
        $this->assertSame('fail', $response->getContent());
        $this->undelivered();
    }

    public static function originalBusinessDefects(): array
    {
        return [['yipay', ['trade_status' => 'WAIT_BUYER_PAY']], ['vpay', ['type' => '2']],
            ['paysapi', ['paysapi_id' => '']]];
    }

    public static function providers(): array { return [['yipay'], ['vpay'], ['paysapi']]; }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testSignedDuplicateFulfillsOnceAndAnotherTransactionFails(string $provider): void
    {
        $this->select($provider);
        $this->ack($this->send($provider), true);
        $this->ack($this->send($provider), true);
        $trade = ['yipay' => 'trade_no', 'vpay' => 'payId', 'paysapi' => 'paysapi_id'][$provider];
        $this->ack($this->send($provider, [$trade => 'DIFFERENT']), false);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('TXN2027', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_SOLD, (int) Carmis::first()->status);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(1, $this->notifications);
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testClosedPaidGatewayAndExpiredOrderKeepReceiptRecovery(string $provider): void
    {
        $this->select($provider);
        DB::table('pays')->update(['is_open' => 0]);
        DB::table('orders')->update(['status' => Order::STATUS_EXPIRED]);
        $this->ack($this->send($provider), true);
        $this->assertSame(Order::STATUS_ABNORMAL, (int) Order::first()->status);
        $this->assertSame('TXN2027', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testCurrentRawSignatureRejectsTampering(string $provider): void
    {
        $this->select($provider);
        $fields = $this->sign($provider, $this->fields($provider));
        $fields[$provider === 'yipay' ? 'money' : 'price'] = '7.02';
        $this->ack($this->sendFields($provider, $fields), false);
        $this->undelivered();
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testDuplicateArrayAndMalformedRawFieldsNeverFulfill(string $provider): void
    {
        $this->select($provider);
        $reference = ['yipay' => 'out_trade_no', 'vpay' => 'param', 'paysapi' => 'orderid'][$provider];
        foreach (['&' . $reference . '=FORMFIXTURE', '&' . $reference . '%5B%5D=FORMFIXTURE', '&extra=%GG', '&_method=GET'] as $extra) {
            $this->ack($this->send($provider, [], $extra), false);
            $this->undelivered();
        }
    }

    /** @dataProvider changedContexts */
    #[\PHPUnit\Framework\Attributes\DataProvider('changedContexts')]
    public function testReceiptContextIsRecheckedInsideActualFulfillment(string $provider, string $change): void
    {
        $this->select($provider);
        $this->beforeFulfillment = function () use ($change) {
            if ($change === 'price') DB::table('orders')->update(['actual_price' => '7.02']);
            if ($change === 'gateway') DB::table('orders')->update(['pay_id' => 8]);
            if ($change === 'id') DB::table('pays')->update(['merchant_id' => 'changed-id']);
            if ($change === 'key') DB::table('pays')->update(['merchant_key' => 'changed-key']);
            if ($change === 'pem') DB::table('pays')->update(['merchant_pem' => 'changed-pem']);
            if ($change === 'route') DB::table('pays')->update(['pay_handleroute' => '/pay/stripe']);
            if ($change === 'method') DB::table('pays')->update(['pay_check' => 'different-method']);
        };
        $this->ack($this->send($provider), false);
        $this->undelivered();
    }

    public static function changedContexts(): array
    {
        $cases = [];
        foreach (['yipay', 'vpay', 'paysapi'] as $provider) foreach (['price', 'gateway', 'id', 'key', 'pem', 'route', 'method'] as $change) $cases[] = [$provider, $change];
        return $cases;
    }

    /** @dataProvider modernBindings */
    #[\PHPUnit\Framework\Attributes\DataProvider('modernBindings')]
    public function testAnotherModernPaymentBindingExcludesLegacyReceipt(string $provider, string $flag, string $table): void
    {
        $this->select($provider);
        config(['dujiaoka.' . $flag => true]);
        \Illuminate\Support\Facades\Schema::create($table, function (\Illuminate\Database\Schema\Blueprint $t) {
            $t->bigIncrements('id'); $t->integer('order_id'); $t->integer('pay_id');
        });
        DB::table($table)->insert(['order_id' => 1, 'pay_id' => 8]);
        $this->ack($this->send($provider), false);
        $this->undelivered();
    }

    public static function modernBindings(): array
    {
        $cases = [];
        foreach (['yipay', 'vpay', 'paysapi'] as $provider) foreach (['stripe_payment_intents' => 'stripe_payment_intents', 'paypal_orders_v2' => 'paypal_orders'] as $flag => $table) $cases[] = [$provider, $flag, $table];
        return $cases;
    }

    /** @dataProvider invalidBusinessReceipts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBusinessReceipts')]
    public function testSignedInvalidAmountOrProviderFieldsNeverFulfill(string $provider, array $changes): void
    {
        $this->select($provider);
        $this->ack($this->send($provider, $changes), false);
        $this->undelivered();
    }

    public static function invalidBusinessReceipts(): array
    {
        $cases = [['yipay', ['trade_status' => 'TRADE_CLOSED']], ['yipay', ['pid' => '1900000102']],
            ['yipay', ['type' => 'wxpay']], ['yipay', ['trade_no' => '']], ['yipay', ['trade_no' => '   ']],
            ['yipay', ['trade_no' => "TXN\r\n2027"]], ['yipay', ['sign_type' => 'RSA']],
            ['vpay', ['payId' => '']], ['vpay', ['reallyPrice' => '0']], ['paysapi', ['realprice' => '0']]];
        foreach (['yipay', 'vpay', 'paysapi'] as $provider) foreach (['7.00', '7.019', '7.01e0'] as $amount) $cases[] = [$provider, [$provider === 'yipay' ? 'money' : 'price' => $amount]];
        return $cases;
    }

    /** @dataProvider adjustedAmounts */
    #[\PHPUnit\Framework\Attributes\DataProvider('adjustedAmounts')]
    public function testSignedAdjustedCollectionAmountDoesNotReplaceShopPrice(string $provider, string $paid): void
    {
        $this->select($provider);
        $this->ack($this->send($provider, [$provider === 'vpay' ? 'reallyPrice' : 'realprice' => $paid]), true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('7.01', (string) Order::first()->actual_price);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
    }

    public static function adjustedAmounts(): array { return [['vpay', '7.00'], ['vpay', '7.02'], ['paysapi', '7.00'], ['paysapi', '7.02']]; }

    public function testSignedQueryValuesArePreservedThroughMiddleware(): void
    {
        $this->ack($this->send('yipay', ['name' => "  item + \r\n  ", 'zero' => '0', 'empty' => '']), true);
        $this->assertSame(1, $this->notifications);
    }

    public function testPaysapiSignedOrderUserWhitespaceIsPreservedThroughMiddleware(): void
    {
        $this->select('paysapi');
        $this->ack($this->send('paysapi', ['orderuid' => "  mailbox + \r\n  "]), true);
        $this->assertSame(1, $this->notifications);
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testReturnPageRedirectDoesNotFulfill(string $provider): void
    {
        $this->select($provider);
        $this->get('/pay/' . $provider . '/return_url?order_id=FORMFIXTURE')->assertRedirect(url('detail-order-sn', ['orderSN' => 'FORMFIXTURE']));
        $this->undelivered();
    }

    /** @dataProvider checkoutModes */
    #[\PHPUnit\Framework\Attributes\DataProvider('checkoutModes')]
    public function testAllExistingBrowserCheckoutModesPreserveActualSignedFields(string $provider, string $mode, string $type): void
    {
        $this->select($provider, $mode);
        if ($provider === 'paysapi') DB::table('orders')->update(['email' => 'mail\'"<script>fixture</script>@invalid']);
        $response = $this->get('/pay/' . $provider . '/' . $mode . '/FORMFIXTURE');
        if ($provider === 'vpay') {
            $response->assertStatus(302);
            $location = $response->headers->get('Location');
            $this->assertSame('vpay.invalid', parse_url($location, PHP_URL_HOST));
            $this->assertSame('/gateway/createOrder', parse_url($location, PHP_URL_PATH));
            parse_str(parse_url($location, PHP_URL_QUERY), $fields);
            $this->assertMatchesRegularExpression('/\A[0-9]{15,19}\z/', $fields['payId']);
            $this->assertSame(md5($fields['payId'] . 'FORMFIXTURE' . $type . '7.01' . self::SECRET), $fields['sign']);
            $this->assertSame($type, $fields['type']);
            $this->assertSame('1', $fields['isHtml']);
        } else {
            $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $dom = new \DOMDocument();
            $this->assertTrue($dom->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING));
            $forms = $dom->getElementsByTagName('form');
            $this->assertSame(1, $forms->length);
            $this->assertSame($provider === 'yipay' ? 'get' : 'post', $forms->item(0)->getAttribute('method'));
            $this->assertSame($provider === 'yipay' ? 'https://epay.invalid/submit.php' : 'https://pay.bearsoftware.net.cn/', $forms->item(0)->getAttribute('action'));
            $fields = [];
            foreach ($forms->item(0)->getElementsByTagName('input') as $input) $fields[$input->getAttribute('name')] = $input->getAttribute('value');
            $this->assertSame(1, $dom->getElementsByTagName('script')->length);
            if ($provider === 'yipay') {
                $signature = $fields['sign'];
                unset($fields['sign']); ksort($fields, SORT_STRING); $parts = [];
                foreach ($fields as $name => $value) if ($name !== 'sign_type' && $value !== '') $parts[] = $name . '=' . $value;
                $this->assertSame(md5(implode('&', $parts) . self::SECRET), $signature);
                $this->assertSame($mode, $fields['type']);
                $this->assertSame('MD5', $fields['sign_type']);
                $this->assertSame('7.01', $fields['money']);
            } else {
                $this->assertSame('mail\'"<script>fixture</script>@invalid', $fields['orderuid']);
                $this->assertSame(md5('FORMFIXTURE' . $type . $fields['notify_url'] . 'FORMFIXTURE' . $fields['orderuid'] . '7.01'
                    . $fields['return_url'] . self::SECRET . $fields['uid']), $fields['key']);
                $this->assertSame($type, $fields['istype']);
            }
        }
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        $this->undelivered();
    }

    public static function checkoutModes(): array
    {
        return [['yipay', 'alipay', 'alipay'], ['yipay', 'wxpay', 'wxpay'], ['yipay', 'qqpay', 'qqpay'],
            ['vpay', 'vwx', '1'], ['vpay', 'vzfb', '2'], ['paysapi', 'pszfb', '1'], ['paysapi', 'pswx', '2']];
    }

    /** @dataProvider zeroReferenceReceipts */
    #[\PHPUnit\Framework\Attributes\DataProvider('zeroReferenceReceipts')]
    public function testZeroTransactionIsARealReferenceDuringDuplicateReceiptChecks(string $provider, string $stored, string $incoming, bool $same): void
    {
        $this->select($provider);
        $field = ['yipay' => 'trade_no', 'vpay' => 'payId', 'paysapi' => 'paysapi_id'][$provider];
        $this->ack($this->send($provider, [$field => $stored]), true);
        $this->ack($this->send($provider, [$field => $incoming]), $same);
        $this->assertSame($stored, Order::first()->trade_no);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame(Carmis::STATUS_SOLD, (int) Carmis::first()->status);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(1, $this->notifications);
    }

    public static function zeroReferenceReceipts(): array
    {
        $cases = [];
        foreach (['yipay', 'vpay', 'paysapi'] as $provider) {
            $cases[] = [$provider, '0', 'DIFFERENT', false];
            $cases[] = [$provider, 'TXN2027', '0', false];
            $cases[] = [$provider, '0', '0', true];
        }
        return $cases;
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testClosedPaidGatewayStillFulfillsOutstandingOrder(string $provider): void
    {
        $this->select($provider);
        DB::table('pays')->update(['is_open' => 0]);
        $this->ack($this->send($provider), true);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('TXN2027', Order::first()->trade_no);
        $this->assertSame(1, $this->notifications);
    }

    public function testPaysapiReadsPostBodyWithoutMergingQueryOrAcceptingJson(): void
    {
        $this->select('paysapi');
        $fields = $this->sign('paysapi', $this->fields('paysapi'));
        $body = http_build_query($fields, '', '&');
        $this->ack($this->call('POST', '/pay/paysapi/notify_url', $fields, [], [], ['CONTENT_TYPE' => 'application/json'], $body), false);
        $this->undelivered();
        $this->ack($this->call('POST', '/pay/paysapi/notify_url?orderid=UNKNOWN', $fields, [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body), true);
    }

    public function testMissingGatewayHasProtocolErrorInsteadOfUnhandledException(): void
    {
        DB::table('orders')->update(['pay_id' => 99]);
        $this->ack($this->send('vpay'), false);
        $this->undelivered();
    }

    private function ack($response, bool $success): void
    {
        $response->assertOk();
        $this->assertSame($success ? 'success' : 'fail', $response->getContent());
    }

    private function select(string $provider, ?string $mode = null): void
    {
        if ($provider === 'vpay') DB::table('pays')->update(['pay_check' => $mode ?? 'vwx', 'pay_handleroute' => 'pay/vpay',
            'merchant_id' => self::SECRET, 'merchant_key' => '', 'merchant_pem' => 'https://vpay.invalid/gateway/']);
        elseif ($provider === 'paysapi') DB::table('pays')->update(['pay_check' => $mode ?? 'pswx', 'pay_handleroute' => '/pay/paysapi',
            'merchant_id' => '1900000101', 'merchant_key' => '', 'merchant_pem' => self::SECRET]);
        elseif ($mode !== null) DB::table('pays')->update(['pay_check' => $mode]);
    }

    private function fields(string $provider): array
    {
        if ($provider === 'vpay') return ['payId' => 'TXN2027', 'param' => 'FORMFIXTURE', 'type' => '1', 'price' => '7.01', 'reallyPrice' => '7.01'];
        if ($provider === 'paysapi') return ['orderid' => 'FORMFIXTURE', 'orderuid' => '', 'paysapi_id' => 'TXN2027', 'price' => '7.01', 'realprice' => '7.01'];
        return ['pid' => '1900000101', 'type' => 'alipay', 'out_trade_no' => 'FORMFIXTURE', 'trade_no' => 'TXN2027',
            'trade_status' => 'TRADE_SUCCESS', 'money' => '7.01', 'sign_type' => 'MD5'];
    }

    private function sign(string $provider, array $fields): array
    {
        if ($provider === 'vpay') $fields['sign'] = md5($fields['payId'] . $fields['param'] . $fields['type'] . $fields['price'] . $fields['reallyPrice'] . self::SECRET);
        elseif ($provider === 'paysapi') $fields['key'] = md5($fields['orderid'] . $fields['orderuid'] . $fields['paysapi_id'] . $fields['price'] . $fields['realprice'] . self::SECRET);
        else {
            ksort($fields, SORT_STRING);
            $parts = [];
            foreach ($fields as $name => $value) if (!in_array($name, ['sign', 'sign_type'], true) && $value !== '') $parts[] = $name . '=' . $value;
            $fields['sign'] = md5(implode('&', $parts) . self::SECRET);
        }
        return $fields;
    }

    private function send(string $provider, array $changes = [], string $extra = '')
    {
        $fields = $this->sign($provider, array_replace($this->fields($provider), $changes));
        return $this->sendFields($provider, $fields, $extra);
    }

    private function sendFields(string $provider, array $fields, string $extra = '')
    {
        $encoded = http_build_query($fields, '', '&') . $extra;
        if ($provider === 'paysapi') return $this->call('POST', '/pay/paysapi/notify_url', $fields, [], [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $encoded);
        return $this->get('/pay/' . $provider . '/notify_url?' . $encoded);
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
