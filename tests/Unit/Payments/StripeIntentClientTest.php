<?php

namespace Tests\Unit\Payments;

use App\Payments\StripeIntentClient;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;

class StripeIntentClientTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        \Mockery::close();
        parent::tearDown();
    }

    public function testDedicatedClientDoesNotInheritLegacyKeyBaseOrApiVersion(): void
    {
        $http = \Mockery::mock(\Stripe\HttpClient\ClientInterface::class);
        ApiRequestor::setHttpClient($http);
        $oldBase = \Stripe\Stripe::$apiBase;
        $oldVersion = \Stripe\Stripe::getApiVersion();
        \Stripe\Stripe::$apiBase = 'https://fixture.invalid';
        \Stripe\Stripe::setApiVersion('2019-01-01');
        $requests = [];
        try {
            $http->shouldReceive('request')->twice()->andReturnUsing(function ($method, $url, $headers, $params) use (&$requests) {
                $requests[] = compact('method', 'url', 'headers', 'params');
                return ['{"id":"pi_fixture","object":"payment_intent"}', 200, []];
            });
            $client = new StripeIntentClient();
            $this->assertSame('pi_fixture', $client->create('sk_test_fixture', ['amount' => '1000', 'currency' => 'usd', 'confirm' => false], 'fixture-idempotency')['id']);
            $this->assertSame('pi_fixture', $client->retrieve('sk_test_fixture', 'pi_fixture')['id']);
            foreach ($requests as $request) {
                $this->assertStringStartsWith('https://api.stripe.com/v1/payment_intents', $request['url']);
                $this->assertContains('Authorization: Bearer sk_test_fixture', $request['headers']);
                $this->assertContains('Stripe-Version: ' . \Stripe\Util\ApiVersion::CURRENT, $request['headers']);
            }
            $this->assertContains('Idempotency-Key: fixture-idempotency', $requests[0]['headers']);
            $this->assertSame('false', $requests[0]['params']['confirm']);
            $this->assertSame(['latest_charge'], $requests[1]['params']['expand']);
        } finally {
            \Stripe\Stripe::$apiBase = $oldBase;
            \Stripe\Stripe::setApiVersion($oldVersion);
        }
    }

    public function testProviderBodyAndChainedExceptionNeverEscape(): void
    {
        $http = \Mockery::mock(\Stripe\HttpClient\ClientInterface::class);
        ApiRequestor::setHttpClient($http);
        $http->shouldReceive('request')->once()->andThrow(new \RuntimeException('fixture-private-api-key-and-body'));
        try {
            (new StripeIntentClient())->retrieve('sk_test_fixture', 'pi_fixture');
            $this->fail('Expected provider failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Payment provider unavailable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }
}
