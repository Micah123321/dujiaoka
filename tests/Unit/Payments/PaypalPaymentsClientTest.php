<?php

namespace Tests\Unit\Payments;

use App\Payments\PaypalOrdersClient;
use App\Payments\PaypalPaymentsClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PaypalPaymentsClientTest extends TestCase
{
    private $history = [];
    private $mock;

    private function client(array $responses): PaypalPaymentsClient
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        return new PaypalPaymentsClient(new Client([
            'handler' => $stack, 'base_uri' => 'http://fixture.invalid/', 'verify' => false,
            'allow_redirects' => true, 'connect_timeout' => 99, 'timeout' => 99, 'http_errors' => true,
        ]));
    }

    private function credentials(string $mode = 'sandbox'): array
    {
        return ['client_id' => 'fixtureClient', 'secret' => 'fixturePrivate', 'mode' => $mode,
            'url' => 'http://fixture.invalid/'];
    }

    private function oauth(string $token = 'fixtureBearer', int $expires = 3600): Response
    {
        return new Response(200, [], json_encode([
            'access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expires,
        ]));
    }

    private function assertSanitizedFailure(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected provider failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Payment provider unavailable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('fixture-private', (string) $exception);
        }
    }

    /** @dataProvider modes */
    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function testActualGuzzleEncodingUsesV1FixedHostsAndStableRequestKeys(string $mode, string $base): void
    {
        $id = 'PAY-1BP41925BA069714SK6SKIAY';
        $payment = ['id' => $id, 'state' => 'created'];
        $executed = ['id' => $id, 'state' => 'approved'];
        $params = [
            'intent' => 'sale', 'payer' => ['payment_method' => 'paypal'],
            'transactions' => [['amount' => ['currency' => 'USD', 'total' => '10.00'],
                'description' => 'fixture "quote" & / slash', 'custom' => 'fixture-reference']],
            'redirect_urls' => ['return_url' => 'https://fixture.invalid/return?a=1&b=2',
                'cancel_url' => 'https://fixture.invalid/cancel'],
        ];
        $client = $this->client([$this->oauth(), new Response(201, [], json_encode($payment)),
            new Response(201, [], json_encode($payment)), new Response(200, [], json_encode($payment)),
            new Response(200, [], json_encode($executed)), new Response(200, [], json_encode($executed))]);
        $this->assertInstanceOf(PaypalOrdersClient::class, $client);
        $credentials = $this->credentials($mode);
        $createKey = str_repeat('a', 32);
        $executeKey = str_repeat('b', 32);
        $this->assertSame($payment, $client->create($credentials, $params, $createKey));
        $this->assertSame($payment, $client->create($credentials, $params, $createKey));
        $this->assertSame($payment, $client->get($credentials, $id));
        $this->assertSame($executed, $client->execute($credentials, $id, 'payer_fixture', $executeKey));
        $this->assertSame($executed, $client->execute($credentials, $id, 'payer_fixture', $executeKey));
        $this->assertCount(6, $this->history);
        $this->assertCount(0, $this->mock);

        $paths = ['/v1/oauth2/token', '/v1/payments/payment', '/v1/payments/payment',
            '/v1/payments/payment/' . $id, '/v1/payments/payment/' . $id . '/execute',
            '/v1/payments/payment/' . $id . '/execute'];
        foreach ($this->history as $i => $entry) {
            $request = $entry['request'];
            $this->assertSame($base . $paths[$i], (string) $request->getUri());
            $this->assertSame($i === 3 ? 'GET' : 'POST', $request->getMethod());
            $this->assertSame('application/json', $request->getHeaderLine('Accept'));
            $this->assertFalse($entry['options']['allow_redirects']);
            $this->assertTrue($entry['options']['verify']);
            $this->assertFalse($entry['options']['http_errors']);
            $this->assertSame(3, $entry['options']['connect_timeout']);
            $this->assertSame(10, $entry['options']['timeout']);
            if ($i > 0) {
                $this->assertSame('Bearer fixtureBearer', $request->getHeaderLine('Authorization'));
                $this->assertSame('return=representation', $request->getHeaderLine('Prefer'));
            }
        }
        $oauth = $this->history[0]['request'];
        $this->assertSame('Basic ' . base64_encode('fixtureClient:fixturePrivate'), $oauth->getHeaderLine('Authorization'));
        $this->assertSame('grant_type=client_credentials', (string) $oauth->getBody());
        $this->assertSame('application/x-www-form-urlencoded', $oauth->getHeaderLine('Content-Type'));
        $this->assertFalse($oauth->hasHeader('PayPal-Request-Id'));
        foreach ([1, 2] as $index) {
            $request = $this->history[$index]['request'];
            $this->assertSame($createKey, $request->getHeaderLine('PayPal-Request-Id'));
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertSame($params, json_decode((string) $request->getBody(), true));
        }
        $this->assertSame((string) $this->history[1]['request']->getBody(), (string) $this->history[2]['request']->getBody());
        $this->assertFalse($this->history[3]['request']->hasHeader('PayPal-Request-Id'));
        $this->assertSame('', (string) $this->history[3]['request']->getBody());
        foreach ([4, 5] as $index) {
            $request = $this->history[$index]['request'];
            $this->assertSame($executeKey, $request->getHeaderLine('PayPal-Request-Id'));
            $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
            $this->assertSame('{"payer_id":"payer_fixture"}', (string) $request->getBody());
        }
    }

    public static function modes(): array
    {
        return [['sandbox', 'https://api-m.sandbox.paypal.com'], ['live', 'https://api-m.paypal.com']];
    }

    /** @dataProvider credentialRotations */
    #[\PHPUnit\Framework\Attributes\DataProvider('credentialRotations')]
    public function testOAuthCacheSeparatesModeClientAndSecretAndReusesEachToken(array $rotation): void
    {
        $client = $this->client([$this->oauth('fixtureOriginal'), new Response(200, [], '{}'),
            new Response(200, [], '{}'), $this->oauth('fixtureRotated'), new Response(200, [], '{}'),
            new Response(200, [], '{}'), new Response(200, [], '{}')]);
        $original = $this->credentials();
        $rotated = array_replace($original, $rotation);
        $id = 'PAYID-123ABC';
        $client->get($original, $id);
        $client->get($original, $id);
        $client->get($rotated, $id);
        $client->get($rotated, $id);
        $client->get($original, $id);
        $this->assertCount(7, $this->history);
        $this->assertSame('/v1/oauth2/token', $this->history[3]['request']->getUri()->getPath());
        $this->assertSame('Basic ' . base64_encode($rotated['client_id'] . ':' . $rotated['secret']),
            $this->history[3]['request']->getHeaderLine('Authorization'));
        $host = $rotated['mode'] === 'live' ? 'api-m.paypal.com' : 'api-m.sandbox.paypal.com';
        foreach ([3, 4, 5] as $index) $this->assertSame($host, $this->history[$index]['request']->getUri()->getHost());
        foreach ([1, 2, 6] as $index) {
            $this->assertSame('Bearer fixtureOriginal', $this->history[$index]['request']->getHeaderLine('Authorization'));
        }
        foreach ([4, 5] as $index) {
            $this->assertSame('Bearer fixtureRotated', $this->history[$index]['request']->getHeaderLine('Authorization'));
        }
    }

    public static function credentialRotations(): array
    {
        return ['mode' => [['mode' => 'live']], 'client' => [['client_id' => 'fixtureOtherClient']],
            'secret' => [['secret' => 'fixtureRotatedPrivate']]];
    }

    public function testExpiringOAuthTokenIsReplacedWithoutSleeping(): void
    {
        $client = $this->client([$this->oauth('fixtureExpiring', 1), new Response(200, [], '{}'),
            $this->oauth('fixtureFresh'), new Response(200, [], '{}')]);
        $client->get($this->credentials(), 'PAY-123ABC');
        $client->get($this->credentials(), 'PAY-123ABC');
        $this->assertCount(4, $this->history);
        $this->assertSame('/v1/oauth2/token', $this->history[2]['request']->getUri()->getPath());
        $this->assertSame('Bearer fixtureFresh', $this->history[3]['request']->getHeaderLine('Authorization'));
    }

    /** @dataProvider failures */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testFailureDoesNotRetryOrExposeOriginalException(string $method, $failure, bool $oauthFailure): void
    {
        $responses = $oauthFailure ? [] : [$this->oauth()];
        $responses[] = $failure;
        $responses[] = new Response(200, [], '{}');
        $client = $this->client($responses);
        $this->assertSanitizedFailure(function () use ($client, $method) {
            if ($method === 'create') $client->create($this->credentials(), ['intent' => 'sale'], str_repeat('a', 32));
            elseif ($method === 'execute') $client->execute($this->credentials(), 'PAY-123ABC', 'payer_fixture', str_repeat('b', 32));
            else $client->get($this->credentials(), 'PAY-123ABC');
        });
        $this->assertCount($oauthFailure ? 1 : 2, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function failures(): array
    {
        $exception = new ConnectException('fixture-private-transport-error', new Request('POST', 'https://fixture.invalid/'));
        return [
            'create 500' => ['create', new Response(500, [], 'fixture-private-response'), false],
            'execute 401' => ['execute', new Response(401, [], 'fixture-private-response'), false],
            'execute 429' => ['execute', new Response(429, ['Retry-After' => '0'], 'fixture-private-response'), false],
            'execute connection' => ['execute', $exception, false],
            'get 500' => ['get', new Response(500, [], 'fixture-private-response'), false],
            'create malformed JSON' => ['create', new Response(201, [], 'fixture-private-invalid-json'), false],
            'get scalar JSON' => ['get', new Response(200, [], '"fixture-private-response"'), false],
            'execute redirect' => ['execute', new Response(302, ['Location' => 'https://fixture.invalid/'], ''), false],
            'OAuth status' => ['create', new Response(401, [], 'fixture-private-oauth'), true],
            'OAuth connection' => ['execute', $exception, true],
        ];
    }

    public function testExplicitRetryUsesSameKeyAndFetchesNewTokenAfterFailure(): void
    {
        $client = $this->client([$this->oauth('fixtureExpired'), new Response(401, [], 'fixture-private-response'),
            $this->oauth('fixtureFresh'), new Response(200, [], '{"state":"approved"}')]);
        $key = str_repeat('b', 32);
        $this->assertSanitizedFailure(function () use ($client, $key) {
            $client->execute($this->credentials(), 'PAY-123ABC', 'payer_fixture', $key);
        });
        $this->assertCount(2, $this->history);
        $this->assertSame(['state' => 'approved'], $client->execute($this->credentials(), 'PAY-123ABC', 'payer_fixture', $key));
        $this->assertCount(4, $this->history);
        $this->assertSame('/v1/oauth2/token', $this->history[2]['request']->getUri()->getPath());
        foreach ([1, 3] as $index) $this->assertSame($key, $this->history[$index]['request']->getHeaderLine('PayPal-Request-Id'));
        $this->assertSame('Bearer fixtureFresh', $this->history[3]['request']->getHeaderLine('Authorization'));
    }

    /** @dataProvider invalidIds */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIds')]
    public function testInvalidPaymentIdMakesNoNetworkRequest(string $id): void
    {
        $client = $this->client([new Response(200, [], '{}')]);
        $this->assertSanitizedFailure(function () use ($client, $id) { $client->get($this->credentials(), $id); });
        $this->assertSanitizedFailure(function () use ($client, $id) {
            $client->execute($this->credentials(), $id, 'payer_fixture', str_repeat('b', 32));
        });
        $this->assertCount(0, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function invalidIds(): array
    {
        return array_map(function ($id) { return [$id]; }, ['', 'PAY-', 'PAYID-', 'OTHER-123', 'pay-123',
            'PAY-a', 'PAYID-abc', 'PAY-123/execute', 'PAY-123?x=1', 'PAY-123#x', 'PAY-123%2F',
            'PAY-123_ABC', 'PAY-123-ABC', 'PAY-123\\ABC', ' PAY-123', 'PAY-123 ', "PAY-123\n", "PAY-123\0",
            'PAY-' . str_repeat('A', 61), 'PAYID-' . str_repeat('A', 59), "PAY-\xC3\xA9"]);
    }

    /** @dataProvider validIds */
    #[\PHPUnit\Framework\Attributes\DataProvider('validIds')]
    public function testBothPaymentIdPrefixesAndMaximumLengthAreAccepted(string $id): void
    {
        $client = $this->client([$this->oauth(), new Response(200, [], '{}'), new Response(200, [], '{}')]);
        $client->get($this->credentials(), $id);
        $client->execute($this->credentials(), $id, 'payer_fixture', str_repeat('b', 32));
        $this->assertSame('/v1/payments/payment/' . $id, $this->history[1]['request']->getUri()->getPath());
        $this->assertSame('/v1/payments/payment/' . $id . '/execute', $this->history[2]['request']->getUri()->getPath());
    }

    public static function validIds(): array
    {
        return [['PAY-1BP41925BA069714SK6SKIAY'], ['PAYID-123ABC'], ['PAY-' . str_repeat('A', 60)],
            ['PAYID-' . str_repeat('9', 58)]];
    }

    /** @dataProvider invalidPayers */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayers')]
    public function testInvalidPayerIdMakesNoNetworkRequest(string $payer): void
    {
        $client = $this->client([new Response(200, [], '{}')]);
        $this->assertSanitizedFailure(function () use ($client, $payer) {
            $client->execute($this->credentials(), 'PAY-123ABC', $payer, str_repeat('b', 32));
        });
        $this->assertCount(0, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function invalidPayers(): array
    {
        return array_map(function ($payer) { return [$payer]; }, ['', str_repeat('A', 129), 'payer/fixture',
            'payer?fixture', 'payer#fixture', 'payer.fixture', 'payer%5Ffixture', 'payer fixture', ' payer',
            'payer ', "payer\n", "payer\0", "payer\x7F", "payer\xC3\xA9"]);
    }

    /** @dataProvider validPayers */
    #[\PHPUnit\Framework\Attributes\DataProvider('validPayers')]
    public function testAsciiPayerIdBoundariesArePreserved(string $payer): void
    {
        $client = $this->client([$this->oauth(), new Response(200, [], '{}')]);
        $client->execute($this->credentials(), 'PAYID-123ABC', $payer, str_repeat('b', 32));
        $this->assertSame(['payer_id' => $payer], json_decode((string) $this->history[1]['request']->getBody(), true));
    }

    public static function validPayers(): array
    {
        return [['A'], ['9'], ['_'], ['-'], ['payer_fixture'], ['PAYER-123_abc'], [str_repeat('Z', 128)]];
    }

    /** @dataProvider invalidKeys */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidKeys')]
    public function testParentRequestKeyContractIsAppliedBeforeOAuth(string $key): void
    {
        $client = $this->client([new Response(200, [], '{}')]);
        $this->assertSanitizedFailure(function () use ($client, $key) {
            $client->create($this->credentials(), ['intent' => 'sale'], $key);
        });
        $this->assertSanitizedFailure(function () use ($client, $key) {
            $client->execute($this->credentials(), 'PAY-123ABC', 'payer_fixture', $key);
        });
        $this->assertCount(0, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function invalidKeys(): array
    {
        return [[''], [str_repeat('a', 31)], [str_repeat('a', 33)], [str_repeat('A', 32)],
            [str_repeat('g', 32)], [str_repeat('a', 32) . "\n"]];
    }

    /** @dataProvider approvalUrls */
    #[\PHPUnit\Framework\Attributes\DataProvider('approvalUrls')]
    public function testApprovalUrlPreservesVendorPathAndQuery(string $mode, string $url): void
    {
        $payment = ['id' => 'PAY-123ABC', 'links' => [
            ['rel' => 'self', 'href' => 'https://fixture.invalid/'],
            ['rel' => 'approval_url', 'href' => $url, 'method' => 'REDIRECT'], ['rel' => 'execute'],
        ]];
        $this->assertSame($url, PaypalPaymentsClient::approvalUrl($payment, $mode));
    }

    public static function approvalUrls(): array
    {
        return [
            ['sandbox', 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=EC-fixture'],
            ['live', 'https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=EC-fixture'],
            ['sandbox', 'https://www.sandbox.paypal.com/checkoutnow?token=EC-fixture'],
            ['live', 'https://www.paypal.com:443/checkoutnow?token=EC-fixture'],
            ['sandbox', 'https://www.sandbox.paypal.com:443/vendor/path/a%2Fb?token=EC-fixture%2B1&vendor=1&vendor=2'],
            ['live', 'https://www.paypal.com/vendor/future/path?return=%2Fcheckout&token=EC-fixture'],
            ['live', 'HTTPS://WWW.PAYPAL.COM/checkoutnow?token=EC-fixture'],
        ];
    }

    /** @dataProvider deceptiveApprovalUrls */
    #[\PHPUnit\Framework\Attributes\DataProvider('deceptiveApprovalUrls')]
    public function testDeceptiveApprovalUrlReturnsNull(string $mode, string $url): void
    {
        $this->assertNull(PaypalPaymentsClient::approvalUrl(['links' => [['rel' => 'approval_url', 'href' => $url]]], $mode));
    }

    public static function deceptiveApprovalUrls(): array
    {
        $urls = ['', '/checkoutnow', '//www.paypal.com/checkoutnow', 'http://www.paypal.com/checkoutnow',
            'ftp://www.paypal.com/checkoutnow', 'https:////www.paypal.com/checkoutnow',
            'https://www.paypal.com.evil.test/checkoutnow', 'https://evil-www.paypal.com/checkoutnow',
            'https://paypal.com/checkoutnow', 'https://api-m.paypal.com/checkoutnow',
            'https://www.paypal.com./checkoutnow', 'https://www.paypa1.com/checkoutnow',
            'https://www%2Epaypal.com/checkoutnow', 'https://127.0.0.1/checkoutnow',
            'https://www.paypal.com@evil.test/checkoutnow', 'https://user@www.paypal.com/checkoutnow',
            'https://user:pass@www.paypal.com/checkoutnow', 'https://@www.paypal.com/checkoutnow',
            'https://www.paypal.com:80/checkoutnow', 'https://www.paypal.com:444/checkoutnow',
            'https://www.paypal.com:0/checkoutnow', 'https://www.paypal.com:65536/checkoutnow',
            'https://www.paypal.com/checkoutnow#private', 'https://www.paypal.com/checkoutnow#',
            'https://www.paypal.com\\@evil.test/checkoutnow', 'https://evil.test\\@www.paypal.com/checkoutnow',
            "https://www.paypal.com/checkoutnow?token=x\r\nLocation:evil", "https://www.paypal.com/checkoutnow\t",
            "https://www.paypal.com/checkoutnow\0", "https://www.paypal.com/checkoutnow\x1F",
            "https://www.paypal.com/checkoutnow\x7F", 'https://www.paypal.com/checkout now',
            "https://www.payp\xD0\xB0l.com/checkoutnow"];
        $cases = array_map(function ($url) { return ['live', $url]; }, $urls);
        $cases[] = ['live', 'https://www.sandbox.paypal.com/checkoutnow'];
        $cases[] = ['sandbox', 'https://www.paypal.com/checkoutnow'];
        $cases[] = ['sandbox', 'https://www.sandbox.paypal.com.evil.test/cgi-bin/webscr?token=EC-fixture'];
        return $cases;
    }

    public function testMalformedModesAndMissingMalformedOrDuplicateLinksReturnNull(): void
    {
        $url = 'https://www.paypal.com/checkoutnow?token=EC-fixture';
        $link = ['rel' => 'approval_url', 'href' => $url];
        foreach (['', 'LIVE', 'Sandbox', 'production', 'live ', "live\0", "sandbox\n"] as $mode) {
            $this->assertNull(PaypalPaymentsClient::approvalUrl(['links' => [$link]], $mode));
        }
        $payments = [[], ['links' => null], ['links' => 'invalid'], ['links' => new \stdClass()],
            ['links' => []], ['links' => ['invalid', null, 1]], ['links' => [['rel' => 'approve', 'href' => $url]]],
            ['links' => [['rel' => 'approval_url']]], ['links' => [['rel' => 'approval_url', 'href' => 123]]],
            ['links' => [$link, $link]], ['links' => [$link, ['rel' => 'approval_url']]],
            ['links' => [$link, ['rel' => 'approval_url', 'href' => 'http://fixture.invalid/']]]];
        foreach ($payments as $payment) $this->assertNull(PaypalPaymentsClient::approvalUrl($payment, 'live'));
    }
}
