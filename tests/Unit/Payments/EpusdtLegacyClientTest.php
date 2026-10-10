<?php

namespace Tests\Unit\Payments;

use App\Payments\EpusdtLegacyClient;
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

class EpusdtLegacyClientTest extends TestCase
{
    private const KEY = 'fixture-legacy-key+%2B&raw';
    private const URL = 'https://checkout.invalid/session/one?order=ORDER-001';
    private $history = [];
    private $mock;

    private function credentials(): array
    {
        return ['key' => self::KEY, 'endpoint' => 'https://gateway.invalid/nested/api/create?version=legacy'];
    }

    private function order(): array
    {
        return ['order_sn' => 'ORDER-001', 'amount' => '10.00',
            'return_url' => 'https://shop.invalid/order?sn=ORDER-001&literal=%2B',
            'notify_url' => 'https://shop.invalid/pay/epusdt/notify_url'];
    }

    private static function receipt(): array
    {
        return ['amount' => 10.0, 'order_id' => 'ORDER-001', 'status' => 2, 'trade_id' => 'TRADE-001'];
    }

    private function oracle(array $data): array
    {
        // Fixed ASCII order, independently captured from the original PHP 7.4 controller.
        $names = ['A', 'amount', 'empty', 'false', 'float', 'note', 'notify_url', 'null', 'order_id',
            'redirect_url', 'status', 'trade_id', 'truth', 'zero', 'zero_float', 'zero_string'];
        $this->assertSame([], array_values(array_diff(array_keys($data), array_merge($names, ['signature']))));
        $parts = [];
        foreach ($names as $name) {
            if (!isset($data[$name]) || in_array($data[$name], ['', false, 0, 0.0], true)) continue;
            $parts[] = $name . '=' . (string) $data[$name];
        }
        $data['signature'] = md5(implode('&', $parts) . self::KEY);
        return $data;
    }

