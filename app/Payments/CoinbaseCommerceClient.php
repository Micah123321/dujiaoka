<?php

namespace App\Payments;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use RuntimeException;
use stdClass;
use Throwable;

class CoinbaseCommerceClient
{
    private const ENDPOINT = 'https://api.commerce.coinbase.com/charges';
    private const MAX_BYTES = 65536;
    private const MAX_PAYMENTS = 128;
    private const FAILURE = 'Payment provider unavailable.';
    private $http;

    public function __construct(?ClientInterface $http = null)
    {
        try {
            // Keep the transport seam, not another client's defaults.
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
            if (array_diff(array_keys($credentials), ['api_key', 'fee'])
                || !is_string($credentials['api_key'] ?? null)
                || !preg_match('/\A[\x21-\x7E]{1,512}\z/', $credentials['api_key'])) {
                throw new RuntimeException();
            }
            $keys = ['order_sn', 'title', 'amount', 'return_url', 'cancel_url'];
            if (count($order) !== count($keys) || array_diff(array_keys($order), $keys)) {
                throw new RuntimeException();
            }
            foreach ($keys as $key) {
                if (!is_string($order[$key]) || $order[$key] === '' || strlen($order[$key]) > self::MAX_BYTES) {
                    throw new RuntimeException();
                }
            }
            $amount = $this->price(CurrencyAmount::cny($order['amount']), array_key_exists('fee', $credentials) ? $credentials['fee'] : '0');
            $json = json_encode([
                'name' => $order['title'],
                'description' => $order['title'] . "\u{9700}\u{4ed8}\u{6b3e}" . $amount . "\u{5143}",
                'pricing_type' => 'fixed_price',
                'local_price' => ['amount' => $amount, 'currency' => 'CNY'],
                'metadata' => ['customer_id' => $order['order_sn'], 'customer_name' => $order['title']],
                'redirect_url' => $order['return_url'],
                'cancel_url' => $order['cancel_url'],
            ], JSON_THROW_ON_ERROR);
            if (strlen($json) > self::MAX_BYTES) throw new RuntimeException();
            $request = new Request('POST', self::ENDPOINT, [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-CC-Api-Key' => $credentials['api_key'],
                'X-CC-Version' => '2018-03-22',
            ], $json);
            $response = $this->http->send($request, [
                'headers' => null, 'auth' => null,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                'connect_timeout' => 3, 'timeout' => 10, 'read_timeout' => 10,
                'cookies' => false, 'proxy' => '', 'debug' => false,
                'decode_content' => false, 'expect' => false, 'stream' => true,
                'version' => '1.1', 'idn_conversion' => false, 'protocols' => ['https'],
            ]);
            $body = $response->getBody();
            try {
                if (!in_array($response->getStatusCode(), [200, 201], true)) throw new RuntimeException();
                $raw = '';
                while (!$body->eof()) {
                    $chunk = $body->read(min(8192, self::MAX_BYTES + 1 - strlen($raw)));
                    $raw .= $chunk;
                    if (strlen($raw) > self::MAX_BYTES || ($chunk === '' && !$body->eof())) {
                        throw new RuntimeException();
                    }
                }
            } finally {
                $body->close();
            }
            $result = json_decode($raw, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!$result instanceof stdClass || !($result->data ?? null) instanceof stdClass
                || !$this->hostedUrl($result->data->hosted_url ?? null)) {
                throw new RuntimeException();
            }
            return $result->data->hosted_url;
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    public function verify(string $payload, string $signature, string $secret): array
    {
        try {
            if ($payload === '' || strlen($payload) > self::MAX_BYTES || $secret === ''
                || strlen($secret) > self::MAX_BYTES || strlen($signature) !== 64
                || !preg_match('/\A[0-9A-Fa-f]{64}\z/', $signature)
                || !hash_equals(hash_hmac('sha256', $payload, $secret), strtolower($signature))) {
                throw new RuntimeException();
            }
            $root = json_decode($payload, false, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!$root instanceof stdClass || !($root->event ?? null) instanceof stdClass
                || !($root->event->data ?? null) instanceof stdClass) {
                throw new RuntimeException();
            }
            $data = $root->event->data;
            if (!($data->metadata ?? null) instanceof stdClass
                || count(get_object_vars($data->metadata)) > 128
                || !is_string($data->metadata->customer_id ?? null) || $data->metadata->customer_id === ''
                || strlen($data->metadata->customer_id) > 128
                || !is_string($data->code ?? null) || $data->code === '' || strlen($data->code) > 128
                || !is_array($data->payments ?? null) || $data->payments === []
                || count($data->payments) > self::MAX_PAYMENTS) {
                throw new RuntimeException();
            }
            foreach ($data->payments as $payment) {
                if (!$payment instanceof stdClass) throw new RuntimeException();
            }
            // Validate JSON object shapes before returning the legacy associative data.
            return json_decode($payload, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)['event']['data'];
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    private function price(string $base, $fee): string
    {
        if (!is_string($fee) || strlen($fee) > 64 || !preg_match('/\A[+-]?[0-9]+(?:\.[0-9]+)?\z/', $fee)) {
            throw new RuntimeException();
        }
        $dot = strpos($fee, '.');
        $scale = $dot === false ? 0 : strlen($fee) - $dot - 1;
        if (bccomp($fee, '0', $scale) <= 0) return $base;
        // Preserve every significant fractional digit; never round the fee to cents.
        $amount = bcmul($base, bcadd('1', $fee, $scale), $scale + 2);
        list($whole, $fraction) = explode('.', $amount);
        return $whole . '.' . str_pad(rtrim($fraction, '0'), 2, '0');
    }

    private function hostedUrl($url): bool
    {
        if (!is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)
            || preg_match('/[\x00-\x20\x7F\\\\]|%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url)
            || strpos($url, '#') !== false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === 'commerce.coinbase.com'
            && (!isset($parts['port']) || $parts['port'] === 443)
            && !array_key_exists('user', $parts) && !array_key_exists('pass', $parts)
            && preg_match('#\A(?:/charges/[A-Za-z0-9_-]{1,128}|/pay/[0-9A-Fa-f]{8}-(?:[0-9A-Fa-f]{4}-){3}[0-9A-Fa-f]{12})\z#', $parts['path'] ?? '') === 1;
    }
}
