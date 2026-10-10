<?php

namespace Tests\Unit\Payments;

use App\Payments\AlipayClient;
use App\Payments\AlipayHttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yansongda\Pay\Pay;

class AlipayClientTest extends TestCase
{
    private const URL = 'https://openapi.alipay.com/gateway.do?charset=utf-8';
    private static $merchants = [];
    private static $providers = [];
    private $history = [];
    private $mock;
    private $temporary = [];

    public static function setUpBeforeClass(): void
    {
        for ($i = 0; $i < 2; ++$i) {
            self::$merchants[] = self::generateKey();
            self::$providers[] = self::generateKey();
        }
    }

    private static function opensslConfig(): array
    {
        return ['config' => dirname(__DIR__, 2) . '/Fixtures/alipay-openssl.cnf',
            'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    }

    private static function generateKey(): array
    {
        $key = openssl_pkey_new(self::opensslConfig());
        if ($key === false || !openssl_pkey_export($key, $private, null, self::opensslConfig())) {
            throw new RuntimeException('Test RSA generation failed with the explicit fixture OpenSSL config.');
        }
        return ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
    }

    protected function setUp(): void
    {
        $this->history = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $file) unlink($file);
        if (class_exists(\Yansongda\Pay\Provider\Alipay::class)) {
            Pay::clear();
        } else {
            try {
                \Yansongda\Pay\Gateways\Alipay\Support::getInstance()->clear();
            } catch (\Yansongda\Pay\Exceptions\InvalidArgumentException $exception) {
            }
        }
    }

    private function credentials(int $account = 0): array
    {
        return ['app_id' => $account === 0 ? '2026000000000001' : '2026000000000002',
            'public_key' => self::$providers[$account]['public'],
            'private_key' => self::$merchants[$account]['private'],
            'notify_url' => 'https://merchant.invalid/alipay/notify',
            'return_url' => 'https://merchant.invalid/alipay/return'];
    }

    private function order(array $changes = []): array
    {
        return array_replace(['out_trade_no' => 'ORDER-FIXTURE-1',
            'subject' => "Fixture \u{4e2d} 'item'", 'total_amount' => '1234567890.10'], $changes);
    }

    private function http(array $responses = [], array $defaults = []): Client
    {
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        return new Client(array_replace($defaults, ['handler' => $stack]));
    }

    // This oracle uses only OpenSSL and explicitly sorted protocol fields, never SDK helpers.
    private function canonical(array $fields, bool $callback = false): string
    {
        unset($fields['sign']);
        if ($callback) unset($fields['sign_type']);
        ksort($fields, SORT_STRING);
        $parts = [];
        foreach ($fields as $key => $value) {
            if ($value !== '' && $value !== null) $parts[] = $key . '=' . $value;
        }
        return implode('&', $parts);
    }

    private function sign(string $contents, string $private): string
    {
        $this->assertTrue(openssl_sign($contents, $signature, $private, OPENSSL_ALGO_SHA256));
        return base64_encode($signature);
    }

    private function responseData(array $changes = []): array
    {
        return array_replace(['code' => '10000', 'msg' => 'Success', 'out_trade_no' => 'ORDER-FIXTURE-1',
            'qr_code' => 'https://qr.alipay.com/fixture', 'note' => "Provider \u{4e2d}"], $changes);
    }

    private function response(int $account = 0, array $changes = [], bool $tamper = false): Response
    {
        $data = $this->responseData($changes);
        $signature = $this->sign(json_encode($data, JSON_UNESCAPED_UNICODE), self::$providers[$account]['private']);
        if ($tamper) $data['qr_code'] .= '-tampered';
        return new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['alipay_trade_precreate_response' => $data, 'sign' => $signature], JSON_UNESCAPED_UNICODE));
    }

    private function callbackParams(int $account = 0, array $changes = []): array
    {
        $data = array_replace(['app_id' => $this->credentials($account)['app_id'], 'out_trade_no' => 'ORDER-FIXTURE-1',
            'trade_no' => '202600000000001', 'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '1234567890.10',
            'subject' => "Fixture \u{4e2d}", 'sign_type' => 'RSA2'], $changes);
        $data['sign'] = $this->sign($this->canonical($data, true), self::$providers[$account]['private']);
        return $data;
    }

    private function assertUnavailable(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a redacted provider failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, get_class($exception));
            $this->assertSame('Payment provider unavailable.', $exception->getMessage());
            $this->assertSame(0, $exception->getCode());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('fixture-private', (string) $exception);
        }
    }

    private function assertPayload(array $payload, string $mode, int $account = 0, ?array $order = null): void
    {
        $order = $order ?? $this->order();
        $this->assertSame($this->credentials($account)['app_id'], $payload['app_id']);
        $this->assertSame('RSA2', $payload['sign_type']);
        $this->assertSame('utf-8', $payload['charset']);
        $this->assertSame('1.0', $payload['version']);
        $this->assertSame('JSON', $payload['format']);
        $this->assertSame($this->credentials($account)['notify_url'], $payload['notify_url']);
        $this->assertSame($this->credentials($account)['return_url'], $payload['return_url']);
        $this->assertArrayNotHasKey('app_cert_sn', $payload);
        $this->assertArrayNotHasKey('alipay_root_cert_sn', $payload);
        $methods = ['scan' => 'alipay.trade.precreate', 'web' => 'alipay.trade.page.pay', 'wap' => 'alipay.trade.wap.pay'];
        $this->assertSame($methods[$mode], $payload['method']);
        $biz = json_decode($payload['biz_content'], true);
        foreach ($order as $key => $value) $this->assertSame($value, $biz[$key]);
        foreach ($biz as $key => $value) $this->assertNotSame('_', substr($key, 0, 1));
        $signature = base64_decode($payload['sign'], true);
        $this->assertNotFalse($signature);
        $this->assertSame(1, openssl_verify($this->canonical($payload), $signature, self::$merchants[$account]['public'], OPENSSL_ALGO_SHA256));
        $this->assertSame(0, openssl_verify($this->canonical($payload), $signature, self::$merchants[1 - $account]['public'], OPENSSL_ALGO_SHA256));
    }

    private function requestPayload(int $index): array
    {
        $request = $this->history[$index]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::URL, (string) $request->getUri());
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        parse_str((string) $request->getBody(), $payload);
        return $payload;
    }

    public function testScanUsesRealSdkSigningAndVerifiedProviderResult(): void
    {
        $response = $this->response();
        $envelope = json_decode((string) $response->getBody(), true);
        $response->getBody()->rewind();
        $result = (new AlipayClient($this->http([$response])))->checkout('scan', $this->credentials(), $this->order());
        $this->assertCount(1, $this->history);
        $this->assertPayload($this->requestPayload(0), 'scan');
        foreach ($this->responseData() as $key => $value) $this->assertSame($value, $result[$key]);
        if (class_exists(\Yansongda\Pay\Provider\Alipay::class)) {
            $this->assertSame($envelope['sign'], $result['_sign']);
            unset($result['_sign']);
            $config = Pay::get(\Yansongda\Artful\Contract\ConfigInterface::class);
            $this->assertFalse($config->get('logger.enable'));
            $this->assertSame(10, $config->get('http.timeout'));
            $this->assertSame(3, $config->get('http.connect_timeout'));
        } else {
            $this->assertInstanceOf(\Psr\Log\NullLogger::class, \Yansongda\Supports\Log::getInstance()->getLogger());
        }
        $this->assertSame($this->responseData(), $result);
    }

    public function testTamperedAndWrongKeyProviderResponsesAreRejected(): void
    {
        foreach ([$this->response(0, [], true), $this->response(1)] as $response) {
            $before = count($this->history);
            $client = new AlipayClient($this->http([$response]));
            $this->assertUnavailable(function () use ($client) {
                $client->checkout('scan', $this->credentials(), $this->order());
            });
            $this->assertCount($before + 1, $this->history);
        }
    }

    public function testSignedButMismatchedInvoiceOrUnsafeQrDoesNotBecomeACheckout(): void
    {
        foreach ([['out_trade_no' => 'OTHER-ORDER'], ['out_trade_no' => null], ['qr_code' => null],
            ['qr_code' => 'javascript:alert(1)'], ['qr_code' => 'http://qr.alipay.com/fixture'],
            ['qr_code' => 'https://qr.alipay.com.evil.invalid/fixture'], ['qr_code' => 'https://user@qr.alipay.com/fixture'],
            ['qr_code' => 'https://qr.alipay.com/fixture#']] as $changes) {
            $client = new AlipayClient($this->http([$this->response(0, $changes)]));
            $this->assertUnavailable(function () use ($client) { $client->checkout('scan', $this->credentials(), $this->order()); });
        }
    }

    public function testWebAndWapProduceSignedHtmlWithoutAnyIo(): void
    {
        $client = new AlipayClient($this->http());
        foreach (['web', 'wap'] as $mode) {
            $html = $client->checkout($mode, $this->credentials(), $this->order());
            $this->assertIsString($html);
            $document = new \DOMDocument();
            $this->assertTrue(@$document->loadHTML($html));
            $form = $document->getElementsByTagName('form')->item(0);
            $this->assertSame(self::URL, $form->getAttribute('action'));
            $this->assertSame('POST', strtoupper($form->getAttribute('method')));
            $payload = [];
            foreach ($document->getElementsByTagName('input') as $input) {
                if ($input->getAttribute('name') !== '') $payload[$input->getAttribute('name')] = $input->getAttribute('value');
            }
            $this->assertPayload($payload, $mode);
            $this->assertStringContainsString("document.forms['alipay_submit'].submit()", $html);
        }
        $this->assertCount(0, $this->history);
    }

    public function testFreshConfigsRotateBothRequestAndResponseKeys(): void
    {
        $client = new AlipayClient($this->http([$this->response(0), $this->response(1), $this->response(0)]));
        foreach ([0, 1, 0] as $index => $account) {
            $result = $client->checkout('scan', $this->credentials($account), $this->order());
            $this->assertSame('10000', $result['code']);
            $this->assertPayload($this->requestPayload($index), 'scan', $account);
        }
        $this->assertCount(3, $this->history);
    }

    public function testCallbackUsesExplicitParamsAndRotatesMerchantConfigs(): void
    {
        $client = new AlipayClient($this->http());
        $globals = [$_GET, $_POST, $_REQUEST];
        $_GET = $_POST = $_REQUEST = ['app_id' => 'fixture-private', 'sign' => 'bad'];
        try {
            foreach ([0, 1, 0] as $account) {
                $params = $this->callbackParams($account);
                $this->assertSame($params, $client->verify($params, $this->credentials($account)));
            }
            $wrongKey = $this->callbackParams(0, ['app_id' => $this->credentials(1)['app_id']]);
            $this->assertUnavailable(function () use ($client, $wrongKey) { $client->verify($wrongKey, $this->credentials(1)); });
        } finally {
            list($_GET, $_POST, $_REQUEST) = $globals;
        }
        $this->assertCount(0, $this->history);
    }

    public function testRawFormDecodePreservesSignedBytesAndRejectsAmbiguousFields(): void
    {
        $this->assertSame(['subject' => '  item + one  ', 'body' => '', 'note' => "line\r\nend", 'zero' => '0'],
            AlipayClient::parseNotification('subject=++item+%2B+one++&body=&note=line%0D%0Aend&zero=0'));
        foreach (['', 'subject=1&subject=2', 'out_trade_no=1&out%5Ftrade_no=2', 'subject%5B%5D=1',
            'subject=bad%GG', 'sign', '&subject=1', 'subject=1&', 'sign=1&_config=x', str_repeat('s', 65537)] as $body) {
            $this->assertUnavailable(function () use ($body) { AlipayClient::parseNotification($body); });
        }
    }

    public function testCallbackRejectsTamperingMissingSignatureAndSdkDirectives(): void
    {
        $client = new AlipayClient($this->http());
        $base = $this->callbackParams();
        $cases = [[], array_replace($base, ['total_amount' => '0.01']),
            array_replace($base, ['app_id' => '2026999999999999']),
            array_replace($base, ['sign_type' => 'RSA']), array_replace($base, ['sign' => '']),
            array_replace($base, ['sign' => 'fixture-private%%']), array_replace($base, ['subject' => ['fixture-private']]),
            array_replace($base, ['total_amount' => 1]), array_replace($base, ['oversize' => str_repeat('x', 65537)]),
            $this->callbackParams(0, ['_config' => 'other']), $this->callbackParams(0, ['_url' => 'https://evil.invalid']),
            $this->callbackParams(0, ['_direction' => 'fixture-private'])];
        foreach ($cases as $params) {
            $this->assertUnavailable(function () use ($client, $params) { $client->verify($params, $this->credentials()); });
        }
        $this->assertCount(0, $this->history);
    }

    public function testCallerSdkControlsAndInexactOrNonpositiveAmountsFailBeforeIo(): void
    {
        $client = new AlipayClient($this->http());
        $orders = [];
        foreach (['_url', '_method', '_config', '_direction', '_return_url', '_notify_url', '_unpack',
            'http_method', 'notify_url', 'product_code', 'extra'] as $key) $orders[] = $this->order([$key => 'fixture-private']);
        foreach ([0, 1, 0.01, '0.00', '0.0', '-1.00', '1e2', 'NaN', '1.001', '01.00', '1.00 ', '1', '', []] as $amount) {
            $orders[] = $this->order(['total_amount' => $amount]);
        }
        $orders[] = $this->order(['subject' => '']);
        $orders[] = $this->order(['subject' => "bad\0fixture-private"]);
        $missing = $this->order(); unset($missing['subject']); $orders[] = $missing;
        foreach ($orders as $order) {
            $this->assertUnavailable(function () use ($client, $order) { $client->checkout('scan', $this->credentials(), $order); });
        }
        foreach (['h5', 'app', '_url', '', 'SCAN'] as $mode) {
            $this->assertUnavailable(function () use ($client, $mode) { $client->checkout($mode, $this->credentials(), $this->order()); });
        }
        $this->assertCount(0, $this->history);
    }

    public function testExactDecimalStringIsNotRoundedOrConverted(): void
    {
        $client = new AlipayClient($this->http([$this->response(), $this->response(), $this->response()]));
        foreach (['0.01', '1.20', '99999999999999999999.99'] as $index => $amount) {
            $order = $this->order(['total_amount' => $amount]);
            $client->checkout('scan', $this->credentials(), $order);
            $this->assertPayload($this->requestPayload($index), 'scan', 0, $order);
        }
    }

    public function testMaliciousGuzzleDefaultsCannotChangeTheSignedRequest(): void
    {
        $defaults = [
            'base_uri' => 'http://evil.invalid/private',
            'headers' => ['Host' => 'evil.invalid', 'Authorization' => 'fixture-private', 'Cookie' => 'fixture-private',
                'Proxy-Authorization' => 'fixture-private', 'X-Fixture-Private' => 'fixture-private'],
            'json' => ['fixture-private' => true], 'form_params' => ['fixture-private' => true],
            'body' => 'fixture-private', 'multipart' => [['name' => 'fixture-private', 'contents' => 'fixture-private']],
            'query' => ['fixture-private' => '1'], 'auth' => ['fixture-private', 'fixture-private'],
            'cookies' => true, 'proxy' => 'http://evil.invalid', 'verify' => false, 'allow_redirects' => true,
            'http_errors' => true, 'connect_timeout' => 99, 'timeout' => 99, 'read_timeout' => 99,
            'curl' => [13 => 99], 'stream_context' => ['ssl' => ['verify_peer' => false]],
            'debug' => true, 'decode_content' => true,
            'on_headers' => function () { throw new RuntimeException('fixture-private'); },
        ];
        $client = new AlipayClient($this->http([$this->response()], $defaults));
        $client->checkout('scan', $this->credentials(), $this->order());
        $this->assertPayload($this->requestPayload(0), 'scan');
        $request = $this->history[0]['request'];
        $this->assertSame('openapi.alipay.com', $request->getHeaderLine('Host'));
        foreach (['Authorization', 'Cookie', 'Proxy-Authorization', 'X-Fixture-Private'] as $header) {
            $this->assertFalse($request->hasHeader($header));
        }
        $this->assertStringNotContainsString('fixture-private', (string) $request->getBody());
        $options = $this->history[0]['options'];
        foreach (['verify' => true, 'allow_redirects' => false, 'http_errors' => false, 'cookies' => false,
            'proxy' => '', 'connect_timeout' => 3, 'timeout' => 10, 'read_timeout' => 10,
            'debug' => false, 'decode_content' => false, 'stream' => true, 'curl' => [], 'stream_context' => []] as $key => $value) {
            $this->assertSame($value, $options[$key]);
        }
        foreach (['json', 'form_params', 'body', 'multipart', 'auth', 'query', 'on_headers'] as $key) {
            $this->assertArrayNotHasKey($key, $options);
        }
        $this->assertCount(1, $this->history);
    }

    public function testHttpAndSdkFailuresAreRedactedAndNeverReplayed(): void
    {
        $failures = [new Response(302, ['Location' => 'https://evil.invalid'], 'fixture-private'),
            new Response(401, [], 'fixture-private'), new Response(503, [], 'fixture-private'),
            new Response(200, [], 'fixture-private'), new Response(200, [], '{}'), new Response(200, [], ''),
            new Response(200, [], str_repeat('x', 65537)), $this->response(0, ['code' => '40004', 'msg' => 'fixture-private']),
            new Response(200, [], json_encode(['alipay_trade_precreate_response' => $this->responseData()])),
            new ConnectException('fixture-private', new Request('POST', self::URL))];
        foreach ($failures as $failure) {
            $before = count($this->history);
            $client = new AlipayClient($this->http([$failure, $this->response()]));
            $this->assertUnavailable(function () use ($client) { $client->checkout('scan', $this->credentials(), $this->order()); });
            $this->assertCount($before + 1, $this->history);
            $this->assertCount(1, $this->mock);
        }
    }

    public function testHttpBoundaryOnlyAcceptsFixedLivePostUrl(): void
    {
        $http = new AlipayHttpClient($this->http());
        foreach (['http://openapi.alipay.com/gateway.do', 'https://openapi.alipay.com.evil.invalid/gateway.do',
            'https://openapi.alipay.com/gateway.do?charset=utf-8&extra=1',
            'https://openapi.alipay.com/gateway.do?charset=UTF-8', 'https://openapi.alipay.com/gateway.do#fragment',
            'https://openapi.alipaydev.com/gateway.do', 'https://user@openapi.alipay.com/gateway.do', '/gateway.do'] as $url) {
            $this->assertUnavailable(function () use ($http, $url) { $http->sendRequest(new Request('POST', $url)); });
        }
        foreach (['GET', 'PUT', 'DELETE', 'HEAD'] as $method) {
            $this->assertUnavailable(function () use ($http, $method) { $http->request($method, self::URL); });
        }
        $this->assertCount(0, $this->history);
    }

    public function testResponseReadingIsBoundedAndClosesStreamsOnFailure(): void
    {
        $read = 0;
        $stream = new PumpStream(function ($length) use (&$read) { $read += $length; return str_repeat('x', $length); });
        $client = new AlipayClient($this->http([new Response(200, [], $stream)]));
        $this->assertUnavailable(function () use ($client) { $client->checkout('scan', $this->credentials(), $this->order()); });
        $this->assertSame(65537, $read);
        $this->assertTrue($stream->eof());
        $stream = Utils::streamFor('fixture-private');
        $client = new AlipayClient($this->http([new Response(500, [], $stream)]));
        $this->assertUnavailable(function () use ($client) { $client->checkout('scan', $this->credentials(), $this->order()); });
        $this->assertFalse($stream->isReadable());
    }

    public function testHttpAcceptsAnySuccess2xxWithinExactly64KiB(): void
    {
        $http = new AlipayHttpClient($this->http([new Response(201, [], str_repeat('x', 65536)), new Response(204)]));
        $this->assertSame(65536, strlen((string) $http->request('POST', self::URL)->getBody()));
        $this->assertSame('', (string) $http->sendRequest(new Request('POST', 'https://openapi.alipay.com/gateway.do'))->getBody());
        $this->assertCount(2, $this->history);
    }

    private function temporaryFile(string $extension, string $contents): string
    {
        $path = sys_get_temp_dir() . '/dujiaoka-alipay-' . bin2hex(random_bytes(12)) . $extension;
        $this->assertSame(strlen($contents), file_put_contents($path, $contents));
        $this->temporary[] = $path;
        return $path;
    }

    private function bare(string $pem): string
    {
        return preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    }

    private function derValue(string $der, int &$offset): string
    {
        ++$offset;
        $length = ord($der[$offset++]);
        if ($length & 128) {
            $octets = $length & 127; $length = 0;
            for ($i = 0; $i < $octets; ++$i) $length = ($length << 8) | ord($der[$offset++]);
        }
        $value = substr($der, $offset, $length);
        $offset += $length;
        return $value;
    }

    private function pkcs1(): string
    {
        $offset = 0;
        $sequence = $this->derValue(base64_decode($this->bare(self::$merchants[0]['private'])), $offset);
        $offset = 0;
        $this->derValue($sequence, $offset);
        $this->derValue($sequence, $offset);
        return base64_encode($this->derValue($sequence, $offset));
    }

    public function testLegacyBarePkcs1Pkcs8PemAndExplicitFilesWork(): void
    {
        $credentials = $this->credentials();
        $bare = $credentials;
        $bare['private_key'] = $this->pkcs1();
        $bare['public_key'] = $this->bare($credentials['public_key']);
        $pkcs8 = $bare; $pkcs8['private_key'] = $this->bare($credentials['private_key']);
        $pkcs1Pem = $bare;
        $pkcs1Pem['private_key'] = "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split($bare['private_key'], 64, "\n") . '-----END RSA PRIVATE KEY-----';
        $files = $credentials;
        $files['private_key'] = $this->temporaryFile('.pem', $credentials['private_key']);
        $files['public_key'] = $this->temporaryFile('.pem', $credentials['public_key']);
        $fileUris = $files;
        $fileUris['private_key'] = 'file://' . $files['private_key'];
        $fileUris['public_key'] = 'file://' . $files['public_key'];
        $csr = openssl_csr_new(['commonName' => 'provider-fixture.invalid'], self::$providers[0]['private'], self::opensslConfig());
        $certificate = openssl_csr_sign($csr, null, self::$providers[0]['private'], 1, self::opensslConfig());
        $this->assertTrue(openssl_x509_export($certificate, $certificatePem));
        $certFiles = $files; $certFiles['public_key'] = $this->temporaryFile('.crt', $certificatePem);
        $certPem = $credentials; $certPem['public_key'] = $certificatePem;
        $client = new AlipayClient($this->http(array_map(function () { return $this->response(); }, range(1, 8))));
        foreach ([$credentials, $bare, $pkcs8, $pkcs1Pem, $files, $fileUris, $certFiles, $certPem] as $index => $config) {
            $this->assertSame('10000', $client->checkout('scan', $config, $this->order())['code']);
            $this->assertPayload($this->requestPayload($index), 'scan');
            $params = $this->callbackParams();
            $this->assertSame($params, $client->verify($params, $config));
        }
        $this->assertCount(8, $this->history);
    }

    public function testInvalidKeysAndCredentialsFailBeforeIoWithoutLeaking(): void
    {
        $client = new AlipayClient($this->http());
        $configs = [];
        foreach (['public_key', 'private_key'] as $field) {
            foreach (['fixture-private', '', [], 'https://evil.invalid/fixture-private.pem',
                'php://filter/fixture-private.pem', str_repeat('x', 65537),
                $this->temporaryFile('.pem', 'fixture-private')] as $value) {
                $config = $this->credentials(); $config[$field] = $value; $configs[] = $config;
            }
        }
        $config = $this->credentials(); $config['app_id'] = []; $configs[] = $config;
        $config = $this->credentials(); $config['notify_url'] = "fixture-private\r\n"; $configs[] = $config;
        $config = $this->credentials(); $config['mode'] = 'sandbox'; $configs[] = $config;
        foreach ($configs as $credentials) {
            $this->assertUnavailable(function () use ($client, $credentials) { $client->checkout('scan', $credentials, $this->order()); });
        }
        $this->assertCount(0, $this->history);
    }
}
