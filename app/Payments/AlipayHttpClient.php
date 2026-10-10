<?php

namespace App\Payments;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

class AlipayHttpClient extends Client
{
    private const URL = 'https://openapi.alipay.com/gateway.do';
    private const MAX_BYTES = 65536;
    private const FAILURE = 'Payment provider unavailable.';
    private $delegate;

    public function __construct(?ClientInterface $http = null)
    {
        parent::__construct();
        $this->delegate = $http ?? new Client();
    }

    public function request(string $method, $uri = '', array $options = []): ResponseInterface
    {
        try {
            $body = isset($options['form_params'])
                ? http_build_query($options['form_params'], '', '&')
                : ($options['body'] ?? '');

            $response = $this->sendRequest(new Request($method, $uri === '' ? self::URL . '?charset=utf-8' : $uri, [], $body));

            // SDK2 Support parses JSON itself; prevent HasHttpRequest from decoding it first.
            return $response->withoutHeader('Content-Type');
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            $url = (string) $request->getUri();
            if (strtoupper($request->getMethod()) !== 'POST'
                || !in_array($url, [self::URL, self::URL . '?charset=utf-8'], true)) {
                throw new RuntimeException();
            }
            $request = new Request('POST', $url, [
                'Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json',
            ], $request->getBody());

            // Null clears inherited defaults; the delegate retains only its transport handler.
            $options = array_fill_keys(array_keys($this->delegate->getConfig()), null);
            unset($options['handler']);
            $response = $this->delegate->send($request, array_replace($options, [
                'headers' => null, 'base_uri' => null, 'auth' => null, 'body' => null, 'json' => null,
                'form_params' => null, 'multipart' => null, 'query' => null,
                'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
                'connect_timeout' => 3, 'timeout' => 10, 'read_timeout' => 10,
                'cookies' => false, 'proxy' => '', 'cert' => null, 'ssl_key' => null,
                'curl' => [], 'stream_context' => [], 'debug' => false, 'sink' => null,
                'on_headers' => null, 'on_stats' => null, 'progress' => null, 'delay' => 0,
                'decode_content' => false, 'expect' => false, 'stream' => true,
                'version' => '1.1', 'idn_conversion' => false, 'force_ip_resolve' => null,
                'protocols' => ['https'],
            ]));
            $stream = $response->getBody();
            try {
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    throw new RuntimeException();
                }
                $body = '';
                while (!$stream->eof()) {
                    $chunk = $stream->read(min(8192, self::MAX_BYTES + 1 - strlen($body)));
                    $body .= $chunk;
                    if (strlen($body) > self::MAX_BYTES || ($chunk === '' && !$stream->eof())) {
                        throw new RuntimeException();
                    }
                }
            } finally {
                $stream->close();
            }

            return $response->withBody(Utils::streamFor($body));
        } catch (Throwable $exception) {
            throw new RuntimeException(self::FAILURE);
        }
    }
}
