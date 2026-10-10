<?php

namespace App\Payments;

use RuntimeException;
use Throwable;

final class MapayClient
{
    private const FAILURE = 'Payment provider unavailable.';
    private const MAX_BYTES = 65536;
    private const MAX_URL_BYTES = 2048;
    private const TYPES = ['mqq' => 2, 'mzfb' => 1, 'mwx' => 3];

    /**
     * Credentials map merchant_id => id, merchant_key => endpoint, merchant_pem => key.
     * This method builds a redirect only; it performs no transport or order mutation.
     */
    public function create(string $mode, array $credentials, array $order): string
    {
        try {
            $id = self::credentials($credentials);
            if (!array_key_exists($mode, self::TYPES) || count($order) !== 4
                || array_diff(array_keys($order), ['order_sn', 'amount', 'return_url', 'notify_url'])) {
                throw new RuntimeException();
            }
            foreach ($order as $value) {
                if (!is_string($value)) throw new RuntimeException();
            }
            self::identifier($order['order_sn']);
            self::url($order['return_url']);
            self::url($order['notify_url']);
            $data = [
                'id' => $id,
                'price' => CurrencyAmount::cny($order['amount']),
                'pay_id' => $order['order_sn'],
                'param' => $mode,
                'act' => 0,
                'outTime' => 120,
                'page' => 1,
                'return_url' => $order['return_url'],
                'notify_url' => $order['notify_url'],
                'pay_type' => 0,
                'chart' => 'utf-8',
                'type' => self::TYPES[$mode],
            ];
            // PHP 7.4's helper omitted integer 0 via == ''; PHP 8 changed that comparison.
            // Preserve these two defaults explicitly without filtering a string "0" anywhere.
            unset($data['act'], $data['pay_type']);
            ksort($data, SORT_STRING);
            $query = [];
            foreach ($data as $name => $value) {
                $query[] = $name . '=' . urlencode((string) $value);
            }
            $query[] = 'sign=' . self::signature($data, $credentials['key']);
            $endpoint = $credentials['endpoint'];
            $separator = strpos($endpoint, '?') === false ? '?'
                : (in_array(substr($endpoint, -1), ['?', '&'], true) ? '' : '&');
            $url = $endpoint . $separator . implode('&', $query);
            if (strlen($url) > self::MAX_URL_BYTES) throw new RuntimeException();
            return $url;
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    /**
     * Authenticate the explicit parsed callback, retaining all signed fields unchanged.
     * The caller owns route/mode checks, order lookup, amount matching and fulfillment.
     */
    public function verify(array $data, array $credentials): array
    {
        try {
            $id = self::credentials($credentials);
            self::fields($data);
            if (!is_string($data['sign'] ?? null) || !preg_match('/\A[0-9A-Fa-f]{32}\z/', $data['sign'])) {
                throw new RuntimeException();
            }
            foreach (['pay_id', 'pay_no'] as $name) {
                if (!array_key_exists($name, $data)) throw new RuntimeException();
                self::identifier((string) $data[$name]);
            }
            if (!array_key_exists('money', $data)
                || (array_key_exists('id', $data) && (string) $data['id'] !== (string) $id)) {
                throw new RuntimeException();
            }
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
            // Form callbacks contain strings: only the empty string is excluded, never "0".
            if ($name !== 'sign' && $value !== '') $parts[] = $name . '=' . $value;
        }
        return md5(implode('&', $parts) . $key);
    }

    private static function credentials(array $credentials): int
    {
        if (count($credentials) !== 3 || !is_string($credentials['id'] ?? null)
            || !is_string($credentials['endpoint'] ?? null) || !is_string($credentials['key'] ?? null)
            || !preg_match('/\A[0-9]{1,64}\z/', $credentials['id'])) {
            throw new RuntimeException();
        }
        $id = ltrim($credentials['id'], '0');
        $max = (string) PHP_INT_MAX;
        if ($id === '' || strlen($id) > strlen($max)
            || (strlen($id) === strlen($max) && strcmp($id, $max) > 0)
            || $credentials['key'] === '' || strlen($credentials['key']) > 512) {
            throw new RuntimeException();
        }
        self::text($credentials['key']);
        self::url($credentials['endpoint']);
        // The provider's account ID is an integer; callbacks must match its wire spelling exactly.
        return (int) $id;
    }

    private static function identifier(string $value): void
    {
        if ($value === '' || strlen($value) > 128) throw new RuntimeException();
        self::text($value);
    }

    private static function fields(array $data): void
    {
        if ($data === []) throw new RuntimeException();
        $bytes = 0;
        foreach ($data as $name => $value) {
            if (!is_string($name) || !preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/', $name)
                || (!is_string($value) && !is_int($value))) {
                throw new RuntimeException();
            }
            $value = (string) $value;
            $bytes += strlen($name) + strlen($value) + 2;
            if ($bytes > self::MAX_BYTES) throw new RuntimeException();
            self::text($value);
        }
    }

    private static function text(string $value): void
    {
        if (preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException();
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
