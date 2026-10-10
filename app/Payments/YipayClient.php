<?php

namespace App\Payments;

use RuntimeException;
use Throwable;

final class YipayClient
{
    private const FAILURE = 'Payment provider unavailable.';
    private const MODES = ['alipay', 'wxpay', 'qqpay'];
    private const MAX_BYTES = 65536;
    private const MAX_URL_BYTES = 2048;
    private const MAX_FIELDS = 128;

    /** Build form data only; the caller owns HTML escaping and transport. */
    public function create(string $mode, array $credentials, array $order): array
    {
        try {
            self::credentials($credentials);
            if (!in_array($mode, self::MODES, true) || count($order) !== 4
                || array_diff(array_keys($order), ['order_sn', 'amount', 'return_url', 'notify_url'])) {
                throw new RuntimeException();
            }
            foreach ($order as $value) {
                if (!is_string($value)) throw new RuntimeException();
            }
            self::identifier($order['order_sn']);
            self::url($order['return_url']);
            self::url($order['notify_url']);
            $fields = [
                'pid' => $credentials['pid'],
                'type' => $mode,
                'out_trade_no' => $order['order_sn'],
                'return_url' => $order['return_url'],
                'notify_url' => $order['notify_url'],
                'name' => $order['order_sn'],
                'money' => CurrencyAmount::cny($order['amount']),
                'sign_type' => 'MD5',
            ];
            $fields['sign'] = self::signature($fields, $credentials['key']);
            ksort($fields, SORT_STRING);
            return ['endpoint' => $credentials['endpoint'], 'fields' => $fields];
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    /** The caller must match the selected mode, order and amount before fulfillment. */
    public function verify(array $data, array $credentials): array
    {
        try {
            self::credentials($credentials);
            self::fields($data);
            if (!is_string($data['sign'] ?? null) || !preg_match('/\A[0-9A-Fa-f]{32}\z/', $data['sign'])
                || (array_key_exists('sign_type', $data) && $data['sign_type'] !== 'MD5')
                || ($data['trade_status'] ?? null) !== 'TRADE_SUCCESS'
                || (array_key_exists('pid', $data) && (string) $data['pid'] !== $credentials['pid'])
                || (array_key_exists('type', $data) && !in_array($data['type'], self::MODES, true))) {
                throw new RuntimeException();
            }
            foreach (['out_trade_no', 'trade_no'] as $name) {
                if (!array_key_exists($name, $data)) throw new RuntimeException();
                self::identifier((string) $data[$name]);
            }
            if (!array_key_exists('money', $data)) throw new RuntimeException();
            CurrencyAmount::cny((string) $data['money']);
            if (!hash_equals(self::signature($data, $credentials['key']), strtolower($data['sign']))) {
                throw new RuntimeException();
            }
            return $data;
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    private static function signature(array $data, string $key): string
    {
        ksort($data, SORT_STRING);
        $parts = [];
        foreach ($data as $name => $value) {
            // Exclude only empty strings: integer 0 and string "0" are signed raw bytes.
            if ($name !== 'sign' && $name !== 'sign_type' && $value !== '') {
                $parts[] = $name . '=' . $value;
            }
        }
        return md5(implode('&', $parts) . $key);
    }

    private static function credentials(array $credentials): void
    {
        if (count($credentials) !== 3 || !is_string($credentials['pid'] ?? null)
            || !is_string($credentials['endpoint'] ?? null) || !is_string($credentials['key'] ?? null)
            || $credentials['pid'] === '' || strlen($credentials['pid']) > 64
            || $credentials['key'] === '' || strlen($credentials['key']) > 512) {
            throw new RuntimeException();
        }
        self::url($credentials['endpoint']);
    }

    private static function identifier(string $value): void
    {
        if ($value === '' || strlen($value) > 128) throw new RuntimeException();
    }

    private static function fields(array $data): void
    {
        if ($data === [] || count($data) > self::MAX_FIELDS) throw new RuntimeException();
        $bytes = 0;
        foreach ($data as $name => $value) {
            if (!is_string($name) || !preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/', $name)
                || (!is_string($value) && !is_int($value))) {
                throw new RuntimeException();
            }
            $bytes += strlen($name) + strlen((string) $value) + 2;
            if ($bytes > self::MAX_BYTES) throw new RuntimeException();
        }
    }

    private static function url(string $value): void
    {
        if ($value === '' || strlen($value) > self::MAX_URL_BYTES || strpos($value, '#') !== false
            || preg_match('/[\x00-\x20\x7F\\\\]|%(?![0-9A-Fa-f]{2})/', $value)
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException();
        }
        $url = parse_url($value);
        if (!is_array($url) || !in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) {
            throw new RuntimeException();
        }
    }
}
