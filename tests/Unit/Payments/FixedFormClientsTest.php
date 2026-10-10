<?php

namespace Tests\Unit\Payments;

use App\Payments\PaysapiClient;
use App\Payments\VpayClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FixedFormClientsTest extends TestCase
{
    private const SECRET = 'fixture-fixed-key+%2B';

    private function credentials(string $provider): array
    {
        return $provider === 'vpay' ? ['key' => self::SECRET, 'endpoint' => 'https://pay.invalid/nested/']
            : ['uid' => '1001', 'key' => self::SECRET];
    }

    private function order(string $provider): array
    {
        $base = ['order_sn' => 'ORDER1', 'amount' => '10.01', 'return_url' => 'https://shop.invalid/return?x=a%2B1',
            'notify_url' => 'https://shop.invalid/notify?x=a%262'];
        return $provider === 'vpay' ? $base + ['payment_id' => 'TX1'] : $base + ['email' => " a'\"<x>@fixture.invalid + "];
    }

    private function receipt(string $provider): array
    {
        return $provider === 'vpay' ? ['payId' => 'TX1', 'param' => 'ORDER1', 'type' => '1', 'price' => '10.01', 'reallyPrice' => '10.00']
            : ['orderid' => 'ORDER1', 'orderuid' => '', 'paysapi_id' => 'TX1', 'price' => '10.01', 'realprice' => '10.00'];
    }

    private function signed(string $provider, array $data): array
    {
        if ($provider === 'vpay') $data['sign'] = md5($data['payId'] . $data['param'] . $data['type'] . $data['price'] . $data['reallyPrice'] . self::SECRET);
        else $data['key'] = md5($data['orderid'] . $data['orderuid'] . $data['paysapi_id'] . $data['price'] . $data['realprice'] . self::SECRET);
        return $data;
    }

    private function client(string $provider) { return $provider === 'vpay' ? new VpayClient() : new PaysapiClient(); }

    public static function modes(): array { return [['vpay', 'vwx', '1'], ['vpay', 'vzfb', '2'], ['paysapi', 'pszfb', '1'], ['paysapi', 'pswx', '2']]; }

    /** @dataProvider modes */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function testActualWireFieldsFollowOriginalFixedSigningOrder(string $provider, string $mode, string $type): void
    {
        $order = $this->order($provider);
        $result = $this->client($provider)->create($mode, $this->credentials($provider), $order);
        if ($provider === 'vpay') {
            $this->assertSame('https://pay.invalid/nested/createOrder', strtok($result, '?'));
            parse_str(parse_url($result, PHP_URL_QUERY), $data);
            $this->assertSame(md5('TX1ORDER1' . $type . '10.01' . self::SECRET), $data['sign']);
            $this->assertSame($type, $data['type']);
            $this->assertSame('1', $data['isHtml']);
            $this->assertSame('ORDER1', $data['param']);
            $this->assertSame($order['notify_url'], $data['notifyUrl']);
            $this->assertSame($order['return_url'], $data['returnUrl']);
        } else {
            $this->assertSame('https://pay.bearsoftware.net.cn/', $result['endpoint']);
            $data = $result['fields'];
            $this->assertSame(md5('ORDER1' . $type . $order['notify_url'] . 'ORDER1' . $order['email'] . '10.01'
                . $order['return_url'] . self::SECRET . '1001'), $data['key']);
            $this->assertSame($type, $data['istype']);
            $this->assertSame($order['email'], $data['orderuid']);
            $this->assertSame('1001', $data['uid']);
        }
        $this->assertSame('10.01', $data['price']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($data));
    }

    public static function providers(): array { return [['vpay'], ['paysapi']]; }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testOriginalCallbackAuthenticatesRawValuesAndAdjustedCollectionPrice(string $provider): void
    {
        foreach (['9.99', '10.01', '10.02'] as $adjusted) {
            $data = $this->receipt($provider);
            $data[$provider === 'vpay' ? 'reallyPrice' : 'realprice'] = $adjusted;
            if ($provider === 'paysapi') $data['orderuid'] = "  \u{5546} + & = %2B\r\n  ";
            $data = $this->signed($provider, $data);
            $this->assertSame($data, $this->client($provider)->verify($data, $this->credentials($provider)));
            $signatureName = $provider === 'vpay' ? 'sign' : 'key';
            $data[$signatureName] = strtoupper($data[$signatureName]);
            $this->assertSame($data, $this->client($provider)->verify($data, $this->credentials($provider)));
        }
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testEverySignedFieldTamperAndAnotherKeyFails(string $provider): void
    {
        $data = $this->signed($provider, $this->receipt($provider));
        $signatureName = $provider === 'vpay' ? 'sign' : 'key';
        foreach (array_keys($data) as $name) {
            if ($name === $signatureName) continue;
            $changed = $data; $changed[$name] .= '1';
            $this->unavailable(function () use ($provider, $changed) { $this->client($provider)->verify($changed, $this->credentials($provider)); });
        }
        $credentials = $this->credentials($provider); $credentials['key'] = 'other-fixed-key';
        $this->unavailable(function () use ($provider, $data, $credentials) { $this->client($provider)->verify($data, $credentials); });
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testOmittedRequiredFieldsAndBlankProviderReferencesFail(string $provider): void
    {
        $data = $this->signed($provider, $this->receipt($provider));
        foreach (array_keys($data) as $name) {
            $changed = $data; unset($changed[$name]);
            $this->unavailable(function () use ($provider, $changed) { $this->client($provider)->verify($changed, $this->credentials($provider)); });
        }
        foreach ($provider === 'vpay' ? ['payId', 'param'] : ['orderid', 'paysapi_id'] as $name) {
            $changed = $this->receipt($provider); $changed[$name] = '';
            $changed = $this->signed($provider, $changed);
            $this->unavailable(function () use ($provider, $changed) { $this->client($provider)->verify($changed, $this->credentials($provider)); });
        }
    }

    /** @dataProvider invalidAmounts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAmounts')]
    public function testSignedInvalidPricesAndForeignCheckoutAmountsFail(string $provider, $amount): void
    {
        foreach (['price', $provider === 'vpay' ? 'reallyPrice' : 'realprice'] as $name) {
            $data = $this->receipt($provider); $data[$name] = $amount;
            if (is_string($amount)) $data = $this->signed($provider, $data);
            $this->unavailable(function () use ($provider, $data) { $this->client($provider)->verify($data, $this->credentials($provider)); });
        }
        $order = $this->order($provider); $order['amount'] = $amount;
        $this->unavailable(function () use ($provider, $order) { $this->client($provider)->create($provider === 'vpay' ? 'vwx' : 'pswx', $this->credentials($provider), $order); });
    }

    public static function invalidAmounts(): array
    {
        $cases = [];
        foreach (['vpay', 'paysapi'] as $provider) foreach (['0', '-1', '10.019', '1e1', ' 10.01', true, null, [], 10.01] as $amount) $cases[] = [$provider, $amount];
        return $cases;
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testUnknownUnsignedFieldsNeverEnterTheFixedConcatenation(string $provider): void
    {
        $data = $this->signed($provider, $this->receipt($provider));
        $data['extra'] = "  zero=0 + %2B\r\n  ";
        $data['state'] = '0';
        $this->assertSame($data, $this->client($provider)->verify($data, $this->credentials($provider)));
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testUnboundedNestedAndNonStringCallbackShapesFail(string $provider): void
    {
        foreach ([['nested'], 1.1, true, null, "a\0b", "\xFF", str_repeat('a', 65537)] as $value) {
            $data = $this->signed($provider, $this->receipt($provider)); $data['extra'] = $value;
            $this->unavailable(function () use ($provider, $data) { $this->client($provider)->verify($data, $this->credentials($provider)); });
        }
        foreach (['', str_repeat('a', 31), str_repeat('a', 33), str_repeat('z', 32)] as $value) {
            $data = $this->receipt($provider); $data[$provider === 'vpay' ? 'sign' : 'key'] = $value;
            $this->unavailable(function () use ($provider, $data) { $this->client($provider)->verify($data, $this->credentials($provider)); });
        }
    }

    /** @dataProvider invalidUrls */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUrls')]
    public function testCheckoutUrlBoundariesPreserveConfiguredAuthority(string $provider, string $url): void
    {
        $order = $this->order($provider); $order['return_url'] = $url;
        $this->unavailable(function () use ($provider, $order) { $this->client($provider)->create($provider === 'vpay' ? 'vwx' : 'pswx', $this->credentials($provider), $order); });
        if ($provider === 'vpay') {
            $credentials = $this->credentials($provider); $credentials['endpoint'] = $url;
            $this->unavailable(function () use ($provider, $credentials) { $this->client($provider)->create('vwx', $credentials, $this->order($provider)); });
        }
    }

    public static function invalidUrls(): array
    {
        $cases = [];
        foreach (['vpay', 'paysapi'] as $provider) foreach (['javascript:alert(1)', 'https://pay.invalid/#', 'https://pay.invalid/#x',
            'https://user:pass@pay.invalid/', "https://pay.invalid/a\r\n", 'https://pay.invalid/%GG'] as $url) $cases[] = [$provider, $url];
        return $cases;
    }

    public function testVpayBasePathSlashAndEmptyQueryRules(): void
    {
        $credentials = $this->credentials('vpay'); $credentials['endpoint'] = 'http://localhost:8080/nested';
        $url = (new VpayClient())->create('vwx', $credentials, $this->order('vpay'));
        $this->assertSame('http://localhost:8080/nested/createOrder', strtok($url, '?'));
        $credentials['endpoint'] .= '?x=1';
        $this->unavailable(function () use ($credentials) { (new VpayClient())->create('vwx', $credentials, $this->order('vpay')); });
    }

    /** @dataProvider providers */
    #[\PHPUnit\Framework\Attributes\DataProvider('providers')]
    public function testCredentialsAndExtraCheckoutFieldsStayExplicit(string $provider): void
    {
        $credentials = $this->credentials($provider); $credentials['key'] = '';
        $this->unavailable(function () use ($provider, $credentials) { $this->client($provider)->create($provider === 'vpay' ? 'vwx' : 'pswx', $credentials, $this->order($provider)); });
        $order = $this->order($provider); $order['key'] = 'injected-merchant-secret';
        $this->unavailable(function () use ($provider, $order) { $this->client($provider)->create($provider === 'vpay' ? 'vwx' : 'pswx', $this->credentials($provider), $order); });
        $this->unavailable(function () use ($provider) { $this->client($provider)->create('unknown', $this->credentials($provider), $this->order($provider)); });
    }

    private function unavailable(callable $call): void
    {
        try { $call(); }
        catch (RuntimeException $exception) {
            $this->assertSame('Payment provider unavailable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            return;
        }
        $this->fail('Expected protocol failure.');
    }
}