    private function client(array $responses, array $defaults = []): EpusdtLegacyClient
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        return new EpusdtLegacyClient(new Client(['handler' => $stack] + $defaults));
    }

    private static function wire(array $data): string
    {
        return json_encode($data, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function response(array $echo = []): Response
    {
        return new Response(200, [], self::wire(['status_code' => 200, 'data' => ['payment_url' => self::URL] + $echo]));
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

    public function testOriginalJsonNumericAmountAndIsolatedTransport(): void
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
        $this->assertSame($this->credentials()['endpoint'], (string) $request->getUri());
        $this->assertSame('gateway.invalid', $request->getHeaderLine('Host'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        foreach (['Authorization', 'Cookie', 'X-Foreign', 'Expect'] as $header) $this->assertFalse($request->hasHeader($header));
        $order = $this->order();
        $expected = $this->oracle(['amount' => 10, 'order_id' => $order['order_sn'],
            'redirect_url' => $order['return_url'], 'notify_url' => $order['notify_url']]);
        $raw = (string) $request->getBody();
        $this->assertSame($expected, json_decode($raw, true, 8, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('"amount":10,', $raw);
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
    public function testExactDecimalNumberTokensAndLegacySignatures(string $input, string $token): void
    {
        $client = $this->client([$this->response(['amount' => $input, 'order_id' => 'ORDER-001'])]);
        $order = array_replace($this->order(), ['amount' => $input]);
        $this->assertSame(self::URL, $client->create($this->credentials(), $order));
        $raw = (string) $this->history[0]['request']->getBody();
        $this->assertStringContainsString('"amount":' . $token . ',', $raw);
        $signed = $this->oracle(['amount' => $token, 'order_id' => $order['order_sn'],
            'redirect_url' => $order['return_url'], 'notify_url' => $order['notify_url']]);
        $this->assertSame($signed['signature'], json_decode($raw, true)['signature']);
    }

    public static function prices(): array
    {
        return [['10.00', '10'], ['0.01', '0.01'], ['0.10', '0.1'], ['10.50', '10.5'], ['00010.00', '10'], ['99999999.99', '99999999.99']];
    }

    public function testCapturedPhp74GoldenAndUnknownRawFields(): void
    {
        $data = self::receipt() + ['A' => 'Fish & chips + 50% = "quote" /', 'empty' => '', 'false' => false,
            'null' => null, 'zero' => 0, 'zero_float' => 0.0, 'zero_string' => '0', 'truth' => true];
        $signed = $this->oracle($data);
        $this->assertSame('2734236ca585f0823a0d9caf44f65cd1', $signed['signature']);
        if (PHP_VERSION_ID < 80000) {
            ksort($data);
            $parts = [];
            foreach ($data as $name => $value) if ($value != '') $parts[] = $name . '=' . $value;
            $this->assertSame($signed['signature'], md5(implode('&', $parts) . self::KEY));
        }
        $client = $this->client([]);
        $this->assertSame($signed, $client->verify($signed, $this->credentials()));
        $uppercase = array_replace($signed, ['signature' => strtoupper($signed['signature'])]);
        $this->assertSame($uppercase, $client->verify($uppercase, $this->credentials()));
        $tampered = array_replace($signed, ['zero_string' => '1']);
        $this->failure(function () use ($client, $tampered) { $client->verify($tampered, $this->credentials()); });
        $data = $this->oracle(self::receipt() + ['note' => "\u{4E2D}\u{6587}&raw=+%2F", 'float' => 1.25]);
        $this->assertSame($data, $client->verify($data, $this->credentials()));
        $this->assertCount(0, $this->history);
    }

    /** @dataProvider validReceipts */
    #[\PHPUnit\Framework\Attributes\DataProvider('validReceipts')]
    public function testVerifiedValuesRemainUnchanged($amount, $status): void
    {
        $data = $this->oracle(array_replace(self::receipt(), ['amount' => $amount, 'status' => $status, 'order_id' => 123, 'trade_id' => 456]));
        $this->assertSame($data, $this->client([])->verify($data, $this->credentials()));
    }

    public static function validReceipts(): array
    {
        return [['10.00', '2'], [10, 2], [0.01, '2'], ['0.10', 2]];
    }

    /** @dataProvider invalidReceipts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidReceipts')]
    public function testBusinessFieldsMustBeValidEvenWithCorrectSignature(array $data): void
    {
        $signed = $this->oracle($data);
        $client = $this->client([]);
        $this->failure(function () use ($client, $signed) { $client->verify($signed, $this->credentials()); });
    }

    public static function invalidReceipts(): array
    {
        $cases = [];
        foreach (['order_id', 'trade_id', 'amount', 'status'] as $name) {
            $data = self::receipt(); unset($data[$name]); $cases[] = [$data];
        }
        foreach ([0, '0', 1, '1', 3, '3', true, false, null, '', '02', 2.0, '2.0', '2e0'] as $value) {
            $cases[] = [array_replace(self::receipt(), ['status' => $value])];
        }
        foreach (['0', 0, 0.0, -1, '-1.00', true, false, null, '', '10.001', '1e1', ' 10', 'NaN', '100000000'] as $value) {
            $cases[] = [array_replace(self::receipt(), ['amount' => $value])];
        }
        foreach (['order_id', 'trade_id'] as $name) {
            foreach (['', 'white space', 'a&b', "bad\n", true, false, null, 1.5, str_repeat('x', $name === 'order_id' ? 65 : 129)] as $value) {
                $cases[] = [array_replace(self::receipt(), [$name => $value])];
            }
        }
        return $cases;
    }

    public function testFlatBoundsAndSignatureValidation(): void
    {
        $signed = $this->oracle(self::receipt());
        $client = $this->client([]);
        $cases = [];
        foreach ([[], new \stdClass(), INF, NAN, str_repeat('x', 65536), "\xFF"] as $value) $cases[] = $signed + ['note' => $value];
        $cases[] = $signed + array_fill_keys(array_map(function ($i) { return 'x' . $i; }, range(1, 129)), 'v');
        $cases[] = $signed + [str_repeat('k', 65) => 'v'];
        $cases[] = $signed + [0 => 'v'];
        foreach ([null, 1, '', str_repeat('x', 32), str_repeat('a', 31), str_repeat('a', 33), '0e12345678901234567890123456789012', str_repeat('0', 32)] as $value) {
            $cases[] = array_replace($signed, ['signature' => $value]);
        }
        foreach ($cases as $data) $this->failure(function () use ($client, $data) { $client->verify($data, $this->credentials()); });
    }

    /** @dataProvider badResponses */
    #[\PHPUnit\Framework\Attributes\DataProvider('badResponses')]
    public function testProviderBusinessFailuresAreRedactedWithoutReplay(string $raw): void
    {
        $client = $this->client([new Response(200, [], $raw), $this->response()]);
        $this->failure(function () use ($client) { $client->create($this->credentials(), $this->order()); });
        $this->assertCount(1, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function badResponses(): array
    {
        $cases = ['', '{', 'null', '[]', '"private"', '{}', '{"status_code":200,"data":[]}',
            '{"status_code":200,"data":{"payment_url":"https://checkout.invalid","amount":null}}'];
        foreach ([null, false, true, 0, 201, '201', 200.0, '0200', '200.0', '200e0'] as $value) {
            $cases[] = self::wire(['status_code' => $value, 'message' => self::KEY, 'data' => ['payment_url' => self::URL]]);
        }
        foreach (['', 'javascript:alert(1)', 'ftp://checkout.invalid/x', 'https://u:p@checkout.invalid/x',
            self::URL . '#', self::URL . '#fragment', self::URL . "\n", self::URL . '%0A', 'https://checkout.invalid/%xx',
            'https://checkout.invalid/' . str_repeat('x', 2048)] as $value) {
            $cases[] = self::wire(['status_code' => 200, 'data' => ['payment_url' => $value]]);
        }
        foreach ([['order_id' => 'OTHER'], ['order_id' => true], ['amount' => '10.01'], ['amount' => 0],
            ['amount' => '10.001'], ['amount' => false]] as $echo) {
            $cases[] = self::wire(['status_code' => 200, 'data' => ['payment_url' => self::URL] + $echo]);
        }
        return array_map(function ($raw) { return [$raw]; }, $cases);
    }

    public function testStringSuccessStatusAndHttpPaymentUrlAreAccepted(): void
    {
        $url = 'http://another-host.invalid/payment/one';
        $client = $this->client([new Response(200, [], self::wire(['status_code' => '200', 'data' => ['payment_url' => $url]]))]);
        $credentials = array_replace($this->credentials(), ['endpoint' => 'http://self-hosted.invalid/api/create']);
        $this->assertSame($url, $client->create($credentials, $this->order()));
        $this->assertSame($credentials['endpoint'], (string) $this->history[0]['request']->getUri());
    }

    public function testInvalidConfigurationAndOrdersFailBeforeTransport(): void
    {
        $client = $this->client([]);
        $cases = [[], ['key' => '', 'endpoint' => $this->credentials()['endpoint']], $this->credentials() + ['pid' => 'modern']];
        foreach (['https://user@gateway.invalid/x', 'https://gateway.invalid/x#', 'file:///tmp/api', "https://gateway.invalid/x\n"] as $url) {
            $cases[] = array_replace($this->credentials(), ['endpoint' => $url]);
        }
        foreach ($cases as $credentials) $this->failure(function () use ($client, $credentials) { $client->create($credentials, $this->order()); });
        foreach ([['amount' => '0.00'], ['amount' => '10.001'], ['amount' => 10], ['order_sn' => ''], ['order_sn' => 'bad&sn'],
            ['notify_url' => 'ftp://shop.invalid/a'], ['return_url' => 'https://user@shop.invalid/a']] as $change) {
            $order = array_replace($this->order(), $change);
            $this->failure(function () use ($client, $order) { $client->create($this->credentials(), $order); });
        }
        $this->assertCount(0, $this->history);
        $http = $this->createMock(ClientInterface::class);
        $http->expects($this->once())->method('getConfig')->with('handler')->willReturn(null);
        $this->failure(function () use ($http) { new EpusdtLegacyClient($http); });
    }

    public function testTransportFailuresNeverReplayOrExposeBodies(): void
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

    public function testBoundedNonSeekableStreamsCloseOnEveryOutcome(): void
    {
        $json = self::wire(['status_code' => 200, 'data' => ['payment_url' => self::URL]]);
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
