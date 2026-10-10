<?php

namespace App\Payments;

use RuntimeException;
use Stripe\Webhook;

final class StripeWebhookVerifier
{
    public function verify(string $payload, string $signature, string $secret): array
    {
        try {
            if (strlen($payload) > 262144 || strlen($signature) > 4096
                || !preg_match('/\Awhsec_[A-Za-z0-9_]{1,249}\z/', $secret)) {
                throw new RuntimeException();
            }

            // Reject broken components before the SDK indexes their values.
            foreach (explode(',', $signature) as $component) {
                $parts = explode('=', $component, 2);
                if (count($parts) !== 2 || trim($parts[0]) === '' || $parts[1] === ''
                    || ($parts[0] === 't' && !preg_match('/\A[0-9]+\z/', $parts[1]))) {
                    throw new RuntimeException();
                }
            }

            $event = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
            if (!$event instanceof \stdClass || ($event->object ?? null) !== 'event'
                || !$this->validId($event->id ?? null, 'evt_', 252)
                || !is_string($event->type ?? null) || strlen($event->type) > 128
                || !preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/', $event->type)
                || !is_bool($event->livemode ?? null)) {
                throw new RuntimeException();
            }

            $account = null;
            if (property_exists($event, 'account')) {
                if (!$this->validId($event->account, 'acct_', 250)) {
                    throw new RuntimeException();
                }
                $account = $event->account;
            }

            $intentId = null;
            if (strpos($event->type, 'payment_intent.') === 0) {
                if (!isset($event->data) || !$event->data instanceof \stdClass
                    || !isset($event->data->object) || !$event->data->object instanceof \stdClass
                    || ($event->data->object->object ?? null) !== 'payment_intent'
                    || !$this->validId($event->data->object->id ?? null, 'pi_', 252)) {
                    throw new RuntimeException();
                }
                $intentId = $event->data->object->id;
            }

            // Authenticate the original bytes; the SDK enforces both past and future tolerance.
            Webhook::constructEvent($payload, $signature, $secret, 300);

            return [
                'id' => $event->id,
                'type' => $event->type,
                'intent_id' => $intentId,
                'livemode' => $event->livemode,
                'account' => $account,
            ];
        } catch (\Throwable $exception) {
            throw new RuntimeException('Webhook verification failed.');
        }
    }

    private function validId($value, string $prefix, int $maxSuffix): bool
    {
        return is_string($value)
            && preg_match('/\A' . $prefix . '[A-Za-z0-9_]{1,' . $maxSuffix . '}\z/', $value) === 1;
    }
}
