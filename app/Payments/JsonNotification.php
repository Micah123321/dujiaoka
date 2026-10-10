<?php

namespace App\Payments;

use RuntimeException;
use Throwable;

final class JsonNotification
{
    public static function parse(string $body, array $moneyFields = []): array
    {
        try {
            if ($body === '' || strlen($body) > 65536) throw new RuntimeException();
            $data = json_decode($body, true, 2, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            if (!is_array($data) || $data === [] || count($data) > 128) throw new RuntimeException();
            // Consume text in chunks; possessive repetitions keep long escaped values off the PCRE JIT stack.
            $string = '"[^"\\\\\\x00-\\x1F]*+(?:\\\\(?:["\\\\/bfnrt]|u[0-9A-Fa-f]{4})[^"\\\\\\x00-\\x1F]*+)*+"';
            $number = '-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?';
            $pattern = '~\G[ \t\r\n]*(' . $string . ')[ \t\r\n]*:[ \t\r\n]*(' . $string . '|' . $number . '|true|false|null)[ \t\r\n]*~';
            if (!preg_match('/\A[ \t\r\n]*\{/', $body, $open)) throw new RuntimeException();
            $position = strlen($open[0]); $seen = [];
            while (preg_match($pattern, $body, $match, 0, $position)) {
                $name = json_decode($match[1], true, 2, JSON_THROW_ON_ERROR);
                if (!is_string($name) || !preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/', $name) || isset($seen[$name])) throw new RuntimeException();
                $seen[$name] = true;
                // Numeric fiat values must retain the original cent precision, before JSON float conversion.
                if (in_array($name, $moneyFields, true) && $match[2][0] !== '"'
                    && !preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/', $match[2])) throw new RuntimeException();
                $position += strlen($match[0]);
                if (($body[$position] ?? '') === ',') { ++$position; continue; }
                if (!preg_match('/\A\}[ \t\r\n]*\z/', substr($body, $position))) throw new RuntimeException();
                if (count($seen) !== count($data)) throw new RuntimeException();
                return $data;
            }
            throw new RuntimeException();
        } catch (Throwable $exception) { throw new RuntimeException('Payment provider unavailable.'); }
    }

    public static function encode(array $data, array $numericFields): string
    {
        try {
            foreach ($numericFields as $name) {
                if (!is_string($name) || !array_key_exists($name, $data) || !is_string($data[$name])
                    || !preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/', $data[$name])) throw new RuntimeException();
            }
            $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            foreach ($numericFields as $name) {
                $body = str_replace(json_encode($name) . ':' . json_encode($data[$name]), json_encode($name) . ':' . $data[$name], $body);
            }
            if (strlen($body) > 65536) throw new RuntimeException();
            return $body;
        } catch (Throwable $exception) { throw new RuntimeException('Payment provider unavailable.'); }
    }
}
