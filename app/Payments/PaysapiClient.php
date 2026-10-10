<?php

namespace App\Payments;

use RuntimeException;
use Throwable;

final class PaysapiClient
{
    public const ENDPOINT = 'https://pay.bearsoftware.net.cn/';

    public function create(string $mode, array $credentials, array $order): array
    {
        try {
            $this->credentials($credentials);
            $types = ['pszfb' => '1', 'pswx' => '2'];
            if (!isset($types[$mode]) || count($order) !== 5
                || array_diff(array_keys($order), ['order_sn', 'amount', 'email', 'return_url', 'notify_url'])) throw new RuntimeException();
            foreach ($order as $value) if (!is_string($value)) throw new RuntimeException();
            $this->identifier($order['order_sn']);
            $this->url($order['return_url']); $this->url($order['notify_url']);
            $fields = ['goodsname' => $order['order_sn'], 'istype' => $types[$mode], 'notify_url' => $order['notify_url'],
                'orderid' => $order['order_sn'], 'orderuid' => $order['email'], 'price' => CurrencyAmount::cny($order['amount']),
                'return_url' => $order['return_url'], 'uid' => $credentials['uid']];
            $this->fields($fields);
            $fields['key'] = md5($fields['goodsname'] . $fields['istype'] . $fields['notify_url'] . $fields['orderid']
                . $fields['orderuid'] . $fields['price'] . $fields['return_url'] . $credentials['key'] . $fields['uid']);
            return ['endpoint' => self::ENDPOINT, 'fields' => $fields];
        } catch (Throwable $exception) { throw new RuntimeException('Payment provider unavailable.'); }
    }

    public function verify(array $data, array $credentials): array
    {
        try {
            $this->credentials($credentials);
            $this->fields($data);
            foreach (['orderid', 'paysapi_id'] as $name) $this->identifier($data[$name] ?? null);
            if (!array_key_exists('orderuid', $data) || !is_string($data['orderuid'])
                || !is_string($data['key'] ?? null) || !preg_match('/\A[0-9A-Fa-f]{32}\z/', $data['key'])) throw new RuntimeException();
            CurrencyAmount::cny($data['price'] ?? null); CurrencyAmount::cny($data['realprice'] ?? null);
            $signature = md5($data['orderid'] . $data['orderuid'] . $data['paysapi_id'] . $data['price'] . $data['realprice'] . $credentials['key']);
            if (!hash_equals($signature, strtolower($data['key']))) throw new RuntimeException();
            return $data;
        } catch (Throwable $exception) { throw new RuntimeException('Payment provider unavailable.'); }
    }

    private function credentials(array $credentials): void
    {
        if (count($credentials) !== 2 || !is_string($credentials['key'] ?? null) || $credentials['key'] === ''
            || strlen($credentials['key']) > 512 || !is_string($credentials['uid'] ?? null)
            || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $credentials['uid'])) throw new RuntimeException();
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
