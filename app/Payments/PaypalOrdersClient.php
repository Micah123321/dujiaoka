<?php

namespace App\Payments;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

class PaypalOrdersClient
{
    private $http;
    private $tokens = [];

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client();
    }

    public function create(array $credentials, array $params, string $key): array
    {
        return $this->orderRequest($credentials, 'POST', '/v2/checkout/orders', $params, $key);
    }

    public function get(array $credentials, string $id): array
    {
        $this->id($id);
        return $this->orderRequest($credentials, 'GET', '/v2/checkout/orders/' . $id);
    }

    public function capture(array $credentials, string $id, string $key): array
    {
        $this->id($id);
        return $this->orderRequest($credentials, 'POST', '/v2/checkout/orders/' . $id . '/capture', new \stdClass(), $key);
    }

    public function getCapture(array $credentials, string $id): array
    {
        $this->id($id);
        return $this->orderRequest($credentials, 'GET', '/v2/payments/captures/' . $id);
    }

    public function verifyWebhook(array $credentials, array $verification, string $rawEvent): array
    {
        try {
            if (strlen($rawEvent) > 262144 || !(json_decode($rawEvent, false, 64, JSON_THROW_ON_ERROR) instanceof \stdClass)) {
                throw new RuntimeException();
            }
            // Embed the original event bytes; decoding/re-encoding may change its signed representation.
            $prefix = json_encode($verification, JSON_THROW_ON_ERROR);
            $raw = substr($prefix, 0, -1) . ',"webhook_event":' . $rawEvent . '}';
            return $this->orderRequest($credentials, 'POST', '/v1/notifications/verify-webhook-signature', null, null, $raw);
        } catch (Throwable $exception) { throw new RuntimeException('Payment provider unavailable.'); }
    }

    protected function orderRequest(array $credentials, string $method, string $path, $body = null, ?string $key = null, ?string $rawBody = null): array
    {
        $cacheKey = null;
        try {
            $base = $this->base($credentials);
            if ($key !== null && !preg_match('/\A[a-f0-9]{32}\z/', $key)) {
                throw new RuntimeException();
            }
            $cacheKey = hash('sha256', $base . "\0" . $credentials['client_id'] . "\0" . $credentials['secret']);
            $saved = $this->tokens[$cacheKey] ?? null;
            if (!$saved || $saved['expires'] <= time()) {
                $oauth = $this->send('POST', $base . '/v1/oauth2/token', [
                    'auth' => [$credentials['client_id'], $credentials['secret']],
                    'form_params' => ['grant_type' => 'client_credentials'],
                ]);
                if (($oauth['token_type'] ?? null) !== 'Bearer' || !is_string($oauth['access_token'] ?? null)
                    || !preg_match('/\A[A-Za-z0-9._~+\/=\-]{1,4096}\z/', $oauth['access_token'])
                    || !is_int($oauth['expires_in'] ?? null) || $oauth['expires_in'] < 1 || $oauth['expires_in'] > 86400) {
                    throw new RuntimeException();
                }
                // Volatile per-client cache only; never persist bearer tokens in application storage.
                $saved = ['token' => $oauth['access_token'], 'expires' => time() + max(0, $oauth['expires_in'] - 60)];
                $this->tokens[$cacheKey] = $saved;
            }
            $options = ['headers' => ['Authorization' => 'Bearer ' . $saved['token'], 'Prefer' => 'return=representation']];
            if ($key !== null) $options['headers']['PayPal-Request-Id'] = $key;
            if ($body !== null) $options['json'] = $body;
            if ($rawBody !== null) {
                $options['headers']['Content-Type'] = 'application/json';
                $options['body'] = $rawBody;
            }
            return $this->send($method, $base . $path, $options);
        } catch (Throwable $exception) {
            if ($cacheKey !== null) unset($this->tokens[$cacheKey]);
            throw new RuntimeException('Payment provider unavailable.');
        }
    }

    private function send(string $method, string $url, array $options): array
    {
        $options = array_replace($options, ['connect_timeout' => 3, 'timeout' => 10, 'allow_redirects' => false,
            'verify' => true, 'http_errors' => false]);
        $options['headers'] = array_merge(['Accept' => 'application/json'], $options['headers'] ?? []);
        $response = $this->http->request($method, $url, $options);
        if (!in_array($response->getStatusCode(), [200, 201], true)) throw new RuntimeException();
        $raw = (string) $response->getBody();
        if (strlen($raw) > 524288) throw new RuntimeException();
        $result = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($result)) throw new RuntimeException();
        return $result;
    }

    private function base(array $c): string
    {
        if (!is_string($c['client_id'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{1,255}\z/', $c['client_id'])
            || !is_string($c['secret'] ?? null) || $c['secret'] === '' || strlen($c['secret']) > 512
            || preg_match('/[\r\n\x00]/', $c['secret']) || !in_array($c['mode'] ?? null, ['live', 'sandbox'], true)) {
            throw new RuntimeException();
        }
        return $c['mode'] === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    private function id(string $id): void
    {
        if (!preg_match('/\A[A-Z0-9]{17}\z/', $id)) throw new RuntimeException('Payment provider unavailable.');
    }
}
