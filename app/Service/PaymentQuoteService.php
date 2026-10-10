<?php

namespace App\Service;

use App\Exceptions\RuleValidationException;
use App\Models\Order;
use App\Models\Pay;
use App\Models\PaymentQuote;
use App\Payments\CurrencyAmount;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaymentQuoteService
{
    private $rates;

    public function __construct(ExchangeRateService $rates)
    {
        $this->rates = $rates;
    }

    public function enabled(): bool
    {
        return (bool) config('dujiaoka.payment_quote_freeze', false);
    }

    public function cnyToUsd(Order $order, Pay $gateway, string $source): string
    {
        if (!$this->enabled()) {
            return $this->rates->cnyToUsd($order->actual_price, $source);
        }
        $order = clone $order;
        $gateway = clone $gateway;
        $existing = $this->locked(function () use ($order, $gateway, $source) {
            $cny = $this->checkOrder($order, $gateway, $source, true);
            return $this->existing($order, $gateway, $source, $cny);
        });
        if ($existing !== null) {
            return $existing;
        }
        // An outer transaction would retain locks during provider I/O.
        if (DB::transactionLevel() !== 0) {
            throw new RuleValidationException(__('dujiaoka.prompt.payment_quote_unavailable'));
        }
        $quote = $this->rates->quoteCnyToUsd($order->actual_price, $source);

        return $this->locked(function () use ($order, $gateway, $source, $quote) {
            $cny = $this->checkOrder($order, $gateway, $source, true);
            $existing = $this->existing($order, $gateway, $source, $cny);
            if ($existing !== null) {
                return $existing;
            }
            if (!is_array($quote) || !isset($quote['fetched_at']) || !is_int($quote['fetched_at']) || $quote['fetched_at'] < 0) {
                $this->invalid();
            }
            $data = $this->validateQuote($quote, $source, $cny);
            PaymentQuote::create(array_merge($data, [
                'order_id' => $order->id,
                'pay_id' => $gateway->id,
                'base_currency' => 'CNY',
                'currency' => 'USD',
            ]));
            return $data['usd_amount'];
        });
    }

    public function frozenUsd(Order $order, Pay $gateway, string $source): string
    {
        return $this->locked(function () use ($order, $gateway, $source) {
            $cny = $this->checkOrder($order, $gateway, $source, false);
            $existing = $this->existing($order, $gateway, $source, $cny);
            if ($existing === null) {
                $this->invalid();
            }
            return $existing;
        });
    }

    private function locked(callable $callback)
    {
        try {
            return DB::transaction($callback);
        } catch (RuleValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuleValidationException(__('dujiaoka.prompt.payment_quote_unavailable'));
        }
    }

    private function checkOrder(Order $snapshot, Pay $gateway, string $source, bool $waiting): string
    {
        $order = Order::whereKey($snapshot->id)->lockForUpdate()->first();
        $pay = Pay::whereKey($gateway->id)->first();
        $routes = ['frankfurter' => 'pay/paypal', 'cmb' => 'pay/stripe'];
        if (!$order || !$pay || !isset($routes[$source])
            || (string) $order->id !== (string) $snapshot->id
            || (string) $order->order_sn !== (string) $snapshot->order_sn
            || (string) $order->pay_id !== (string) $gateway->id
            || (string) $snapshot->pay_id !== (string) $gateway->id
            || trim((string) $gateway->pay_handleroute, '/') !== $routes[$source]
            || trim((string) $pay->pay_handleroute, '/') !== $routes[$source]
            || ($waiting && (int) $order->status !== Order::STATUS_WAIT_PAY)) {
            $this->invalid();
        }
        try {
            $cny = CurrencyAmount::cny($order->actual_price);
            if ($cny !== CurrencyAmount::cny($snapshot->actual_price)) {
                $this->invalid();
            }
            return $cny;
        } catch (Throwable $exception) {
            $this->invalid();
        }
    }

    private function existing(Order $order, Pay $gateway, string $source, string $cny)
    {
        $row = PaymentQuote::where('order_id', $order->id)->where('pay_id', $gateway->id)
            ->where('currency', 'USD')->lockForUpdate()->first();
        if (!$row) {
            // This service only creates USD quotes; a changed currency is corrupt state.
            if (PaymentQuote::where('order_id', $order->id)->where('pay_id', $gateway->id)->exists()) {
                $this->invalid();
            }
            return null;
        }
        try {
            if ($row->base_currency !== 'CNY' || $row->currency !== 'USD') {
                $this->invalid();
            }
            $data = $row->toArray();
            $data['date'] = $row->rate_date;
            return $this->validateQuote($data, $source, $cny)['usd_amount'];
        } catch (Throwable $exception) {
            $this->invalid();
        }
    }

    private function validateQuote($quote, string $source, string $cny): array
    {
        try {
            if (!is_array($quote) || ($quote['source'] ?? null) !== $source
                || !isset($quote['cny_amount'], $quote['usd_amount'], $quote['numerator'], $quote['denominator'], $quote['date'])
                || !is_string($quote['date'])
                || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $quote['date'])
                || !checkdate((int) substr($quote['date'], 5, 2), (int) substr($quote['date'], 8, 2), (int) substr($quote['date'], 0, 4))
                || CurrencyAmount::cny($quote['cny_amount']) !== $cny) {
                $this->invalid();
            }
            $numerator = CurrencyAmount::positiveDecimal($quote['numerator']);
            $denominator = CurrencyAmount::positiveDecimal($quote['denominator']);
            $usd = CurrencyAmount::toUsd($cny, $numerator, $denominator);
            if (CurrencyAmount::cny($quote['usd_amount']) !== $usd) {
                $this->invalid();
            }
            return [
                'cny_amount' => $cny, 'usd_amount' => $usd, 'source' => $source,
                'numerator' => $numerator, 'denominator' => $denominator, 'rate_date' => $quote['date'],
            ];
        } catch (Throwable $exception) {
            $this->invalid();
        }
    }

    private function invalid(): void
    {
        throw new RuleValidationException(__('dujiaoka.prompt.payment_quote_invalid'));
    }
}
