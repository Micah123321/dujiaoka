<?php

namespace Tests\Unit\Payments;

use App\Payments\BepusdtClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BepusdtClientTest extends TestCase
{
    private const GOLDEN = 'f217e9057f979dda820585c409dca27b';
    private const PAYMENT_URL = 'http://checkout.other.test/payment/be-trade-01';

    private function credentials(): array
    {
        return ['key' => 'be-test-key', 'endpoint' => 'https://gateway.test', 'method' => 'usdt.trc20'];
    }

    private function order(): array
    {
        return ['order_sn' => 'BE-2025_001', 'amount' => '12.30',
            'notify_url' => 'https://shop.test/pay/bepusdt/notify_url', 'return_url' => 'https://shop.test/return/BE-2025_001'];
    }

    private function notification(): array
    {
        return ['order_id' => 'BE-2025_001', 'trade_id' => 'be-trade-01', 'amount' => '12.3', 'status' => 2,
            'fiat' => 'CNY', 'trade_type' => 'usdt.trc20', 'zero' => 0, 'zero_float' => 0.0,
            'zero_string' => '0', 'enabled' => true, 'disabled' => false, 'blank' => '', 'nil' => null,
            'token' => 'TReceiverAddress', 'actual_amount' => '1.721'];
    }

    // Frozen from the original controller helper; verified by PHP 7.4 Reflection before integration.
    private function originalSignature(array $parameters, string $key = 'be-test-key'): string
    {
        ksort($parameters, SORT_STRING);
        $parts = [];
        foreach ($parameters as $name => $value) {
            if ($name === 'signature' || $value === null || $value === '') continue;
            $parts[] = $name . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : $value);
        }
        return md5(implode('&', $parts) . $key);
    }

    private function signed(array $data, string $key = 'be-test-key'): array
    {
        $data['signature'] = $this->originalSignature($data, $key);
        return $data;
    }

    private function response(array $echo = [], $code = 200): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'status_code' => $code, 'data' => array_merge(['payment_url' => self::PAYMENT_URL], $echo),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function client(array $queue, array &$history, array $defaults = []): BepusdtClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        return new BepusdtClient(new Client(array_merge($defaults, ['handler' => $stack])));
    }

    private function failure(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected a provider failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Payment provider unavailable.', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testOriginalControllerGoldenAndRawValues(): void
    {
        $data = $this->notification();
        self::assertSame(self::GOLDEN, $this->originalSignature($data));
        self::assertSame(self::GOLDEN, md5('actual_amount=1.721&amount=12.3&disabled=false&enabled=true&fiat=CNY'
            . '&order_id=BE-2025_001&status=2&token=TReceiverAddress&trade_id=be-trade-01&trade_type=usdt.trc20'
            . '&zero=0&zero_float=0&zero_string=0be-test-key'));
        $data['signature'] = self::GOLDEN;
        self::assertSame($data, (new BepusdtClient())->verify($data, $this->credentials()));
        $data['signature'] = strtoupper(self::GOLDEN);
        self::assertSame($data, (new BepusdtClient())->verify($data, $this->credentials()));
    }

    /** @dataProvider signedValueProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('signedValueProvider')]
    public function testZerosBooleansAndExcludedValues($value, string $literal): void
    {
        $data = ['trade_id' => 'T', 'status' => 2, 'order_id' => 'O', 'amount' => 1, 'extra' => $value];
        $raw = 'amount=1' . $literal . '&order_id=O&status=2&trade_id=Tbe-test-key';
        $data['signature'] = md5($raw);
        self::assertSame($data, (new BepusdtClient())->verify($data, $this->credentials()));
    }

    public static function signedValueProvider(): array
    {
        return [[0, '&extra=0'], [0.0, '&extra=0'], ['0', '&extra=0'], [true, '&extra=true'],
            [false, '&extra=false'], ['', ''], [null, '']];
    }

    /** @dataProvider lifecycleProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('lifecycleProvider')]
    public function testLegacyCallbackMayOmitEchoesAndPreservesLifecycle($status): void
    {
        $data = $this->notification();
        unset($data['fiat'], $data['trade_type']);
        $data['status'] = $status;
        $data['token'] = 'receiver-address-not-a-currency';
        $data['actual_amount'] = '0.000001';
        $data = $this->signed($data);
        self::assertSame($data, (new BepusdtClient())->verify($data, $this->credentials()));
    }

    public static function lifecycleProvider(): array
    {
        return [[1], [2], [3], ['1'], ['2'], ['3']];
    }

    /** @dataProvider tamperProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('tamperProvider')]
    public function testEverySignedFieldAndUnknownExtraParticipates(string $field, $value): void
    {
        $data = $this->signed($this->notification());
        $data[$field] = $value;
        $this->failure(function () use ($data) { (new BepusdtClient())->verify($data, $this->credentials()); });
    }

    public static function tamperProvider(): array
    {
        return [['order_id', 'another-order'], ['trade_id', 'another-trade'], ['amount', '12.31'], ['status', 1],
            ['fiat', 'USD'], ['trade_type', 'usdt.erc20'], ['token', 'another-address'], ['actual_amount', '2.001'],
            ['zero', ''], ['zero_float', null], ['zero_string', 1], ['enabled', false], ['disabled', true],
            ['new_extra', 'signed-too'], ['A_extra', 'ascii-sort'], ['signature', str_repeat('0', 32)]];
    }

    public function testExcludedFieldsMayBeAbsentAndOpaqueExtrasUseRawValues(): void
    {
        $data = $this->signed($this->notification());
        unset($data['blank'], $data['nil']);
        self::assertSame($data, (new BepusdtClient())->verify($data, $this->credentials()));
        $data['A_extra'] = ' value=&literal%20 ';
        $data['z_extra'] = 1.25;
        $data = $this->signed($data);
        self::assertSame($data, (new BepusdtClient())->verify($data, $this->credentials()));
    }

    /** @dataProvider invalidBusinessProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBusinessProvider')]
    public function testSignedInvalidBusinessFieldsFail(string $field, $value): void
    {
        $data = $this->notification();
        $data[$field] = $value;
        $data = $this->signed($data);
        $this->failure(function () use ($data) { (new BepusdtClient())->verify($data, $this->credentials()); });
    }

    public static function invalidBusinessProvider(): array
    {
        $cases = [];
        foreach (['order_id', 'trade_id'] as $field) {
            foreach (['', null, 123, true, str_repeat('x', 129), "id\n"] as $value) $cases[] = [$field, $value];
        }
        foreach ([0, 0.0, '0', '-1', '1.001', '1e2', 'NaN', true, null, '100000000', ' 1', '1.'] as $value) $cases[] = ['amount', $value];
        foreach ([0, 4, 2.0, true, false, null, '02', '2.0', 'paid'] as $value) $cases[] = ['status', $value];
        foreach (['USD', 'cny', '', null, true] as $value) $cases[] = ['fiat', $value];
        foreach (['usdt.erc20', '', null, true] as $value) $cases[] = ['trade_type', $value];
        return $cases;
    }

    /** @dataProvider malformedSignatureProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedSignatureProvider')]
    public function testMalformedSignatureFails($signature): void
    {
        $data = $this->notification();
        $data['signature'] = $signature;
        $this->failure(function () use ($data) { (new BepusdtClient())->verify($data, $this->credentials()); });
    }

    public static function malformedSignatureProvider(): array
    {
        return [[null], [true], [123], [''], [str_repeat('f', 31)], [str_repeat('g', 32)], [str_repeat('f', 33)], [self::GOLDEN . "\n"]];
    }

    /** @dataProvider invalidFlatProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFlatProvider')]
    public function testInvalidOrUnboundedFlatPayloadFails(array $extra): void
    {
        $data = array_merge($this->signed($this->notification()), $extra);
        $this->failure(function () use ($data) { (new BepusdtClient())->verify($data, $this->credentials()); });
    }

    public static function invalidFlatProvider(): array
    {
        $tooMany = [];
        for ($i = 0; $i < 129; ++$i) $tooMany['extra_' . $i] = 0;
        return [[['extra' => []]], [['extra' => new \stdClass()]], [['extra' => INF]], [['extra' => -INF]],
            [['extra' => NAN]], [['extra' => "\xff"]], [['extra' => str_repeat('x', 65537)]],
            [['a' => str_repeat('x', 33000), 'b' => str_repeat('x', 33000)]], [['bad key' => 'value']],
            [[str_repeat('k', 65) => 0]], [[0 => 'numeric-key']], [$tooMany]];
    }

    public function testRequiredCallbackFieldsAndIdentifierBoundary(): void
    {
        foreach (['order_id', 'trade_id', 'amount', 'status', 'signature'] as $field) {
            $data = $this->signed($this->notification());
            unset($data[$field]);
            $this->failure(function () use ($data) { (new BepusdtClient())->verify($data, $this->credentials()); });
        }
        $data = $this->notification();
        $data['order_id'] = str_repeat('O', 128);
        $data['trade_id'] = str_repeat('T', 128);
        $data = $this->signed($data);
        self::assertSame($data, (new BepusdtClient())->verify($data, $this->credentials()));
    }

    /** @dataProvider endpointProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('endpointProvider')]
    public function testEndpointJoiningPreservesSelfHostedHttpAndNestedPaths(string $endpoint, string $expected): void
    {
        $history = [];
        $client = $this->client([$this->response()], $history);
        $credentials = $this->credentials();
        $credentials['endpoint'] = $endpoint;
        self::assertSame(self::PAYMENT_URL, $client->create($credentials, $this->order()));
        self::assertCount(1, $history);
        self::assertSame($expected, (string) $history[0]['request']->getUri());
    }

    public static function endpointProvider(): array
    {
        $path = '/api/v1/order/create-transaction';
        return [['https://gateway.test', 'https://gateway.test' . $path], ['https://gateway.test/', 'https://gateway.test' . $path],
            ['http://127.0.0.1:8080/nested', 'http://127.0.0.1:8080/nested' . $path],
            ['http://gateway.test/nested///', 'http://gateway.test/nested' . $path],
            ['https://gateway.test' . $path, 'https://gateway.test' . $path],
            ['https://gateway.test' . $path . '/', 'https://gateway.test' . $path],
            ['https://gateway.test/nested' . $path . '///', 'https://gateway.test/nested' . $path],
            ['http://[::1]:8080', 'http://[::1]:8080' . $path]];
    }

    /** @dataProvider amountProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('amountProvider')]
    public function testRawNumericAmountAndOriginalCreateFields(string $amount, string $numeric): void
    {
        $history = [];
        $client = $this->client([$this->response()], $history);
        $order = $this->order();
        $order['amount'] = $amount;
        $client->create($this->credentials(), $order);
        $request = $history[0]['request'];
        $raw = (string) $request->getBody();
        self::assertStringContainsString('"amount":' . $numeric . ',', $raw);
        $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        self::assertSame(['order_id', 'amount', 'fiat', 'trade_type', 'notify_url', 'redirect_url', 'signature'], array_keys($data));
        self::assertSame('BE-2025_001', $data['order_id']);
        self::assertSame('CNY', $data['fiat']);
        self::assertSame('usdt.trc20', $data['trade_type']);
        self::assertSame($order['notify_url'], $data['notify_url']);
        self::assertSame($order['return_url'], $data['redirect_url']);
        $data['amount'] = $numeric;
        self::assertSame($this->originalSignature($data), $data['signature']);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public static function amountProvider(): array
    {
        return [['12.30', '12.3'], ['10.00', '10'], ['0.01', '0.01'], ['0.10', '0.1'],
            ['99999999.99', '99999999.99'], ['00012.30', '12.3'], ['1', '1']];
    }

    public function testFreshClientUsesOnlyHandlerAndEnforcesTransportOptions(): void
    {
        $history = [];
        $defaults = ['base_uri' => 'https://wrong.test', 'auth' => ['wrong-user', 'wrong-password'],
            'headers' => ['Authorization' => 'Bearer pollution', 'Cookie' => 'pollution=1', 'X-Pollution' => '1', 'Content-Type' => 'text/plain'],
            'query' => ['pollution' => 1], 'json' => ['pollution' => 1], 'body' => 'pollution',
            'form_params' => ['pollution' => 1], 'verify' => false, 'cert' => 'wrong-cert.pem', 'sink' => 'wrong-sink',
            'cookies' => true, 'allow_redirects' => true, 'proxy' => 'http://wrong-proxy.test', 'curl' => [64 => false],
            'timeout' => 99, 'connect_timeout' => 99, 'read_timeout' => 99, 'debug' => true,
            'stream' => false, 'decode_content' => true, 'expect' => true, 'http_errors' => true];
        $client = $this->client([$this->response()], $history, $defaults);
        self::assertSame(self::PAYMENT_URL, $client->create($this->credentials(), $this->order()));
        self::assertCount(1, $history);
        $request = $history[0]['request'];
        $options = $history[0]['options'];
        foreach (['Authorization', 'Cookie', 'X-Pollution', 'Expect'] as $name) self::assertFalse($request->hasHeader($name));
        self::assertSame('', $request->getUri()->getQuery());
        foreach (['verify' => true, 'allow_redirects' => false, 'cookies' => false, 'connect_timeout' => 3,
            'timeout' => 10, 'read_timeout' => 10, 'proxy' => '', 'debug' => false,
            'decode_content' => false, 'expect' => false, 'stream' => true, 'http_errors' => false] as $name => $value) {
            self::assertSame($value, $options[$name], $name);
        }
        self::assertNull($options['auth'] ?? null);
        foreach (['cert', 'sink', 'curl', 'json', 'body', 'form_params', 'query'] as $name) self::assertArrayNotHasKey($name, $options);
    }

    /** @dataProvider lifecycleProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('lifecycleProvider')]
    public function testCreateAcceptsOptionalKnownLifecycleWithoutRequiringPaid($status): void
    {
        $history = [];
        $client = $this->client([$this->response(['order_id' => 'BE-2025_001', 'amount' => 12.3, 'fiat' => 'CNY',
            'trade_type' => 'usdt.trc20', 'status' => $status, 'token' => 'receiver-address', 'actual_amount' => '1.721'], '200')], $history);
        self::assertSame(self::PAYMENT_URL, $client->create($this->credentials(), $this->order()));
    }

    /** @dataProvider invalidEchoProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEchoProvider')]
    public function testCreateRejectsMismatchedOptionalEcho(array $echo): void
    {
        $history = [];
        $client = $this->client([$this->response($echo)], $history);
        $this->failure(function () use ($client) { $client->create($this->credentials(), $this->order()); });
        self::assertCount(1, $history);
    }

    public static function invalidEchoProvider(): array
    {
        return [[['order_id' => 'another-order']], [['order_id' => 123]], [['order_id' => null]], [['order_id' => '']],
            [['amount' => '12.31']], [['amount' => 0]], [['amount' => null]], [['amount' => '12.301']], [['amount' => true]],
            [['fiat' => 'USD']], [['fiat' => null]], [['trade_type' => 'usdt.erc20']], [['trade_type' => null]],
            [['status' => 0]], [['status' => 4]], [['status' => true]], [['status' => 1.0]], [['status' => null]], [['status' => '01']]];
    }

    public function testNativeReturnAndPaymentQueryStringsArePreservedAndSigned(): void
    {
        $history = []; $order = $this->order();
        $order['return_url'] = 'http://localhost/pay/bepusdt/return_url?order_id=BE-2025_001';
        $order['notify_url'] .= '?fixture=raw%2Bvalue';
        $paymentUrl = 'https://checkout.test/pay?trade_id=be-trade-01';
        $client = $this->client([$this->response(['payment_url' => $paymentUrl])], $history);
        self::assertSame($paymentUrl, $client->create($this->credentials(), $order));
        $data = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame($order['return_url'], $data['redirect_url']); self::assertSame($order['notify_url'], $data['notify_url']);
        self::assertSame($this->originalSignature($data), $data['signature']);
    }

    public function testApiQueryStringsAreRejectedBeforePost(): void
    {
        foreach (['https://gateway.test/path?key=value', 'https://gateway.test/path?'] as $endpoint) {
            $history = []; $credentials = $this->credentials(); $credentials['endpoint'] = $endpoint;
            $client = $this->client([$this->response()], $history);
            $this->failure(function () use ($client, $credentials) { $client->create($credentials, $this->order()); });
            self::assertCount(0, $history);
        }
    }

    /** @dataProvider invalidUrlProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUrlProvider')]
    public function testInvalidUrlsFailBeforePostAndPaymentUrlsFailAfterOnePost(string $url): void
    {
        foreach (['endpoint', 'notify_url', 'return_url'] as $field) {
            $history = [];
            $client = $this->client([$this->response()], $history);
            $credentials = $this->credentials();
            $order = $this->order();
            if ($field === 'endpoint') $credentials[$field] = $url; else $order[$field] = $url;
            $this->failure(function () use ($client, $credentials, $order) { $client->create($credentials, $order); });
            self::assertCount(0, $history);
        }
        $history = [];
        $client = $this->client([$this->response(['payment_url' => $url])], $history);
        $this->failure(function () use ($client) { $client->create($this->credentials(), $this->order()); });
        self::assertCount(1, $history);
    }

    public static function invalidUrlProvider(): array
    {
        return [[''], ['ftp://gateway.test/path'], ['//gateway.test/path'], ['https://user:pass@gateway.test/path'],
            ['https://user@gateway.test/path'],
            ['https://gateway.test/path#fragment'], ['https://gateway.test/path#'], ["https://gateway.test/\npath"],
            ['https://gateway.test/a b'], ['https://gateway.test/a\\b'], ['https://gateway.test/%0a'],
            ['https://gateway.test/%00'], ['https://gateway.test/%7f'], ['https://gateway.test/%5c'],
            ['https://gateway.test/%'], ['https://gateway.test/%xz'], ['https://gateway.test/' . str_repeat('x', 2048)]];
    }

    public function testOpaqueCredentialValuesAreNotTrimmedOrEncoded(): void
    {
        $credentials = $this->credentials();
        $credentials['key'] = " opaque=&%20 \xc3\xa9 ";
        $credentials['method'] = 'custom.method=&raw';
        $history = [];
        $client = $this->client([$this->response(['trade_type' => $credentials['method']])], $history);
        $client->create($credentials, $this->order());
        $data = json_decode((string) $history[0]['request']->getBody(), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame($credentials['method'], $data['trade_type']);
        self::assertSame($this->originalSignature($data, $credentials['key']), $data['signature']);
        $data = $this->notification();
        $data['trade_type'] = $credentials['method'];
        $data = $this->signed($data, $credentials['key']);
        self::assertSame($data, $client->verify($data, $credentials));
        $wrong = $credentials;
        $wrong['key'] = trim($wrong['key']);
        $this->failure(function () use ($client, $data, $wrong) { $client->verify($data, $wrong); });
    }

    /** @dataProvider invalidCredentialsProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCredentialsProvider')]
    public function testInvalidCredentialsAreBoundedAndNeverPost(array $replacement, ?string $remove = null): void
    {
        $history = [];
        $client = $this->client([$this->response()], $history);
        $credentials = array_merge($this->credentials(), $replacement);
        if ($remove !== null) unset($credentials[$remove]);
        $this->failure(function () use ($client, $credentials) { $client->create($credentials, $this->order()); });
        $data = $this->signed($this->notification());
        $this->failure(function () use ($client, $credentials, $data) { $client->verify($data, $credentials); });
        self::assertCount(0, $history);
    }

    public static function invalidCredentialsProvider(): array
    {
        return [[['key' => '']], [['key' => null]], [['key' => 123]], [['key' => str_repeat('x', 513)]], [['key' => "\xff"]],
            [['method' => '']], [['method' => null]], [['method' => 123]], [['method' => str_repeat('x', 129)]], [['method' => "\xff"]],
            [['endpoint' => 'https://gateway.test/?key=secret']], [['extra' => 'unexpected']], [[], 'key'], [[], 'endpoint'], [[], 'method']];
    }

    /** @dataProvider invalidOrderProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidOrderProvider')]
    public function testInvalidCreateOrderNeverPosts(array $replacement, ?string $remove = null): void
    {
        $history = [];
        $client = $this->client([$this->response()], $history);
        $order = array_merge($this->order(), $replacement);
        if ($remove !== null) unset($order[$remove]);
        $this->failure(function () use ($client, $order) { $client->create($this->credentials(), $order); });
        self::assertCount(0, $history);
    }

    public static function invalidOrderProvider(): array
    {
        return [[['amount' => 12.3]], [['amount' => '0']], [['amount' => '-1']], [['amount' => '12.301']], [['amount' => '1e2']],
            [['order_sn' => '']], [['order_sn' => 123]], [['order_sn' => str_repeat('x', 129)]], [['order_sn' => "order\n"]],
            [['notify_url' => null]], [['extra' => 'unexpected']], [[], 'amount'], [[], 'order_sn'], [[], 'notify_url'], [[], 'return_url']];
    }

    /** @dataProvider invalidResponseProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidResponseProvider')]
    public function testResponseShapeHttpStatusAndErrorsFailWithoutRetry(int $status, string $raw): void
    {
        $history = [];
        $stream = $this->stream($raw);
        $client = $this->client([new Response($status, ['Location' => 'https://redirect.test'], $stream), $this->response()], $history);
        $this->failure(function () use ($client) { $client->create($this->credentials(), $this->order()); });
        self::assertCount(1, $history);
        self::assertSame('POST', $history[0]['request']->getMethod());
        self::assertTrue($stream->closed);
    }

    public static function invalidResponseProvider(): array
    {
        $valid = '{"status_code":200,"data":{"payment_url":"https://checkout.test/pay"}}';
        return [[201, $valid], [302, $valid], [429, $valid], [500, $valid], [200, ''], [200, 'not-json key=be-test-key'],
            [200, '[]'], [200, 'null'], [200, '{"status_code":true,"data":{"payment_url":"https://checkout.test"}}'],
            [200, '{"status_code":200.0,"data":{"payment_url":"https://checkout.test"}}'],
            [200, '{"status_code":"0200","data":{"payment_url":"https://checkout.test"}}'],
            [200, '{"status_code":200,"data":[]}'], [200, '{"status_code":200,"data":null}'],
            [200, '{"status_code":200,"data":{"payment_url":123}}'], [200, '{"status_code":200,"data":{}}'],
            [200, '{"status_code":500,"data":{"payment_url":"https://checkout.test"},"message":"secret"}']];
    }

    private function stream(string $raw): BepusdtTrackingStream
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $raw);
        rewind($resource);
        return new BepusdtTrackingStream($resource);
    }

    public function testResponseStreamExactLimitAndOverLimitAreClosed(): void
    {
        $valid = '{"status_code":200,"data":{"payment_url":"https://checkout.test/pay"}}';
        foreach ([65536, 65537] as $size) {
            $history = [];
            $stream = $this->stream($valid . str_repeat(' ', $size - strlen($valid)));
            $client = $this->client([new Response(200, [], $stream)], $history);
            if ($size === 65536) self::assertSame('https://checkout.test/pay', $client->create($this->credentials(), $this->order()));
            else $this->failure(function () use ($client) { $client->create($this->credentials(), $this->order()); });
            self::assertTrue($stream->closed);
            self::assertSame($size, $stream->bytesRead);
            self::assertLessThanOrEqual(8192, max($stream->readLengths));
            self::assertCount(1, $history);
        }
    }

    public function testReadFailureOrStalledStreamIsClosedAndSanitized(): void
    {
        foreach (['throw', 'stall'] as $mode) {
            $history = [];
            $stream = $this->stream('secret response body');
            $stream->mode = $mode;
            $client = $this->client([new Response(200, [], $stream)], $history);
            $this->failure(function () use ($client) { $client->create($this->credentials(), $this->order()); });
            self::assertTrue($stream->closed);
            self::assertCount(1, $stream->readLengths);
            self::assertCount(1, $history);
        }
    }

    public function testConnectionExceptionDoesNotLeakOrRetry(): void
    {
        $history = [];
        $error = new ConnectException('be-test-key secret error body', new Request('POST', 'https://gateway.test'));
        $client = $this->client([$error, $this->response()], $history);
        $this->failure(function () use ($client) { $client->create($this->credentials(), $this->order()); });
        self::assertCount(1, $history);
    }
}

class BepusdtTrackingStream extends Stream
{
    public $closed = false;
    public $bytesRead = 0;
    public $readLengths = [];
    public $mode = '';

    public function read($length): string
    {
        $this->readLengths[] = $length;
        if ($this->mode === 'throw') throw new RuntimeException('be-test-key secret stream error');
        if ($this->mode === 'stall') return '';
        $chunk = parent::read($length);
        $this->bytesRead += strlen($chunk);
        return $chunk;
    }

    public function close(): void
    {
        $this->closed = true;
        parent::close();
    }
}
