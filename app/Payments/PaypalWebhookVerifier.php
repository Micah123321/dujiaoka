<?php

namespace App\Payments;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PaypalWebhookVerifier
{
    private $client;
    public function __construct(PaypalOrdersClient $client) { $this->client = $client; }

    public function verify(array $credentials, string $payload, string $webhookId, array $headers): array
    {
        try {
            if (strlen($payload) > 262144 || !preg_match('/\A[A-Z0-9]{1,128}\z/', $webhookId)) throw new \Exception();
            $event = json_decode($payload, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($event) || !is_string($event['id'] ?? null) || !preg_match('/\A[A-Za-z0-9-]{1,128}\z/', $event['id'])
                || !is_string($event['event_type'] ?? null) || !preg_match('/\A[A-Z][A-Z0-9_.-]{1,127}\z/', $event['event_type'])
                || !is_array($event['resource'] ?? null)) throw new \Exception();
            foreach (['transmission_id', 'transmission_time', 'transmission_sig', 'cert_url', 'auth_algo'] as $name) {
                if (!is_string($headers[$name] ?? null) || $headers[$name] === '' || strlen($headers[$name]) > 4096
                    || preg_match('/[\r\n\x00]/', $headers[$name])) throw new \Exception();
            }
            if (!preg_match('/\A[A-Za-z0-9-]{1,128}\z/', $headers['transmission_id'])
                || !preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,9})?(?:Z|[+-]\d\d:\d\d)\z/', $headers['transmission_time'])
                || $headers['auth_algo'] !== 'SHA256withRSA' || base64_decode($headers['transmission_sig'], true) === false) throw new \Exception();
            $url = parse_url($headers['cert_url']);
            $hosts = ($credentials['mode'] ?? null) === 'live' ? ['api.paypal.com', 'api-m.paypal.com']
                : (($credentials['mode'] ?? null) === 'sandbox' ? ['api.sandbox.paypal.com', 'api-m.sandbox.paypal.com'] : []);
            if (!is_array($url) || ($url['scheme'] ?? null) !== 'https' || !in_array($url['host'] ?? null, $hosts, true)
                || !preg_match('/\A\/v1\/notifications\/certs\/[A-Za-z0-9_-]{1,255}\z/', $url['path'] ?? '')
                || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['query']) || isset($url['fragment'])) throw new \Exception();
            $type = $event['event_type']; $resource = $event['resource'];
            $provider = null; $resourceId = null;
            if ($type === 'CHECKOUT.ORDER.APPROVED') {
                if (!$this->id($resource['id'] ?? null)) throw new \Exception();
                $provider = $resourceId = $resource['id'];
            } elseif (in_array($type, ['PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED'], true)) {
                if (!$this->id($resource['id'] ?? null)) throw new \Exception();
                $resourceId = $resource['id'];
                $candidate = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
                if ($candidate !== null && !$this->id($candidate)) throw new \Exception();
                $provider = $candidate;
            }
        } catch (Throwable $exception) { throw new InvalidArgumentException('Webhook verification failed.'); }
        // PayPal verifies against this REST app's registered endpoint, not an event-supplied URL or ID.
        try {
            $response = $this->client->verifyWebhook($credentials, array_intersect_key($headers, array_flip([
                'transmission_id', 'transmission_time', 'transmission_sig', 'cert_url', 'auth_algo',
            ])) + ['webhook_id' => $webhookId], $payload);
        } catch (Throwable $exception) { throw new RuntimeException('Webhook verification unavailable.'); }
        if (($response['verification_status'] ?? null) === 'FAILURE') throw new InvalidArgumentException('Webhook verification failed.');
        if (($response['verification_status'] ?? null) !== 'SUCCESS') throw new RuntimeException('Webhook verification unavailable.');
        return ['id' => $event['id'], 'type' => $type, 'provider_id' => $provider, 'resource_id' => $resourceId];
    }

    private function id($id): bool { return is_string($id) && preg_match('/\A[A-Z0-9]{17}\z/', $id) === 1; }
}
