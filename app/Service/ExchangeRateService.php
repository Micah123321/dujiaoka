<?php

namespace App\Service;

use App\Exceptions\RuleValidationException;
use App\Payments\CurrencyAmount;
use App\Payments\CurrencyRateClient;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

class ExchangeRateService
{
    private $rates;
    private $cache;

    public function __construct(CurrencyRateClient $rates, Repository $cache)
    {
        $this->rates = $rates;
        $this->cache = $cache;
    }

    public function cnyToUsd($amount, string $source = 'frankfurter'): string
    {
        return $this->quoteCnyToUsd($amount, $source)['usd_amount'];
    }

    public function quoteCnyToUsd($amount, string $source = 'frankfurter'): array
    {
        try {
            $cny = CurrencyAmount::cny($amount);
        } catch (Throwable $exception) {
            throw new RuleValidationException(__('dujiaoka.prompt.currency_amount_invalid'));
        }
        if (!in_array($source, ['frankfurter', 'cmb'], true)) {
            throw new RuleValidationException(__('dujiaoka.prompt.currency_rate_unavailable'));
        }
        $now = Carbon::now()->getTimestamp();
        $ttl = min(3600, max(0, (int) config('dujiaoka.currency_quote_cache_seconds', 300)));
        $key = 'currency-quote:v1:CNY:USD:' . $source;
        $quote = null;
        if ($ttl > 0) {
            try { $quote = $this->cache->get($key); } catch (Throwable $ignored) { /* Fetch without cache. */ }
        }
        if (!$this->validCachedQuote($quote, $source, $now, $ttl)) {
            try {
                $quote = $this->rates->fetch($source, $now, 7);
                $quote['fetched_at'] = $now;
                if (!$this->validCachedQuote($quote, $source, $now, max(1, $ttl))) {
                    throw new \RuntimeException('Invalid currency quote.');
                }
            } catch (Throwable $exception) {
                // Never expose provider bodies, URLs or exception chains in checkout.
                throw new RuleValidationException(__('dujiaoka.prompt.currency_rate_unavailable'));
            }
            if ($ttl > 0) {
                try { $this->cache->put($key, $quote, $ttl); } catch (Throwable $ignored) { /* Use the validated quote. */ }
            }
        }
        try {
            $quote['cny_amount'] = $cny;
            $quote['usd_amount'] = CurrencyAmount::toUsd($cny, $quote['numerator'], $quote['denominator']);
            return $quote;
        } catch (Throwable $exception) {
            throw new RuleValidationException(__('dujiaoka.prompt.currency_amount_invalid'));
        }
    }

    private function validCachedQuote($quote, string $source, int $now, int $ttl): bool
    {
        if (!is_array($quote) || ($quote['source'] ?? null) !== $source || !isset($quote['fetched_at'])
            || !is_int($quote['fetched_at']) || $quote['fetched_at'] > $now
            || $now - $quote['fetched_at'] >= $ttl || !isset($quote['date']) || !is_string($quote['date'])) {
            return false;
        }
        $localNow = $source === 'cmb' ? $now + 8 * 3600 : $now;
        $date = $quote['date'];
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)
            || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))
            || $date > gmdate('Y-m-d', $localNow) || $date < gmdate('Y-m-d', $localNow - 7 * 86400)) {
            return false;
        }
        try {
            CurrencyAmount::positiveDecimal($quote['numerator'] ?? null);
            CurrencyAmount::positiveDecimal($quote['denominator'] ?? null);
        } catch (Throwable $ignored) {
            return false;
        }
        return true;
    }
}
