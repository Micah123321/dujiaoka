<?php

namespace Tests\Unit\Payments;

use App\Payments\FormNotification;
use App\Payments\YipayClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/app/Payments/CurrencyAmount.php';
require_once dirname(__DIR__, 3) . '/app/Payments/FormNotification.php';
require_once dirname(__DIR__, 3) . '/app/Payments/YipayClient.php';

class YipayClientTest extends TestCase
{
    private const KEY = 'fixture%2B key+&raw';

    private function credentials(array $changes = []): array
    {
        return array_replace(['pid' => '1001', 'endpoint' => 'https://pay.invalid/submit.php?fixed=1&',
            'key' => self::KEY], $changes);
    }

    private function order(array $changes = []): array
    {
        return array_replace(['order_sn' => 'ORDER-1', 'amount' => '12.34',
            'return_url' => 'https://shop.invalid/return?token=a%2Bb&x=1',
            'notify_url' => 'https://shop.invalid/notify?route=yipay&opaque=%26'], $changes);
    }

    private function callbackData(array $changes = []): array
    {
        return array_replace(['pid' => '1001', 'type' => 'alipay', 'out_trade_no' => 'ORDER-1',
            'trade_no' => 'PROVIDER-9', 'money' => '12.34', 'trade_status' => 'TRADE_SUCCESS',
            'sign_type' => 'MD5'], $changes);
    }

    private function oracle(array $data, string $key = self::KEY): string
    {
        // An independently enumerated ASCII order, never the client's sorting/signing helper.
        $names = ['A', 'Z', 'checkoutsig', 'empty', 'endpoint', 'extra', 'key', 'money', 'name',
            'notify_url', 'out_trade_no', 'pid', 'return_url', 'secret', 'trade_no',
            'trade_status', 'type', 'zero', 'zero_int'];
        unset($data['sign'], $data['sign_type']);
        $this->assertSame([], array_values(array_diff(array_keys($data), $names)));
        $parts = [];
        foreach ($names as $name) {
            if (array_key_exists($name, $data) && $data[$name] !== '') {
                $parts[] = $name . '=' . $data[$name];
            }
        }
        return md5(implode('&', $parts) . $key);
    }

    private function signed(array $data, string $key = self::KEY): array
    {
        $data['sign'] = $this->oracle($data, $key);
        return $data;
    }

