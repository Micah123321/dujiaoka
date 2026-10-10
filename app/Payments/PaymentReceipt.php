<?php

namespace App\Payments;

use Throwable;

class PaymentReceipt
{
    public static function minorMatches($amount, $currency, string $expectedCurrency, string $expectedAmount): bool
    {
        return $currency === $expectedCurrency && (is_int($amount) || is_string($amount))
            && preg_match('/\A[0-9]{1,12}\z/', (string) $amount)
            && bccomp((string) $amount, $expectedAmount, 0) === 0;
    }

    public static function paypalOrderMatches(array $payment, string $id, string $orderSN, string $usd): bool
    {
        $transactions = $payment['transactions'] ?? null;
        if (($payment['id'] ?? null) !== $id || ($payment['intent'] ?? null) !== 'sale'
            || !is_array($transactions) || count($transactions) !== 1 || !is_array($transactions[0])) {
            return false;
        }
        $transaction = $transactions[0];
        return ($transaction['invoice_number'] ?? null) === $orderSN
            && self::usdMatches($transaction['amount'] ?? null, $usd);
    }

    public static function paypalSaleId(array $payment, string $id, string $orderSN, string $usd): ?string
    {
        if (!self::paypalOrderMatches($payment, $id, $orderSN, $usd) || ($payment['state'] ?? null) !== 'approved') {
            return null;
        }
        $resources = $payment['transactions'][0]['related_resources'] ?? null;
        if (!is_array($resources) || count($resources) !== 1 || !is_array($resources[0])) {
            return null;
        }
        $sale = $resources[0]['sale'] ?? null;
        if (!is_array($sale) || ($sale['state'] ?? null) !== 'completed' || ($sale['parent_payment'] ?? null) !== $id
            || !self::usdMatches($sale['amount'] ?? null, $usd) || !self::validId($sale['id'] ?? null)) {
            return null;
        }
        return $sale['id'];
    }

    public static function stripeChargeId(array $charge, string $currency, string $minor, ?string $source = null): ?string
    {
        if (($charge['status'] ?? null) !== 'succeeded' || ($charge['paid'] ?? null) !== true
            || ($charge['captured'] ?? null) !== true || ($charge['refunded'] ?? null) !== false
            || ($charge['amount_refunded'] ?? null) !== 0 || !self::validId($charge['id'] ?? null)
            || !self::minorMatches($charge['amount'] ?? null, $charge['currency'] ?? null, $currency, $minor)) {
            return null;
        }
        if ($source !== null && (!is_array($charge['source'] ?? null) || ($charge['source']['id'] ?? null) !== $source)) {
            return null;
        }
        return $charge['id'];
    }

    private static function usdMatches($amount, string $expected): bool
    {
        if (!is_array($amount) || ($amount['currency'] ?? null) !== 'USD') {
            return false;
        }
        try {
            return CurrencyAmount::cny($amount['total'] ?? null) === $expected;
        } catch (Throwable $exception) {
            return false;
        }
    }

    private static function validId($id): bool
    {
        return is_string($id) && $id !== '' && strlen($id) <= 255;
    }
}
