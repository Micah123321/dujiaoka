<?php

namespace App\Payments;

use RuntimeException;
use Throwable;

final class FormNotification
{
    public static function parse(string $body): array
    {
        try {
            if ($body === '' || strlen($body) > 65536 || preg_match('/%(?![0-9A-Fa-f]{2})/', $body)) {
                throw new RuntimeException();
            }
            $params = [];
            foreach (explode('&', $body) as $pair) {
                if (strpos($pair, '=') === false) throw new RuntimeException();
                [$name, $value] = explode('=', $pair, 2);
                $name = urldecode($name);
                if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $name) || array_key_exists($name, $params)) {
                    throw new RuntimeException();
                }
                $params[$name] = urldecode($value);
            }
            return $params;
        } catch (Throwable $exception) {
            throw new RuntimeException('Payment provider unavailable.');
        }
    }
}
