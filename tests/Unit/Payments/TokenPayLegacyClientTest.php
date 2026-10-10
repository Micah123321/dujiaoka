<?php

namespace Tests\Unit\Payments;

use App\Payments\TokenPayLegacyClient;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
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

class TokenPayLegacyClientTest extends TestCase
{
    private const KEY = 'fixture-legacy-key+%2B&raw';
    private const URL = 'https://checkout.invalid/session/one?order=ORDER-001';
    private $history = [];
    private $mock;

    private function credentials(): array
    {
        return ['currency' => 'USDT_TRC20', 'key' => self::KEY, 'endpoint' => 'https://gateway.invalid/nested/api'];
    }

    private function order(): array
    {
        return ['order_sn' => 'ORDER-001', 'amount' => '10.00', 'email' => 'customer+tag@example.invalid',
            'return_url' => 'https://shop.invalid/order?sn=ORDER-001&literal=%2B',
            'notify_url' => 'https://shop.invalid/pay/tokenpay/notify_url'];
    }

    private static function receipt(): array
    {
        return ['ActualAmount' => 10.0, 'OutOrderId' => 'ORDER-001', 'Id' => 'TRADE-001', 'Status' => 1,
            'BaseCurrency' => 'CNY', 'Currency' => 'USDT_TRC20'];
    }

    private function oracle(array $data): array
    {
        // Fixed ASCII field order is independent of the client's sorting implementation.
        $names = ['A', 'ActualAmount', 'Amount', 'BaseCurrency', 'Currency', 'Id', 'NotifyUrl', 'OrderUserKey',
            'OutOrderId', 'PayAmount', 'RedirectUrl', 'Status', 'empty', 'false', 'float', 'note', 'null',
            'truth', 'zero', 'zero_float', 'zero_string'];
        $this->assertSame([], array_values(array_diff(array_keys($data), array_merge($names, ['Signature']))));
        $parts = [];
        foreach ($names as $name) {
            if (array_key_exists($name, $data)) $parts[] = $name . '=' . (string) $data[$name];
        }
        $data['Signature'] = md5(implode('&', $parts) . self::KEY);
        return $data;
    }

