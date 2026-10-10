<?php

declare(strict_types=1);

namespace App\Captcha;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class GeetestClient
{
    private const REGISTER_URL = 'https://api.geetest.com/register.php';
    private const VALIDATE_URL = 'https://api.geetest.com/validate.php';
    private const SDK_VERSION = 'php_3.0.0';

    /** @var ClientInterface */
    private $http;
    /** @var string */
    private $id;
    /** @var string */
    private $key;

    public function __construct(ClientInterface $http, string $id, string $key)
    {
        $this->http = $http;
        $this->id = $id;
        $this->key = $key;
    }

    /**
     * @return array{success:int,gt:string,challenge:string,new_captcha:int}
     */
    public function register(array $context): array
    {
        $query = array_merge(
            array('gt' => $this->id, 'new_captcha' => 1),
            $this->context($context)
        );

        try {
            $response = $this->http->request('GET', self::REGISTER_URL, $this->options(array('query' => $query)));
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return $this->fallback();
            }

            $raw = trim((string) $response->getBody());
            if (!preg_match('/^[a-f0-9]{32}$/i', $raw)) {
                return $this->fallback();
            }

            return array(
                'success' => 1,
                'gt' => $this->id,
                'challenge' => md5($raw . $this->key),
                'new_captcha' => 1,
            );
        } catch (Throwable $ignored) {
            return $this->fallback();
        }
    }

    public function validateOnline(string $challenge, string $validate, string $seccode, array $context): bool
    {
        if (!hash_equals(md5($this->key . 'geetest' . $challenge), $validate)) {
            return false;
        }

        $form = array_merge(
            array(
                'seccode' => $seccode,
                'timestamp' => time(),
                'challenge' => $challenge,
                'captchaid' => $this->id,
                'json_format' => 1,
                'sdk' => self::SDK_VERSION,
            ),
            $this->context($context)
        );

        try {
            $response = $this->http->request('POST', self::VALIDATE_URL, $this->options(array('form_params' => $form)));
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return false;
            }
            $decoded = json_decode((string) $response->getBody(), true);
            return is_array($decoded)
                && isset($decoded['seccode'])
                && is_string($decoded['seccode'])
                && hash_equals(md5($seccode), $decoded['seccode']);
        } catch (Throwable $ignored) {
            return false;
        }
    }

    public function validateOffline(string $challenge, string $validate, string $seccode): bool
    {
        return hash_equals(md5($challenge), $validate);
    }

    private function options(array $request): array
    {
        return array_merge(array(
            'connect_timeout' => 3.0,
            'timeout' => 5.0,
            'verify' => true,
            'allow_redirects' => false,
            'http_errors' => false,
        ), $request);
    }

    private function context(array $context): array
    {
        $allowed = array();
        foreach (array('user_id', 'client_type', 'ip_address') as $name) {
            if (array_key_exists($name, $context) && is_scalar($context[$name])) {
                $allowed[$name] = (string) $context[$name];
            }
        }
        return $allowed;
    }

    /** @return array{success:int,gt:string,challenge:string,new_captcha:int} */
    private function fallback(): array
    {
        return array(
            'success' => 0,
            'gt' => $this->id,
            'challenge' => bin2hex(random_bytes(17)),
            'new_captcha' => 1,
        );
    }
}
