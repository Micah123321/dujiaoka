<?php

namespace App\Service;

use App\Exceptions\RuleValidationException;
use App\Models\Order;
use App\Models\Pay;
use App\Models\StripePaymentIntent;
use App\Payments\CurrencyAmount;
use App\Payments\PaymentReceipt;
use App\Payments\StripeIntentClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class StripePaymentIntentService
{
    private $quotes;
    private $stripe;

    public function __construct(PaymentQuoteService $quotes, StripeIntentClient $stripe)
    {
        $this->quotes = $quotes;
        $this->stripe = $stripe;
    }

    public function enabled(): bool
    {
        return (bool) config('dujiaoka.stripe_payment_intents', false);
    }

    public function selectGateway(Order $snapshot, Pay $gate): Order
    {
        return $this->guard(function () use ($snapshot, $gate) {
            if (!$this->enabled()) {
                $snapshot->pay_id = $gate->id;
                $snapshot->save();
                return $snapshot->fresh();
            }
            return DB::transaction(function () use ($snapshot, $gate) {
                $order = Order::whereKey($snapshot->id)->lockForUpdate()->first();
                $pay = Pay::whereKey($gate->id)->lockForUpdate()->first();
                if (!$order || !$pay || (int) $pay->is_open !== Pay::STATUS_OPEN
                    || (string) $order->order_sn !== (string) $snapshot->order_sn
                    || (int) $order->status !== Order::STATUS_WAIT_PAY) {
                    $this->fail();
                }
                $row = StripePaymentIntent::where('order_id', $order->id)->lockForUpdate()->first();
                if ($row && (string) $row->pay_id !== (string) $pay->id) {
                    $this->fail('payment_method_locked');
                }
                app(PaymentGatewayLock::class)->assertBoundGateway($order, $pay);
                $order->pay_id = $pay->id;
                $order->save();
                return $order->fresh();
            });
        });
    }

    public function begin(Order $order, Pay $pay, string $currency): array
    {
        $order = clone $order;
        $pay = clone $pay;
        return $this->guard(function () use ($order, $pay, $currency) {
            $this->available();
            if (!$this->quotes->enabled()) {
                $this->fail('payment_intent_unavailable');
            }
            if (!in_array($currency, ['usd', 'cny'], true)) {
                $this->fail();
            }
            // Freeze FX outside every order/intent transaction, including caller transactions.
            $amount = $currency === 'usd' ? $this->quotes->cnyToUsd($order, $pay, 'cmb') : $order->actual_price;
            $minor = bcmul(CurrencyAmount::cny($amount), '100', 0);
            $row = DB::transaction(function () use ($order, $pay, $currency, $minor) {
                $this->checkOrder($order, $pay, true);
                $row = StripePaymentIntent::where('order_id', $order->id)->lockForUpdate()->first();
                if (!$row) {
                    $row = StripePaymentIntent::create([
                        'order_id' => $order->id, 'pay_id' => $pay->id,
                        'cny_amount' => CurrencyAmount::cny($order->actual_price),
                        'currency' => $currency, 'amount_minor' => $minor,
                        'reference' => bin2hex(random_bytes(16)),
                        'account_fingerprint' => $this->fingerprint($pay),
                    ]);
                }
                $this->checkRow($row, $order, $pay);
                if ($row->currency !== $currency || (string) $row->amount_minor !== $minor) {
                    $this->fail();
                }
                return $row;
            });
            $this->outsideTransaction();
            if ($row->provider_id === null) {
                $age = Carbon::now()->getTimestamp() - $row->created_at->getTimestamp();
                if ($age < 0 || $age >= 23 * 3600) {
                    $this->fail('payment_intent_unavailable');
                }
                $intent = $this->stripe->create((string) $pay->merchant_pem, $this->params($row, $order), $row->reference);
            } else {
                $intent = $this->stripe->retrieve((string) $pay->merchant_pem, $row->provider_id);
            }
            $this->validateIntent($intent, $row, $order, $pay);
            if (!is_string($intent['client_secret'] ?? null)
                || strpos($intent['client_secret'], $intent['id'] . '_secret_') !== 0
                || strlen($intent['client_secret']) <= strlen($intent['id'] . '_secret_')) {
                $this->fail();
            }
            DB::transaction(function () use ($order, $pay, $row, $intent) {
                $this->checkOrder($order, $pay, true);
                $current = $this->reloadRow($row, $order, $pay);
                if ($current->provider_id !== null && $current->provider_id !== $intent['id']) {
                    $this->fail();
                }
                if ($current->provider_id === null) {
                    $current->provider_id = $intent['id'];
                    $current->save();
                }
            });
            return ['intent_id' => $intent['id'], 'client_secret' => $intent['client_secret'],
                'currency' => $row->currency, 'amount' => (string) $row->amount_minor, 'status' => $intent['status']];
        });
    }

    public function sync(Order $order, Pay $pay): array
    {
        $order = clone $order;
        $pay = clone $pay;
        return $this->guard(function () use ($order, $pay) {
            $this->available();
            $row = DB::transaction(function () use ($order, $pay) {
                $this->checkOrder($order, $pay, false);
                $row = StripePaymentIntent::where('order_id', $order->id)->lockForUpdate()->first();
                if (!$row || $row->provider_id === null) {
                    $this->fail();
                }
                $this->checkRow($row, $order, $pay);
                return $row;
            });
            $expected = $row->currency === 'usd' ? $this->quotes->frozenUsd($order, $pay, 'cmb') : $order->actual_price;
            if ((string) $row->amount_minor !== bcmul(CurrencyAmount::cny($expected), '100', 0)) {
                $this->fail();
            }
            $this->outsideTransaction();
            $intent = $this->stripe->retrieve((string) $pay->merchant_pem, $row->provider_id);
            $this->validateIntent($intent, $row, $order, $pay);
            $chargeId = null;
            if ($intent['status'] === 'succeeded') {
                $charge = $intent['latest_charge'] ?? null;
                if (!PaymentReceipt::minorMatches($intent['amount_received'] ?? null, $intent['currency'], $row->currency, $row->amount_minor)
                    || !is_array($charge) || ($charge['object'] ?? null) !== 'charge'
                    || ($charge['payment_intent'] ?? null) !== $row->provider_id) {
                    $this->fail();
                }
                $chargeId = PaymentReceipt::stripeChargeId($charge, $row->currency, $row->amount_minor);
                if ($chargeId === null || !preg_match('/\Ach_[a-zA-Z0-9_]{1,252}\z/', $chargeId)) {
                    $this->fail();
                }
            }
            $fresh = DB::transaction(function () use ($order, $pay, $row) {
                $fresh = $this->checkOrder($order, $pay, false);
                $this->reloadRow($row, $order, $pay);
                return $fresh;
            });
            // Repeat the decisive check inside fulfillment; these binding locks last until commit.
            if ($chargeId !== null) {
                $fresh = app(OrderProcessService::class)->completedOrder($order->order_sn, (float) $row->cny_amount, $chargeId,
                    function (Order $locked) use ($order, $pay, $row) {
                        $this->checkOrder($order, $pay, false);
                        $this->reloadRow($row, $order, $pay);
                    });
            }
            return ['status' => $intent['status'], 'order_status' => (int) $fresh->status];
        });
    }

    public function recoverProvider(Pay $pay, string $providerId): ?StripePaymentIntent
    {
        $pay = clone $pay;
        return $this->guard(function () use ($pay, $providerId) {
            $this->available();
            if (!$this->intentId($providerId)) {
                $this->fail();
            }
            $known = StripePaymentIntent::where('pay_id', $pay->id)->where('provider_id', $providerId)->first();
            if ($known) {
                return $known;
            }
            // A signed event only names a candidate. Trust the server object's immutable binding.
            $intent = $this->stripe->retrieve((string) $pay->merchant_pem, $providerId);
            $reference = $intent['metadata']['binding_reference'] ?? null;
            if (!is_string($reference) || !preg_match('/\A[a-f0-9]{32}\z/', $reference)) {
                return null;
            }
            $row = StripePaymentIntent::where('pay_id', $pay->id)->where('reference', $reference)->first();
            if (!$row) {
                return null;
            }
            $order = Order::find($row->order_id);
            if (!$order || ($intent['id'] ?? null) !== $providerId) {
                $this->fail();
            }
            $this->checkRow($row, $order, $pay);
            $this->validateIntent($intent, $row, $order, $pay);
            $expected = $row->currency === 'usd' ? $this->quotes->frozenUsd($order, $pay, 'cmb') : $order->actual_price;
            if ((string) $row->amount_minor !== bcmul(CurrencyAmount::cny($expected), '100', 0)) {
                $this->fail();
            }
            return DB::transaction(function () use ($order, $pay, $row, $providerId) {
                $this->checkOrder($order, $pay, false);
                $current = $this->reloadRow($row, $order, $pay);
                if ($current->provider_id !== null && $current->provider_id !== $providerId) {
                    $this->fail();
                }
                $current->provider_id = $providerId;
                $current->save();
                return $current;
            });
        });
    }

    private function available(): void
    {
        if (!$this->enabled()) {
            $this->fail('payment_intent_unavailable');
        }
        $this->outsideTransaction();
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            $this->fail('payment_intent_unavailable');
        }
    }

    private function checkOrder(Order $snapshot, Pay $gateway, bool $waiting): Order
    {
        $order = Order::whereKey($snapshot->id)->lockForUpdate()->first();
        $pay = Pay::whereKey($gateway->id)->lockForUpdate()->first();
        if (!$order || !$pay || (string) $order->order_sn !== (string) $snapshot->order_sn
            || (string) $order->pay_id !== (string) $gateway->id
            || (string) $snapshot->pay_id !== (string) $gateway->id
            || ($waiting && ((int) $pay->is_open !== Pay::STATUS_OPEN || (int) $gateway->is_open !== Pay::STATUS_OPEN))
            || trim((string) $pay->pay_handleroute, '/') !== 'pay/stripe'
            || trim((string) $gateway->pay_handleroute, '/') !== 'pay/stripe'
            || $this->fingerprint($pay) !== $this->fingerprint($gateway)
            || CurrencyAmount::cny($order->actual_price) !== CurrencyAmount::cny($snapshot->actual_price)
            || ($waiting && (int) $order->status !== Order::STATUS_WAIT_PAY)) {
            $this->fail();
        }
        $this->liveMode($pay);
        app(PaymentGatewayLock::class)->assertBoundGateway($order, $pay);
        return $order;
    }

    private function fingerprint(Pay $pay): string
    {
        return hash('sha256', (string) $pay->merchant_id . "\0" . (string) $pay->merchant_pem);
    }

    private function liveMode(Pay $pay): bool
    {
        if (!preg_match('/\A(?:sk|rk)_(test|live)_[a-zA-Z0-9_]+\z/', (string) $pay->merchant_pem, $matches)
            || !preg_match('/\Apk_' . $matches[1] . '_[a-zA-Z0-9_]+\z/', (string) $pay->merchant_id)) {
            $this->fail();
        }
        return $matches[1] === 'live';
    }

    private function checkRow(StripePaymentIntent $row, Order $order, Pay $pay): void
    {
        if ((string) $row->order_id !== (string) $order->id || (string) $row->pay_id !== (string) $pay->id
            || CurrencyAmount::cny($row->cny_amount) !== CurrencyAmount::cny($order->actual_price)
            || !in_array($row->currency, ['usd', 'cny'], true)
            || !preg_match('/\A[1-9][0-9]{0,11}\z/', (string) $row->amount_minor)
            || !preg_match('/\A[a-f0-9]{32}\z/', (string) $row->reference)
            || $row->account_fingerprint !== $this->fingerprint($pay) || !$row->created_at
            || ($row->provider_id !== null && !$this->intentId($row->provider_id))) {
            $this->fail();
        }
    }

    private function reloadRow(StripePaymentIntent $before, Order $order, Pay $pay): StripePaymentIntent
    {
        $row = StripePaymentIntent::where('order_id', $order->id)->lockForUpdate()->first();
        if (!$row) {
            $this->fail();
        }
        $this->checkRow($row, $order, $pay);
        foreach (['id', 'order_id', 'pay_id', 'cny_amount', 'currency', 'amount_minor', 'reference', 'account_fingerprint', 'created_at'] as $field) {
            if ((string) $row->$field !== (string) $before->$field) {
                $this->fail();
            }
        }
        if ($before->provider_id !== null && $row->provider_id !== $before->provider_id) {
            $this->fail();
        }
        return $row;
    }

    private function params(StripePaymentIntent $row, Order $order): array
    {
        $params = ['amount' => (string) $row->amount_minor, 'currency' => $row->currency, 'confirm' => false,
            'capture_method' => 'automatic', 'confirmation_method' => 'automatic',
            'payment_method_types' => $row->currency === 'usd' ? ['card', 'wechat_pay'] : ['alipay'],
            'metadata' => ['order_sn' => (string) $order->order_sn, 'binding_reference' => $row->reference]];
        if ($row->currency === 'usd') {
            $params['payment_method_options'] = ['wechat_pay' => ['client' => 'web']];
        }
        return $params;
    }

    private function intentId($id): bool
    {
        return is_string($id) && preg_match('/\Api_[a-zA-Z0-9_]{1,252}\z/', $id) === 1;
    }

    private function validateIntent(array $intent, StripePaymentIntent $row, Order $order, Pay $pay): void
    {
        $methods = $intent['payment_method_types'] ?? null;
        $expected = $this->params($row, $order);
        if (is_array($methods)) {
            sort($methods);
        }
        $expectedMethods = $expected['payment_method_types'];
        sort($expectedMethods);
        if (!$this->intentId($intent['id'] ?? null)
            || ($row->provider_id !== null && $row->provider_id !== $intent['id'])
            || ($intent['object'] ?? null) !== 'payment_intent'
            || ($intent['capture_method'] ?? null) !== 'automatic'
            || ($intent['confirmation_method'] ?? null) !== 'automatic'
            || $methods !== $expectedMethods || ($intent['livemode'] ?? null) !== $this->liveMode($pay)
            || !PaymentReceipt::minorMatches($intent['amount'] ?? null, $intent['currency'] ?? null, $row->currency, $row->amount_minor)
            || !is_array($intent['metadata'] ?? null)
            || ($intent['metadata']['order_sn'] ?? null) !== (string) $order->order_sn
            || ($intent['metadata']['binding_reference'] ?? null) !== $row->reference
            || !in_array($intent['status'] ?? null, ['requires_payment_method', 'requires_confirmation', 'requires_action',
                'processing', 'requires_capture', 'canceled', 'succeeded'], true)) {
            $this->fail();
        }
    }

    private function fail(string $key = 'payment_intent_invalid'): void
    {
        throw new RuleValidationException(__('dujiaoka.prompt.' . $key));
    }

    private function guard(callable $callback)
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            // Never forward SDK bodies, SQL text, credentials, or exception chains.
            if ($exception instanceof RuleValidationException) {
                foreach (['payment_intent_invalid', 'payment_intent_unavailable', 'payment_method_locked'] as $key) {
                    if ($exception->getMessage() === __('dujiaoka.prompt.' . $key)) {
                        $this->fail($key);
                    }
                }
            }
            $this->fail('payment_intent_unavailable');
        }
    }
}
