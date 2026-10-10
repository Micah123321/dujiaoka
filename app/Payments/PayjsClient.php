<?php

namespace App\Payments;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use RuntimeException;
use Throwable;

class PayjsClient
{
    private const ENDPOINT = 'https://payjs.cn/api/native';
    private const MAX_BYTES = 65536;
    private const FAILURE = 'Payment provider unavailable.';
    private $http;

    public function __construct(?ClientInterface $http = null)
    {
        try {
            // Retain only the transport seam, never another client's default options.
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

    public function create(array $credentials, array $order): array
    {
        try {
            self::credentials($credentials);
            self::order($order);
            $data = array_filter([
                'mchid' => $credentials['mchid'],
                'body' => $order['body'],
                'total_fee' => $order['total_fee'],
                'out_trade_no' => $order['out_trade_no'],
                'notify_url' => $order['notify_url'],
            ]);
            ksort($data, SORT_STRING);
            $data['sign'] = self::signature($data, $credentials['key']);
            $request = new Request('POST', self::ENDPOINT, [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ], http_build_query($data, '', '&', PHP_QUERY_RFC1738));
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
                if ($response->getStatusCode() !== 200) throw new RuntimeException();
                $json = '';
                while (!$body->eof()) {
                    $chunk = $body->read(min(8192, self::MAX_BYTES + 1 - strlen($json)));
                    $json .= $chunk;
                    if (strlen($json) > self::MAX_BYTES || ($chunk === '' && !$body->eof())) {
                        throw new RuntimeException();
                    }
                }
            } finally {
                $body->close();
            }
            $object = json_decode($json, false, 4, JSON_THROW_ON_ERROR);
            if (!$object instanceof \stdClass) throw new RuntimeException();
            $result = $this->verify(get_object_vars($object), $credentials);
            if (!in_array($result['return_code'] ?? null, [1, '1'], true)
                || !array_key_exists('total_fee', $result)
                || (string) $result['total_fee'] !== $order['total_fee']
                || !array_key_exists('out_trade_no', $result)
                || (string) $result['out_trade_no'] !== $order['out_trade_no']
                || !array_key_exists('payjs_order_id', $result)
                || (string) $result['payjs_order_id'] === '' || strlen((string) $result['payjs_order_id']) > 128
                || !is_string($result['code_url'] ?? null)
                || !preg_match('#\Aweixin://wxpay/bizpayurl\?pr=[A-Za-z0-9._~-]{1,256}\z#', $result['code_url'])) {
                throw new RuntimeException();
            }
            return $result;
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    public function verify(array $data, array $credentials): array
    {
        try {
            self::credentials($credentials);
            self::fields($data);
            if (!is_string($data['sign'] ?? null) || !preg_match('/\A[0-9A-Fa-f]{32}\z/', $data['sign'])
                || (array_key_exists('mchid', $data) && (!self::merchant($data['mchid'])
                    || (string) $data['mchid'] !== $credentials['mchid']))
                || (array_key_exists('total_fee', $data) && !self::amount($data['total_fee'], false))) {
                throw new RuntimeException();
            }
            $unsigned = $data;
            unset($unsigned['sign']);
            if (!hash_equals(self::signature($unsigned, $credentials['key']), strtoupper($data['sign']))) {
                throw new RuntimeException();
            }
            return $data;
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    private static function signature(array $data, string $key): string
    {
        // PAYJS's legacy contract also URL-decodes the appended key and omits zero.
        $data = array_filter($data);
        ksort($data, SORT_STRING);
        return strtoupper(md5(urldecode(http_build_query($data, '', '&', PHP_QUERY_RFC1738) . '&key=' . $key)));
    }

    private static function credentials(array $credentials): void
    {
        if (count($credentials) !== 2 || !is_string($credentials['mchid'] ?? null)
            || !self::merchant($credentials['mchid']) || !is_string($credentials['key'] ?? null)
            || !preg_match('/\A[\x20-\x7E]{1,512}\z/', $credentials['key'])) {
            throw new RuntimeException();
        }
    }

    private static function merchant($value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/\A[0-9]{1,64}\z/', (string) $value) === 1;
    }

    private static function amount($value, bool $positive): bool
    {
        if (!is_string($value) && !is_int($value)) return false;
        $value = (string) $value;
        return preg_match($positive ? '/\A[1-9][0-9]{0,9}\z/' : '/\A(?:0|[1-9][0-9]{0,9})\z/', $value) === 1
            && (strlen($value) < 10 || strcmp($value, '2147483647') <= 0);
    }

    private static function fields(array $data): void
    {
        if ($data === [] || count($data) > 128) throw new RuntimeException();
        foreach ($data as $name => $value) {
            if (!is_string($name) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]{0,63}\z/', $name)
                || (!is_string($value) && !is_int($value)) || strlen((string) $value) > self::MAX_BYTES
                || preg_match('//u', (string) $value) !== 1
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', (string) $value)) {
                throw new RuntimeException();
            }
        }
        if (strlen(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) > self::MAX_BYTES) {
            throw new RuntimeException();
        }
    }

    private static function order(array $order): void
    {
        if (count($order) !== 4 || array_diff(array_keys($order), ['body', 'total_fee', 'out_trade_no', 'notify_url'])
            || !is_string($order['body'] ?? null) || $order['body'] === '' || strlen($order['body']) > 128
            || !is_string($order['total_fee'] ?? null) || !self::amount($order['total_fee'], true)
            || !is_string($order['out_trade_no'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $order['out_trade_no'])
            || !is_string($order['notify_url'] ?? null) || strlen($order['notify_url']) > 2048
            || !filter_var($order['notify_url'], FILTER_VALIDATE_URL)
            || preg_match('/[\x00-\x20\x7F\\\\]|%(?![0-9A-Fa-f]{2})/', $order['notify_url'])) {
            throw new RuntimeException();
        }
        self::fields($order);
        $url = parse_url($order['notify_url']);
        if (!is_array($url) || !in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) {
            throw new RuntimeException();
        }
    }
}
