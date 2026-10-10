<?php

namespace App\Payments;

use DOMDocument;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use RuntimeException;
use Throwable;

class WechatV2Client
{
    private const MAX_XML_BYTES = 65536;
    private const FAILURE = 'Payment provider unavailable.';
    private $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client();
    }

    public function create(array $credentials, array $order): array
    {
        try {
            $this->credentials($credentials);
            $this->order($order);
            $data = [
                'appid' => $credentials['app_id'], 'mch_id' => $credentials['mch_id'],
                'nonce_str' => bin2hex(random_bytes(16)), 'trade_type' => 'NATIVE', 'sign_type' => 'MD5',
                'out_trade_no' => $order['out_trade_no'], 'body' => $order['body'],
                'total_fee' => $order['total_fee'], 'notify_url' => $order['notify_url'],
                'spbill_create_ip' => $order['spbill_create_ip'],
            ];
            if (array_key_exists('fee_type', $order)) $data['fee_type'] = 'CNY';
            $data['sign'] = $this->sign($data, $credentials['key']);
            $request = new Request('POST', 'https://api.mch.weixin.qq.com/pay/unifiedorder', [
                'Content-Type' => 'application/xml; charset=utf-8', 'Accept' => 'application/xml',
            ], self::toXml($data));
            // Null removes inherited Guzzle options; headers on the request remain intact.
            $response = $this->http->send($request, [
                'headers' => null, 'auth' => null, 'body' => null, 'json' => null,
                'form_params' => null, 'multipart' => null, 'query' => null,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                'connect_timeout' => 3, 'timeout' => 10, 'read_timeout' => 10,
                'cookies' => false, 'proxy' => '', 'cert' => null, 'ssl_key' => null,
                'curl' => [], 'stream_context' => [], 'debug' => false, 'sink' => null,
                'on_headers' => null, 'on_stats' => null, 'progress' => null, 'delay' => 0,
                'decode_content' => false, 'expect' => false, 'stream' => true,
                'version' => '1.1', 'idn_conversion' => false, 'force_ip_resolve' => null,
                'protocols' => ['https'],
            ]);
            $body = $response->getBody();
            try {
                if ($response->getStatusCode() !== 200) throw new RuntimeException();
                $xml = '';
                while (!$body->eof()) {
                    $chunk = $body->read(min(8192, self::MAX_XML_BYTES + 1 - strlen($xml)));
                    $xml .= $chunk;
                    if (strlen($xml) > self::MAX_XML_BYTES || ($chunk === '' && !$body->eof())) {
                        throw new RuntimeException();
                    }
                }
            } finally {
                $body->close();
            }
            $result = $this->verify($xml, $credentials);
            if (($result['return_code'] ?? null) !== 'SUCCESS' || ($result['result_code'] ?? null) !== 'SUCCESS'
                || ($result['trade_type'] ?? null) !== 'NATIVE'
                || !preg_match('#\Aweixin://wxpay/bizpayurl\?pr=[A-Za-z0-9._~-]{1,256}\z#', $result['code_url'] ?? '')
                || (array_key_exists('out_trade_no', $result) && $result['out_trade_no'] !== $order['out_trade_no'])
                || (array_key_exists('total_fee', $result) && $result['total_fee'] !== $order['total_fee'])) {
                throw new RuntimeException();
            }
            return $result;
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    public function verify(string $xml, array $credentials): array
    {
        try {
            $this->credentials($credentials);
            $data = self::parseXml($xml);
            $type = $data['sign_type'] ?? 'MD5';
            $pattern = $type === 'MD5' ? '/\A[0-9A-Fa-f]{32}\z/' : '/\A[0-9A-Fa-f]{64}\z/';
            if (!in_array($type, ['MD5', 'HMAC-SHA256'], true)
                || !preg_match($pattern, $data['sign'] ?? '')
                || ($data['appid'] ?? null) !== $credentials['app_id']
                || ($data['mch_id'] ?? null) !== $credentials['mch_id']
                || !hash_equals($this->sign($data, $credentials['key'], $type), strtoupper($data['sign']))) {
                throw new RuntimeException();
            }
            return $data;
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    public function sign(array $data, string $key, string $type = 'MD5'): string
    {
        try {
            if (!preg_match('/\A[\x20-\x7E]{32}\z/', $key) || !in_array($type, ['MD5', 'HMAC-SHA256'], true)) {
                throw new RuntimeException();
            }
            ksort($data, SORT_STRING);
            $parts = [];
            foreach ($data as $name => $value) {
                self::field($name, $value);
                if ($name !== 'sign' && (string) $value !== '') $parts[] = $name . '=' . $value;
            }
            $canonical = implode('&', $parts) . '&key=' . $key;
            return strtoupper($type === 'MD5' ? md5($canonical) : hash_hmac('sha256', $canonical, $key));
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    public static function parseXml(string $xml): array
    {
        try {
            if ($xml === '' || strlen($xml) > self::MAX_XML_BYTES
                || !self::xmlText($xml) || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) {
                throw new RuntimeException();
            }
            // DOM omits namespace declarations from its ordinary attribute collection.
            $markup = preg_replace('/<!\[CDATA\[.*?\]\]>/s', '', $xml);
            if (preg_match('/<[A-Za-z_][A-Za-z0-9_.:-]*\s+[^>\s\/]/', $markup)) {
                throw new RuntimeException();
            }
            $document = new DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            $document->validateOnParse = false;
            // Suppress parser diagnostics locally without changing libxml's global switches.
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
                || $document->doctype !== null) throw new RuntimeException();
            $root = $document->documentElement;
            if ($root === null || $root->tagName !== 'xml' || $root->namespaceURI !== null || $root->hasAttributes()) {
                throw new RuntimeException();
            }
            foreach ($document->childNodes as $node) {
                if ($node !== $root) throw new RuntimeException();
            }
            $data = [];
            foreach ($root->childNodes as $node) {
                if ($node->nodeType === XML_TEXT_NODE && trim($node->nodeValue, " \t\r\n") === '') continue;
                if ($node->nodeType !== XML_ELEMENT_NODE || $node->namespaceURI !== null || $node->hasAttributes()
                    || array_key_exists($node->nodeName, $data)) throw new RuntimeException();
                $value = '';
                foreach ($node->childNodes as $text) {
                    if (!in_array($text->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
                        throw new RuntimeException();
                    }
                    $value .= $text->nodeValue;
                }
                self::field($node->nodeName, $value);
                $data[$node->nodeName] = $value;
            }
            if ($data === []) throw new RuntimeException();
            return $data;
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    public static function toXml(array $data): string
    {
        try {
            if ($data === []) throw new RuntimeException();
            $xml = '<xml>';
            foreach ($data as $name => $value) {
                self::field($name, $value);
                $text = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                // Literal CR is normalized by XML parsers; preserve the bytes used in signing.
                $text = str_replace("\r", '&#13;', $text);
                $xml .= '<' . $name . '>' . $text . '</' . $name . '>';
                if (strlen($xml) + 6 > self::MAX_XML_BYTES) throw new RuntimeException();
            }
            return $xml . '</xml>';
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    private static function xmlText(string $value): bool
    {
        return preg_match('/\A[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*\z/u', $value) === 1;
    }

    private static function field($name, $value): void
    {
        if (!is_string($name) || !preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]{0,63}\z/', $name)
            || (!is_string($value) && !is_int($value)) || strlen((string) $value) > self::MAX_XML_BYTES
            || !self::xmlText((string) $value)) throw new RuntimeException();
    }

    private function credentials(array $credentials): void
    {
        if (count($credentials) !== 3
            || !is_string($credentials['app_id'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $credentials['app_id'])
            || !is_string($credentials['mch_id'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $credentials['mch_id'])
            || !is_string($credentials['key'] ?? null) || !preg_match('/\A[\x20-\x7E]{32}\z/', $credentials['key'])) {
            throw new RuntimeException();
        }
    }

    private function order(array $order): void
    {
        if (array_diff(array_keys($order), ['out_trade_no', 'body', 'total_fee', 'notify_url', 'spbill_create_ip', 'fee_type'])
            || !is_string($order['out_trade_no'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{1,32}\z/', $order['out_trade_no'])
            || !is_string($order['body'] ?? null) || $order['body'] === '' || strlen($order['body']) > 128
            || !self::xmlText($order['body']) || !is_string($order['total_fee'] ?? null)
            || !preg_match('/\A[1-9][0-9]{0,9}\z/', $order['total_fee'])
            || (strlen($order['total_fee']) === 10 && strcmp($order['total_fee'], '2147483647') > 0)
            || !is_string($order['notify_url'] ?? null) || strlen($order['notify_url']) > 256
            || !filter_var($order['notify_url'], FILTER_VALIDATE_URL)
            || preg_match('/[\x00-\x20\x7F\\\\]|%(?![0-9A-Fa-f]{2})/', $order['notify_url'])
            || !is_string($order['spbill_create_ip'] ?? null) || !filter_var($order['spbill_create_ip'], FILTER_VALIDATE_IP)
            || (array_key_exists('fee_type', $order) && $order['fee_type'] !== 'CNY')) {
            throw new RuntimeException();
        }
        $url = parse_url($order['notify_url']);
        if (!is_array($url) || !in_array(strtolower($url['scheme'] ?? ''), ['http', 'https'], true)
            || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])) {
            throw new RuntimeException();
        }
    }
}
