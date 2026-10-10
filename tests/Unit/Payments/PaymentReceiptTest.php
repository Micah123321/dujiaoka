<?php

namespace Tests\Unit\Payments;

use App\Payments\PaymentReceipt;
use PHPUnit\Framework\TestCase;

class PaymentReceiptTest extends TestCase
{
    public function testSinglePaypalSaleMustMatchPaymentOrderAndMoney(): void
    {
        $transaction = ['invoice_number' => 'order-1', 'amount' => ['currency' => 'USD', 'total' => '14.90'],
            'related_resources' => [['sale' => ['id' => 'sale-1', 'state' => 'completed', 'parent_payment' => 'pay-1',
                'amount' => ['currency' => 'USD', 'total' => '14.90']]]]];
        $payment = ['id' => 'pay-1', 'intent' => 'sale', 'state' => 'approved', 'transactions' => [$transaction]];
        $this->assertSame('sale-1', PaymentReceipt::paypalSaleId($payment, 'pay-1', 'order-1', '14.90'));
        $this->assertNull(PaymentReceipt::paypalSaleId($payment, 'pay-2', 'order-1', '14.90'));
        $this->assertNull(PaymentReceipt::paypalSaleId($payment, 'pay-1', 'order-2', '14.90'));
        $this->assertNull(PaymentReceipt::paypalSaleId($payment, 'pay-1', 'order-1', '14.91'));
        $payment['transactions'][] = $transaction;
        $this->assertFalse(PaymentReceipt::paypalOrderMatches($payment, 'pay-1', 'order-1', '14.90'));
        $payment['transactions'] = [['amount' => 'bad']];
        $this->assertNull(PaymentReceipt::paypalSaleId($payment, 'pay-1', 'order-1', '14.90'));
    }

    public function testStripeChargeMustUseTheExpectedSourceAndCapturedMoney(): void
    {
        $charge = ['id' => 'ch-1', 'status' => 'succeeded', 'paid' => true, 'captured' => true,
            'refunded' => false, 'amount_refunded' => 0, 'amount' => 1490, 'currency' => 'usd', 'source' => ['id' => 'src-1']];
        $this->assertSame('ch-1', PaymentReceipt::stripeChargeId($charge, 'usd', '1490', 'src-1'));
        $this->assertNull(PaymentReceipt::stripeChargeId($charge, 'usd', '1490', 'src-2'));
        $this->assertNull(PaymentReceipt::stripeChargeId($charge, 'cny', '1490', 'src-1'));
        $charge['source'] = 'src-1';
        $this->assertNull(PaymentReceipt::stripeChargeId($charge, 'usd', '1490', 'src-1'));
        $charge['paid'] = 1;
        $this->assertNull(PaymentReceipt::stripeChargeId($charge, 'usd', '1490'));
    }

    /** @dataProvider malformedCents */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedCents')]
    public function testMinorAmountsRejectMalformedOrRoundedInput($amount): void
    {
        $this->assertFalse(PaymentReceipt::minorMatches($amount, 'usd', 'usd', '1490'));
    }

    public static function malformedCents(): array
    {
        return [[1490.0], [1490.5], [true], [null], [[]], ['1.490e3'], ['1490.0'], ['+1490'], [' 1490'], ['-1490']];
    }
}
