<?php

namespace Tests\Unit\Payments;

use App\Payments\WechatV2Client;
use DOMDocument;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WechatV2ClientTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';
    private $history = [];
    private $mock;

    private static function credentials(): array
    {
        return ['app_id' => 'wxfixture123', 'mch_id' => '1900000109', 'key' => self::KEY];
    }

    private static function order(): array
    {
        return ['out_trade_no' => 'fixture_order_123', 'body' => 'Fish & chips <tag> ]]> "quote"',
            'total_fee' => '10', 'notify_url' => 'https://shop.invalid/pay/notify?a=1&b=2',
            'spbill_create_ip' => '192.0.2.10', 'fee_type' => 'CNY'];
    }

    private static function gatewayResponse(): array
    {
        return ['return_code' => 'SUCCESS', 'result_code' => 'SUCCESS', 'appid' => 'wxfixture123',
            'mch_id' => '1900000109', 'nonce_str' => 'fixtureNonce', 'trade_type' => 'NATIVE',
            'code_url' => 'weixin://wxpay/bizpayurl?pr=fixture_123',
            'out_trade_no' => 'fixture_order_123', 'total_fee' => '10'];
    }

    private static function wire(array $data): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->appendChild($document->createElement('xml'));
        foreach ($data as $name => $value) {
            $element = $root->appendChild($document->createElement($name));
            $element->appendChild($document->createTextNode((string) $value));
        }
        return $document->saveXML();
    }

    private static function oracleSigned(array $data, string $type = 'MD5'): array
    {
        // A fixed protocol field order is independent of the client's sorting implementation.
        $order = ['appid', 'body', 'code_url', 'err_code', 'fee_type', 'mch_id', 'nonce_str',
            'out_trade_no', 'result_code', 'return_code', 'return_msg', 'sign_type', 'total_fee',
            'trade_type', 'transaction_id'];
        self::assertSame([], array_values(array_diff(array_keys($data), $order)));
        $parts = [];
        foreach ($order as $name) {
            if (isset($data[$name]) && (string) $data[$name] !== '') $parts[] = $name . '=' . $data[$name];
        }
        $canonical = implode('&', $parts) . '&key=' . self::KEY;
        $data['sign'] = strtoupper($type === 'MD5' ? md5($canonical) : hash_hmac('sha256', $canonical, self::KEY));
        return $data;
    }

    private function client(array $responses, array $defaults = []): WechatV2Client
    {
        $this->history = [];
        $this->mock = new MockHandler($responses);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        return new WechatV2Client(new Client(['handler' => $stack] + $defaults));
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

    public function testCreateUsesFixedHostCanonicalBodyAndOverridesExternalClientDefaults(): void
    {
        $expected = self::oracleSigned(self::gatewayResponse());
        $callback = function () { $this->fail('Inherited HTTP callback must be cleared'); };
        $client = $this->client([new Response(200, [], self::wire($expected))], [
            'base_uri' => 'http://fixture.invalid/wrong', 'verify' => false, 'allow_redirects' => true,
            'http_errors' => true, 'connect_timeout' => 99, 'timeout' => 99, 'read_timeout' => 99,
            'auth' => ['fixture-user', 'fixture-password'],
            'headers' => ['Host' => 'fixture.invalid', 'Authorization' => 'fixture-private',
                'Cookie' => 'fixture-cookie', 'Content-Type' => 'application/json', 'X-Default' => 'secret'],
            'query' => ['injected' => '1'], 'body' => 'fixture-default-body', 'json' => ['injected' => true],
            'form_params' => ['injected' => '1'], 'multipart' => [['name' => 'injected', 'contents' => '1']],
            'cookies' => true, 'proxy' => 'http://proxy.invalid:8080', 'cert' => 'fixture-cert',
            'ssl_key' => 'fixture-key', 'curl' => [CURLOPT_SSL_VERIFYPEER => false, CURLOPT_URL => 'http://fixture.invalid'],
            'stream_context' => ['ssl' => ['verify_peer' => false]], 'debug' => true, 'sink' => 'php://temp',
            'on_headers' => $callback, 'on_stats' => $callback, 'progress' => $callback, 'delay' => 1000,
            'decode_content' => true, 'expect' => true, 'stream' => false, 'version' => '1.0',
            'idn_conversion' => true, 'force_ip_resolve' => 'v4', 'protocols' => ['http'],
        ]);
        $this->assertSame($expected, $client->create(self::credentials(), self::order()));
        $this->assertCount(1, $this->history);
        $this->assertCount(0, $this->mock);
        $entry = $this->history[0];
        $request = $entry['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.mch.weixin.qq.com/pay/unifiedorder', (string) $request->getUri());
        $this->assertSame('api.mch.weixin.qq.com', $request->getHeaderLine('Host'));
        $this->assertSame('application/xml; charset=utf-8', $request->getHeaderLine('Content-Type'));
        $this->assertSame('application/xml', $request->getHeaderLine('Accept'));
        $this->assertSame('1.1', $request->getProtocolVersion());
        foreach (['Authorization', 'Cookie', 'X-Default'] as $name) $this->assertFalse($request->hasHeader($name));
        foreach (['verify' => true, 'allow_redirects' => false, 'http_errors' => false,
            'connect_timeout' => 3, 'timeout' => 10, 'read_timeout' => 10, 'cookies' => false,
            'proxy' => '', 'curl' => [], 'stream_context' => [], 'debug' => false, 'delay' => 0,
            'decode_content' => false, 'expect' => false, 'stream' => true, 'protocols' => ['https']] as $name => $value) {
            $this->assertSame($value, $entry['options'][$name], $name);
        }
        foreach (['auth', 'json', 'form_params', 'multipart', 'query', 'body', 'cert', 'ssl_key',
            'sink', 'on_headers', 'on_stats', 'progress', 'force_ip_resolve'] as $name) {
            $this->assertArrayNotHasKey($name, $entry['options']);
        }
        $raw = (string) $request->getBody();
        $this->assertStringNotContainsString('<![CDATA[', $raw);
        $this->assertStringNotContainsString(self::KEY, $raw);
        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($raw));
        $fields = [];
        foreach ($document->documentElement->childNodes as $field) $fields[$field->nodeName] = $field->nodeValue;
        $nonce = $fields['nonce_str'];
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $nonce);
        $this->assertSame(self::order()['body'], $fields['body']);
        $this->assertSame(self::order()['notify_url'], $fields['notify_url']);
        $canonical = 'appid=wxfixture123&body=Fish & chips <tag> ]]> "quote"&fee_type=CNY'
            . '&mch_id=1900000109&nonce_str=' . $nonce
            . '&notify_url=https://shop.invalid/pay/notify?a=1&b=2&out_trade_no=fixture_order_123'
            . '&sign_type=MD5&spbill_create_ip=192.0.2.10&total_fee=10&trade_type=NATIVE&key=' . self::KEY;
        $this->assertSame(strtoupper(md5($canonical)), $fields['sign']);
        $this->assertCount(12, $fields);
    }

    public function testOptionalEchoesAndCurrencyFreshNonceAndIntegerBoundaries(): void
    {
        $result = self::gatewayResponse();
        unset($result['out_trade_no'], $result['total_fee']);
        $signed = self::oracleSigned($result);
        $client = $this->client([new Response(200, [], self::wire($signed)), new Response(200, [], self::wire($signed))]);
        $order = self::order();
        unset($order['fee_type']);
        $order['total_fee'] = '2147483647';
        $order['body'] = '0';
        $order['out_trade_no'] = '0';
        $order['notify_url'] = 'http://shop.invalid/notify';
        $order['spbill_create_ip'] = '2001:db8::1';
        $this->assertSame($signed, $client->create(self::credentials(), $order));
        $order['total_fee'] = '1';
        $this->assertSame($signed, $client->create(self::credentials(), $order));
        $first = WechatV2Client::parseXml((string) $this->history[0]['request']->getBody());
        $second = WechatV2Client::parseXml((string) $this->history[1]['request']->getBody());
        $this->assertSame('2147483647', $first['total_fee']);
        $this->assertSame('1', $second['total_fee']);
        $this->assertArrayNotHasKey('fee_type', $first);
        $this->assertNotSame($first['nonce_str'], $second['nonce_str']);
    }

    public function testPublicSignerMatchesExplicitCanonicalOracleAndPreservesZeroWithoutUrlEncoding(): void
    {
        $data = ['z' => 'last', 'zero' => 0, 'sign' => 'ignored', 'empty' => '',
            'body' => 'A&B=<tag> ]]> + space', 'A' => 'first', 'zero_string' => '0'];
        $canonical = 'A=first&body=A&B=<tag> ]]> + space&z=last&zero=0&zero_string=0&key=' . self::KEY;
        $client = new WechatV2Client();
        $this->assertSame(strtoupper(md5($canonical)), $client->sign($data, self::KEY));
        $this->assertSame(strtoupper(hash_hmac('sha256', $canonical, self::KEY)), $client->sign($data, self::KEY, 'HMAC-SHA256'));
        $this->assertFailure(function () use ($client, $data) { $client->sign($data, self::KEY, 'SHA256'); });
        $this->assertFailure(function () use ($client) { $client->sign(['body' => []], self::KEY); });
        $this->assertFailure(function () use ($client, $data) { $client->sign($data, 'short'); });
    }

    /** @dataProvider signatureModes */
    #[\PHPUnit\Framework\Attributes\DataProvider('signatureModes')]
    public function testVerifyOnlyChecksSignatureAndMerchantIdentity(string $type, bool $explicit): void
    {
        $data = self::gatewayResponse();
        $data['return_code'] = 'FAIL';
        $data['result_code'] = 'FAIL';
        $data['total_fee'] = '0';
        $data['fee_type'] = 'USD';
        $data['out_trade_no'] = 'another_order';
        if ($explicit) $data['sign_type'] = $type;
        $signed = self::oracleSigned($data, $type);
        $signed['sign'] = strtolower($signed['sign']);
        $client = $this->client([]);
        $this->assertSame($signed, $client->verify(self::wire($signed), self::credentials()));
        $this->assertCount(0, $this->history);
    }

    public static function signatureModes(): array
    {
        return [['MD5', false], ['MD5', true], ['HMAC-SHA256', true]];
    }

    /** @dataProvider invalidVerification */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidVerification')]
    public function testVerifyRejectsTamperingMalformedSignaturesAndAccountMismatch(array $data, array $credentials): void
    {
        $client = $this->client([]);
        $this->assertFailure(function () use ($client, $data, $credentials) { $client->verify(self::wire($data), $credentials); });
        $this->assertCount(0, $this->history);
    }

    public static function invalidVerification(): array
    {
        $signed = self::oracleSigned(self::gatewayResponse());
        $cases = [];
        foreach (['', str_repeat('G', 32), str_repeat('A', 31), str_repeat('A', 33), str_repeat('A', 64),
            str_repeat('A', 32) . "\n", '0e123456789012345678901234567890'] as $signature) {
            $cases[] = [array_replace($signed, ['sign' => $signature]), self::credentials()];
        }
        $missing = $signed;
        unset($missing['sign']);
        $cases[] = [$missing, self::credentials()];
        $cases[] = [array_replace($signed, ['total_fee' => '11']), self::credentials()];
        $cases[] = [$signed, array_replace(self::credentials(), ['key' => str_repeat('f', 32)])];
        foreach (['appid' => 'wxother', 'mch_id' => '1900000110'] as $name => $value) {
            $data = array_replace(self::gatewayResponse(), [$name => $value]);
            $cases[] = [self::oracleSigned($data), self::credentials()];
            unset($data[$name]);
            $cases[] = [self::oracleSigned($data), self::credentials()];
        }
        foreach (['SHA256', 'md5', '', 'HMAC-SHA1'] as $type) {
            $cases[] = [self::oracleSigned(array_replace(self::gatewayResponse(), ['sign_type' => $type])), self::credentials()];
        }
        $hmac = self::oracleSigned(array_replace(self::gatewayResponse(), ['sign_type' => 'HMAC-SHA256']), 'HMAC-SHA256');
        foreach ([str_repeat('A', 32), str_repeat('A', 63), str_repeat('A', 65), str_repeat('G', 64)] as $signature) {
            $cases[] = [array_replace($hmac, ['sign' => $signature]), self::credentials()];
        }
        unset($hmac['sign_type']);
        $cases[] = [$hmac, self::credentials()];
        return $cases;
    }

    /** @dataProvider invalidResults */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidResults')]
    public function testCreateRejectsEvenValidlySignedInvalidResults(array $result): void
    {
        $client = $this->client([new Response(200, [], self::wire(self::oracleSigned($result))), new Response(200)]);
        $this->assertFailure(function () use ($client) { $client->create(self::credentials(), self::order()); });
        $this->assertCount(1, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function invalidResults(): array
    {
        $cases = [];
        foreach (['return_code', 'result_code', 'appid', 'mch_id', 'trade_type', 'code_url'] as $name) {
            $data = self::gatewayResponse();
            unset($data[$name]);
            $cases[] = [$data];
        }
        foreach (['return_code' => 'FAIL', 'result_code' => 'FAIL', 'appid' => 'wxother',
            'mch_id' => 'other', 'trade_type' => 'APP', 'out_trade_no' => 'other', 'total_fee' => '11'] as $name => $value) {
            $cases[] = [array_replace(self::gatewayResponse(), [$name => $value])];
        }
        $cases[] = [array_replace(self::gatewayResponse(), ['total_fee' => '010'])];
        foreach (['https://fixture.invalid/', 'weixin://evil/bizpayurl?pr=x',
            'weixin://user@wxpay/bizpayurl?pr=x', 'weixin://wxpay:443/bizpayurl?pr=x',
            'weixin://wxpay/other?pr=x', 'weixin://wxpay/bizpayurl?pr=',
            'weixin://wxpay/bizpayurl?pr=x&other=y', 'weixin://wxpay/bizpayurl?pr=x#fragment',
            'weixin://wxpay/bizpayurl?pr=%0A', "weixin://wxpay/bizpayurl?pr=x\n",
            'weixin://wxpay/bizpayurl?pr=' . str_repeat('x', 257)] as $url) {
            $cases[] = [array_replace(self::gatewayResponse(), ['code_url' => $url])];
        }
        return $cases;
    }

    /** @dataProvider transportFailures */
    #[\PHPUnit\Framework\Attributes\DataProvider('transportFailures')]
    public function testProviderFailuresAreRedactedAndNeverRetried($response): void
    {
        $client = $this->client([$response, new Response(200)]);
        $this->assertFailure(function () use ($client) { $client->create(self::credentials(), self::order()); });
        $this->assertCount(1, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function transportFailures(): array
    {
        $cases = [];
        foreach ([201, 302, 400, 429, 500] as $status) {
            $cases[] = [new Response($status, ['Location' => 'https://fixture.invalid/'], 'fixture-private-provider-message')];
        }
        foreach (['', '<xml>fixture-private-malformed', '<xml><return_code>FAIL</return_code></xml>',
            str_repeat('x', 65537), self::wire(array_replace(self::gatewayResponse(), ['sign' => str_repeat('F', 32)]))] as $body) {
            $cases[] = [new Response(200, [], $body)];
        }
        $cases[] = [new ConnectException('fixture-private ' . self::KEY . ' <xml>secret</xml>',
            new Request('POST', 'https://fixture.invalid/'))];
        return $cases;
    }

    /** @dataProvider invalidInputs */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInputs')]
    public function testInvalidInputsFailBeforeAnyHttpRequest(array $credentials, array $order): void
    {
        $client = $this->client([new Response(200)]);
        $this->assertFailure(function () use ($client, $credentials, $order) { $client->create($credentials, $order); });
        $this->assertCount(0, $this->history);
        $this->assertCount(1, $this->mock);
    }

    public static function invalidInputs(): array
    {
        $cases = [];
        foreach (['0', '-1', '+1', '01', '1.0', '1e2', ' 1', "1\n", '2147483648', '99999999999999999999', 1, 1.1] as $fee) {
            $cases[] = [self::credentials(), array_replace(self::order(), ['total_fee' => $fee])];
        }
        foreach (['ftp://shop.invalid/notify', 'https://user:pass@shop.invalid/notify',
            'https://@shop.invalid/notify', 'https://shop.invalid/notify#fragment', 'not a url',
            "https://shop.invalid/notify\n", 'https://shop.invalid/a%XX', 'https://shop.invalid/a\\b'] as $url) {
            $cases[] = [self::credentials(), array_replace(self::order(), ['notify_url' => $url])];
        }
        foreach (['spbill_create_ip' => 'invalid', 'body' => '', 'out_trade_no' => '', 'fee_type' => 'USD',
            'handler' => 'fixture', 'notify_url' => str_repeat('x', 257)] as $name => $value) {
            $cases[] = [self::credentials(), array_replace(self::order(), [$name => $value])];
        }
        $cases[] = [self::credentials(), array_replace(self::order(), ['body' => "bad\0text"])];
        $cases[] = [self::credentials(), array_replace(self::order(), ['body' => str_repeat('x', 129)])];
        foreach (['app_id' => '', 'mch_id' => '', 'key' => 'short', 'url' => 'https://fixture.invalid/',
            'base_uri' => 'https://fixture.invalid/', 'handler' => 'fixture', 'http' => ['verify' => false]] as $name => $value) {
            $cases[] = [array_replace(self::credentials(), [$name => $value]), self::order()];
        }
        $cases[] = [array_replace(self::credentials(), ['key' => str_repeat("\xC3\xA9", 16)]), self::order()];
        $cases[] = [array_replace(self::credentials(), ['key' => str_repeat('a', 31) . "\0"]), self::order()];
        $cases[] = [array_replace(self::credentials(), ['app_id' => 123]), self::order()];
        $missing = self::order();
        unset($missing['notify_url']);
        $cases[] = [self::credentials(), $missing];
        return $cases;
    }

    public function testXmlTextEscapingCdataAndExactSizeBoundary(): void
    {
        $data = ['body' => 'Fish & <chips> ]]> "quote"', 'zero' => 0, 'empty' => '', 'line' => "a\rb\nc\td"];
        $xml = WechatV2Client::toXml($data);
        $this->assertStringContainsString('&amp;', $xml);
        $this->assertStringContainsString('&lt;chips&gt;', $xml);
        $this->assertStringNotContainsString('<![CDATA[', $xml);
        $this->assertSame(array_map('strval', $data), WechatV2Client::parseXml($xml));
        $this->assertSame(['body' => 'A&B ]]> tail', 'zero' => '0', 'empty' => ''],
            WechatV2Client::parseXml('<?xml version="1.0"?><xml>' . "\n" . '<body><![CDATA[A&B ]]]]><![CDATA[> tail]]></body><zero>0</zero><empty/></xml>'));
        $length = 65536 - strlen('<xml><body></body></xml>');
        $max = WechatV2Client::toXml(['body' => str_repeat('x', $length)]);
        $this->assertSame(65536, strlen($max));
        $this->assertSame(str_repeat('x', $length), WechatV2Client::parseXml($max)['body']);
        $this->assertFailure(function () use ($length) { WechatV2Client::toXml(['body' => str_repeat('x', $length + 1)]); });
        $this->assertFailure(function () use ($max) { WechatV2Client::parseXml($max . ' '); });
        $name = str_repeat('x', 64);
        $this->assertSame([$name => '1'], WechatV2Client::parseXml(WechatV2Client::toXml([$name => 1])));
        $this->assertFailure(function () { WechatV2Client::toXml(['body' => str_repeat('&', 20000)]); });
    }

    /** @dataProvider xmlAttacks */
    #[\PHPUnit\Framework\Attributes\DataProvider('xmlAttacks')]
    public function testXmlParserRejectsNonFlatAmbiguousAndExternalEntityDocuments(string $xml): void
    {
        $this->assertFailure(function () use ($xml) { WechatV2Client::parseXml($xml); });
    }

    public static function xmlAttacks(): array
    {
        return array_map(function ($xml) { return [$xml]; }, [
            '', '<xml/>', '<other><a>1</a></other>', '<xml><a>secret',
            '<xml><a>1</a></xml>secret', 'secret<xml><a>1</a></xml>',
            '<xml>secret<a>1</a></xml>', '<xml><a>1</a>secret</xml>', '<xml><![CDATA[secret]]><a>1</a></xml>',
            '<xml attr="x"><a>1</a></xml>', '<xml><a attr="x">1</a></xml>',
            '<xml xmlns="urn:fixture"><a>1</a></xml>', '<xml xmlns=""><a>1</a></xml>',
            '<xml><a xmlns="urn:fixture">1</a></xml>', '<xml><a xmlns:x="urn:fixture">1</a></xml>',
            '<xml xmlns:xml="http://www.w3.org/XML/1998/namespace"><a>1</a></xml>',
            '<xml><a xmlns:xml="http://www.w3.org/XML/1998/namespace">1</a></xml>',
            '<x:xml xmlns:x="urn:fixture"><x:a>1</x:a></x:xml>', '<xml><a><nested>1</nested></a></xml>',
            '<xml><a>1</a><a>2</a></xml>', '<xml><a/><a>2</a></xml>', '<xml><a>1</a><a/></xml>',
            '<xml><a>&unknown;</a></xml>', '<xml><a>1<!--comment-->2</a></xml>',
            '<xml><!--comment--><a>1</a></xml>', '<!--comment--><xml><a>1</a></xml>',
            '<?fixture secret?><xml><a>1</a></xml>', '<xml><a><?fixture secret?></a></xml>',
            '<!DOCTYPE xml SYSTEM "https://fixture.invalid/external"><xml><a>1</a></xml>',
            '<!DOCTYPE xml [<!ENTITY x SYSTEM "file:///fixture-private">]><xml><a>&x;</a></xml>',
            '<!DOCTYPE xml [<!ENTITY x "secret"><!ENTITY y "&x;&x;&x;">]><xml><a>&y;</a></xml>',
            '<!ENTITY x "secret"><xml><a>1</a></xml>',
            '<xml><' . str_repeat('a', 65) . '>1</' . str_repeat('a', 65) . '></xml>',
            "<xml><a>bad\0text</a></xml>", "<xml><a>\xFF</a></xml>", "<xml><a>\x01</a></xml>",
            "\xFF\xFE<\0x\0m\0l\0>\0", str_repeat('x', 65537),
        ]);
    }

    /** @dataProvider invalidXmlData */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidXmlData')]
    public function testSerializerRejectsNonScalarIllegalNamesAndCharacters(array $data): void
    {
        $this->assertFailure(function () use ($data) { WechatV2Client::toXml($data); });
    }

    public static function invalidXmlData(): array
    {
        return [[[]], [['body' => []]], [['body' => true]], [['body' => 1.1]], [['body' => null]],
            [['body' => new \stdClass()]], [[0 => 'x']], [['1field' => 'x']], [['a:b' => 'x']],
            [['a><other' => 'x']], [[str_repeat('a', 65) => 'x']], [['body' => "bad\0text"]],
            [['body' => "\xFF"]], [['body' => "\xEF\xBF\xBF"]]];
    }

    /** @dataProvider libxmlModes */
    #[\PHPUnit\Framework\Attributes\DataProvider('libxmlModes')]
    public function testParserDoesNotPersistLibxmlSettingsOrExposeDiagnostics(bool $mode): void
    {
        $previous = libxml_use_internal_errors($mode);
        $entityLoader = PHP_VERSION_ID < 80000 ? libxml_disable_entity_loader($mode) : null;
        $warnings = [];
        set_error_handler(function ($severity, $message) use (&$warnings) { $warnings[] = $message; return true; });
        ob_start();
        try {
            $this->assertFailure(function () { WechatV2Client::parseXml('<xml><secret>fixture-private'); });
            $this->assertSame(['a' => '1'], WechatV2Client::parseXml('<xml><a>1</a></xml>'));
            $this->assertSame($mode, libxml_use_internal_errors());
            if (PHP_VERSION_ID < 80000) $this->assertSame($mode, libxml_disable_entity_loader($mode));
            $this->assertSame([], $warnings);
            $this->assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
            restore_error_handler();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (PHP_VERSION_ID < 80000) libxml_disable_entity_loader($entityLoader);
        }
    }

    public static function libxmlModes(): array
    {
        return [[false], [true]];
    }

    public function testResponseReadStopsAtLimitAndClosesTheStream(): void
    {
        $readBytes = 0;
        $closed = false;
        $stream = Utils::streamFor(str_repeat('x', 70000));
        $bounded = new \GuzzleHttp\Psr7\FnStream([
            'read' => function ($length) use ($stream, &$readBytes) {
                $chunk = $stream->read($length);
                $readBytes += strlen($chunk);
                return $chunk;
            },
            'eof' => function () use ($stream) { return $stream->eof(); },
            'close' => function () use ($stream, &$closed) { $closed = true; $stream->close(); },
        ]);
        $client = $this->client([new Response(200, [], $bounded)]);
        $this->assertFailure(function () use ($client) { $client->create(self::credentials(), self::order()); });
        $this->assertSame(65537, $readBytes);
        $this->assertTrue($closed);
    }
}
