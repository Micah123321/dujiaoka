<?php

namespace Tests\Unit\Payments;

use App\Payments\PaypalOrdersClient;
use App\Payments\PaypalWebhookVerifier;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PaypalWebhookVerifierTest extends TestCase
{
    private $history = [];
    private function headers(): array
    {
        return ['transmission_id' => 'db49fb10-1343-11ef-ac58-e32457403f67', 'transmission_time' => '2024-05-16T05:19:23Z',
            'transmission_sig' => base64_encode('fixture-signature'), 'cert_url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42-fca2a594-ab66f33d',
            'auth_algo' => 'SHA256withRSA'];
    }
    private function credentials(): array { return ['client_id' => 'fixtureClient', 'secret' => 'fixtureSecret', 'mode' => 'sandbox']; }
    private function verifier(array $results = []): PaypalWebhookVerifier
    {
        $oauth = new Response(200, [], '{"access_token":"fixtureBearer","token_type":"Bearer","expires_in":3600}');
        $stack = HandlerStack::create(new MockHandler(array_merge([$oauth], $results ?: [new Response(200, [], '{"verification_status":"SUCCESS"}')])));
        $stack->push(Middleware::history($this->history));
        return new PaypalWebhookVerifier(new PaypalOrdersClient(new Client(['handler' => $stack])));
    }
    private function payload(array $changes = []): string
    {
        return json_encode(array_replace_recursive(['id' => 'WH-FIXTURE-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => ['id' => '3C679366HH908993F', 'amount' => ['value' => '0.01'], 'payer' => ['email' => 'fixture@example.invalid'],
                'supplementary_data' => ['related_ids' => ['order_id' => '5O190127TN364715T']]]], $changes));
    }
    public function testOriginalBytesAreEmbeddedAtFixedVerificationEndpointAndOnlyRoutingFieldsEscape(): void
    {
        $raw = "{\n\"id\":\"WH-FIXTURE-1\",\"event_type\":\"CHECKOUT.ORDER.APPROVED\",\"resource\":{\"id\":\"5O190127TN364715T\",\"empty\":{},\"note\":\"\\u4e2d\\/\"}\n}";
        $result = $this->verifier()->verify($this->credentials(), $raw, '0NH55953DH663215D', $this->headers() + ['ignored' => 'fixture-private']);
        $this->assertSame(['id' => 'WH-FIXTURE-1', 'type' => 'CHECKOUT.ORDER.APPROVED', 'provider_id' => '5O190127TN364715T', 'resource_id' => '5O190127TN364715T'], $result);
        $this->assertCount(2, $this->history);
        $r = $this->history[1];
        $this->assertSame('https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature', (string) $r['request']->getUri());
        $body = (string) $r['request']->getBody(); $this->assertStringEndsWith(',"webhook_event":' . $raw . '}', $body);
        $this->assertStringNotContainsString('fixture-private', $body);
        $data = json_decode($body, true); $this->assertSame('0NH55953DH663215D', $data['webhook_id']);
        foreach ($this->headers() as $key => $value) $this->assertSame($value, $data[$key]);
        $this->assertSame('application/json', $r['request']->getHeaderLine('Content-Type'));
        $this->assertSame('', $r['request']->getHeaderLine('PayPal-Request-Id'));
        $this->assertFalse($r['options']['allow_redirects']); $this->assertTrue($r['options']['verify']);
        $this->assertSame(3, $r['options']['connect_timeout']); $this->assertSame(10, $r['options']['timeout']);
    }
    public function testCaptureWithAbsentRelatedOrderIsNormalizedForServerResolution(): void
    {
        $event = json_decode($this->payload(), true); unset($event['resource']['supplementary_data']);
        $result = $this->verifier()->verify($this->credentials(), json_encode($event), '0NH55953DH663215D', $this->headers());
        $this->assertNull($result['provider_id']); $this->assertSame('3C679366HH908993F', $result['resource_id']);
        $this->assertSame(['id', 'type', 'provider_id', 'resource_id'], array_keys($result));
    }
    public function testUnsupportedEventIsVerifiedButCarriesNoProviderCandidate(): void
    {
        $r = $this->verifier()->verify($this->credentials(), $this->payload(['event_type' => 'CUSTOMER.DISPUTE.CREATED']), '0NH55953DH663215D', $this->headers());
        $this->assertNull($r['provider_id']); $this->assertNull($r['resource_id']); $this->assertCount(2, $this->history);
    }
    public function testLocalMalformedRequestsCannotReachOAuthOrCertificateHost(): void
    {
        $verifier = $this->verifier(); $base = $this->headers();
        $cases = [];
        foreach (['http://api.sandbox.paypal.com/v1/notifications/certs/CERT-A', 'https://api.sandbox.paypal.com.evil.test/v1/notifications/certs/CERT-A',
            'https://api.paypal.com/v1/notifications/certs/CERT-A', 'https://api.sandbox.paypal.com:443/v1/notifications/certs/CERT-A',
            'https://api.sandbox.paypal.com/v1/notifications/certs/../CERT-A', 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-A?url=private'] as $url) {
            $h = $base; $h['cert_url'] = $url; $cases[] = $h;
        }
        foreach (['', 'HMAC', str_repeat('a', 4097)] as $algo) { $h = $base; $h['auth_algo'] = $algo; $cases[] = $h; }
        $h = $base; $h['transmission_sig'] = "invalid\r\nprivate"; $cases[] = $h;
        $h = $base; $h['transmission_time'] = 'invalid'; $cases[] = $h;
        foreach ($cases as $h) {
            try { $verifier->verify($this->credentials(), $this->payload(), '0NH55953DH663215D', $h); $this->fail('Expected malformed'); }
            catch (InvalidArgumentException $e) { $this->assertSame('Webhook verification failed.', $e->getMessage()); $this->assertNull($e->getPrevious()); }
        }
        foreach (['{', '[]', str_repeat('a', 262145), $this->payload(['id' => '']), $this->payload(['resource' => ['id' => 'OTHER']])] as $raw) {
            try { $verifier->verify($this->credentials(), $raw, '0NH55953DH663215D', $base); $this->fail('Expected malformed'); }
            catch (InvalidArgumentException $e) { $this->assertSame('Webhook verification failed.', $e->getMessage()); }
        }
        $this->assertCount(0, $this->history);
    }
    public function testSignatureFailureIsInvalidButVerifierOutageOrMalformedAckMustBeRetryable(): void
    {
        foreach ([new Response(200, [], '{"verification_status":"FAILURE"}'), new Response(500, [], 'fixture-private'),
            new Response(200, [], '{}'), new Response(200, [], '{"verification_status":true}')] as $i => $response) {
            $verifier = $this->verifier([$response]);
            try { $verifier->verify($this->credentials(), $this->payload(), '0NH55953DH663215D', $this->headers()); $this->fail('Expected verification failure'); }
            catch (\Exception $e) {
                $this->assertInstanceOf($i === 0 ? InvalidArgumentException::class : RuntimeException::class, $e);
                $this->assertNull($e->getPrevious()); $this->assertStringNotContainsString('fixture-private', (string) $e);
            }
        }
        $this->assertCount(8, $this->history);
    }
}
