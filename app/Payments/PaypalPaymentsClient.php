<?php

namespace App\Payments;

use RuntimeException;

class PaypalPaymentsClient extends PaypalOrdersClient
{
    public function create(array $credentials, array $params, string $key): array
    {
        return $this->orderRequest($credentials, 'POST', '/v1/payments/payment', $params, $key);
    }

    public function get(array $credentials, string $id): array
    {
        $this->paymentId($id);
        return $this->orderRequest($credentials, 'GET', '/v1/payments/payment/' . $id);
    }

    public function execute(array $credentials, string $id, string $payerId, string $key): array
    {
        $this->paymentId($id);
        if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $payerId)) {
            throw new RuntimeException('Payment provider unavailable.');
        }

        return $this->orderRequest($credentials, 'POST', '/v1/payments/payment/' . $id . '/execute',
            ['payer_id' => $payerId], $key);
    }

    public static function approvalUrl(array $payment, string $mode): ?string
    {
        if (!in_array($mode, ['live', 'sandbox'], true) || !is_array($payment['links'] ?? null)) {
            return null;
        }

        $url = null;
        $count = 0;
        foreach ($payment['links'] as $link) {
            if (!is_array($link) || ($link['rel'] ?? null) !== 'approval_url') continue;
            if (++$count > 1 || !is_string($link['href'] ?? null)) return null;
            $url = $link['href'];
        }

        if ($count !== 1 || $url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        $host = $mode === 'live' ? 'www.paypal.com' : 'www.sandbox.paypal.com';
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== $host
            || (isset($parts['port']) && $parts['port'] !== 443)
            || array_key_exists('user', $parts) || array_key_exists('pass', $parts)
            // Check the raw delimiter too: PHP 7.4 drops an empty fragment in parse_url().
            || strpos($url, '#') !== false) {
            return null;
        }

        return $url;
    }

    private function paymentId(string $id): void
    {
        if (strlen($id) > 64 || !preg_match('/\A(?:PAY|PAYID)-[A-Z0-9]+\z/', $id)) {
            throw new RuntimeException('Payment provider unavailable.');
        }
    }
}
