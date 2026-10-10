<?php

namespace Tests\Unit\Payments;

use App\Payments\CurrencyAmount;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CurrencyAmountTest extends TestCase
{
    /** @dataProvider amounts */
    #[\PHPUnit\Framework\Attributes\DataProvider('amounts')]
    public function testKeepsTheRateUntilFinalHalfUpRounding($cny, string $numerator, string $denominator, string $expected): void
    {
        $this->assertSame($expected, CurrencyAmount::toUsd($cny, $numerator, $denominator));
    }

    public static function amounts(): array
    {
        return [
            ['100.00', '0.14928', '1', '14.93'],
            ['100.00', '100', '671.050', '14.90'],
            ['28.88', '100', '671.050', '4.30'],
            ['0.10', '0.15', '1', '0.02'],
            ['0.09', '0.15', '1', '0.01'],
            ['99999999.99', '0.15', '1', '15000000.00'],
            [10, '0.15', '1', '1.50'],
            [10.5, '0.15', '1', '1.58'],
            ['10.00', '1.005', '1', '10.05'],
            ['100.00', '1', '7', '14.29'],
            ['19.99', '0.123456789012', '1', '2.47'],
        ];
    }

    /** @dataProvider invalidAmounts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidAmounts')]
    public function testRejectsInvalidOrderAmountsBeforeCurrencyConversion($amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        CurrencyAmount::cny($amount);
    }

    public static function invalidAmounts(): array
    {
        return array_map(function ($value) { return [$value]; },
            ['0', '-1.00', '1e2', '1.234', ' 1.00', '1.00 ', '100000000', '', 'NaN', INF, NAN, true, null, []]);
    }

    /** @dataProvider invalidRatios */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRatios')]
    public function testRejectsBadQuotesAndBelowCentPayments(string $numerator, string $denominator): void
    {
        $this->expectException(InvalidArgumentException::class);
        CurrencyAmount::toUsd('0.01', $numerator, $denominator);
    }

    public static function invalidRatios(): array
    {
        return [['0', '1'], ['1', '0'], ['-0.15', '1'], ['1e-1', '1'], ['0.1234567890123', '1'], ['0.15', '1']];
    }
}
