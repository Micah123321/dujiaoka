<?php

namespace Tests\Unit\Payments;

use App\Payments\PayjsClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PayjsClientTest extends TestCase
{
    private const KEY = 'fixture-payjs-key';
    private $history = [];
    private $mock;

    private static function credentials(): array
    {
        return ['mchid' => '1900000109', 'key' => self::KEY];
    }

    private static function order(): array
    {
        return ['body' => "Fish & chips + 50% = \"quote\" \xE4\xB8\xAD\xE6\x96\x87",
            'total_fee' => '10', 'out_trade_no' => 'fixture_order_123',
            'notify_url' => 'https://shop.invalid/pay/notify?a=1&b=%2B'];
    }

    private static function gatewayResponse(): array
    {
        return ['return_code' => 1, 'return_msg' => 'SUCCESS', 'mchid' => '1900000109',
            'total_fee' => 10, 'out_trade_no' => 'fixture_order_123', 'payjs_order_id' => 'fixture_payjs_123',
            'code_url' => 'weixin://wxpay/bizpayurl?pr=fixture_123'];
    }

    private static function protocolOracle(array $data, string $key = self::KEY): array
    {
        // Fixed field order and raw values are independent of the client's query/sort code.
        $names = ['A', 'body', 'code_url', 'empty', 'mchid', 'notify_url', 'out_trade_no',
            'payjs_order_id', 'return_code', 'return_msg', 'total_fee', 'transaction_id', 'x', 'zero', 'zero_string'];
        unset($data['sign']);
        self::assertSame([], array_values(array_diff(array_keys($data), $names)));
        $parts = [];
        foreach ($names as $name) {
            if (!array_key_exists($name, $data) || in_array($data[$name], ['', 0, '0'], true)) continue;
            $parts[] = $name . '=' . $data[$name];
        }
        $data['sign'] = strtoupper(md5(implode('&', $parts) . '&key=' . urldecode($key)));
        return $data;
    }

    private static function wire(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function client(array $responses, array $defaults = []): PayjsClient
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        return new PayjsClient(new Client(['handler' => $stack] + $defaults));
    }

    private function assertFailure(callable $call): void
    {
        try {
            $call();
        } catch (RuntimeException $exception) {
            $this->assertSame('Payment provider unavailable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            return;
        }
        $this->fail('Expected provider failure');
    }

    public function testFixedEndpointEncodedFieldsAndCompleteDefaultIsolation(): void
    {
        $signed = self::protocolOracle(self::gatewayResponse());
        $callback = function () { $this->fail('Inherited callback executed'); };
        $client = $this->client([new Response(200, [], self::wire($signed))], [
            'base_uri' => 'http://fixture.invalid/wrong', 'verify' => false, 'allow_redirects' => true,
            'http_errors' => true, 'connect_timeout' => 99, 'timeout' => 99, 'read_timeout' => 99,
            'auth' => ['fixture-user', 'fixture-password'], 'cookies' => true,
            'headers' => ['Host' => 'fixture.invalid', 'Authorization' => 'fixture-secret',
                'Cookie' => 'fixture-cookie', 'Content-Type' => 'application/json', 'X-Default' => 'secret'],
            'query' => ['injected' => '1'], 'body' => 'fixture-default-body', 'json' => ['injected' => true],
            'form_params' => ['injected' => '1'], 'multipart' => [['name' => 'injected', 'contents' => '1']],
            'proxy' => 'http://proxy.invalid:8080', 'cert' => 'fixture-cert', 'ssl_key' => 'fixture-key',
            'curl' => [64 => false, 10002 => 'http://fixture.invalid'],
            'stream_context' => ['ssl' => ['verify_peer' => false]], 'debug' => true, 'sink' => 'php://temp',
            'on_headers' => $callback, 'on_stats' => $callback, 'progress' => $callback, 'delay' => 1000,
            'decode_content' => true, 'expect' => true, 'stream' => false, 'version' => '1.0',
            'idn_conversion' => true, 'force_ip_resolve' => 'v4', 'protocols' => ['http'],
            'defaults' => ['verify' => false], 'arbitrary_external_option' => 'fixture-secret',
        ]);
        $this->assertSame($signed, $client->create(self::credentials(), self::order()));
        $this->assertCount(1, $this->history);
        $this->assertCount(0, $this->mock);
        $entry = $this->history[0];
        $request = $entry['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://payjs.cn/api/native', (string) $request->getUri());
        $this->assertSame('payjs.cn', $request->getHeaderLine('Host'));
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('1.1', $request->getProtocolVersion());
        foreach (['Authorization', 'Cookie', 'X-Default'] as $name) $this->assertFalse($request->hasHeader($name));
        foreach (['verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'connect_timeout' => 3,
            'timeout' => 10, 'read_timeout' => 10, 'cookies' => false, 'proxy' => '', 'debug' => false,
            'decode_content' => false, 'expect' => false, 'stream' => true, 'protocols' => ['https']] as $name => $value) {
            $this->assertSame($value, $entry['options'][$name], $name);
        }
        foreach (['auth', 'base_uri', 'headers', 'body', 'json', 'form_params', 'multipart', 'query', 'curl',
            'stream_context', 'cert', 'ssl_key', 'sink', 'on_headers', 'on_stats', 'progress', 'delay',
            'force_ip_resolve', 'defaults', 'arbitrary_external_option'] as $name) {
            $this->assertArrayNotHasKey($name, $entry['options']);
        }
        $raw = (string) $request->getBody();
        parse_str($raw, $fields);
        $order = self::order();
        $expected = self::protocolOracle(['body' => $order['body'], 'mchid' => self::credentials()['mchid'],
            'notify_url' => $order['notify_url'], 'out_trade_no' => $order['out_trade_no'], 'total_fee' => $order['total_fee']]);
        $this->assertSame($expected, $fields);
        $this->assertStringContainsString('Fish+%26+chips+%2B+50%25+%3D', $raw);
        $this->assertStringContainsString('%E4%B8%AD%E6%96%87', $raw);
        $this->assertStringContainsString('b%3D%252B', $raw);
        $this->assertStringNotContainsString(self::KEY, $raw);
        $this->assertCount(6, $fields);
    }

    public function testFreshCredentialsRequestsAndVendorZeroOmission(): void
    {
        $first = self::protocolOracle(self::gatewayResponse());
        $credentials = ['mchid' => str_repeat('9', 64), 'key' => 'second+key%2B%26space'];
        $order = ['body' => '0', 'out_trade_no' => '0', 'total_fee' => '2147483647',
            'notify_url' => 'http://shop.invalid/notify'];
        $result = array_replace(self::gatewayResponse(), ['return_code' => '1', 'mchid' => $credentials['mchid'],
            'total_fee' => '2147483647', 'out_trade_no' => 0, 'payjs_order_id' => 123,
            'empty' => '', 'zero' => 0, 'zero_string' => '0']);
        $second = self::protocolOracle($result, $credentials['key']);
        $client = $this->client([new Response(200, [], self::wire($first)), new Response(200, [], self::wire($second))]);
        $this->assertSame($first, $client->create(self::credentials(), self::order()));
        $this->assertSame($second, $client->create($credentials, $order));
        parse_str((string) $this->history[0]['request']->getBody(), $firstFields);
        parse_str((string) $this->history[1]['request']->getBody(), $secondFields);
        $this->assertNotSame($firstFields['mchid'], $secondFields['mchid']);
        $this->assertArrayNotHasKey('body', $secondFields);
        $this->assertArrayNotHasKey('out_trade_no', $secondFields);
        $expected = self::protocolOracle(['mchid' => $credentials['mchid'],
            'notify_url' => $order['notify_url'], 'total_fee' => $order['total_fee']], $credentials['key']);
        $this->assertSame($expected, $secondFields);
        $this->assertCount(2, $this->history);
    }

    public function testLegacyResponseCanOmitMerchantEchoButStillRequiresItsSignature(): void
    {
        $data = self::gatewayResponse(); unset($data['mchid']);
        $signed = self::protocolOracle($data);
        $client = $this->client([new Response(200, [], self::wire($signed))]);
        $this->assertSame($signed, $client->create(self::credentials(), self::order()));
        $this->assertSame($signed, $client->verify($signed, self::credentials()));
        $this->assertFailure(function () use ($client, $signed) {
            $client->verify($signed, array_replace(self::credentials(), ['key' => 'another-merchant-key']));
        });
    }

    public function testNumericJsonIdentitiesMatchByExactStringValue(): void
    {
        $order = array_replace(self::order(), ['out_trade_no' => '123', 'total_fee' => '1']);
        $data = array_replace(self::gatewayResponse(), ['mchid' => 1900000109, 'out_trade_no' => 123, 'total_fee' => 1]);
        $signed = self::protocolOracle($data);
        $client = $this->client([new Response(200, [], self::wire($signed))]);
        $this->assertSame($signed, $client->create(self::credentials(), $order));
    }

    public function testVerifyUsesExplicitDataAndLeavesBusinessStatusToCaller(): void
    {
        $data = array_replace(self::gatewayResponse(), ['return_code' => 0, 'total_fee' => '0',
            'out_trade_no' => 'other_order', 'payjs_order_id' => '', 'code_url' => 'not-a-native-qr',
            'return_msg' => 'DECLINED', 'transaction_id' => 'fixture_transaction']);
        $signed = self::protocolOracle($data);
        $signed['sign'] = strtolower($signed['sign']);
        $client = $this->client([]);
        $this->assertSame($signed, $client->verify($signed, self::credentials()));
        $this->assertCount(0, $this->history);
    }

    public function testOmittedEmptyAndZeroFieldsStillReturnUnchangedAndOtherFieldsAreSigned(): void
    {
        $data = self::protocolOracle(['mchid' => '1900000109', 'A' => 'first', 'x' => 'A&B=+%2B',
            'empty' => '', 'zero' => 0, 'zero_string' => '0']);
        $client = $this->client([]);
        $this->assertSame($data, $client->verify($data, self::credentials()));
        $data['zero'] = '';
        $this->assertSame($data, $client->verify($data, self::credentials()));
        $data['x'] = 'changed';
        $this->assertFailure(function () use ($client, $data) { $client->verify($data, self::credentials()); });
    }

    public function testCapturedLegacySdkSignatureRetainsEscapedKeyAndZeroFiltering(): void
    {
        // Captured with the installed SDK's sign/checkSign before removing its framework wrapper.
        $key = 'short+key%2B%26';
        $signed = self::protocolOracle(['mchid' => '1900000109', 'body' => self::order()['body'],
            'notify_url' => self::order()['notify_url'], 'empty' => '', 'zero' => 0, 'zero_string' => '0'], $key);
        $this->assertSame('AA527643E355C3174C9B3A5FBD47419E', $signed['sign']);
        $client = $this->client([]);
        $this->assertSame($signed, $client->verify($signed, ['mchid' => '1900000109', 'key' => $key]));
    }

    /** @dataProvider invalidVerification */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidVerification')]
    public function testVerifyRejectsTamperingWrongMerchantAndSignatureShape(array $data, array $credentials): void
    {
        $client = $this->client([]);
        $this->assertFailure(function () use ($client, $data, $credentials) { $client->verify($data, $credentials); });
        $this->assertCount(0, $this->history);
    }

    public static function invalidVerification(): array
    {
        $signed = self::protocolOracle(self::gatewayResponse());
        $cases = [];
        foreach (['', str_repeat('G', 32), str_repeat('A', 31), str_repeat('A', 33), str_repeat('A', 64),
            str_repeat('A', 32) . "\n", '0e123456789012345678901234567890', 123, true, [], null] as $sign) {
            $cases[] = [array_replace($signed, ['sign' => $sign]), self::credentials()];
        }
        foreach (['sign', 'mchid'] as $name) {
            $data = $signed;
            unset($data[$name]);
            $cases[] = [$data, self::credentials()];
        }
        $cases[] = [array_replace($signed, ['total_fee' => '11']), self::credentials()];
        $cases[] = [array_replace($signed, ['out_trade_no' => 'tampered']), self::credentials()];
        $cases[] = [$signed, array_replace(self::credentials(), ['key' => 'wrong-key'])];
        foreach (['1900000110', 1900000110, '01900000109', '1900000109e0', '', -1] as $merchant) {
            $data = self::protocolOracle(array_replace(self::gatewayResponse(), ['mchid' => $merchant]));
            $cases[] = [$data, self::credentials()];
        }
        return $cases;
    }

    /** @dataProvider invalidFlatData */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFlatData')]
    public function testVerifyRejectsInvalidFlatDataEvenBeforeSignatureComparison(array $patch): void
    {
        $data = array_replace(self::protocolOracle(self::gatewayResponse()), $patch);
        $client = $this->client([]);
        $this->assertFailure(function () use ($client, $data) { $client->verify($data, self::credentials()); });
        $this->assertCount(0, $this->history);
    }

    public static function invalidFlatData(): array
    {
        $cases = [];
        foreach ([[], new \stdClass(), null, false, true, 10.0, INF, NAN, "\xFF", "bad\0text",
            "bad\x01text", str_repeat('x', 65537)] as $value) $cases[] = [['x' => $value]];
        foreach ([0, '', '1field', 'a[b]', 'x&key', 'a=b', str_repeat('x', 65)] as $name) $cases[] = [[$name => 'value']];
        foreach (['1e1', '1E1', '10.0', '010', '+10', '-1', '2147483648', '99999999999999999999999',
            '10 ', "10\n", 10.0, [], false] as $amount) $cases[] = [['total_fee' => $amount]];
        $cases[] = [['x' => str_repeat('x', 33000), 'body' => str_repeat('x', 33000)]];
        $many = [];
        for ($i = 0; $i < 129; $i++) $many['x' . $i] = '1';
        $cases[] = [$many];
        return $cases;
    }

    /** @dataProvider invalidCredentials */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCredentials')]
    public function testInvalidCredentialsFailBeforeIoForBothApis(array $credentials): void
    {
        $client = $this->client([]);
        $this->assertFailure(function () use ($client, $credentials) { $client->create($credentials, self::order()); });
        $data = self::protocolOracle(self::gatewayResponse());
        $this->assertFailure(function () use ($client, $credentials, $data) { $client->verify($data, $credentials); });
        $this->assertCount(0, $this->history);
    }

    public static function invalidCredentials(): array
    {
        $cases = [[[]], [['mchid' => '1900000109']], [['key' => self::KEY]],
            [self::credentials() + ['merchant_id' => 'other']], [self::credentials() + ['pem' => 'other']],
            [self::credentials() + ['api_url' => 'http://fixture.invalid']]];
        foreach (['', 'merchant', '1e2', '1.0', '-1', ' 123', '123 ', str_repeat('1', 65), 123, [], null, true] as $value) {
            $cases[] = [array_replace(self::credentials(), ['mchid' => $value])];
        }
        foreach (['', str_repeat('k', 513), "key\n", "key\0", "\xFF", [], 123, false, null] as $value) {
            $cases[] = [array_replace(self::credentials(), ['key' => $value])];
        }
        return $cases;
    }

    public function testBoundedAsciiKeysDoNotRequireThirtyTwoBytes(): void
    {
        $client = $this->client([]);
        foreach (['x', str_repeat('k', 512), 'with space+percent%2B'] as $key) {
            $data = self::protocolOracle(self::gatewayResponse(), $key);
            $this->assertSame($data, $client->verify($data, ['mchid' => '1900000109', 'key' => $key]));
        }
    }

    /** @dataProvider invalidOrders */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOrders')]
    public function testOrderAllowlistAndIntegerBoundsFailBeforeIo(array $order): void
    {
        $client = $this->client([]);
        $this->assertFailure(function () use ($client, $order) { $client->create(self::credentials(), $order); });
        $this->assertCount(0, $this->history);
    }

    public static function invalidOrders(): array
    {
        $cases = [];
        foreach (array_keys(self::order()) as $name) {
            $order = self::order();
            unset($order[$name]);
            $cases[] = [$order];
        }
        foreach (['mchid', '_mchid', 'sign', 'api_url', 'type', 'attach', 'fee_type'] as $name) {
            $cases[] = [self::order() + [$name => 'injected']];
        }
        foreach (['0', '00', '01', '-1', '+1', '1.0', '1e2', '2147483648', '999999999999999999999',
            "1\n", 10, 1.0, false, null, []] as $value) $cases[] = [array_replace(self::order(), ['total_fee' => $value])];
        foreach (['', str_repeat('x', 129), "\xFF", "bad\0text", [], 1, false] as $value) {
            $cases[] = [array_replace(self::order(), ['body' => $value])];
        }
        foreach (['', str_repeat('x', 65), 'with space', "invoice\n", [], 123] as $value) {
            $cases[] = [array_replace(self::order(), ['out_trade_no' => $value])];
        }
        foreach (['', 'not-url', 'ftp://shop.invalid/notify', 'https://user:pass@shop.invalid/notify',
            'https://shop.invalid/notify#fragment', 'https://shop.invalid/a%GG', 'https://shop.invalid/a b',
            'https://shop.invalid/a\\b', 'https://shop.invalid/' . str_repeat('x', 2048), [], false] as $value) {
            $cases[] = [array_replace(self::order(), ['notify_url' => $value])];
        }
        return $cases;
    }

    /** @dataProvider invalidNativeResults */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNativeResults')]
    public function testNativeRequiresSignedSuccessCorrectInvoiceAmountAndOfficialQr(array $data): void
    {
        $signed = self::protocolOracle($data);
        $client = $this->client([new Response(200, [], self::wire($signed)), new Response(200, [], 'unused')]);
        $this->assertFailure(function () use ($client) { $client->create(self::credentials(), self::order()); });
        $this->assertCount(1, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function invalidNativeResults(): array
    {
        $cases = [];
        foreach (['return_code', 'total_fee', 'out_trade_no', 'payjs_order_id', 'code_url'] as $name) {
            $data = self::gatewayResponse();
            unset($data[$name]);
            $cases[] = [$data];
        }
        foreach ([0, '0', '01', 'SUCCESS', 2, '1e0'] as $value) $cases[] = [array_replace(self::gatewayResponse(), ['return_code' => $value])];
        foreach (['1900000110', 1900000110, '01900000109', '1900000109e0'] as $value) {
            $cases[] = [array_replace(self::gatewayResponse(), ['mchid' => $value])];
        }
        foreach ([9, '9', 11, '11', '010', '1e1', '10.0', '0'] as $value) {
            $cases[] = [array_replace(self::gatewayResponse(), ['total_fee' => $value])];
        }
        foreach (['other_order', 123, 'fixture_order_123 ', '0'] as $value) {
            $cases[] = [array_replace(self::gatewayResponse(), ['out_trade_no' => $value])];
        }
        foreach (['', str_repeat('x', 129)] as $value) $cases[] = [array_replace(self::gatewayResponse(), ['payjs_order_id' => $value])];
        foreach (['', 'https://payjs.cn/qr/fixture', 'http://fixture.invalid', 'javascript:alert(1)',
            'weixin://wrong/bizpayurl?pr=fixture', 'weixin://wxpay/bizpayurl?pr=',
            'weixin://wxpay/bizpayurl?pr=fixture&redirect=other', 'weixin://wxpay/bizpayurl?pr=fixture#fragment',
            "weixin://wxpay/bizpayurl?pr=fixture\n", 'weixin://wxpay/bizpayurl?pr=' . str_repeat('x', 257)] as $value) {
            $cases[] = [array_replace(self::gatewayResponse(), ['code_url' => $value])];
        }
        return $cases;
    }

    public function testSignedNumericInvoiceAndLeadingZeroMerchantAreNeverLooselyCompared(): void
    {
        $order = array_replace(self::order(), ['out_trade_no' => '0123']);
        $data = self::protocolOracle(array_replace(self::gatewayResponse(), ['out_trade_no' => 123]));
        $client = $this->client([new Response(200, [], self::wire($data))]);
        $this->assertFailure(function () use ($client, $order) { $client->create(self::credentials(), $order); });
        $credentials = array_replace(self::credentials(), ['mchid' => '01900000109']);
        $data = self::protocolOracle(array_replace(self::gatewayResponse(), ['mchid' => 1900000109]));
        $this->assertFailure(function () use ($client, $credentials, $data) { $client->verify($data, $credentials); });
    }

    /** @dataProvider invalidJson */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidJson')]
    public function testJsonShapesFloatsDepthAndMissingSignFailWithoutReplay(string $json): void
    {
        $client = $this->client([new Response(200, [], $json), new Response(200, [], 'unused')]);
        $this->assertFailure(function () use ($client) { $client->create(self::credentials(), self::order()); });
        $this->assertCount(1, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function invalidJson(): array
    {
        $signed = self::protocolOracle(self::gatewayResponse());
        $json = self::wire($signed);
        $unsigned = $signed;
        unset($unsigned['sign']);
        $tampered = array_replace($signed, ['sign' => str_repeat('F', 32)]);
        $cases = ['', '{', 'null', 'false', '[]', '[1]', '"string"', '{}', self::wire($unsigned), self::wire($tampered),
            '{"x":{"nested":{"deep":{"too":"far"}}}}', "{\"x\":\"\xFF\"}",
            str_replace('"total_fee":10', '"total_fee":10.0', $json),
            str_replace('"total_fee":10', '"total_fee":1e1', $json),
            str_replace('"return_code":1', '"return_code":1.0', $json),
            str_replace('"total_fee":10', '"total_fee":9999999999999999999999999', $json)];
        foreach ([[], new \stdClass(), true, false, null, 1.5] as $value) {
            $cases[] = self::wire(array_replace($signed, ['x' => $value]));
        }
        return array_map(function ($value) { return [$value]; }, $cases);
    }

    /** @dataProvider httpFailures */
    #[\PHPUnit\Framework\Attributes\DataProvider('httpFailures')]
    public function testHttpAndTransportFailuresAreRedactedAndNeverReplay($failure): void
    {
        $client = $this->client([$failure, new Response(200, [], 'unused')]);
        ob_start();
        try {
            $this->assertFailure(function () use ($client) { $client->create(self::credentials(), self::order()); });
            $this->assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
        }
        $this->assertCount(1, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function httpFailures(): array
    {
        return [[new Response(301, ['Location' => 'http://fixture.invalid'], 'secret-response')],
            [new Response(302, ['Location' => 'https://fixture.invalid'], 'secret-response')],
            [new Response(429, [], 'secret-response')], [new Response(500, [], 'secret-response')],
            [new ConnectException('fixture-secret ' . self::KEY . ' payload=',
                new Request('POST', 'https://payjs.cn/api/native', [], 'fixture-private-payload'))]];
    }

    public function testRawJsonLimitIncludesWhitespaceAndAcceptsExactly64KiB(): void
    {
        $signed = self::protocolOracle(self::gatewayResponse());
        $json = self::wire($signed);
        $json .= str_repeat(' ', 65536 - strlen($json));
        $client = $this->client([new Response(200, [], $json)]);
        $this->assertSame($signed, $client->create(self::credentials(), self::order()));
        $client = $this->client([new Response(200, [], $json . ' ')]);
        $this->assertFailure(function () use ($client) { $client->create(self::credentials(), self::order()); });
    }

    public function testNonSeekableStreamStopsAtLimitClosesAndNeverUsesGetContents(): void
    {
        $bytes = 0;
        $closed = false;
        $stream = Utils::streamFor(str_repeat('x', 70000));
        $body = new FnStream([
            'read' => function ($length) use ($stream, &$bytes) {
                $chunk = $stream->read($length);
                $bytes += strlen($chunk);
                return $chunk;
            },
            'eof' => function () use ($stream) { return $stream->eof(); },
            'isSeekable' => function () { return false; },
            'getSize' => function () { return null; },
            'getContents' => function () { $this->fail('Unbounded response read'); },
            'close' => function () use ($stream, &$closed) { $closed = true; $stream->close(); },
        ]);
        $client = $this->client([new Response(200, [], $body), new Response(200, [], 'unused')]);
        $this->assertFailure(function () use ($client) { $client->create(self::credentials(), self::order()); });
        $this->assertSame(65537, $bytes);
        $this->assertTrue($closed);
        $this->assertCount(1, $this->mock);
    }

    /** @dataProvider failedStreamModes */
    #[\PHPUnit\Framework\Attributes\DataProvider('failedStreamModes')]
    public function testStalledThrowingAndNon200StreamsCloseWithoutLeaking(bool $throw, int $status): void
    {
        $closed = false;
        $reads = 0;
        $body = new FnStream([
            'read' => function () use ($throw, &$reads) {
                $reads++;
                if ($throw) throw new RuntimeException('fixture-secret ' . self::KEY);
                return '';
            },
            'eof' => function () { return false; },
            'getSize' => function () { return null; },
            'close' => function () use (&$closed) { $closed = true; },
        ]);
        $client = $this->client([new Response($status, [], $body)]);
        $this->assertFailure(function () use ($client) { $client->create(self::credentials(), self::order()); });
        $this->assertSame($status === 200 ? 1 : 0, $reads);
        $this->assertTrue($closed);
    }

    public static function failedStreamModes(): array
    {
        return [[false, 200], [true, 200], [false, 500]];
    }
}
