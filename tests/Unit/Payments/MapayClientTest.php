<?php

namespace Tests\Unit\Payments;

use App\Payments\FormNotification;
use App\Payments\MapayClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/app/Payments/CurrencyAmount.php';
require_once dirname(__DIR__, 3) . '/app/Payments/FormNotification.php';
require_once dirname(__DIR__, 3) . '/app/Payments/MapayClient.php';

class MapayClientTest extends TestCase
{
    private const KEY = 'fixture%2B key+&raw';

    private function credentials(array $changes = []): array
    {
        return array_replace(['id' => '1001', 'endpoint' => 'https://pay.invalid/create?', 'key' => self::KEY], $changes);
    }

    private function order(array $changes = []): array
    {
        return array_replace(['order_sn' => 'ORDER-1', 'amount' => '12.34',
            'return_url' => 'https://shop.invalid/return?token=a%2Bb&x=1',
            'notify_url' => 'https://shop.invalid/notify?route=mapay&opaque=%26'], $changes);
    }

    private function callbackData(array $changes = []): array
    {
        return array_replace(['id' => '1001', 'money' => '12.34', 'pay_id' => 'ORDER-1',
            'pay_no' => 'PROVIDER-9', 'param' => 'mzfb', 'type' => '1'], $changes);
    }

    private function oracle(array $data, string $key = self::KEY): string
    {
        // Fixed ASCII field order, raw bytes and fixture key; no client/helper signing code.
        $names = ['A', 'Z', 'act', 'chart', 'empty', 'extra', 'id', 'money', 'notify_url',
            'outTime', 'page', 'param', 'pay_id', 'pay_no', 'pay_type', 'price', 'return_url',
            'type', 'zero', 'zero_int'];
        unset($data['sign']);
        $this->assertSame([], array_values(array_diff(array_keys($data), $names)));
        $parts = [];
        foreach ($names as $name) {
            if (array_key_exists($name, $data) && $data[$name] !== '') $parts[] = $name . '=' . $data[$name];
        }
        return md5(implode('&', $parts) . $key);
    }

    private function signed(array $data, string $key = self::KEY): array
    {
        $data['sign'] = $this->oracle($data, $key);
        return $data;
    }

