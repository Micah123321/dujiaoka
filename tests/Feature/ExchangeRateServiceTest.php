<?php

namespace Tests\Feature;

use App\Exceptions\RuleValidationException;
use App\Payments\CurrencyRateClient;
use App\Service\ExchangeRateService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    private $history = [];
    private const CACHE_KEY = 'currency-quote:v1:CNY:USD:frankfurter';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
        config(['dujiaoka.currency_quote_cache_seconds' => 300]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function frank(string $rate = '0.14928'): Response
    {
        return new Response(200, [], '{"date":"2026-10-09","base":"CNY","quote":"USD","rate":' . $rate . '}');
    }

    private function network(array $responses): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);
        $this->app->instance(Client::class, $client);
        return $client;
    }

    public function testCachesOnlyTheQuoteAndConvertsEachAmountIndependently(): void
    {
        $this->network([$this->frank()]);
        $rates = app(ExchangeRateService::class);
        $this->assertSame('14.93', $rates->cnyToUsd('100.00'));
        $this->assertSame('29.86', $rates->cnyToUsd('200.00'));
        $this->assertCount(1, $this->history);
        $this->assertSame('0.14928', Cache::get(self::CACHE_KEY)['numerator']);
    }

    public function testCachesProvidersSeparately(): void
    {
        $bank = ['body' => ['data' => [['ccyNbr' => "\u{7f8e}\u{5143}", 'rtcOfr' => '671.050',
            'ratDat' => "2026\u{5e74}10\u{6708}09\u{65e5}"]]]];
        $this->network([$this->frank(), new Response(200, [], json_encode($bank))]);
        $rates = app(ExchangeRateService::class);
        $this->assertSame('14.93', $rates->cnyToUsd('100.00'));
        $this->assertSame('14.90', $rates->cnyToUsd('100.00', 'cmb'));
        $this->assertSame('29.80', $rates->cnyToUsd('200.00', 'cmb'));
        $this->assertCount(2, $this->history);
    }

    public function testExpiredCachedQuoteFetchesAgain(): void
    {
        $this->network([$this->frank(), $this->frank('0.15')]);
        $rates = app(ExchangeRateService::class);
        $this->assertSame('14.93', $rates->cnyToUsd('100.00'));
        Carbon::setTestNow(Carbon::now()->addSeconds(300));
        $this->assertSame('15.00', $rates->cnyToUsd('100.00'));
        $this->assertCount(2, $this->history);
    }

    public function testReducedTtlAppliesToAnExistingQuote(): void
    {
        $this->network([$this->frank(), $this->frank('0.15')]);
        $rates = app(ExchangeRateService::class);
        $rates->cnyToUsd('100.00');
        config(['dujiaoka.currency_quote_cache_seconds' => 30]);
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->assertSame('15.00', $rates->cnyToUsd('100.00'));
        $this->assertCount(2, $this->history);
    }

    public function testZeroTtlDisablesQuoteCaching(): void
    {
        config(['dujiaoka.currency_quote_cache_seconds' => 0]);
        $this->network([$this->frank(), $this->frank('0.15')]);
        $rates = app(ExchangeRateService::class);
        $this->assertSame('14.93', $rates->cnyToUsd('100.00'));
        $this->assertSame('15.00', $rates->cnyToUsd('100.00'));
        $this->assertNull(Cache::get(self::CACHE_KEY));
        $this->assertCount(2, $this->history);
    }

    public function testFailureIsNotCachedAndDoesNotExposeProviderContent(): void
    {
        $this->network([new Response(503, [], 'private-response-token'), $this->frank()]);
        $rates = app(ExchangeRateService::class);
        try {
            $rates->cnyToUsd('100.00');
            $this->fail('Expected a public currency failure.');
        } catch (RuleValidationException $exception) {
            $this->assertSame(__('dujiaoka.prompt.currency_rate_unavailable'), $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertNull(Cache::get(self::CACHE_KEY));
        $this->assertSame('14.93', $rates->cnyToUsd('100.00'));
        $this->assertSame('29.86', $rates->cnyToUsd('200.00'));
        $this->assertCount(2, $this->history);
    }

    public function testProviderOutageAfterExpiryNeverUsesAStaleQuote(): void
    {
        $this->network([$this->frank(), new Response(503)]);
        $rates = app(ExchangeRateService::class);
        $rates->cnyToUsd('100.00');
        Carbon::setTestNow(Carbon::now()->addSeconds(301));
        $this->expectException(RuleValidationException::class);
        $this->expectExceptionMessage(__('dujiaoka.prompt.currency_rate_unavailable'));
        $rates->cnyToUsd('100.00');
    }

    public function testInvalidCachedQuoteIsReplacedFromTheProvider(): void
    {
        Cache::forever(self::CACHE_KEY, ['source' => 'frankfurter', 'numerator' => '0', 'denominator' => '1',
            'date' => '2026-10-09', 'fetched_at' => Carbon::now()->timestamp]);
        $this->network([$this->frank()]);
        $this->assertSame('14.93', app(ExchangeRateService::class)->cnyToUsd('100.00'));
        $this->assertCount(1, $this->history);
    }

    public function testInvalidOrderAmountNeverFetchesAQuote(): void
    {
        $this->network([]);
        try {
            app(ExchangeRateService::class)->cnyToUsd('1.234');
            $this->fail('Expected invalid amount.');
        } catch (RuleValidationException $exception) {
            $this->assertSame(__('dujiaoka.prompt.currency_amount_invalid'), $exception->getMessage());
        }
        $this->assertCount(0, $this->history);
    }

    public function testCacheOutageStillAllowsAValidatedLiveQuote(): void
    {
        $client = $this->network([$this->frank()]);
        $cache = Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $cache->shouldReceive('get')->once()->andThrow(new \RuntimeException('cache-private-error'));
        $cache->shouldReceive('put')->once()->andThrow(new \RuntimeException('cache-private-error'));
        $service = new ExchangeRateService(new CurrencyRateClient($client), $cache);
        $this->assertSame('14.93', $service->cnyToUsd('100.00'));
    }
}
