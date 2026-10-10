<?php

namespace Tests\Unit\Payments;

use App\Payments\CurrencyRateClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CurrencyRateClientTest extends TestCase
{
    private function payload($source, $rate = '0.14928', $date = '2026-10-09')
    {
        if ($source === 'frankfurter') {
            return '{"date":"' . $date . '","base":"CNY","quote":"USD","rate":' . $rate . '}';
        }
        $parts = explode('-', $date);
        return json_encode(['body' => ['data' => [[
            'ccyNbr' => "\u{7f8e}\u{5143}",
            'rtcOfr' => $rate,
            'ratDat' => $parts[0] . "\u{5e74}" . $parts[1] . "\u{6708}" . $parts[2] . "\u{65e5}",
            'ratTim' => '16:14:45',
        ]]]], JSON_UNESCAPED_UNICODE);
    }

    private function client($response, &$history)
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($history));
        return new CurrencyRateClient(new Client(['handler' => $stack]));
    }

    public function testRealPayloadsAndHttpOptions()
    {
        foreach (['frankfurter', 'cmb'] as $source) {
            $history = [];
            $rate = $source === 'cmb' ? '671.050' : '0.14928';
            $client = $this->client(new Response(200, [], $this->payload($source, $rate)), $history);
            $this->assertSame([
                'source' => $source,
                'numerator' => $source === 'cmb' ? '100' : $rate,
                'denominator' => $source === 'cmb' ? $rate : '1',
                'date' => '2026-10-09',
            ], $client->fetch($source, strtotime('2026-10-09 12:00:00 UTC')));
            $this->assertCount(1, $history);
            $this->assertSame('GET', $history[0]['request']->getMethod());
            $this->assertSame($source === 'cmb' ? 'https://m.cmbchina.com/api/rate/fx-rate' : 'https://api.frankfurter.dev/v2/rate/CNY/USD', (string) $history[0]['request']->getUri());
            foreach (['verify' => true, 'allow_redirects' => false, 'connect_timeout' => 3, 'timeout' => 5, 'http_errors' => false] as $key => $value) {
                $this->assertSame($value, $history[0]['options'][$key]);
            }
        }
    }

    public function testDecimalPrecisionIsPreserved()
    {
        foreach (['0.000000000001', '99999999.123456789012', '1', '0.149280000000'] as $rate) {
            $history = [];
            $client = $this->client(new Response(200, [], $this->payload('frankfurter', $rate)), $history);
            $this->assertSame($rate, $client->fetch('frankfurter', strtotime('2026-10-09 UTC'))['numerator']);
        }
    }

    private function assertUnavailable($response, $source = 'frankfurter', $now = null, $age = 7)
    {
        $history = [];
        $client = $this->client($response, $history);
        try {
            $client->fetch($source, $now ?? strtotime('2026-10-09 12:00:00 UTC'), $age);
            $this->fail('Expected unavailable rate.');
        } catch (RuntimeException $error) {
            $this->assertSame(RuntimeException::class, get_class($error));
            $this->assertSame('Currency rate unavailable.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        if ($source === 'unknown') {
            $this->assertCount(0, $history);
        }
    }

    public function testInvalidRates()
    {
        foreach (['0', '-1', '0.000', '100000000', '0.1234567890123', '1e-3', 'null', 'true', '[]', '{}', '"NaN"', '" 1"', '"1 "', '"1\n"'] as $rate) {
            $this->assertUnavailable(new Response(200, [], $this->payload('frankfurter', $rate)));
        }
        foreach (['0', '-1', '', 'NaN', '1e2', '0.1234567890123'] as $rate) {
            $this->assertUnavailable(new Response(200, [], $this->payload('cmb', $rate)), 'cmb');
        }
    }

    public function testBadPayloadsAndTransportErrors()
    {
        foreach (['not-json SECRET', '{}', 'null', '[]', '{"base":"USD","quote":"CNY","rate":1,"date":"2026-10-09"}', '{"base":"CNY","quote":"USD","date":"2026-10-09"}'] as $body) {
            $this->assertUnavailable(new Response(200, [], $body));
        }
        foreach ([199, 204, 301, 400, 429, 500] as $status) {
            $this->assertUnavailable(new Response($status, [], $status === 204 ? '' : $this->payload('frankfurter')));
        }
        $this->assertUnavailable(new ConnectException('SECRET URL timeout', new Request('GET', 'https://secret.invalid')));
        $this->assertUnavailable(new Response(200), 'unknown');
        $row = json_decode($this->payload('cmb', '671.050'), true)['body']['data'][0];
        foreach ([[], [$row, $row], [['ccyNbr' => 'USD']], [['ccyNbr' => "\u{7f8e}\u{5143}"]]] as $rows) {
            $this->assertUnavailable(new Response(200, [], json_encode(['body' => ['data' => $rows]])), 'cmb');
        }
        $this->assertUnavailable(new Response(200, [], '{"body":{"data":{}}}'), 'cmb');
    }

    public function testDatesAndClampedAgeBoundaries()
    {
        foreach (['frankfurter', 'cmb'] as $source) {
            $rate = $source === 'cmb' ? '671.050' : '0.14928';
            foreach (['2026-10-10', '2026-10-01', '2026-02-30', '2026-13-01', '2026-1-09'] as $date) {
                $this->assertUnavailable(new Response(200, [], $this->payload($source, $rate, $date)), $source);
            }
            foreach ([[7, '2026-10-02'], [0, '2026-10-08'], [99, '2026-09-25']] as $case) {
                $history = [];
                $client = $this->client(new Response(200, [], $this->payload($source, $rate, $case[1])), $history);
                $this->assertSame($case[1], $client->fetch($source, strtotime('2026-10-09 12:00:00 UTC'), $case[0])['date']);
            }
            foreach ([[0, '2026-10-07'], [99, '2026-09-24']] as $case) {
                $this->assertUnavailable(new Response(200, [], $this->payload($source, $rate, $case[1])), $source, null, $case[0]);
            }
        }
        $now = strtotime('2026-10-08 16:00:00 UTC');
        $history = [];
        $client = $this->client(new Response(200, [], $this->payload('cmb', '671.050')), $history);
        $this->assertSame('2026-10-09', $client->fetch('cmb', $now)['date']);
        $this->assertUnavailable(new Response(200, [], $this->payload('frankfurter')), 'frankfurter', $now);
        $this->assertUnavailable(new Response(200, [], $this->payload('cmb', '671.050')), 'cmb', $now - 1);
    }
}
