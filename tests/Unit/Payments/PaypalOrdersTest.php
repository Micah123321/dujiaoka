<?php

namespace Tests\Unit\Payments;

use App\Payments\PaypalOrdersClient;
use App\Payments\PaypalOrderReceipt;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PaypalOrdersTest extends TestCase
{
    public function testPendingCaptureIsOnlyWaitingAndStillNeedsExactBoundAmountAndFinalCapture(): void
    {
        $capture = ['id' => '3C679366HH908993F', 'status' => 'PENDING', 'final_capture' => true, 'amount' => ['currency_code' => 'USD', 'value' => '10.00']];
        $order = ['id' => '5O190127TN364715T', 'status' => 'COMPLETED', 'intent' => 'CAPTURE', 'purchase_units' => [[
            'reference_id' => 'reference', 'custom_id' => 'reference', 'invoice_id' => 'SN', 'payee' => ['merchant_id' => 'M123456789012'],
            'amount' => ['currency_code' => 'USD', 'value' => '10.00'], 'payments' => ['captures' => [$capture]],
        ]]];
        $args = ['5O190127TN364715T', 'SN', 'reference', '10.00', 'M123456789012'];
        $this->assertSame($capture['id'], \App\Payments\PaypalOrderReceipt::pendingCaptureId($order, ...$args));
        $this->assertNull(\App\Payments\PaypalOrderReceipt::captureId($order, ...$args));
        foreach (['status' => 'COMPLETED', 'final_capture' => false, 'amount' => ['currency_code' => 'USD', 'value' => '9.00'],
            'supplementary_data' => ['related_ids' => ['order_id' => '6O190127TN364715T']]] as $field => $value) {
            $changed = $order; $changed['purchase_units'][0]['payments']['captures'][0][$field] = $value;
            $this->assertNull(\App\Payments\PaypalOrderReceipt::pendingCaptureId($changed, ...$args));
        }
        $order['purchase_units'][0]['payments']['captures'][] = $capture;
        $this->assertNull(\App\Payments\PaypalOrderReceipt::pendingCaptureId($order, ...$args));
    }


    private $history = [];
    private function client(array $responses): PaypalOrdersClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new PaypalOrdersClient(new Client(['handler' => $stack]));
    }
    private function credentials(string $mode = 'sandbox'): array
    {
        return ['client_id' => 'fixtureClient', 'secret' => 'fixturePrivate', 'merchant_id' => 'M123456789012', 'mode' => $mode];
    }
    private function oauth(): Response { return new Response(200, [], '{"access_token":"fixtureBearer","token_type":"Bearer","expires_in":3600}'); }
    private function order(): array
    {
        return ['id' => '5O190127TN364715T', 'intent' => 'CAPTURE', 'status' => 'COMPLETED', 'purchase_units' => [[
            'reference_id' => str_repeat('a', 32), 'custom_id' => str_repeat('a', 32), 'invoice_id' => 'LOCALORDER',
            'payee' => ['merchant_id' => 'M123456789012'], 'amount' => ['currency_code' => 'USD', 'value' => '10.00'],
            'payments' => ['captures' => [$this->capture()]],
        ]], 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T']]];
    }
    private function capture(): array
    {
        return ['id' => '3C679366HH908993F', 'status' => 'COMPLETED', 'final_capture' => true,
            'amount' => ['currency_code' => 'USD', 'value' => '10.00'],
            'supplementary_data' => ['related_ids' => ['order_id' => '5O190127TN364715T']]];
    }
    private function orderMatches(array $order): bool
    {
        return PaypalOrderReceipt::matches($order, '5O190127TN364715T', 'LOCALORDER', str_repeat('a', 32), '10.00', 'M123456789012');
    }
    private function captureId(array $order): ?string
    {
        return PaypalOrderReceipt::captureId($order, '5O190127TN364715T', 'LOCALORDER', str_repeat('a', 32), '10.00', 'M123456789012');
    }

    public function testActualGuzzleEncodingUsesFixedEndpointsSingleOAuthAndSeparateStableKeys(): void
    {
        $params = ['intent' => 'CAPTURE', 'purchase_units' => [['amount' => ['currency_code' => 'USD', 'value' => '10.00']]]];
        $client = $this->client([$this->oauth(), new Response(201, [], json_encode($this->order())),
            new Response(200, [], json_encode($this->order())), new Response(201, [], json_encode($this->order())), new Response(200, [], json_encode($this->capture()))]);
        $c = $this->credentials(); $c['url'] = 'http://fixture-invalid.example';
        $client->create($c, $params, str_repeat('a', 32));
        $client->get($c, '5O190127TN364715T');
        $client->capture($c, '5O190127TN364715T', str_repeat('b', 32));
        $client->getCapture($c, '3C679366HH908993F');
        $this->assertCount(5, $this->history);
        $this->assertSame('grant_type=client_credentials', (string) $this->history[0]['request']->getBody());
        $this->assertStringStartsWith('Basic ', $this->history[0]['request']->getHeaderLine('Authorization'));
        $paths = ['/v1/oauth2/token', '/v2/checkout/orders', '/v2/checkout/orders/5O190127TN364715T',
            '/v2/checkout/orders/5O190127TN364715T/capture', '/v2/payments/captures/3C679366HH908993F'];
        foreach ($this->history as $i => $h) {
            $this->assertSame('https://api-m.sandbox.paypal.com' . $paths[$i], (string) $h['request']->getUri());
            $this->assertFalse($h['options']['allow_redirects']); $this->assertTrue($h['options']['verify']);
            $this->assertSame(3, $h['options']['connect_timeout']); $this->assertSame(10, $h['options']['timeout']);
            if ($i > 0) $this->assertSame('Bearer fixtureBearer', $h['request']->getHeaderLine('Authorization'));
        }
        $this->assertSame(str_repeat('a', 32), $this->history[1]['request']->getHeaderLine('PayPal-Request-Id'));
        $this->assertSame(str_repeat('b', 32), $this->history[3]['request']->getHeaderLine('PayPal-Request-Id'));
        $this->assertSame('{}', (string) $this->history[3]['request']->getBody());
        $this->assertSame($params, json_decode((string) $this->history[1]['request']->getBody(), true));
        $this->assertSame('return=representation', $this->history[3]['request']->getHeaderLine('Prefer'));
    }

    public function testLiveModeAndCredentialRotationDoNotReuseSandboxToken(): void
    {
        $client = $this->client([$this->oauth(), new Response(200, [], '{}'), $this->oauth(), new Response(200, [], '{}')]);
        $client->get($this->credentials(), '5O190127TN364715T');
        $client->get($this->credentials('live'), '5O190127TN364715T');
        $this->assertSame('api-m.paypal.com', $this->history[2]['request']->getUri()->getHost());
        $this->assertSame('/v1/oauth2/token', $this->history[2]['request']->getUri()->getPath());
    }

    public function testTransportFailureDoesNotAutoReplayCaptureAndRedactsBodyChain(): void
    {
        $client = $this->client([$this->oauth(), new Response(500, [], 'fixture-private-response')]);
        try { $client->capture($this->credentials(), '5O190127TN364715T', str_repeat('b', 32)); $this->fail('Expected failure'); }
        catch (RuntimeException $e) {
            $this->assertSame('Payment provider unavailable.', $e->getMessage()); $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('fixture-private-response', (string) $e);
        }
        $this->assertCount(2, $this->history);
    }

    public function testOrderBindingAndReceiverAreVerifiedBeforeCapture(): void
    {
        $base = $this->order(); $this->assertTrue($this->orderMatches($base));
        $cases = [['id', 'OTHER'], ['intent', 'AUTHORIZE'], ['status', 'INVALID']];
        foreach ($cases as [$key, $value]) { $order = $base; $order[$key] = $value; $this->assertFalse($this->orderMatches($order)); }
        foreach (['reference_id', 'custom_id', 'invoice_id'] as $key) {
            $order = $base; $order['purchase_units'][0][$key] = 'OTHER'; $this->assertFalse($this->orderMatches($order));
        }
        $order = $base; $order['purchase_units'][0]['payee']['merchant_id'] = 'OTHER'; $this->assertFalse($this->orderMatches($order));
        $order = $base; $order['purchase_units'][] = $order['purchase_units'][0]; $this->assertFalse($this->orderMatches($order));
        foreach (['9.99', '10.001', '1e1', 10.0, null] as $v) {
            $order = $base; $order['purchase_units'][0]['amount']['value'] = $v; $this->assertFalse($this->orderMatches($order));
        }
    }

    public function testCompletedOrderAloneIsNotProofOfSingleFinalCompletedCapture(): void
    {
        $base = $this->order(); $this->assertSame('3C679366HH908993F', $this->captureId($base));
        foreach (['PENDING', 'DECLINED', 'REFUNDED', 'PARTIALLY_REFUNDED'] as $status) {
            $order = $base; $order['purchase_units'][0]['payments']['captures'][0]['status'] = $status;
            $this->assertNull($this->captureId($order));
        }
        $order = $base; $order['purchase_units'][0]['payments']['captures'][0]['final_capture'] = false; $this->assertNull($this->captureId($order));
        $order = $base; $order['purchase_units'][0]['payments']['captures'][] = $this->capture(); $this->assertNull($this->captureId($order));
        $capture = $this->capture(); $capture['supplementary_data']['related_ids']['order_id'] = 'OTHER';
        $this->assertFalse(PaypalOrderReceipt::captureMatches($capture, '3C679366HH908993F', '5O190127TN364715T', '10.00'));
        $capture = $this->capture(); $capture['amount']['value'] = '9.99';
        $this->assertFalse(PaypalOrderReceipt::captureMatches($capture, '3C679366HH908993F', '5O190127TN364715T', '10.00'));
    }

    public function testApprovalRedirectRequiresExactModeHostPathAndStoredToken(): void
    {
        $base = $this->order(); $this->assertNotNull(PaypalOrderReceipt::approvalUrl($base, 'sandbox'));
        foreach (['http://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T',
            'https://www.sandbox.paypal.com.evil.test/checkoutnow?token=5O190127TN364715T',
            'https://www.sandbox.paypal.com/checkoutnow?token=OTHER',
            'https://www.sandbox.paypal.com/checkoutnow?token=OTHER&token=5O190127TN364715T',
            'https://www.sandbox.paypal.com:443/checkoutnow?token=5O190127TN364715T',
            'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T#private'] as $url) {
            $order = $base; $order['links'][0]['href'] = $url; $this->assertNull(PaypalOrderReceipt::approvalUrl($order, 'sandbox'));
        }
        $this->assertNull(PaypalOrderReceipt::approvalUrl($base, 'live'));
    }
}