    private function assertUnavailable(callable $operation): void
    {
        try {
            $operation();
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, get_class($exception));
            $this->assertSame('Payment provider unavailable.', $exception->getMessage());
            $this->assertSame(0, $exception->getCode());
            $this->assertNull($exception->getPrevious());
            return;
        }
        $this->fail('Expected a generic provider failure.');
    }

    /** @dataProvider modes */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function testCreatesExactLegacyFieldsForEveryMode(string $mode): void
    {
        $expected = ['money' => '12.34', 'name' => 'ORDER-1',
            'notify_url' => 'https://shop.invalid/notify?route=yipay&opaque=%26',
            'out_trade_no' => 'ORDER-1', 'pid' => '1001',
            'return_url' => 'https://shop.invalid/return?token=a%2Bb&x=1',
            'sign' => '', 'sign_type' => 'MD5', 'type' => $mode];
        $expected['sign'] = $this->oracle($expected);
        $result = (new YipayClient())->create($mode, $this->credentials(), $this->order());
        $this->assertSame(['endpoint' => $this->credentials()['endpoint'], 'fields' => $expected], $result);
        $this->assertArrayNotHasKey('key', $result['fields']);
        $this->assertStringNotContainsString(self::KEY, implode('', $result['fields']));
    }

    public static function modes(): array
    {
        return [['alipay'], ['wxpay'], ['qqpay']];
    }

    public function testFixedGoldenBytesFromTheOldController(): void
    {
        $rawCreate = 'money=12.34&name=ORDER-1&notify_url=https://shop.invalid/notify?route=yipay&opaque=%26'
            . '&out_trade_no=ORDER-1&pid=1001&return_url=https://shop.invalid/return?token=a%2Bb&x=1&type=alipay';
        $rawCallback = 'money=12.34&out_trade_no=ORDER-1&pid=1001&trade_no=PROVIDER-9&trade_status=TRADE_SUCCESS&type=alipay';
        // Captured by executing YipayController's original signing block with these fixtures.
        $this->assertSame('928eed1516143efdf85baad12a76fc17', md5($rawCreate . self::KEY));
        $this->assertSame('761a6075c09870f164b8c051e5255894', md5($rawCallback . self::KEY));
        $result = (new YipayClient())->create('alipay', $this->credentials(), $this->order());
        $this->assertSame('928eed1516143efdf85baad12a76fc17', $result['fields']['sign']);
        $data = $this->callbackData(['sign' => '761a6075c09870f164b8c051e5255894']);
        $this->assertSame($data, (new YipayClient())->verify($data, $this->credentials()));
    }

    /** @dataProvider amounts */
    #[\PHPUnit\Framework\Attributes\DataProvider('amounts')]
    public function testCheckoutUsesExactNormalizedMoneyAndSignsItsActualWire(string $amount, string $wire): void
    {
        $result = (new YipayClient())->create('alipay', $this->credentials(), $this->order(['amount' => $amount]));
        $this->assertSame($wire, $result['fields']['money']);
        $raw = 'money=' . $wire . '&name=ORDER-1&notify_url=https://shop.invalid/notify?route=yipay&opaque=%26'
            . '&out_trade_no=ORDER-1&pid=1001&return_url=https://shop.invalid/return?token=a%2Bb&x=1&type=alipay';
        $this->assertSame(md5($raw . self::KEY), $result['fields']['sign']);
        if ($wire === '10.00') {
            $this->assertNotSame(md5(str_replace('money=10.00&', 'money=10&', $raw) . self::KEY), $result['fields']['sign']);
        }
    }

    public static function amounts(): array
    {
        return [['0.01', '0.01'], ['0.10', '0.10'], ['10', '10.00'], ['10.00', '10.00'],
            ['12.3', '12.30'], ['00012.34', '12.34'], ['99999999.99', '99999999.99']];
    }

    public function testFieldBytesAreIndependentOfFormEncodingAndHtmlEscaping(): void
    {
        $sn = " <'\"&+%2B=\u{4e2d}\t\r\n";
        $fields = (new YipayClient())->create('alipay', $this->credentials(), $this->order(['order_sn' => $sn]))['fields'];
        $this->assertSame($sn, $fields['out_trade_no']);
        $this->assertSame($sn, $fields['name']);
        $this->assertSame($this->oracle($fields), $fields['sign']);
        $data = $this->signed($this->callbackData(['out_trade_no' => $sn, 'extra' => " +%2B\r\n\u{4e2d}"]));
        foreach ([PHP_QUERY_RFC1738, PHP_QUERY_RFC3986] as $encoding) {
            $encoded = http_build_query($fields, '', '&', $encoding);
            $decoded = FormNotification::parse($encoded);
            $this->assertSame($fields, $decoded);
            $this->assertSame($fields['sign'], $this->oracle($decoded));
            $decodedCallback = FormNotification::parse(http_build_query($data, '', '&', $encoding));
            $this->assertSame($data, (new YipayClient())->verify($decodedCallback, $this->credentials()));
        }
    }

    public function testUnknownMetadataRemainsOpaqueAndAllNonemptyFieldsAreSigned(): void
    {
        $data = $this->signed($this->callbackData(['A' => 'upper', 'Z' => 'upper last',
            'extra' => " \u{4e2d}+%2B&x=y\t\r\n ", 'empty' => '', 'zero' => '0', 'zero_int' => 0,
            'key' => 'foreign-key', 'secret' => 'foreign-secret', 'checkoutsig' => 'foreign-signature',
            'endpoint' => 'foreign-endpoint']));
        $client = new YipayClient();
        $this->assertSame($data, $client->verify($data, $this->credentials()));
        $this->assertSame(array_reverse($data, true), $client->verify(array_reverse($data, true), $this->credentials()));
        foreach (['A', 'Z', 'extra', 'zero', 'zero_int', 'key', 'secret', 'checkoutsig', 'endpoint'] as $name) {
            $tampered = $data;
            $tampered[$name] = '';
            $this->assertUnavailable(function () use ($client, $tampered) { $client->verify($tampered, $this->credentials()); });
        }
        $withoutEmpty = $data;
        unset($withoutEmpty['empty']);
        $this->assertSame($withoutEmpty, $client->verify($withoutEmpty, $this->credentials()));
        $foreignSignature = $this->signed($data, $data['key']);
        $this->assertUnavailable(function () use ($client, $foreignSignature) { $client->verify($foreignSignature, $this->credentials()); });
    }

    public function testOptionalEchoFieldsAndAlgorithmAreNotNewRequiredParameters(): void
    {
        $data = $this->callbackData();
        unset($data['pid'], $data['type'], $data['sign_type']);
        $data = $this->signed($data);
        $this->assertSame($data, (new YipayClient())->verify($data, $this->credentials()));
        $data['sign_type'] = 'MD5';
        $data['sign'] = strtoupper($data['sign']);
        $this->assertSame($data, (new YipayClient())->verify($data, $this->credentials()));
    }

    /** @dataProvider modes */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function testSignedValidOtherModeStillRequiresTheCallerToMatchSelectedMode(string $mode): void
    {
        $client = new YipayClient();
        $checkout = $client->create('alipay', $this->credentials(), $this->order());
        $data = $this->signed($this->callbackData(['type' => $mode]));
        $verified = $client->verify($data, $this->credentials());
        $this->assertSame($mode, $verified['type']);
        $this->assertSame($mode === 'alipay', $checkout['fields']['type'] === $verified['type']);
    }

    public function testIntegerInputsAndStringZeroIdentifiersArePreserved(): void
    {
        $client = new YipayClient();
        $fields = $client->create('qqpay', $this->credentials(['pid' => '0']), $this->order(['order_sn' => '0']))['fields'];
        $this->assertSame('0', $fields['pid']);
        $this->assertSame('0', $fields['name']);
        $this->assertSame($this->oracle($fields), $fields['sign']);
        $data = $this->signed($this->callbackData(['pid' => 1001, 'out_trade_no' => 0, 'trade_no' => '0', 'money' => 10]));
        $this->assertSame($data, $client->verify($data, $this->credentials()));
        foreach (['001001', '9223372036854775808', 'merchant-A'] as $pid) {
            $credentials = $this->credentials(['pid' => $pid]);
            $this->assertSame($pid, $client->create('alipay', $credentials, $this->order())['fields']['pid']);
            $data = $this->signed($this->callbackData(['pid' => $pid]));
            $this->assertSame($data, $client->verify($data, $credentials));
        }
    }

    public function testCorrectlySignedWrongAccountAndWrongKeyFail(): void
    {
        foreach (['1002', '01001', '1001 '] as $pid) {
            $data = $this->signed($this->callbackData(['pid' => $pid]));
            $this->assertUnavailable(function () use ($data) { (new YipayClient())->verify($data, $this->credentials()); });
        }
        $data = $this->signed($this->callbackData(), 'wrong-key');
        $this->assertUnavailable(function () use ($data) { (new YipayClient())->verify($data, $this->credentials()); });
        $data = $this->signed($this->callbackData());
        $this->assertUnavailable(function () use ($data) { (new YipayClient())->verify($data, $this->credentials(['key' => 'wrong-key'])); });
    }

    public function testChangesToTransactionAmountAndUnsignedExtraFieldsFail(): void
    {
        $data = $this->signed($this->callbackData());
        foreach (['money' => '12.35', 'out_trade_no' => 'ORDER-2', 'trade_no' => 'PROVIDER-10',
            'type' => 'wxpay', 'extra' => 'unsigned'] as $name => $value) {
            $changed = $data;
            $changed[$name] = $value;
            $this->assertUnavailable(function () use ($changed) { (new YipayClient())->verify($changed, $this->credentials()); });
        }
    }

    /** @dataProvider invalidSignedFields */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSignedFields')]
    public function testEvenCorrectlySignedInvalidProtocolValuesFail(string $name, string $value): void
    {
        $data = $this->signed($this->callbackData([$name => $value]));
        $this->assertUnavailable(function () use ($data) { (new YipayClient())->verify($data, $this->credentials()); });
    }

    public static function invalidSignedFields(): array
    {
        $cases = [];
        foreach (['WAIT_BUYER_PAY', 'TRADE_CLOSED', 'TRADE_FINISHED', 'trade_success', 'TRADE_SUCCESS ', ''] as $value) {
            $cases[] = ['trade_status', $value];
        }
        foreach (['alipay ', 'ALIPAY', 'bank', '1', ''] as $value) $cases[] = ['type', $value];
        foreach (['md5', 'RSA', 'HMAC', 'MD5 ', ''] as $value) $cases[] = ['sign_type', $value];
        foreach (['out_trade_no', 'trade_no'] as $name) {
            $cases[] = [$name, ''];
            $cases[] = [$name, str_repeat('x', 129)];
        }
        foreach (['0', '0.00', '-1', '+1', '1e2', '1.001', '100000000', ' 1', '1 ', '1.', '.01', "1\n"] as $value) {
            $cases[] = ['money', $value];
        }
        return $cases;
    }

    public function testRequiredFieldsCannotBeOmittedEvenWithAValidSignature(): void
    {
        foreach (['money', 'out_trade_no', 'trade_no', 'trade_status'] as $name) {
            $data = $this->callbackData();
            unset($data[$name]);
            $data = $this->signed($data);
            $this->assertUnavailable(function () use ($data) { (new YipayClient())->verify($data, $this->credentials()); });
        }
    }

    /** @dataProvider invalidSignatures */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSignatures')]
    public function testSignatureMustBeExactly32HexBytes($signature): void
    {
        $data = $this->callbackData(['sign' => $signature]);
        $this->assertUnavailable(function () use ($data) { (new YipayClient())->verify($data, $this->credentials()); });
    }

    public static function invalidSignatures(): array
    {
        return [[''], [str_repeat('a', 31)], [str_repeat('a', 33)], [str_repeat('g', 32)],
            [str_repeat('0', 32)], ["761a6075c09870f164b8c051e5255894\n"], [0], [null], [true], [[]]];
    }

    /** @dataProvider badScalarShapes */
    #[\PHPUnit\Framework\Attributes\DataProvider('badScalarShapes')]
    public function testCallbackRejectsFloatBooleanNullAndNestedValues($value): void
    {
        foreach (['extra', 'money', 'out_trade_no', 'pid'] as $name) {
            $data = $this->signed($this->callbackData());
            $data[$name] = $value;
            $this->assertUnavailable(function () use ($data) { (new YipayClient())->verify($data, $this->credentials()); });
        }
    }

    public static function badScalarShapes(): array
    {
        return [[10.0], [true], [false], [null], [[]], [['nested' => 'value']], [(object) ['value' => '10']]];
    }

    public function testNotificationMoneyIsValidatedWithoutChangingItsSignedSpelling(): void
    {
        foreach (['10', '10.0', '10.00', '00010.00', '0.01', '99999999.99'] as $money) {
            $data = $this->signed($this->callbackData(['money' => $money]));
            $this->assertSame($data, (new YipayClient())->verify($data, $this->credentials()));
        }
    }

    public function testCallbackBoundsAndIdentifiers(): void
    {
        $client = new YipayClient();
        $data = $this->signed($this->callbackData(['out_trade_no' => str_repeat('o', 128),
            'trade_no' => str_repeat('t', 128), 'extra' => str_repeat('x', 60000)]));
        $this->assertSame($data, $client->verify($data, $this->credentials()));
        $data = $this->signed($this->callbackData(['extra' => str_repeat('x', 65536)]));
        $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
        $data = $this->signed($this->callbackData());
        $data[str_repeat('x', 64)] = '';
        $this->assertSame($data, $client->verify($data, $this->credentials()));
        $data[str_repeat('x', 65)] = '';
        $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
        $data = $this->signed($this->callbackData());
        for ($i = count($data); $i < 128; $i++) $data['empty_' . $i] = '';
        $this->assertSame($data, $client->verify($data, $this->credentials()));
        $data['overflow'] = '';
        $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
        foreach (['', 'extra.name', 'extra[]', 0] as $name) {
            $data = $this->signed($this->callbackData());
            $data[$name] = 'value';
            $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
        }
        $this->assertUnavailable(function () use ($client) { $client->verify([], $this->credentials()); });
    }

    /** @dataProvider validUrls */
    #[\PHPUnit\Framework\Attributes\DataProvider('validUrls')]
    public function testPreservesSelfHostedEndpointsAndCallbackQueries(string $url): void
    {
        $result = (new YipayClient())->create('wxpay', $this->credentials(['endpoint' => $url]),
            $this->order(['return_url' => $url, 'notify_url' => $url]));
        $this->assertSame($url, $result['endpoint']);
        $this->assertSame($url, $result['fields']['return_url']);
        $this->assertSame($url, $result['fields']['notify_url']);
        $this->assertSame($this->oracle($result['fields']), $result['fields']['sign']);
    }

    public static function validUrls(): array
    {
        $prefix = 'https://pay.invalid/submit?opaque=';
        return [['https://pay.invalid/submit.php'], ['https://pay.invalid/submit.php?'],
            ['https://pay.invalid/submit.php?fixed=1&'],
            ['https://pay.invalid/submit.php?opaque=a%2B%252F&flag&blank=&x=1&&'],
            ['http://localhost:8080/submit.php?fixed=1'], ['http://[::1]:8080/submit.php?'],
            [$prefix . str_repeat('x', 2048 - strlen($prefix))]];
    }

    /** @dataProvider invalidUrls */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUrls')]
    public function testRejectsInvalidEndpointAndCallbackUrls(string $url): void
    {
        $client = new YipayClient();
        $this->assertUnavailable(function () use ($client, $url) {
            $client->create('alipay', $this->credentials(['endpoint' => $url]), $this->order());
        });
        foreach (['return_url', 'notify_url'] as $name) {
            $this->assertUnavailable(function () use ($client, $url, $name) {
                $client->create('alipay', $this->credentials(), $this->order([$name => $url]));
            });
        }
    }

    public static function invalidUrls(): array
    {
        return [[''], ['//pay.invalid/submit'], ['/submit'], ['ftp://pay.invalid/submit'],
            ['javascript:alert(1)'], ['https:///submit'], ['https://pay.invalid:99999/'],
            ['https://user:pass@pay.invalid/submit'], ['https://@pay.invalid/submit'],
            ['https://pay.invalid/submit#'], ['https://pay.invalid/submit#fragment'],
            ["https://pay.invalid/submit?x=1\r\n"], ["https://pay.invalid/submit?x=\0"],
            ["https://pay.invalid/submit?x=\t"], ["https://pay.invalid/submit?x=\x7f"],
            ['https://pay.invalid/a b'], ['https://pay.invalid/a\\b'], ['https://pay.invalid/?x=%ZZ'],
            ['https://pay.invalid/' . str_repeat('x', 2049 - strlen('https://pay.invalid/'))]];
    }

    public function testExactCheckoutShapePreventsExtraFieldsFromOverridingSignatureOrSecret(): void
    {
        $client = new YipayClient();
        foreach (['sign', 'sign_type', 'pid', 'type', 'money', 'key', 'endpoint', 'checkoutsig', 'secret'] as $name) {
            $this->assertUnavailable(function () use ($client, $name) {
                $client->create('alipay', $this->credentials(), $this->order([$name => 'injected']));
            });
        }
        foreach (['order_sn', 'amount', 'return_url', 'notify_url'] as $name) {
            $order = $this->order();
            unset($order[$name]);
            $this->assertUnavailable(function () use ($client, $order) { $client->create('alipay', $this->credentials(), $order); });
        }
        foreach (['', 'ALIPAY', 'bank'] as $mode) {
            $this->assertUnavailable(function () use ($client, $mode) { $client->create($mode, $this->credentials(), $this->order()); });
        }
        foreach (['', str_repeat('x', 129), 123, false, []] as $sn) {
            $this->assertUnavailable(function () use ($client, $sn) {
                $client->create('alipay', $this->credentials(), $this->order(['order_sn' => $sn]));
            });
        }
        foreach ([10.0, 10, true, null, [], '0', '-1', '1e2', '1.001'] as $amount) {
            $this->assertUnavailable(function () use ($client, $amount) {
                $client->create('alipay', $this->credentials(), $this->order(['amount' => $amount]));
            });
        }
    }

    public function testInvalidCredentialsYieldOnlyGenericUnchainedFailuresInBothApis(): void
    {
        $client = new YipayClient();
        $data = $this->signed($this->callbackData());
        $cases = [$this->credentials(['pid' => '']), $this->credentials(['pid' => 1001]),
            $this->credentials(['pid' => str_repeat('x', 65)]), $this->credentials(['key' => '']),
            $this->credentials(['key' => str_repeat('k', 513)]), $this->credentials(['key' => true]),
            $this->credentials(['endpoint' => []]), $this->credentials(['endpoint' => 'https://user@pay.invalid/']),
            $this->credentials(['secret' => 'injected']), $this->credentials(['sign' => 'injected'])];
        foreach (['pid', 'endpoint', 'key'] as $name) {
            $credentials = $this->credentials();
            unset($credentials[$name]);
            $cases[] = $credentials;
        }
        foreach ($cases as $credentials) {
            $this->assertUnavailable(function () use ($client, $credentials) { $client->create('alipay', $credentials, $this->order()); });
            $this->assertUnavailable(function () use ($client, $credentials, $data) { $client->verify($data, $credentials); });
        }
    }
}
