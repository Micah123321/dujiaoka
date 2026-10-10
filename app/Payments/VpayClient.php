<?php

namespace App\Payments;

use RuntimeException;
use Throwable;

final class VpayClient
{
    public function create(string $mode, array $credentials, array $order): string
    {
        try {
            $this->credentials($credentials);
            $types = ['vzfb' => '2', 'vwx' => '1'];
            if (!isset($types[$mode]) || count($order) !== 5
                || array_diff(array_keys($order), ['payment_id', 'order_sn', 'amount', 'return_url', 'notify_url'])) throw new RuntimeException();
            foreach ($order as $value) if (!is_string($value)) throw new RuntimeException();
            $this->identifier($order['payment_id']); $this->identifier($order['order_sn']);
            $this->url($order['return_url']); $this->url($order['notify_url']);
            if (strpos($credentials['endpoint'], '?') !== false) throw new RuntimeException();
            $data = ['payId' => $order['payment_id'], 'price' => CurrencyAmount::cny($order['amount']), 'param' => $order['order_sn'],
                'returnUrl' => $order['return_url'], 'notifyUrl' => $order['notify_url'], 'isHtml' => '1', 'type' => $types[$mode]];
            $data['sign'] = md5($data['payId'] . $data['param'] . $data['type'] . $data['price'] . $credentials['key']);
            $url = rtrim($credentials['endpoint'], '/') . '/createOrder?' . http_build_query($data, '', '&', PHP_QUERY_RFC1738);
            if (strlen($url) > 8192) throw new RuntimeException();
            return $url;
        } catch (Throwable $exception) { throw new RuntimeException('Payment provider unavailable.'); }
    }

    public function verify(array $data, array $credentials): array
    {
        try {
            $this->credentials($credentials);
            $this->fields($data);
            foreach (['payId', 'param'] as $name) $this->identifier($data[$name] ?? null);
            if (!in_array($data['type'] ?? null, ['1', '2', 1, 2], true)
                || !is_string($data['sign'] ?? null) || !preg_match('/\A[0-9A-Fa-f]{32}\z/', $data['sign'])) throw new RuntimeException();
            CurrencyAmount::cny($data['price'] ?? null); CurrencyAmount::cny($data['reallyPrice'] ?? null);
            $signature = md5($data['payId'] . $data['param'] . $data['type'] . $data['price'] . $data['reallyPrice'] . $credentials['key']);
            if (!hash_equals($signature, strtolower($data['sign']))) throw new RuntimeException();
            return $data;
        } catch (Throwable $exception) { throw new RuntimeException('Payment provider unavailable.'); }
    }

    private function credentials(array $credentials): void
    {
        if (count($credentials) !== 2 || !is_string($credentials['key'] ?? null) || $credentials['key'] === ''
            || strlen($credentials['key']) > 512 || !is_string($credentials['endpoint'] ?? null)) throw new RuntimeException();
        $this->url($credentials['endpoint']);
    }

    private function identifier($value): void
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', (string) $value)) throw new RuntimeException();
    }

    private function fields(array $data): void
    {
        $bytes = 0;
        foreach ($data as $name => $value) {
            if (!is_string($name) || !preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/', $name)
                || (!is_string($value) && !is_int($value))) throw new RuntimeException();
            $bytes += strlen($name) + strlen((string) $value) + 2;
            if ($bytes > 65536 || preg_match('//u', (string) $value) !== 1 || strpos((string) $value, "\0") !== false) throw new RuntimeException();
        }
    }

    private function url(string $value): void
    {
        $parts = parse_url($value);
        if (strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL) || strpos($value, '#') !== false
            || preg_match('/[\x00-\x20\x7F\\\\]|%(?![0-9A-Fa-f]{2})/', $value)
            || !is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) throw new RuntimeException();
    }
}
