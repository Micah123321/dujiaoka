<?php

namespace App\Payments;

use GuzzleHttp\ClientInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;
use Yansongda\Pay\Gateways\Alipay\Support;
use Yansongda\Pay\Pay;
use Yansongda\Pay\Plugin\Alipay\V2\StartPlugin;
use Yansongda\Pay\Provider\Alipay;
use Yansongda\Pay\Shortcut\Alipay\H5Shortcut;
use Yansongda\Pay\Shortcut\Alipay\ScanShortcut;
use Yansongda\Pay\Shortcut\Alipay\WebShortcut;
use Yansongda\Supports\Log;
use Yansongda\Supports\Logger;

class AlipayClient
{
    private const FAILURE = 'Payment provider unavailable.';
    private const MAX_BYTES = 65536;
    private $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = new AlipayHttpClient($http);
    }

    /** @return array|string */
    public function checkout(string $mode, array $credentials, array $order)
    {
        try {
            if (!in_array($mode, ['scan', 'web', 'wap'], true)) throw new RuntimeException();
            $this->validateOrder($order);
            $gateway = $this->gateway($credentials);
            if (class_exists(Alipay::class)) {
                $shortcuts = ['scan' => ScanShortcut::class, 'web' => WebShortcut::class, 'wap' => H5Shortcut::class];
                $shortcut = new $shortcuts[$mode]();
                $plugins = $shortcut->getPlugins($order);
                $replaced = 0;
                foreach ($plugins as &$plugin) {
                    if ($plugin === StartPlugin::class) {
                        $plugin = RawKeyAlipayStartPlugin::class;
                        ++$replaced;
                    }
                }
                unset($plugin);
                if ($replaced !== 1) throw new RuntimeException();
                $result = $gateway->pay($plugins, $order);
            } else {
                $result = $gateway->{$mode}($order);
            }
            if ($mode === 'scan') {
                $data = $result->all();
                $qr = $data['qr_code'] ?? null;
                if (($data['code'] ?? null) !== '10000' || ($data['out_trade_no'] ?? null) !== $order['out_trade_no']
                    || !is_string($qr) || strlen($qr) > 2048 || preg_match('/[\x00-\x20\x7f]/', $qr)
                    || strpos($qr, '\\') !== false || strpos($qr, '#') !== false) throw new RuntimeException();
                $uri = parse_url($qr);
                if (!$uri || ($uri['scheme'] ?? null) !== 'https' || strtolower($uri['host'] ?? '') !== 'qr.alipay.com'
                    || empty($uri['path']) || isset($uri['user']) || isset($uri['pass'])
                    || (isset($uri['port']) && $uri['port'] !== 443)) throw new RuntimeException();
                return $data;
            }

            return class_exists(Alipay::class) ? (string) $result->getBody() : $result->getContent();
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    public static function parseNotification(string $body): array
    {
        return FormNotification::parse($body);
    }

    public function verify(array $params, array $credentials): array
    {
        try {
            $this->validateCallback($params);
            if (($params['app_id'] ?? null) !== ($credentials['app_id'] ?? null)) throw new RuntimeException();
            $gateway = $this->gateway($credentials);
            $result = class_exists(Alipay::class) ? $gateway->callback($params) : $gateway->verify($params);

            return $result->all();
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    private function gateway(array $credentials)
    {
        $fields = ['app_id', 'public_key', 'private_key', 'notify_url', 'return_url'];
        if (array_diff(array_keys($credentials), $fields)
            || !is_string($credentials['app_id'] ?? null)
            || !preg_match('/\A[0-9]{1,64}\z/', $credentials['app_id'])) throw new RuntimeException();
        foreach (['notify_url', 'return_url'] as $field) {
            $credentials[$field] = $credentials[$field] ?? '';
            if (!is_string($credentials[$field]) || strlen($credentials[$field]) > 2048
                || preg_match('/[\x00-\x1f\x7f]/', $credentials[$field])) throw new RuntimeException();
        }
        $public = $this->readKey($credentials['public_key'] ?? null, false);
        $private = $this->readKey($credentials['private_key'] ?? null, true);
        $publicDetails = openssl_pkey_get_details($public);
        $privateDetails = openssl_pkey_get_details($private);
        if ($publicDetails['type'] !== OPENSSL_KEYTYPE_RSA || $privateDetails['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException();
        }
        $privateBody = $this->rsaPrivateBody($privateDetails['rsa']);
        if (class_exists(Alipay::class)) {
            // SDK3 accepts PKCS8 PEM, whereas SDK2 wraps a bare PKCS1 body itself.
            $pkcs8 = $this->der(0x30, "\x02\x01\x00" . base64_decode('MA0GCSqGSIb3DQEBAQUA')
                . $this->der(0x04, base64_decode($privateBody)));
            Pay::clear();
            Pay::config([
                'alipay' => ['default' => [
                    'app_id' => $credentials['app_id'],
                    'app_secret_cert' => $this->pem('PRIVATE KEY', base64_encode($pkcs8)),
                    'alipay_public_cert_path' => $publicDetails['key'],
                    'notify_url' => $credentials['notify_url'], 'return_url' => $credentials['return_url'],
                ]],
                'logger' => ['enable' => false],
                'http' => ['timeout' => 10, 'connect_timeout' => 3],
            ]);
            Pay::set(\Yansongda\Artful\Contract\HttpClientInterface::class, $this->http);

            return Pay::alipay();
        }
        try {
            Support::getInstance()->clear();
        } catch (\Yansongda\Pay\Exceptions\InvalidArgumentException $exception) {
            // SDK2 has no instance before the first call.
        }
        $gateway = Pay::alipay([
            'app_id' => $credentials['app_id'],
            'ali_public_key' => $this->bare($publicDetails['key']), 'private_key' => $privateBody,
            'notify_url' => $credentials['notify_url'], 'return_url' => $credentials['return_url'],
            'http' => ['timeout' => 10, 'connect_timeout' => 3], 'log' => [],
        ]);
        Log::setInstance((new Logger())->setLogger(new NullLogger()));
        Support::getInstance()->setHttpClient($this->http);

        return $gateway;
    }

    private function validateOrder(array $order): void
    {
        if (count($order) !== 3 || array_diff(array_keys($order), ['out_trade_no', 'subject', 'total_amount'])) {
            throw new RuntimeException();
        }
        foreach ($order as $value) {
            if (!is_string($value) || $value === '' || !preg_match('//u', $value)
                || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new RuntimeException();
        }
        if (strlen($order['out_trade_no']) > 64 || strlen($order['subject']) > 256
            || strlen($order['total_amount']) > 32
            || !preg_match('/\A(?:0|[1-9][0-9]*)\.[0-9]{1,2}\z/', $order['total_amount'])
            || !preg_match('/[1-9]/', $order['total_amount'])) throw new RuntimeException();
    }

    private function validateCallback(array $params): void
    {
        $size = 0;
        foreach ($params as $key => $value) {
            if (!is_string($key) || !preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $key)
                || !is_string($value)) throw new RuntimeException();
            $size += strlen($key) + strlen($value) + 2;
            if ($size > self::MAX_BYTES) throw new RuntimeException();
        }
        if (($params['sign_type'] ?? null) !== 'RSA2' || empty($params['sign'])
            || base64_decode($params['sign'], true) === false
            || strlen(http_build_query($params, '', '&')) > self::MAX_BYTES) throw new RuntimeException();
    }

    private function readKey($value, bool $private)
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_BYTES) throw new RuntimeException();
        $value = trim($value);
        if (strpos($value, '-----BEGIN ') !== 0 && preg_match('/\.(?:pem|crt)\z/i', $value)) {
            $path = strpos($value, 'file://') === 0 ? substr($value, 7) : $value;
            if (strpos($path, '://') !== false || strpos($path, "\0") !== false
                || !is_file($path)) throw new RuntimeException();
            $value = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
            if (!is_string($value) || strlen($value) > self::MAX_BYTES) throw new RuntimeException();
        }
        $candidates = [$value];
        if (strpos($value, '-----BEGIN ') !== 0) {
            $body = preg_replace('/\s+/', '', $value);
            if (base64_decode($body, true) === false) throw new RuntimeException();
            $candidates = $private
                ? [$this->pem('RSA PRIVATE KEY', $body), $this->pem('PRIVATE KEY', $body)]
                : [$this->pem('PUBLIC KEY', $body), $this->pem('RSA PUBLIC KEY', $body)];
        }
        foreach ($candidates as $candidate) {
            $key = $private ? @openssl_pkey_get_private($candidate) : @openssl_pkey_get_public($candidate);
            if ($key !== false) return $key;
        }
        throw new RuntimeException();
    }

    private function pem(string $type, string $body): string
    {
        return '-----BEGIN ' . $type . "-----\n" . chunk_split($body, 64, "\n") . '-----END ' . $type . '-----';
    }

    private function bare(string $pem): string
    {
        return preg_replace('/-----[^-]+-----|\s+/', '', $pem);
    }

    private function rsaPrivateBody(array $rsa): string
    {
        $encoded = "\x02\x01\x00";
        foreach (['n', 'e', 'd', 'p', 'q', 'dmp1', 'dmq1', 'iqmp'] as $part) {
            $integer = ltrim($rsa[$part], "\0");
            if ($integer === '' || ord($integer[0]) > 127) $integer = "\0" . $integer;
            $encoded .= $this->der(0x02, $integer);
        }

        return base64_encode($this->der(0x30, $encoded));
    }

    private function der(int $tag, string $bytes): string
    {
        $length = strlen($bytes);
        $encoded = '';
        for ($remaining = $length; $remaining > 0; $remaining >>= 8) {
            $encoded = chr($remaining & 255) . $encoded;
        }

        return chr($tag) . ($length < 128 ? chr($length) : chr(128 | strlen($encoded)) . $encoded) . $bytes;
    }
}
