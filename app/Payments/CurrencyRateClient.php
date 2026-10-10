<?php

namespace App\Payments;

use GuzzleHttp\ClientInterface;
use RuntimeException;
use Throwable;

class CurrencyRateClient
{
    private $http;

    public function __construct(ClientInterface $http)
    {
        $this->http = $http;
    }

    public function fetch(string $source, int $now, int $maxAgeDays = 7): array
    {
        try {
            $urls = [
                'frankfurter' => 'https://api.frankfurter.dev/v2/rate/CNY/USD',
                'cmb' => 'https://m.cmbchina.com/api/rate/fx-rate',
            ];
            if (!isset($urls[$source])) {
                throw new RuntimeException();
            }
            $response = $this->http->request('GET', $urls[$source], [
                'verify' => true,
                'allow_redirects' => false,
                'connect_timeout' => 3,
                'timeout' => 5,
                'http_errors' => false,
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new RuntimeException();
            }
            $raw = (string) $response->getBody();
            json_decode($raw);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException();
            }
            // Preserve numeric tokens without rounding through a binary float.
            $raw = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|(-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/s', function ($match) {
                return isset($match[1]) ? '"' . $match[1] . '"' : $match[0];
            }, $raw);
            $data = json_decode($raw);
            if (!is_object($data)) {
                throw new RuntimeException();
            }
            if ($source === 'frankfurter') {
                if (($data->base ?? null) !== 'CNY' || ($data->quote ?? null) !== 'USD') {
                    throw new RuntimeException();
                }
                $numerator = $this->decimal($data->rate ?? null);
                $denominator = '1';
                $date = $data->date ?? null;
            } else {
                $rows = $data->body->data ?? null;
                if (!is_array($rows)) {
                    throw new RuntimeException();
                }
                $usd = [];
                foreach ($rows as $row) {
                    if (is_object($row) && ($row->ccyNbr ?? null) === "\u{7f8e}\u{5143}") {
                        $usd[] = $row;
                    }
                }
                if (count($usd) !== 1) {
                    throw new RuntimeException();
                }
                $numerator = '100';
                $denominator = $this->decimal($usd[0]->rtcOfr ?? null);
                $date = $usd[0]->ratDat ?? null;
                if (!is_string($date) || !preg_match('/\A([0-9]{4})\x{5e74}([0-9]{2})\x{6708}([0-9]{2})\x{65e5}\z/u', $date, $parts)) {
                    throw new RuntimeException();
                }
                $date = $parts[1] . '-' . $parts[2] . '-' . $parts[3];
            }
            if (!is_string($date) || !preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $parts)
                || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                throw new RuntimeException();
            }
            $maxAgeDays = max(1, min(14, $maxAgeDays));
            $clock = $now + ($source === 'cmb' ? 28800 : 0);
            if ($date > gmdate('Y-m-d', $clock) || $date < gmdate('Y-m-d', $clock - $maxAgeDays * 86400)) {
                throw new RuntimeException();
            }
            return ['source' => $source, 'numerator' => $numerator, 'denominator' => $denominator, 'date' => $date];
        } catch (Throwable $error) {
            throw new RuntimeException('Currency rate unavailable.');
        }
    }

    private function decimal($value): string
    {
        if (!is_string($value) || !preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,12})?\z/', $value)
            || !preg_match('/[1-9]/', $value)) {
            throw new RuntimeException();
        }
        return $value;
    }
}