    private function client(array $responses, array $defaults = []): TokenPayLegacyClient
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        return new TokenPayLegacyClient(new Client(['handler' => $stack] + $defaults));
    }

    private static function wire(array $data): string
    {
        return json_encode($data, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function response(?array $info = null): Response
    {
        $data = ['success' => true, 'data' => self::URL];
        if ($info !== null) $data['info'] = (object) $info;
        return new Response(200, [], self::wire($data));
    }

    private function failure(callable $call): void
    {
        try {
            $call();
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, get_class($exception));
            $this->assertSame('Payment provider unavailable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            return;
        }
        $this->fail('Expected redacted provider failure.');
    }

    public function testOriginalFieldsJsonNumberNestedEndpointAndTransportIsolation(): void
    {
        $callback = function () { $this->fail('Foreign callback was inherited.'); };
        $client = $this->client([$this->response()], [
            'base_uri' => 'http://foreign.invalid/wrong', 'headers' => ['Host' => 'foreign.invalid',
                'Authorization' => 'private-auth', 'Cookie' => 'private-cookie', 'Content-Type' => 'text/plain', 'X-Foreign' => 'private'],
            'auth' => ['private-user', 'private-pass'], 'cookies' => true,
            'verify' => false, 'allow_redirects' => true, 'http_errors' => true,
            'connect_timeout' => 99, 'timeout' => 99, 'read_timeout' => 99,
            'query' => ['foreign' => 1], 'body' => 'private-body', 'json' => ['foreign' => true],
            'form_params' => ['foreign' => 1], 'multipart' => [['name' => 'foreign', 'contents' => 'private']],
            'proxy' => 'http://foreign.invalid:8080', 'cert' => 'private-cert', 'ssl_key' => 'private-key',
            'curl' => [64 => false], 'stream_context' => ['ssl' => ['verify_peer' => false]],
            'debug' => true, 'stream' => false, 'decode_content' => true, 'expect' => true,
            'sink' => 'private-sink', 'delay' => 60000, 'on_headers' => $callback, 'on_stats' => $callback,
        ]);
        $this->assertSame(self::URL, $client->create($this->credentials(), $this->order()));
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://gateway.invalid/nested/api/CreateOrder', (string) $request->getUri());
        $this->assertSame('gateway.invalid', $request->getHeaderLine('Host'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        foreach (['Authorization', 'Cookie', 'X-Foreign', 'Expect'] as $header) $this->assertFalse($request->hasHeader($header));
        $order = $this->order();
        $expected = $this->oracle(['ActualAmount' => 10, 'OutOrderId' => $order['order_sn'], 'OrderUserKey' => $order['email'],
            'Currency' => 'USDT_TRC20', 'RedirectUrl' => $order['return_url'], 'NotifyUrl' => $order['notify_url']]);
        $raw = (string) $request->getBody();
        $this->assertSame($expected, json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('"ActualAmount":10,', $raw);
        $options = $this->history[0]['options'];
        foreach (['verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'connect_timeout' => 3,
            'timeout' => 10, 'read_timeout' => 10, 'cookies' => false, 'proxy' => '', 'debug' => false,
            'stream' => true, 'decode_content' => false, 'expect' => false, 'idn_conversion' => false] as $name => $value) {
            $this->assertSame($value, $options[$name]);
        }
        foreach (['auth', 'query', 'body', 'json', 'form_params', 'multipart', 'cert', 'ssl_key', 'curl',
            'stream_context', 'sink', 'delay', 'on_headers', 'on_stats'] as $name) $this->assertArrayNotHasKey($name, $options);
    }

    /** @dataProvider prices */
    #[\PHPUnit\Framework\Attributes\DataProvider('prices')]
    public function testExactMoneyTokensAndEmptyOrderUserKey(string $input, string $token): void
    {
        $client = $this->client([$this->response(['ActualAmount' => $input, 'OutOrderId' => 'ORDER-001',
            'BaseCurrency' => 'CNY', 'Currency' => 'USDT_TRC20'])]);
        $order = array_replace($this->order(), ['amount' => $input, 'email' => '']);
        $credentials = array_replace($this->credentials(), ['endpoint' => 'https://gateway.invalid/nested/api/']);
        $this->assertSame(self::URL, $client->create($credentials, $order));
        $this->assertSame('https://gateway.invalid/nested/api/CreateOrder', (string) $this->history[0]['request']->getUri());
        $raw = (string) $this->history[0]['request']->getBody();
        $this->assertStringContainsString('"ActualAmount":' . $token . ',', $raw);
        $this->assertStringContainsString('"OrderUserKey":""', $raw);
        $signed = $this->oracle(['ActualAmount' => $token, 'OutOrderId' => $order['order_sn'], 'OrderUserKey' => '',
            'Currency' => 'USDT_TRC20', 'RedirectUrl' => $order['return_url'], 'NotifyUrl' => $order['notify_url']]);
        $this->assertSame($signed['Signature'], json_decode($raw, true)['Signature']);
    }

    public static function prices(): array
    {
        return [['10.00', '10'], ['0.01', '0.01'], ['0.10', '0.1'], ['10.50', '10.5'], ['00010.00', '10'], ['99999999.99', '99999999.99']];
    }

    public function testCapturedPhp74GoldenIncludesEveryEmptyNullAndBooleanField(): void
    {
        $data = self::receipt() + ['A' => 'Fish & chips + 50% = "quote" /', 'empty' => '', 'false' => false,
            'null' => null, 'zero' => 0, 'zero_float' => 0.0, 'zero_string' => '0', 'truth' => true];
        $signed = $this->oracle($data);
        $this->assertSame('cb5d30ed485cc1660372df4d9224bca6', $signed['Signature']);
        if (PHP_VERSION_ID < 80000) {
            ksort($data);
            $parts = [];
            foreach ($data as $name => $value) $parts[] = $name . '=' . $value;
            $this->assertSame($signed['Signature'], md5(implode('&', $parts) . self::KEY));
        }
        $client = $this->client([]);
        $this->assertSame($signed, $client->verify($signed, $this->credentials()));
        $uppercase = array_replace($signed, ['Signature' => strtoupper($signed['Signature'])]);
        $this->assertSame($uppercase, $client->verify($uppercase, $this->credentials()));
        foreach (['empty', 'null', 'false', 'zero', 'A'] as $name) {
            $tampered = $signed; unset($tampered[$name]);
            $this->failure(function () use ($client, $tampered) { $client->verify($tampered, $this->credentials()); });
        }
        $signed = $this->oracle(self::receipt() + ['note' => "\u{4E2D}\u{6587}&raw=+%2F", 'float' => 1.25]);
        $this->assertSame($signed, $client->verify($signed, $this->credentials()));
        $this->assertCount(0, $this->history);
    }

    /** @dataProvider validReceipts */
    #[\PHPUnit\Framework\Attributes\DataProvider('validReceipts')]
    public function testRawNotificationValuesArePreserved($amount, $status): void
    {
        $data = self::receipt(); unset($data['BaseCurrency'], $data['Currency']);
        $signed = $this->oracle(array_replace($data, ['ActualAmount' => $amount, 'Status' => $status, 'OutOrderId' => 123, 'Id' => 456]));
        $this->assertSame($signed, $this->client([])->verify($signed, $this->credentials()));
    }

    public static function validReceipts(): array
    {
        return [['10.00', '1'], [10, 1], [0.01, '1'], ['0.10', 1]];
    }

    public function testCryptoAmountsAreSignedButNeverReplaceActualAmount(): void
    {
        $client = $this->client([]);
        $data = $this->oracle(self::receipt() + ['Amount' => '0.000001', 'PayAmount' => '9000.0000001']);
        $this->assertSame($data, $client->verify($data, $this->credentials()));
        unset($data['ActualAmount']);
        $data = $this->oracle($data);
        $this->failure(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
    }

    /** @dataProvider invalidReceipts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidReceipts')]
    public function testPaidStatusCnyCurrencyAndIdentifiersAreRequiredDespiteValidSignature(array $data): void
    {
        $signed = $this->oracle($data);
        $client = $this->client([]);
        $this->failure(function () use ($client, $signed) { $client->verify($signed, $this->credentials()); });
    }

    public static function invalidReceipts(): array
    {
        $cases = [];
        foreach (['OutOrderId', 'Id', 'ActualAmount', 'Status'] as $name) {
            $data = self::receipt(); unset($data[$name]); $cases[] = [$data];
        }
        foreach ([0, '0', 2, '2', 3, '3', true, false, null, '', '01', 1.0, '1.0', '1e0'] as $value) {
            $cases[] = [array_replace(self::receipt(), ['Status' => $value])];
        }
        foreach (['0', 0, 0.0, -1, '-1.00', true, false, null, '', '10.001', '1e1', ' 10', 'NaN', '100000000'] as $value) {
            $cases[] = [array_replace(self::receipt(), ['ActualAmount' => $value])];
        }
        foreach (['OutOrderId', 'Id'] as $name) {
            foreach (['', 'white space', 'a&b', "bad\n", true, false, null, 1.5, str_repeat('x', $name === 'OutOrderId' ? 65 : 129)] as $value) {
                $cases[] = [array_replace(self::receipt(), [$name => $value])];
            }
        }
        foreach (['USD', 'cny', '', null, false, 1] as $value) $cases[] = [array_replace(self::receipt(), ['BaseCurrency' => $value])];
        foreach (['USDT_ERC20', 'usdt_trc20', '', null, false, 1] as $value) $cases[] = [array_replace(self::receipt(), ['Currency' => $value])];
        return $cases;
    }

    public function testFlatBoundsAndConstantTimeSignatureInputShape(): void
    {
        $signed = $this->oracle(self::receipt());
        $client = $this->client([]);
        $cases = [];
        foreach ([[], new \stdClass(), INF, NAN, str_repeat('x', 65536), "\xFF"] as $value) $cases[] = $signed + ['note' => $value];
        $cases[] = $signed + array_fill_keys(array_map(function ($i) { return 'x' . $i; }, range(1, 129)), 'v');
        $cases[] = $signed + [str_repeat('k', 65) => 'v'];
        $cases[] = $signed + [0 => 'v'];
        foreach ([null, 1, '', str_repeat('x', 32), str_repeat('a', 31), str_repeat('a', 33), '0e12345678901234567890123456789012', str_repeat('0', 32)] as $value) {
            $cases[] = array_replace($signed, ['Signature' => $value]);
        }
        foreach ($cases as $data) $this->failure(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
    }

    /** @dataProvider badResponses */
    #[\PHPUnit\Framework\Attributes\DataProvider('badResponses')]
    public function testStrictBooleanSuccessPaymentUrlAndOptionalEchoAssociation(string $raw): void
    {
        $client = $this->client([new Response(200, [], $raw), $this->response()]);
        $this->failure(function () use ($client) { $client->create($this->credentials(), $this->order()); });
        $this->assertCount(1, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function badResponses(): array
    {
        $cases = ['', '{', 'null', '[]', '"private"', '{}', '{"success":true,"data":[]}',
            '{"success":true,"data":"https://checkout.invalid","info":{"ActualAmount":null}}'];
        foreach ([null, false, 'false', 'true', 0, 1, '1', 1.0, 200] as $value) {
            $cases[] = self::wire(['success' => $value, 'message' => self::KEY, 'data' => self::URL]);
        }
        foreach (['', 'javascript:alert(1)', 'ftp://checkout.invalid/x', 'https://u:p@checkout.invalid/x',
            self::URL . '#', self::URL . '#fragment', self::URL . "\n", self::URL . '%0A', 'https://checkout.invalid/%xx',
            'https://checkout.invalid/' . str_repeat('x', 2048)] as $value) {
            $cases[] = self::wire(['success' => true, 'data' => $value]);
        }
        foreach ([['OutOrderId' => 'OTHER'], ['OutOrderId' => true], ['ActualAmount' => '10.01'], ['ActualAmount' => 0],
            ['ActualAmount' => '10.001'], ['ActualAmount' => false], ['BaseCurrency' => 'USD'], ['BaseCurrency' => 'cny'],
            ['BaseCurrency' => null], ['Currency' => 'USDT_ERC20'], ['Currency' => 'usdt_trc20'], ['Currency' => null]] as $echo) {
            $cases[] = self::wire(['success' => true, 'data' => self::URL, 'info' => (object) $echo]);
        }
        return array_map(function ($raw) { return [$raw]; }, $cases);
    }

    public function testOptionalInfoAndSelfHostedForeignOriginHttpPaymentUrl(): void
    {
        foreach ([null, [], ['note' => 'informational'], ['ActualAmount' => 10, 'OutOrderId' => 'ORDER-001'],
            ['ActualAmount' => '10.0', 'OutOrderId' => 'ORDER-001', 'BaseCurrency' => 'CNY', 'Currency' => 'USDT_TRC20', 'Amount' => '0.000001']] as $info) {
            $client = $this->client([$this->response($info)]);
            $this->assertSame(self::URL, $client->create($this->credentials(), $this->order()));
        }
        foreach ([null, 'informational', false] as $info) {
            $client = $this->client([new Response(200, [], self::wire(['success' => true, 'data' => self::URL, 'info' => $info]))]);
            $this->assertSame(self::URL, $client->create($this->credentials(), $this->order()));
        }
        $url = 'http://another-host.invalid/payment/one';
        $client = $this->client([new Response(200, [], self::wire(['success' => true, 'data' => $url]))]);
        $credentials = array_replace($this->credentials(), ['endpoint' => 'http://self-hosted.invalid/base']);
        $this->assertSame($url, $client->create($credentials, $this->order()));
        $this->assertSame('http://self-hosted.invalid/base/CreateOrder', (string) $this->history[0]['request']->getUri());
    }

    public function testInvalidEndpointCredentialsAndOrdersFailBeforeTransport(): void
    {
        $client = $this->client([]);
        $cases = [[], array_replace($this->credentials(), ['key' => '']), array_replace($this->credentials(), ['currency' => '']),
            $this->credentials() + ['token' => 'modern']];
        foreach (['https://user@gateway.invalid/x', 'https://gateway.invalid/x#', 'https://gateway.invalid/x?secret=yes',
            'https://gateway.invalid/x?', 'file:///tmp/api', "https://gateway.invalid/x\n", 'https://gateway.invalid/%0D'] as $url) {
            $cases[] = array_replace($this->credentials(), ['endpoint' => $url]);
        }
        foreach ($cases as $credentials) $this->failure(function () use ($client, $credentials) { $client->create($credentials, $this->order()); });
        foreach ([['amount' => '0.00'], ['amount' => '10.001'], ['amount' => 10], ['email' => null],
            ['email' => str_repeat('x', 513)], ['order_sn' => ''], ['order_sn' => 'bad&sn'],
            ['notify_url' => 'ftp://shop.invalid/a'], ['return_url' => 'https://user@shop.invalid/a']] as $change) {
            $order = array_replace($this->order(), $change);
            $this->failure(function () use ($client, $order) { $client->create($this->credentials(), $order); });
        }
        $this->assertCount(0, $this->history);
        $http = $this->createMock(ClientInterface::class);
        $http->expects($this->once())->method('getConfig')->with('handler')->willReturn(null);
        $this->failure(function () use ($http) { new TokenPayLegacyClient($http); });
    }

    public function testTransportFailureIsRedactedWithNoPostReplay(): void
    {
        $failures = [new Response(201, [], self::KEY), new Response(302, ['Location' => 'https://other.invalid'], self::KEY),
            new Response(429, [], self::KEY), new Response(500, [], self::KEY),
            new ConnectException('private: ' . self::KEY, new Request('POST', $this->credentials()['endpoint'], [], 'private-body'))];
        foreach ($failures as $response) {
            $client = $this->client([$response, $this->response()]);
            ob_start();
            try {
                $this->failure(function () use ($client) { $client->create($this->credentials(), $this->order()); });
                $this->assertSame('', ob_get_contents());
            } finally { ob_end_clean(); }
            $this->assertCount(1, $this->history);
            $this->assertCount(1, $this->mock);
        }
    }

    public function testBoundedNonSeekableStreamsCloseOnSuccessAndFailure(): void
    {
        $json = self::wire(['success' => true, 'data' => self::URL]);
        $limit = $json . str_repeat(' ', 65536 - strlen($json));
        foreach ([[$limit, 200, true, 65536], [$limit . ' ', 200, false, 65537], [self::KEY, 500, false, 0],
            ['{', 200, false, 1]] as $case) {
            list($raw, $status, $ok, $expectedBytes) = $case;
            $closed = false; $bytes = 0; $stream = Utils::streamFor($raw);
            $body = new FnStream([
                'read' => function ($length) use ($stream, &$bytes) { $value = $stream->read($length); $bytes += strlen($value); return $value; },
                'eof' => function () use ($stream) { return $stream->eof(); },
                'getSize' => function () { return null; }, 'isSeekable' => function () { return false; },
                'getContents' => function () { $this->fail('Unbounded stream read.'); },
                'close' => function () use ($stream, &$closed) { $closed = true; $stream->close(); },
            ]);
            $client = $this->client([new Response($status, [], $body), $this->response()]);
            if ($ok) $this->assertSame(self::URL, $client->create($this->credentials(), $this->order()));
            else $this->failure(function () use ($client) { $client->create($this->credentials(), $this->order()); });
            $this->assertTrue($closed); $this->assertSame($expectedBytes, $bytes); $this->assertCount(1, $this->mock);
        }
        foreach ([false, true] as $throw) {
            $closed = false; $reads = 0;
            $body = new FnStream([
                'read' => function () use ($throw, &$reads) { ++$reads; if ($throw) throw new RuntimeException(self::KEY); return ''; },
                'eof' => function () { return false; }, 'close' => function () use (&$closed) { $closed = true; },
            ]);
            $client = $this->client([new Response(200, [], $body)]);
            $this->failure(function () use ($client) { $client->create($this->credentials(), $this->order()); });
            $this->assertTrue($closed); $this->assertSame(1, $reads);
        }
    }
}
