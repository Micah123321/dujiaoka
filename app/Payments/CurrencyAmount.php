<?php

namespace App\Payments;

use InvalidArgumentException;

final class CurrencyAmount
{
    public static function cny($amount): string
    {
        if ((!is_string($amount) && !is_int($amount) && !is_float($amount))
            || (is_float($amount) && !is_finite($amount))) {
            throw new InvalidArgumentException('Invalid payment amount.');
        }
        $amount = (string) $amount;
        if (!preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,2})?\z/', $amount) || bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Invalid payment amount.');
        }
        return bcadd($amount, '0', 2);
    }

    public static function positiveDecimal($value): string
    {
        if (!is_string($value) || !preg_match('/\A[0-9]{1,8}(?:\.[0-9]{1,12})?\z/', $value)
            || bccomp($value, '0', 12) <= 0) {
            throw new InvalidArgumentException('Invalid currency quote.');
        }
        return $value;
    }

    public static function toUsd($cny, string $numerator, string $denominator): string
    {
        $amount = self::cny($cny);
        self::positiveDecimal($numerator);
        self::positiveDecimal($denominator);
        // Keep the provider's ratio until the final cent, then round half up once.
        $product = bcmul($amount, $numerator, 14);
        $usd = bcadd(bcdiv($product, $denominator, 3), '0.005', 2);
        if (bccomp($usd, '0.01', 2) < 0 || bccomp($usd, '99999999.99', 2) > 0) {
            throw new InvalidArgumentException('Converted payment amount is out of range.');
        }
        return $usd;
    }
}
