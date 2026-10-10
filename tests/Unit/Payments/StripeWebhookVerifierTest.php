<?php

namespace Tests\Unit\Payments;

use App\Payments\StripeWebhookVerifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StripeWebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_fixturePrivateKey';
    private const PAYLOAD = '{"object":"event","id":"evt_fixture","type":"payment_intent.succeeded","livemode":false,"data":{"object":{"object":"payment_intent","id":"pi_fixture","amount":999,"metadata":{"private":"fixture-private-payload"},"client_secret":"fixture-private-secret"}}}';

    private function header(string $payload, ?int $timestamp = null, string $secret = self::SECRET): string
    {
        $timestamp = $timestamp ?? time();
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    private function verify(string $payload): array
    {
        return (new StripeWebhookVerifier())->verify($payload, $this->header($payload), self::SECRET);
    }

    private function assertRejected(string $payload, string $header, string $secret = self::SECRET): void
    {
        try {
            (new StripeWebhookVerifier())->verify($payload, $header, $secret);
            $this->fail('Expected webhook verification failure');
        } catch (RuntimeException $exception) {
            $this->assertSame(RuntimeException::class, get_class($exception));
            $this->assertSame('Webhook verification failed.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('fixture-private', (string) $exception);
        }
    }

    public function testAuthenticatesRawJsonAndReturnsOnlyRoutingFields(): void
    {
        $this->assertSame([
            'id' => 'evt_fixture', 'type' => 'payment_intent.succeeded',
            'intent_id' => 'pi_fixture', 'livemode' => false, 'account' => null,
        ], $this->verify(self::PAYLOAD));
        $pretty = json_encode(json_decode(self::PAYLOAD), JSON_PRETTY_PRINT);
        $this->assertSame('pi_fixture', $this->verify($pretty)['intent_id']);
        $this->assertRejected($pretty, $this->header(self::PAYLOAD));
        $this->assertRejected(str_replace('999', '998', self::PAYLOAD), $this->header(self::PAYLOAD));
        $this->assertRejected(self::PAYLOAD, $this->header(self::PAYLOAD), 'whsec_wrong');
    }

    public function testRotationAcceptsAnyMatchingV1Signature(): void
    {
        $valid = $this->header(self::PAYLOAD);
        $bad = str_repeat('0', 64);
        foreach ([$valid . ',v1=' . $bad, str_replace(',v1=', ',v1=' . $bad . ',v1=', $valid)] as $header) {
            $this->assertSame('evt_fixture', (new StripeWebhookVerifier())->verify(self::PAYLOAD, $header, self::SECRET)['id']);
        }
    }

    public function testRealClockEnforcesPastAndFutureTolerance(): void
    {
        foreach ([-299, 299] as $offset) {
            $this->assertSame('evt_fixture', (new StripeWebhookVerifier())->verify(self::PAYLOAD, $this->header(self::PAYLOAD, time() + $offset), self::SECRET)['id']);
        }
        foreach ([-301, 600] as $offset) {
            $this->assertRejected(self::PAYLOAD, $this->header(self::PAYLOAD, time() + $offset));
        }
    }

    public function testMalformedHeadersJsonAndSecretsHaveUniformErrors(): void
    {
        foreach (['', 't', 'v1', 't=abc,v1=abc', 't=1', 'v1=abc', 't=1,v1=', 't=1,v1=abc,broken'] as $header) {
            $this->assertRejected(self::PAYLOAD, $header);
        }
        foreach (['', '{', 'null', '[]', '1', '"fixture-private-payload"'] as $payload) {
            $this->assertRejected($payload, $this->header($payload));
        }
        foreach (['', 'whsec_', 'sk_test_fixture', 'whsec_has space', "whsec_bad\n", 'whsec_' . str_repeat('a', 250)] as $secret) {
            $this->assertRejected(self::PAYLOAD, $this->header(self::PAYLOAD, null, $secret), $secret);
        }
    }

    public function testRejectsInvalidEventStructureAndThinEvents(): void
    {
        $base = json_decode(self::PAYLOAD, true);
        $cases = [];
        foreach (['object' => ['v2.core.event', 'payment_intent', null], 'id' => ['', 'evt_', 'evt_bad-id', 'evt_' . str_repeat('a', 253), 123], 'type' => ['', 'payment_intent.', 'payment_intent bad', "charge.succeeded\n", str_repeat('a', 129), 12], 'livemode' => [0, 1, 'false', null]] as $field => $values) {
            foreach ($values as $value) {
                $event = $base;
                $event[$field] = $value;
                $cases[] = $event;
            }
            $event = $base;
            unset($event[$field]);
            $cases[] = $event;
        }
        foreach ([null, [], 'bad', ['object' => []], ['object' => ['object' => 'charge', 'id' => 'pi_fixture']], ['object' => ['object' => 'payment_intent', 'id' => 'pi_']], ['object' => ['object' => 'payment_intent', 'id' => 42]], ['object' => ['object' => 'payment_intent', 'id' => 'pi_bad-id']]] as $data) {
            $event = $base;
            $event['data'] = $data;
            $cases[] = $event;
        }
        $event = $base;
        unset($event['data']);
        $cases[] = $event;
        foreach ($cases as $event) {
            $payload = json_encode($event);
            $this->assertRejected($payload, $this->header($payload));
        }
    }

    public function testNonPaymentIntentEventsDoNotExtractIntent(): void
    {
        $event = json_decode(self::PAYLOAD, true);
        $event['type'] = 'charge.succeeded';
        $this->assertNull($this->verify(json_encode($event))['intent_id']);
        unset($event['data']);
        $event['livemode'] = true;
        $result = $this->verify(json_encode($event));
        $this->assertNull($result['intent_id']);
        $this->assertTrue($result['livemode']);
    }

    public function testConnectedAccountMustBeCanonicalWhenPresent(): void
    {
        $event = json_decode(self::PAYLOAD, true);
        $event['account'] = 'acct_fixture_123';
        $this->assertSame('acct_fixture_123', $this->verify(json_encode($event))['account']);
        foreach ([null, false, 1, [], '', 'acct_', 'acct_bad-id', "acct_bad\n", 'acct_' . str_repeat('a', 251)] as $account) {
            $event['account'] = $account;
            $payload = json_encode($event);
            $this->assertRejected($payload, $this->header($payload));
        }
    }

    public function testExactSizeLimitsAreInclusive(): void
    {
        $payload = self::PAYLOAD . str_repeat(' ', 262144 - strlen(self::PAYLOAD));
        $this->assertSame('evt_fixture', $this->verify($payload)['id']);
        $this->assertRejected($payload . ' ', $this->header($payload . ' '));
        $header = $this->header(self::PAYLOAD);
        $header .= ',v0=' . str_repeat('a', 4096 - strlen($header) - 4);
        $this->assertSame('evt_fixture', (new StripeWebhookVerifier())->verify(self::PAYLOAD, $header, self::SECRET)['id']);
        $this->assertRejected(self::PAYLOAD, $header . 'a');
        $secret = 'whsec_' . str_repeat('a', 249);
        $this->assertSame('evt_fixture', (new StripeWebhookVerifier())->verify(self::PAYLOAD, $this->header(self::PAYLOAD, null, $secret), $secret)['id']);
        $event = json_decode(self::PAYLOAD, true);
        $event['id'] = 'evt_' . str_repeat('a', 252);
        $event['type'] = str_repeat('a', 128);
        $event['account'] = 'acct_' . str_repeat('a', 250);
        $this->assertSame($event['id'], $this->verify(json_encode($event))['id']);
    }
}