    private function createOracle(string $mode, string $amount = '12.34', ?array $order = null, int $id = 1001): string
    {
        $order = $order ?? $this->order();
        $types = ['mqq' => '2', 'mzfb' => '1', 'mwx' => '3'];
        $fields = ['chart' => 'utf-8', 'id' => (string) $id, 'notify_url' => $order['notify_url'],
            'outTime' => '120', 'page' => '1', 'param' => $mode, 'pay_id' => $order['order_sn'],
            'price' => $amount, 'return_url' => $order['return_url'], 'type' => $types[$mode]];
        $query = [];
        foreach ($fields as $name => $value) $query[] = $name . '=' . urlencode($value);
        return implode('&', $query) . '&sign=' . $this->oracle($fields);
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
    public function testCreatesAllModesWithTheExactLegacyWireContract(string $mode): void
    {
        $url = (new MapayClient())->create($mode, $this->credentials(), $this->order());
        $this->assertSame('https://pay.invalid/create?' . $this->createOracle($mode), $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $fields);
        $this->assertArrayNotHasKey('act', $fields);
        $this->assertArrayNotHasKey('pay_type', $fields);
        $this->assertArrayNotHasKey('key', $fields);
        $this->assertSame('12.34', $fields['price']);
        $this->assertSame($mode, $fields['param']);
        $this->assertSame($this->order()['notify_url'], $fields['notify_url']);
        $this->assertSame($this->order()['return_url'], $fields['return_url']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $fields['sign']);
    }

    public static function modes(): array
    {
        return [['mqq'], ['mzfb'], ['mwx']];
    }

    public function testMatchesGoldenBytesCapturedFromPhp74Helpers(): void
    {
        $golden = 'chart=utf-8&id=1001&notify_url=https%3A%2F%2Fshop.invalid%2Fnotify%3Froute%3Dmapay%26opaque%3D%2526'
            . '&outTime=120&page=1&param=mzfb&pay_id=ORDER-1&price=12.34'
            . '&return_url=https%3A%2F%2Fshop.invalid%2Freturn%3Ftoken%3Da%252Bb%26x%3D1'
            . '&type=1&sign=961a2eda0877154ef19460b9cea6eb7e';
        $this->assertSame($golden, $this->createOracle('mzfb'));
        $this->assertSame('https://pay.invalid/create?' . $golden,
            (new MapayClient())->create('mzfb', $this->credentials(), $this->order()));
        $data = $this->callbackData(['empty' => '', 'zero' => '0']);
        $this->assertSame('c43fe925621e640d99a76fd2841c2f22', $this->oracle($data));
        $data['sign'] = 'c43fe925621e640d99a76fd2841c2f22';
        $this->assertSame($data, (new MapayClient())->verify($data, $this->credentials()));
        if (PHP_VERSION_ID < 80000) {
            require_once dirname(__DIR__, 3) . '/app/Helpers/functions.php';
            $order = $this->order();
            $legacy = ['id' => 1001, 'price' => 12.34, 'pay_id' => $order['order_sn'], 'param' => 'mzfb',
                'act' => 0, 'outTime' => 120, 'page' => 1, 'return_url' => $order['return_url'],
                'notify_url' => $order['notify_url'], 'pay_type' => 0, 'chart' => 'utf-8', 'type' => 1];
            $this->assertSame($golden, md5_signquery($legacy, self::KEY));
            $this->assertSame($data['sign'], md5(signquery_string($data) . self::KEY));
        }
    }

    /** @dataProvider decimalAmounts */
    #[\PHPUnit\Framework\Attributes\DataProvider('decimalAmounts')]
    public function testUsesExactDecimalStringsWithoutFloatingPoint(string $amount, string $expected): void
    {
        $order = $this->order(['amount' => $amount]);
        $this->assertSame('https://pay.invalid/create?' . $this->createOracle('mwx', $expected, $order),
            (new MapayClient())->create('mwx', $this->credentials(), $order));
    }

    public static function decimalAmounts(): array
    {
        return [['0.01', '0.01'], ['0.10', '0.10'], ['10', '10.00'], ['12.3', '12.30'],
            ['00012.34', '12.34'], ['99999999.99', '99999999.99']];
    }

    /** @dataProvider endpoints */
    #[\PHPUnit\Framework\Attributes\DataProvider('endpoints')]
    public function testPreservesConfiguredEndpointAndOpaqueQuery(string $endpoint, string $prefix): void
    {
        $this->assertSame($prefix . $this->createOracle('mqq'),
            (new MapayClient())->create('mqq', $this->credentials(['endpoint' => $endpoint]), $this->order()));
    }

    public static function endpoints(): array
    {
        return [
            ['https://pay.invalid/create', 'https://pay.invalid/create?'],
            ['https://pay.invalid/create?', 'https://pay.invalid/create?'],
            ['https://pay.invalid/create?fixed=1&', 'https://pay.invalid/create?fixed=1&'],
            ['https://pay.invalid/create?fixed=1', 'https://pay.invalid/create?fixed=1&'],
            ['https://pay.invalid/create?opaque=a%2B%252F&flag&blank=&x=1&&',
                'https://pay.invalid/create?opaque=a%2B%252F&flag&blank=&x=1&&'],
            ['http://localhost:8080/create?', 'http://localhost:8080/create?'],
            ['http://[::1]:8080/create?fixed=1&', 'http://[::1]:8080/create?fixed=1&'],
        ];
    }

    public function testPreservesStringZeroAndEncodesOpaqueOrderBytesOnlyOnce(): void
    {
        $client = new MapayClient();
        foreach (['0', " \u{4e2d} + & = %2B\t\r\n"] as $sn) {
            $order = $this->order(['order_sn' => $sn]);
            $url = $client->create('mzfb', $this->credentials(), $order);
            $this->assertSame('https://pay.invalid/create?' . $this->createOracle('mzfb', '12.34', $order), $url);
            parse_str(parse_url($url, PHP_URL_QUERY), $fields);
            $this->assertSame($sn, $fields['pay_id']);
            $this->assertStringNotContainsString("\r", $url);
            $this->assertStringNotContainsString("\n", $url);
        }
        $data = $this->signed($this->callbackData(['pay_id' => '0', 'zero' => '0', 'zero_int' => 0, 'empty' => '']));
        $this->assertSame($data, $client->verify($data, $this->credentials()));
        foreach (['zero', 'zero_int'] as $name) {
            $changed = $data;
            $changed[$name] = '';
            $this->assertUnavailable(function () use ($client, $changed) { $client->verify($changed, $this->credentials()); });
        }
        $data['extra'] = '';
        $this->assertSame($data, $client->verify($data, $this->credentials()));
    }

    public function testCallbackKeepsRawWhitespaceUnknownFieldsAndActualProviderTransaction(): void
    {
        $data = $this->signed($this->callbackData(['A' => 'upper', 'Z' => 'last upper',
            'extra' => "  \u{4e2d} + %2B & x=y\t\r\n  ", 'param' => " mzfb \t\r\n "]));
        $body = http_build_query($data, '', '&', PHP_QUERY_RFC1738);
        $parsed = FormNotification::parse($body);
        $client = new MapayClient();
        $this->assertSame($data, $client->verify($parsed, $this->credentials()));
        $this->assertSame('PROVIDER-9', $client->verify($parsed, $this->credentials())['pay_no']);
        $this->assertNotSame($data['pay_id'], $data['pay_no']);
        $data['sign'] = strtoupper($data['sign']);
        $this->assertSame($data, $client->verify($data, $this->credentials()));
    }

    public function testOptionalIdentityAndModeFieldsLeaveModeChecksToTheCaller(): void
    {
        $client = new MapayClient();
        $data = ['pay_id' => 'ORDER-1', 'pay_no' => 'PROVIDER-9', 'money' => '12.34'];
        $signed = $this->signed($data);
        $this->assertSame($signed, $client->verify($signed, $this->credentials()));
        $signed = $this->signed($data + ['type' => '99', 'param' => ' caller must check this ']);
        $this->assertSame($signed, $client->verify($signed, $this->credentials()));
        $signed = $this->signed(['id' => 1001, 'pay_id' => 0, 'pay_no' => 9, 'money' => 10, 'extra' => 0]);
        $this->assertSame($signed, $client->verify($signed, $this->credentials()));
    }

    public function testIntegerMerchantWireIdentityIsBoundedAndCallbackComparisonIsExact(): void
    {
        $credentials = $this->credentials(['id' => '0001001']);
        $client = new MapayClient();
        $this->assertSame('https://pay.invalid/create?' . $this->createOracle('mzfb'),
            $client->create('mzfb', $credentials, $this->order()));
        $data = $this->signed($this->callbackData());
        $this->assertSame($data, $client->verify($data, $credentials));
        $max = (string) PHP_INT_MAX;
        $this->assertSame('https://pay.invalid/create?' . $this->createOracle('mzfb', '12.34', null, PHP_INT_MAX),
            $client->create('mzfb', $this->credentials(['id' => $max]), $this->order()));
        foreach (['01001', '1002', '', '0', 0, '+1001', '1.001e3'] as $id) {
            $data = $this->signed($this->callbackData(['id' => $id]));
            $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
        }
    }

    public function testRejectsTamperingWrongKeysAndSignedForeignAccount(): void
    {
        $client = new MapayClient();
        $data = $this->signed($this->callbackData(['extra' => 'future field']));
        foreach (['pay_id', 'pay_no', 'money', 'param', 'type', 'extra'] as $name) {
            $changed = $data;
            $changed[$name] .= '1';
            $this->assertUnavailable(function () use ($client, $changed) { $client->verify($changed, $this->credentials()); });
        }
        $this->assertUnavailable(function () use ($client, $data) {
            $client->verify($data, $this->credentials(['key' => 'wrong-fixture-key']));
        });
        $foreign = $this->signed($this->callbackData(['id' => '1002']));
        $this->assertUnavailable(function () use ($client, $foreign) { $client->verify($foreign, $this->credentials()); });
    }

    public function testRequiresBothOrderAndProviderTransactionAndMoney(): void
    {
        $client = new MapayClient();
        foreach (['pay_id', 'pay_no', 'money', 'sign'] as $name) {
            $data = $this->callbackData();
            unset($data[$name]);
            $data = $this->signed($data);
            if ($name === 'sign') unset($data['sign']);
            $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
        }
        foreach (['pay_id', 'pay_no'] as $name) {
            foreach (['', str_repeat('x', 129)] as $value) {
                $data = $this->signed($this->callbackData([$name => $value]));
                $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
            }
        }
    }

    /** @dataProvider invalidAmounts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAmounts')]
    public function testRejectsInvalidDecimalAmountsInOrdersAndCallbacks($amount): void
    {
        $client = new MapayClient();
        $this->assertUnavailable(function () use ($client, $amount) {
            $client->create('mzfb', $this->credentials(), $this->order(['amount' => $amount]));
        });
        $data = $this->callbackData(['money' => $amount]);
        $data['sign'] = is_string($amount) || is_int($amount) ? $this->oracle($data) : str_repeat('a', 32);
        $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
    }

    public static function invalidAmounts(): array
    {
        return array_map(function ($value) { return [$value]; },
            ['', '0', '0.00', '-1.00', '+1.00', '1.234', '1e2', ' 1.00', '1.00 ',
                '100000000', 'NaN', INF, NAN, 1.25, true, null, []]);
    }

    public function testCreateRequiresExactStringFieldsAndKnownModes(): void
    {
        $client = new MapayClient();
        foreach (['', 'wx', 'unknown', 'MZFB', 'mzfb '] as $mode) {
            $this->assertUnavailable(function () use ($client, $mode) { $client->create($mode, $this->credentials(), $this->order()); });
        }
        foreach (array_keys($this->order()) as $name) {
            $order = $this->order();
            unset($order[$name]);
            $this->assertUnavailable(function () use ($client, $order) { $client->create('mzfb', $this->credentials(), $order); });
            foreach ([1, 1.0, true, null, []] as $value) {
                $order = $this->order([$name => $value]);
                $this->assertUnavailable(function () use ($client, $order) { $client->create('mzfb', $this->credentials(), $order); });
            }
        }
        foreach (['act', 'pay_type', 'sign', 'param', 'extra'] as $name) {
            $order = $this->order([$name => 'injected']);
            $this->assertUnavailable(function () use ($client, $order) { $client->create('mzfb', $this->credentials(), $order); });
        }
        foreach (['', str_repeat('x', 129), "order\0sn"] as $sn) {
            $this->assertUnavailable(function () use ($client, $sn) { $client->create('mzfb', $this->credentials(), $this->order(['order_sn' => $sn])); });
        }
    }

    public function testRejectsMalformedCredentialsWithOnlyGenericErrors(): void
    {
        $client = new MapayClient();
        $bad = [[], $this->credentials(['extra' => 'secret']), $this->credentials(['id' => '0']),
            $this->credentials(['id' => str_repeat('9', 64)]), $this->credentials(['id' => '-1']),
            $this->credentials(['id' => '1e3']), $this->credentials(['key' => '']),
            $this->credentials(['key' => str_repeat('s', 513)]), $this->credentials(['key' => "secret\0key"])];
        foreach (array_keys($this->credentials()) as $name) {
            $credentials = $this->credentials();
            unset($credentials[$name]);
            $bad[] = $credentials;
            foreach ([1, 1.0, true, null, []] as $value) $bad[] = $this->credentials([$name => $value]);
        }
        foreach ($bad as $credentials) {
            $this->assertUnavailable(function () use ($client, $credentials) { $client->create('mzfb', $credentials, $this->order()); });
            $this->assertUnavailable(function () use ($client, $credentials) { $client->verify($this->signed($this->callbackData()), $credentials); });
        }
    }

    /** @dataProvider invalidUrls */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUrls')]
    public function testRejectsMalformedAndOversizedUrls(string $url): void
    {
        $client = new MapayClient();
        $this->assertUnavailable(function () use ($client, $url) { $client->create('mzfb', $this->credentials(['endpoint' => $url]), $this->order()); });
        foreach (['return_url', 'notify_url'] as $name) {
            $this->assertUnavailable(function () use ($client, $name, $url) { $client->create('mzfb', $this->credentials(), $this->order([$name => $url])); });
        }
    }

    public static function invalidUrls(): array
    {
        return array_map(function ($value) { return [$value]; }, ['', 'ftp://pay.invalid/create',
            'javascript:alert(1)', 'data:text/plain,x', 'file:///tmp/fixture', '//pay.invalid/create',
            'https://user@pay.invalid/create', 'https://user:fixture-secret@pay.invalid/create',
            'https://pay.invalid/create#fragment', 'https://pay.invalid/create#', 'https://pay.invalid/a b',
            'https://pay.invalid/a\\b', "https://pay.invalid/a\r\nLocation:x", "https://pay.invalid/a\0b",
            'https://pay.invalid/%', 'https://pay.invalid/%GG', 'https://pay.invalid/' . str_repeat('x', 2048)]);
    }

    public function testAlsoBoundsTheFinalEncodedRedirectUrl(): void
    {
        $client = new MapayClient();
        $endpoint = 'https://pay.invalid/' . str_repeat('x', 1850) . '?';
        $this->assertLessThanOrEqual(2048, strlen($endpoint));
        $this->assertUnavailable(function () use ($client, $endpoint) { $client->create('mzfb', $this->credentials(['endpoint' => $endpoint]), $this->order()); });
        $notify = 'https://shop.invalid/notify?q=' . str_repeat('%26', 500);
        $this->assertLessThanOrEqual(2048, strlen($notify));
        $this->assertUnavailable(function () use ($client, $notify) { $client->create('mzfb', $this->credentials(), $this->order(['notify_url' => $notify])); });
    }

    public function testCallbackRejectsInvalidFieldNamesShapesUtf8AndControlBytes(): void
    {
        $client = new MapayClient();
        $base = $this->signed($this->callbackData());
        foreach (['', '_hidden', 'x.y', 'x-y', 'x[]', 'x&y', 'x=y', 1, str_repeat('a', 65)] as $name) {
            $data = $base + [$name => 'x'];
            $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
        }
        foreach ([[], ['x'], new \stdClass(), 1.25, true, false, null, "x\0y", "x\x01y", "x\x7Fy", "x\xFFy"] as $value) {
            $data = $base + ['extra' => $value];
            $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
        }
        foreach ([[], ['value'], ['sign' => str_repeat('a', 32)]] as $data) {
            $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
        }
    }

    public function testCallbackHasAnAggregate64KibBudget(): void
    {
        $client = new MapayClient();
        $data = $this->signed($this->callbackData(['extra' => '']));
        $bytes = 0;
        foreach ($data as $name => $value) $bytes += strlen($name) + strlen($value) + 2;
        $data['extra'] = str_repeat('x', 65536 - $bytes);
        $data = $this->signed($data);
        $this->assertSame($data, $client->verify($data, $this->credentials()));
        $data['extra'] .= 'x';
        $data = $this->signed($data);
        $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
        $data = $this->signed($this->callbackData(['A' => str_repeat('a', 33000), 'Z' => str_repeat('z', 33000)]));
        $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
    }

    public function testCallbackRequiresAnExact32HexSignature(): void
    {
        $client = new MapayClient();
        foreach (['', str_repeat('a', 31), str_repeat('a', 33), str_repeat('g', 32),
            str_repeat('a', 32) . "\n", 123, true, null, []] as $sign) {
            $data = $this->callbackData(['sign' => $sign]);
            $this->assertUnavailable(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
        }
    }
}
