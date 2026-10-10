<?php

namespace Tests\Unit\Payments;

use App\Payments\CoinbaseCommerceClient;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

class CoinbaseCommerceClientTest extends TestCase
{
    private const URL = 'https://commerce.coinbase.com/charges/ABCD1234';
    private const SECRET = 'fixture-whsec-capture';
    private $history = [];
    private $mock;

    private function client(array $responses, array $defaults = []): CoinbaseCommerceClient
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        return new CoinbaseCommerceClient(new Client(array_replace($defaults, ['handler' => $stack])));
    }

    private function order(): array
    {
        return ['order_sn' => 'ORDER-001', 'title' => "\u{5546}\u{54c1}" . ' "quote" & / slash',
            'amount' => '10.00', 'return_url' => 'https://shop.invalid/detail?sn=ORDER-001&a=1',
            'cancel_url' => 'https://shop.invalid/detail?sn=ORDER-001&cancel=1'];
    }

    private function response(): Response
    {
        return new Response(200, [], '{"data":{"hosted_url":"' . self::URL . '"}}');
    }

    private function failure(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected provider failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, get_class($exception));
            $this->assertSame('Payment provider unavailable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('fixture-private', $exception->getMessage());
        }
    }

    public function testOriginalJsonFieldsHeadersAndForeignDefaultsAreIsolated(): void
    {
        $called = 0;
        $client = $this->client([$this->response()], [
            'base_uri' => 'http://fixture.invalid/', 'headers' => ['Host' => 'fixture.invalid',
                'X-CC-Api-Key' => 'fixture-private-other', 'Authorization' => 'fixture-private-auth',
                'Content-Type' => 'text/plain', 'X-Foreign' => 'yes'],
            'auth' => ['fixture-private-user', 'fixture-private-pass'],
            'cookies' => CookieJar::fromArray(['foreign' => 'fixture-private-cookie'], 'commerce.coinbase.com'),
            'verify' => false, 'allow_redirects' => true, 'http_errors' => true,
            'connect_timeout' => 99, 'timeout' => 99, 'read_timeout' => 99,
            'proxy' => 'http://fixture.invalid:8000', 'debug' => true, 'stream' => false,
            'decode_content' => true, 'expect' => true, 'version' => '2.0',
            'idn_conversion' => true, 'protocols' => ['http'], 'query' => ['foreign' => 'yes'],
            'body' => 'fixture-private-body', 'json' => ['foreign' => 'yes'],
            'form_params' => ['foreign' => 'yes'], 'multipart' => [['name' => 'foreign', 'contents' => 'yes']],
            'cert' => 'fixture-private-cert', 'ssl_key' => 'fixture-private-key',
            'curl' => [10018 => 'fixture-private-agent'], 'sink' => 'fixture-private-sink',
            'delay' => 60000, 'on_headers' => function () use (&$called) { ++$called; },
            'on_stats' => function () use (&$called) { ++$called; },
        ]);
        $order = $this->order();
        $this->assertSame(self::URL, $client->create(['api_key' => 'fixture-api', 'fee' => '0.075'], $order));
        $this->assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.commerce.coinbase.com/charges', (string) $request->getUri());
        $this->assertSame('api.commerce.coinbase.com', $request->getHeaderLine('Host'));
        $this->assertSame('fixture-api', $request->getHeaderLine('X-CC-Api-Key'));
        $this->assertSame('2018-03-22', $request->getHeaderLine('X-CC-Version'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        foreach (['Authorization', 'Cookie', 'X-Foreign', 'Idempotency-Key', 'Expect'] as $header) {
            $this->assertFalse($request->hasHeader($header));
        }
        $this->assertSame([
            'name' => $order['title'],
            'description' => $order['title'] . "\u{9700}\u{4ed8}\u{6b3e}" . '10.75' . "\u{5143}",
            'pricing_type' => 'fixed_price', 'local_price' => ['amount' => '10.75', 'currency' => 'CNY'],
            'metadata' => ['customer_id' => 'ORDER-001', 'customer_name' => $order['title']],
            'redirect_url' => $order['return_url'], 'cancel_url' => $order['cancel_url'],
        ], json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR));
        $options = $this->history[0]['options'];
        foreach (['verify' => true, 'allow_redirects' => false, 'http_errors' => false,
            'connect_timeout' => 3, 'timeout' => 10, 'read_timeout' => 10,
            'cookies' => false, 'proxy' => '', 'debug' => false, 'decode_content' => false,
            'stream' => true, 'expect' => false, 'version' => '1.1', 'idn_conversion' => false,
            'protocols' => ['https']] as $name => $value) {
            $this->assertSame($value, $options[$name], $name);
        }
        foreach (['base_uri', 'auth', 'query', 'json', 'form_params', 'multipart', 'cert', 'ssl_key',
            'curl', 'sink', 'delay', 'on_headers', 'on_stats'] as $name) {
            $this->assertArrayNotHasKey($name, $options);
        }
        $this->assertSame(0, $called);
    }

    /** @dataProvider prices */
    #[\PHPUnit\Framework\Attributes\DataProvider('prices')]
    public function testExactFeeArithmeticAndNonpositiveFees(string $amount, string $fee, string $expected): void
    {
        $client = $this->client([$this->response()]);
        $client->create(['api_key' => 'fixture-api', 'fee' => $fee], array_replace($this->order(), ['amount' => $amount]));
        $data = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame($expected, $data['local_price']['amount']);
        $this->assertSame('CNY', $data['local_price']['currency']);
    }

    public static function prices(): array
    {
        return [
            ['10', '0', '10.00'], ['10.1', '-0.05', '10.10'], ['10.00', '-100', '10.00'],
            ['10.00', '-0.000000000000000001', '10.00'], ['10.00', '-0.00', '10.00'],
            ['10.00', '+0.00', '10.00'], ['10.00', '0.05', '10.50'],
            ['0.01', '0.05', '0.0105'], ['0.01', '0.075', '0.01075'],
            ['12.50', '0.05', '13.125'], ['12.50', '000.0500', '13.125'],
            ['0.01', '0.000000000000000001', '0.01000000000000000001'],
            ['0.01', '0.' . str_repeat('0', 61) . '1', '0.01' . str_repeat('0', 61) . '1'],
            ['1.01', '0.123456789012345678', '1.13469135690246913478'],
            ['10.00', '+0.05', '10.50'], ['10.00', '1', '20.00'],
            ['99999999.99', '99999999', '9999999999000000.00'],
        ];
    }

    public function testOmittedFeeDefaultsToZero(): void
    {
        $client = $this->client([$this->response()]);
        $client->create(['api_key' => 'fixture-api'], $this->order());
        $this->assertSame('10.00', json_decode((string) $this->history[0]['request']->getBody(), true)['local_price']['amount']);
    }

    /** @dataProvider invalidFees */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFees')]
    public function testInvalidFeesStopBeforeSending($fee): void
    {
        $client = $this->client([$this->response()]);
        $this->failure(function () use ($client, $fee) {
            $client->create(['api_key' => 'fixture-api', 'fee' => $fee], $this->order());
        });
        $this->assertCount(0, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function invalidFees(): array
    {
        return array_map(function ($fee) { return [$fee]; }, [null, false, 0, 0.05, [], '', 'NaN', 'INF', '-INF',
            '1e-2', '1E3', ' 0.05', '0.05 ', "0.05\n", '1.', '.05', '--1', '0x01', '1,5',
            str_repeat('1', 65), '0.' . str_repeat('1', 63), str_repeat('0', 65536)]);
    }

    public function testInvalidOrdersAndCredentialsStopBeforeSending(): void
    {
        $cases = [];
        foreach (['0', '-1', '1.001', '100000000', '1e2', '', 'NaN', "1\n", 10, 10.00, null, []] as $amount) {
            $cases[] = [['api_key' => 'fixture-api'], array_replace($this->order(), ['amount' => $amount])];
        }
        foreach (['', null, 12, "fixture-private\r\nInjected: yes", str_repeat('a', 513)] as $key) {
            $cases[] = [['api_key' => $key], $this->order()];
        }
        $cases[] = [[], $this->order()];
        $cases[] = [['api_key' => 'fixture-api', 'merchant_key' => 'foreign'], $this->order()];
        foreach (array_keys($this->order()) as $key) {
            $order = $this->order();
            unset($order[$key]);
            $cases[] = [['api_key' => 'fixture-api'], $order];
        }
        foreach (['', null, [], "\xFF", str_repeat('a', 65536)] as $title) {
            $cases[] = [['api_key' => 'fixture-api'], array_replace($this->order(), ['title' => $title])];
        }
        $cases[] = [['api_key' => 'fixture-api'], $this->order() + ['endpoint' => 'https://fixture.invalid']];
        foreach ($cases as $case) {
            $client = $this->client([$this->response()]);
            $this->failure(function () use ($client, $case) { $client->create($case[0], $case[1]); });
            $this->assertCount(0, $this->history);
        }
    }

    /** @dataProvider failureStatuses */
    #[\PHPUnit\Framework\Attributes\DataProvider('failureStatuses')]
    public function testFailedStatusNeverReplaysCreatePost(int $status): void
    {
        $body = new CoinbaseTrackingStream(Utils::streamFor('fixture-private-body'));
        $client = $this->client([new Response($status, ['Location' => self::URL, 'Retry-After' => '0'], $body), $this->response()]);
        $this->failure(function () use ($client) { $client->create(['api_key' => 'fixture-api'], $this->order()); });
        $this->assertCount(1, $this->history);
        $this->assertCount(1, $this->mock);
        $this->assertSame(1, $body->closed);
        $this->assertSame(0, $body->bytes);
    }

    public static function failureStatuses(): array
    {
        return array_map(function ($status) { return [$status]; }, [199, 204, 301, 302, 307, 308, 400, 401, 403, 429, 500, 503]);
    }

    public function testCreatedStatusAcceptsOfficialUuidPaymentPage(): void
    {
        $url = 'https://commerce.coinbase.com/pay/131bf7a0-69be-4b63-a8da-847ff831bb46';
        $client = $this->client([new Response(201, [], json_encode(['data' => ['hosted_url' => $url]]))]);
        $this->assertSame($url, $client->create(['api_key' => 'fixture-api'], $this->order()));
        $this->assertCount(1, $this->history);
    }

    public function testTransportFailureIsRedactedWithoutRetryAndExplicitCallsRemainIndependent(): void
    {
        $error = new ConnectException('fixture-private-api-and-body', new Request('POST', 'https://fixture.invalid'));
        $client = $this->client([$error, $this->response(), $this->response()]);
        $this->failure(function () use ($client) { $client->create(['api_key' => 'fixture-api'], $this->order()); });
        $this->assertCount(1, $this->history);
        $this->assertCount(2, $this->mock);
        $this->assertSame(self::URL, $client->create(['api_key' => 'fixture-api'], $this->order()));
        $this->assertSame(self::URL, $client->create(['api_key' => 'fixture-api'], $this->order()));
        $this->assertCount(3, $this->history);
    }

    /** @dataProvider badResponses */
    #[\PHPUnit\Framework\Attributes\DataProvider('badResponses')]
    public function testMalformedJsonAndResponseObjectShapesAreRedacted(string $json): void
    {
        $body = new CoinbaseTrackingStream(Utils::streamFor($json));
        $client = $this->client([new Response(200, [], $body), $this->response()]);
        $this->failure(function () use ($client) { $client->create(['api_key' => 'fixture-api'], $this->order()); });
        $this->assertSame(1, $body->closed);
        $this->assertCount(1, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function badResponses(): array
    {
        return array_map(function ($json) { return [$json]; }, ['', 'fixture-private-json', 'null', '[]', '{}', 'true',
            '{"data":[]}', '{"data":null}', '{"data":"fixture-private"}', '{"data":{}}',
            '{"data":{"hosted_url":1}}', '{"data":{"hosted_url":[]}}', '{"data":{"hosted_url":null}}',
            '{"data":{"hosted_url":"' . self::URL . '"},"deep":' . str_repeat('[', 33) . '0' . str_repeat(']', 33) . '}',
            "{\"data\":{\"hosted_url\":\"\xFF\"}}"]);
    }

    /** @dataProvider badUrls */
    #[\PHPUnit\Framework\Attributes\DataProvider('badUrls')]
    public function testHostedUrlRequiresOfficialHttpsChargePath(string $url): void
    {
        $client = $this->client([new Response(200, [], json_encode(['data' => ['hosted_url' => $url]]))]);
        $this->failure(function () use ($client) { $client->create(['api_key' => 'fixture-api'], $this->order()); });
        $this->assertCount(1, $this->history);
    }

    public static function badUrls(): array
    {
        return array_map(function ($url) { return [$url]; }, ['', 'http://commerce.coinbase.com/charges/ABCD1234',
            'https://commerce.coinbase.com.fixture.invalid/charges/ABCD1234', 'https://fixture.invalid/charges/ABCD1234',
            'https://commerce.coinbase.com./charges/ABCD1234', 'https://commerce.coinbase.com:444/charges/ABCD1234',
            'https://commerce.coinbase.com:0/charges/ABCD1234', 'https://commerce.coinbase.com:65536/charges/ABCD1234',
            'https://user@commerce.coinbase.com/charges/ABCD1234', 'https://user:pass@commerce.coinbase.com/charges/ABCD1234',
            'https://@commerce.coinbase.com/charges/ABCD1234', self::URL . '#', self::URL . '#fragment',
            self::URL . "\n", "\x00" . self::URL, self::URL . "\x7F", self::URL . '?x=%0a',
            self::URL . '?x=%00', self::URL . '?x=%7f', 'https://commerce.coinbase.com\\@fixture.invalid/charges/ABCD1234',
            '//commerce.coinbase.com/charges/ABCD1234', '/charges/ABCD1234',
            'https://commerce.coinbase.com/', 'https://commerce.coinbase.com/charges/',
            'https://commerce.coinbase.com/charges/../ABCD1234', self::URL . '/extra',
            'https://commerce.coinbase.com/charges/ABCD%2f1234', 'https://commerce.coinbase.com/charges/' . str_repeat('A', 129),
            'https://commerce.coinbase.com/pay/not-a-uuid', 'https://commerce.coinbase.com/pay/131bf7a0-69be-4b63-a8da-847ff831bb46/extra']);
    }

    public function testOfficialHttpsUrlCanUseNormalPortAndQuery(): void
    {
        foreach ([self::URL, 'https://commerce.coinbase.com:443/charges/ABCD1234',
            'https://COMMERCE.COINBASE.COM/charges/ABCD1234?locale=zh'] as $url) {
            $client = $this->client([new Response(200, [], json_encode(['data' => ['hosted_url' => $url]]))]);
            $this->assertSame($url, $client->create(['api_key' => 'fixture-api'], $this->order()));
        }
    }

    public function testResponseBodyLimitIsEnforcedWhileReadingAndAlwaysClosed(): void
    {
        $json = '{"data":{"hosted_url":"' . self::URL . '"}}';
        foreach ([65536, 65537, 200000] as $size) {
            $body = new CoinbaseTrackingStream(Utils::streamFor($json . str_repeat(' ', $size - strlen($json))));
            $client = $this->client([new Response(200, ['Content-Length' => '1'], $body)]);
            if ($size === 65536) $this->assertSame(self::URL, $client->create(['api_key' => 'fixture-api'], $this->order()));
            else $this->failure(function () use ($client) { $client->create(['api_key' => 'fixture-api'], $this->order()); });
            $this->assertSame(1, $body->closed);
            $this->assertLessThanOrEqual(65537, $body->bytes);
            $this->assertLessThanOrEqual(8192, max($body->reads));
        }
        foreach (['stuck', 'read', 'eof', 'close'] as $mode) {
            $body = new CoinbaseTrackingStream(Utils::streamFor($json), $mode);
            $client = $this->client([new Response(200, [], $body)]);
            $this->failure(function () use ($client) { $client->create(['api_key' => 'fixture-api'], $this->order()); });
            $this->assertSame(1, $body->closed);
        }
    }

    public function testInvalidTransportSeamIsRedacted(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects($this->once())->method('getConfig')->with('handler')->willReturn(null);
        $http->expects($this->never())->method('send');
        $this->failure(function () use ($http) { new CoinbaseCommerceClient($http); });
        $http = $this->createStub(ClientInterface::class);
        $http->method('getConfig')->willThrowException(new RuntimeException('fixture-private-handler'));
        $this->failure(function () use ($http) { new CoinbaseCommerceClient($http); });
    }

    private function capturedPayload(): string
    {
        return "{\r\n" . '  "id": "delivery_fixture", "event": {"data": {"metadata": {"customer_id": "ORDER-001", "customer_name": "\u5546\u54c1"}, "code": "ABCD1234", "payments": [{"status": "confirmed", "value": {"local": {"amount": "10.000000000000000001", "currency": "CNY"}}}, {"status": "pending", "value": {"local": {"amount": "999.00", "currency": "USD"}}}], "hosted_url": "https:\/\/commerce.coinbase.com\/charges\/ABCD1234"}}' . "\r\n}";
    }

    private function sign(string $payload, string $secret = self::SECRET): string
    {
        // Independent RFC 2104 construction, not the client's hash_hmac call.
        $key = strlen($secret) > 64 ? hash('sha256', $secret, true) : $secret;
        $key = str_pad($key, 64, "\0");
        return hash('sha256', ($key ^ str_repeat("\x5c", 64))
            . hash('sha256', ($key ^ str_repeat("\x36", 64)) . $payload, true));
    }

    public function testCapturedRawBytesGoldenHmacAndExactEventData(): void
    {
        $this->assertSame('b0344c61d8db38535ca8afceaf0bf12b881dc200c9833da726e9376c2e32cff7', $this->sign('Hi There', str_repeat("\x0b", 20)));
        $payload = $this->capturedPayload();
        $signature = '0c475564828817403c7adbde71de7fff20c0e49644bc86768e4daf5483f33734';
        $this->assertSame(415, strlen($payload));
        $this->assertSame($signature, $this->sign($payload));
        $client = new CoinbaseCommerceClient();
        $expected = json_decode($payload, true)['event']['data'];
        $this->assertSame($expected, $client->verify($payload, $signature, self::SECRET));
        $this->assertSame($expected, $client->verify($payload, strtoupper($signature), self::SECRET));
        $this->assertSame('10.000000000000000001', $expected['payments'][0]['value']['local']['amount']);
    }

    public function testRawByteTamperingAndWrongSecretFail(): void
    {
        $payload = $this->capturedPayload();
        $signature = $this->sign($payload);
        $client = new CoinbaseCommerceClient();
        foreach ([str_replace('ORDER-001', 'ORDER-002', $payload), str_replace('10.000', '11.000', $payload),
            str_replace('confirmed', 'resolved', $payload), str_replace("\r\n", "\n", $payload),
            json_encode(json_decode($payload, true)), $payload . ' ', substr($payload, 0, -1)] as $tampered) {
            $this->failure(function () use ($client, $tampered, $signature) { $client->verify($tampered, $signature, self::SECRET); });
        }
        foreach (['fixture-private-wrong-secret', '', str_repeat('s', 65537)] as $secret) {
            $this->failure(function () use ($client, $payload, $signature, $secret) { $client->verify($payload, $signature, $secret); });
        }
        $longSecret = str_repeat('s', 100);
        $this->assertSame(json_decode($payload, true)['event']['data'], $client->verify($payload, $this->sign($payload, $longSecret), $longSecret));
    }

    /** @dataProvider badSignatures */
    #[\PHPUnit\Framework\Attributes\DataProvider('badSignatures')]
    public function testHeaderRequiresExactly64HexCharacters(string $signature): void
    {
        $client = new CoinbaseCommerceClient();
        $this->failure(function () use ($client, $signature) { $client->verify($this->capturedPayload(), $signature, self::SECRET); });
    }

    public static function badSignatures(): array
    {
        return array_map(function ($signature) { return [$signature]; }, ['', str_repeat('a', 63), str_repeat('a', 65),
            str_repeat('g', 64), '0x' . str_repeat('a', 62), 'sha256=' . str_repeat('a', 64),
            ' ' . str_repeat('a', 64), str_repeat('a', 64) . "\n", str_repeat('a', 64) . ',duplicate',
            str_repeat('a', 64), str_repeat('a', 65536), "\0" . str_repeat('a', 63)]);
    }

    private function minimalData(): array
    {
        return ['metadata' => ['customer_id' => 'ORDER-001'], 'code' => 'ABCD1234', 'payments' => [(object) []]];
    }

    public function testVerificationNeedsNoEventTypeTimestampOrPaymentDecision(): void
    {
        $client = new CoinbaseCommerceClient();
        $data = $this->minimalData();
        $data['payments'] = [['status' => 'pending', 'value' => ['local' => ['amount' => '0.000000000000000001', 'currency' => 'USD']]]];
        $payload = json_encode(['event' => ['data' => $data]]);
        $this->assertSame($data, $client->verify($payload, $this->sign($payload), self::SECRET));
        $this->assertSame($data, $client->verify($payload, $this->sign($payload), self::SECRET));
    }

    public function testSignedJsonSourceShapeAndMetadataAreValidated(): void
    {
        $client = new CoinbaseCommerceClient();
        $bodies = ['', 'null', '[]', '{}', 'true', '{"event":[]}', '{"event":{}}', '{"event":{"data":[]}}',
            '{"event":{"data":null}}', "{\"event\":\"\xFF\"}", '{"event":',
            '{"event":{"data":' . str_repeat('[', 33) . '0' . str_repeat(']', 33) . '}}'];
        foreach ([null, [], 'fixture-private', (object) [], ['customer_id' => ''], ['customer_id' => 1],
            ['customer_id' => false], ['customer_id' => []], ['customer_id' => str_repeat('s', 129)]] as $metadata) {
            $bodies[] = json_encode(['event' => ['data' => array_replace($this->minimalData(), ['metadata' => $metadata])]]);
        }
        foreach ([null, '', 1, false, [], str_repeat('c', 129)] as $code) {
            $bodies[] = json_encode(['event' => ['data' => array_replace($this->minimalData(), ['code' => $code])]]);
        }
        foreach ([null, [], (object) [], 'fixture-private', [1], [null], [[]], ['0' => (object) [], '2' => (object) []]] as $payments) {
            $bodies[] = json_encode(['event' => ['data' => array_replace($this->minimalData(), ['payments' => $payments])]]);
        }
        foreach (array_keys($this->minimalData()) as $key) {
            $data = $this->minimalData();
            unset($data[$key]);
            $bodies[] = json_encode(['event' => ['data' => $data]]);
        }
        foreach ($bodies as $payload) {
            $this->failure(function () use ($client, $payload) { $client->verify($payload, $this->sign($payload), self::SECRET); });
        }
    }

    public function testSignedBodyMetadataAndPaymentsBounds(): void
    {
        $client = new CoinbaseCommerceClient();
        $data = $this->minimalData();
        $data['metadata']['customer_id'] = str_repeat('s', 128);
        $data['code'] = str_repeat('c', 128);
        for ($i = 1; $i < 128; ++$i) $data['metadata']['field' . $i] = 'value';
        $data['payments'] = array_fill(0, 128, (object) []);
        $payload = json_encode(['event' => ['data' => $data]]);
        $this->assertSame(json_decode($payload, true)['event']['data'], $client->verify($payload, $this->sign($payload), self::SECRET));
        foreach (['metadata', 'payments'] as $field) {
            $over = $data;
            if ($field === 'metadata') $over['metadata']['extra'] = 'value';
            else $over['payments'][] = (object) [];
            $bad = json_encode(['event' => ['data' => $over]]);
            $this->failure(function () use ($client, $bad) { $client->verify($bad, $this->sign($bad), self::SECRET); });
        }
        $payload = json_encode(['event' => ['data' => $this->minimalData()]]);
        $maximum = $payload . str_repeat(' ', 65536 - strlen($payload));
        $this->assertSame(json_decode($payload, true)['event']['data'], $client->verify($maximum, $this->sign($maximum), self::SECRET));
        $over = $maximum . ' ';
        $this->failure(function () use ($client, $over) { $client->verify($over, $this->sign($over), self::SECRET); });
    }
}

class CoinbaseTrackingStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public $closed = 0;
    public $bytes = 0;
    public $reads = [];
    private $mode;
    private $stream;

    public function __construct(StreamInterface $stream, string $mode = '')
    {
        $this->stream = $stream;
        $this->mode = $mode;
    }

    public function getSize(): ?int { return 1; }
    public function __toString(): string { throw new RuntimeException('fixture-private-unbounded-read'); }
    public function getContents(): string { throw new RuntimeException('fixture-private-unbounded-read'); }

    public function eof(): bool
    {
        if ($this->mode === 'eof') throw new RuntimeException('fixture-private-eof');
        return $this->mode === 'stuck' ? false : $this->stream->eof();
    }

    public function read($length): string
    {
        $this->reads[] = $length;
        if ($this->mode === 'read') throw new RuntimeException('fixture-private-read');
        $chunk = $this->mode === 'stuck' ? '' : $this->stream->read($length);
        $this->bytes += strlen($chunk);
        return $chunk;
    }

    public function close(): void
    {
        ++$this->closed;
        $this->stream->close();
        if ($this->mode === 'close') throw new RuntimeException('fixture-private-close');
    }
}
