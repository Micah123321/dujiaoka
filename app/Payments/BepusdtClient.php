<?php

namespace App\Payments;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use RuntimeException;
use Throwable;

class BepusdtClient
{
    private const MAX_BYTES = 65536;
    private const CREATE_PATH = '/api/v1/order/create-transaction';
    private const FAILURE = 'Payment provider unavailable.';
    private $http;

    public function __construct(?ClientInterface $http = null)
    {
        try {
            $config = [];
            if ($http !== null) {
                $handler = $http->getConfig('handler');
                if (!is_callable($handler)) throw new RuntimeException();
                $config['handler'] = $handler;
            }
            $this->http = new Client($config);
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    public function create(array $credentials, array $order): string
    {
        try {
            self::credentials($credentials);
            if (count($order) !== 4 || array_diff(array_keys($order), ['order_sn', 'amount', 'notify_url', 'return_url'])) {
                throw new RuntimeException();
            }
            foreach ($order as $value) {
                if (!is_string($value)) throw new RuntimeException();
            }
            if (!self::identifier($order['order_sn']) || !self::url($order['notify_url']) || !self::url($order['return_url'])) {
                throw new RuntimeException();
            }
            $amount = CurrencyAmount::cny($order['amount']);
            $data = [
                'order_id' => $order['order_sn'],
                'amount' => rtrim(rtrim($amount, '0'), '.'),
                'fiat' => 'CNY',
                'trade_type' => $credentials['method'],
                'notify_url' => $order['notify_url'],
                'redirect_url' => $order['return_url'],
            ];
            $data['signature'] = self::signature($data, $credentials['key']);
            $endpoint = rtrim($credentials['endpoint'], '/');
            if (substr($endpoint, -strlen(self::CREATE_PATH)) !== self::CREATE_PATH) $endpoint .= self::CREATE_PATH;
            if (!self::url($endpoint, false)) throw new RuntimeException();
            $request = new Request('POST', $endpoint, ['Content-Type' => 'application/json'],
                JsonNotification::encode($data, ['amount']));
            $response = $this->http->send($request, [
                'headers' => null, 'auth' => null,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                'connect_timeout' => 3, 'timeout' => 10, 'read_timeout' => 10,
                'cookies' => false, 'proxy' => '', 'debug' => false,
                'decode_content' => false, 'expect' => false, 'stream' => true,
                'version' => '1.1', 'idn_conversion' => false, 'protocols' => ['http', 'https'],
            ]);
            $body = $response->getBody();
            try {
                if ($response->getStatusCode() !== 200) throw new RuntimeException();
                $raw = '';
                while (!$body->eof()) {
                    $chunk = $body->read(min(8192, self::MAX_BYTES + 1 - strlen($raw)));
                    $raw .= $chunk;
                    if (strlen($raw) > self::MAX_BYTES || ($chunk === '' && !$body->eof())) throw new RuntimeException();
                }
            } finally {
                $body->close();
            }
            $result = json_decode($raw, false, 8, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!$result instanceof \stdClass || !in_array($result->status_code ?? null, [200, '200'], true)
                || !($result->data ?? null) instanceof \stdClass || !self::url($result->data->payment_url ?? null)) {
                throw new RuntimeException();
            }
            $echo = get_object_vars($result->data);
            if ((array_key_exists('order_id', $echo) && (!self::identifier($echo['order_id']) || $echo['order_id'] !== $order['order_sn']))
                || (array_key_exists('amount', $echo) && CurrencyAmount::cny($echo['amount']) !== $amount)
                || !self::echoes($echo, $credentials)
                || (array_key_exists('status', $echo) && !self::status($echo['status']))) {
                throw new RuntimeException();
            }
            return $echo['payment_url'];
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    public function verify(array $data, array $credentials): array
    {
        try {
            self::credentials($credentials);
            self::fields($data);
            if (!self::identifier($data['order_id'] ?? null) || !self::identifier($data['trade_id'] ?? null)
                || !array_key_exists('amount', $data) || !self::status($data['status'] ?? null)
                || !self::echoes($data, $credentials)
                || !is_string($data['signature'] ?? null) || !preg_match('/\A[0-9A-Fa-f]{32}\z/', $data['signature'])) {
                throw new RuntimeException();
            }
            CurrencyAmount::cny($data['amount']);
            if (!hash_equals(self::signature($data, $credentials['key']), strtolower($data['signature']))) {
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
            // BE signs zero values and literal booleans, unlike the older Epusdt client.
            if ($name === 'signature' || $value === null || $value === '') continue;
            $parts[] = $name . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : $value);
        }
        return md5(implode('&', $parts) . $key);
    }

    private static function fields(array $data): void
    {
        if ($data === [] || count($data) > 128) throw new RuntimeException();
        foreach ($data as $name => $value) {
            if (!is_string($name) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]{0,63}\z/', $name)
                || (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value) && $value !== null)
                || (is_float($value) && !is_finite($value)) || strlen((string) $value) > self::MAX_BYTES) {
                throw new RuntimeException();
            }
        }
        if (strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new RuntimeException();
        }
    }

    private static function credentials(array $credentials): void
    {
        if (count($credentials) !== 3 || array_diff(array_keys($credentials), ['key', 'endpoint', 'method'])
            || !is_string($credentials['key'] ?? null) || $credentials['key'] === '' || strlen($credentials['key']) > 512
            || preg_match('//u', $credentials['key']) !== 1
            || !is_string($credentials['method'] ?? null) || $credentials['method'] === '' || strlen($credentials['method']) > 128
            || preg_match('//u', $credentials['method']) !== 1 || !self::url($credentials['endpoint'] ?? null, false)) {
            throw new RuntimeException();
        }
    }

    private static function identifier($value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 128
            && preg_match('//u', $value) === 1 && !preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    private static function status($value): bool
    {
        return in_array($value, [1, 2, 3, '1', '2', '3'], true);
    }

    private static function echoes(array $data, array $credentials): bool
    {
        return (!array_key_exists('fiat', $data) || $data['fiat'] === 'CNY')
            && (!array_key_exists('trade_type', $data) || $data['trade_type'] === $credentials['method']);
    }

    private static function url($value, bool $allowQuery = true): bool
    {
        if (!is_string($value) || strlen($value) > 2048 || (!$allowQuery && strpos($value, '?') !== false) || strpos($value, '#') !== false
            || !filter_var($value, FILTER_VALIDATE_URL) || preg_match('/[\x00-\x20\x7F\\\\]|%(?![0-9A-Fa-f]{2})/', $value)
            || preg_match('/[\x00-\x1F\x7F\\\\]/', rawurldecode($value))) return false;
        $url = parse_url($value);
        return is_array($url) && in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($url['host']) && !isset($url['user']) && !isset($url['pass']) && ($allowQuery || !isset($url['query'])) && !isset($url['fragment']);
    }
}
