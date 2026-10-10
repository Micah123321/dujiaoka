<?php

namespace App\Service;

use App\Exceptions\RuleValidationException;
use App\Models\Order;
use App\Models\Pay;
use Illuminate\Support\Facades\DB;

class PaymentGatewayLock
{
    public function enabled(): bool
    {
        return (bool) config('dujiaoka.stripe_payment_intents', false) || (bool) config('dujiaoka.paypal_orders_v2', false);
    }

    public function selectGateway(Order $snapshot, Pay $gateway): Order
    {
        try {
            return DB::transaction(function () use ($snapshot, $gateway) {
                $order = Order::whereKey($snapshot->id)->lockForUpdate()->first();
                $pay = Pay::whereKey($gateway->id)->lockForUpdate()->first();
                if (!$order || !$pay || (int) $pay->is_open !== Pay::STATUS_OPEN || (int) $order->status !== Order::STATUS_WAIT_PAY
                    || (string) $order->order_sn !== (string) $snapshot->order_sn) {
                    throw new RuleValidationException(__('dujiaoka.prompt.payment_intent_invalid'));
                }
                $this->assertBoundGateway($order, $pay);
                $order->pay_id = $pay->id;
                $order->save();
                return $order->fresh();
            });
        } catch (RuleValidationException $exception) { throw $exception; }
        catch (\Throwable $exception) { throw new RuleValidationException(__('dujiaoka.prompt.payment_intent_unavailable')); }
    }

    public function receiptGuard(Order $snapshot, Pay $gateway, string $route): callable
    {
        return function (Order $locked) use ($snapshot, $gateway, $route) {
            $current = Pay::whereKey($gateway->id)->lockForUpdate()->first();
            if (!$current || (string) $locked->order_sn !== (string) $snapshot->order_sn
                || (string) $locked->pay_id !== (string) $gateway->id
                || bccomp((string) $locked->actual_price, (string) $snapshot->actual_price, 2) !== 0
                || trim((string) $current->pay_handleroute, '/') !== trim($route, '/')
                || (string) $current->pay_check !== (string) $gateway->pay_check) {
                throw new \RuntimeException('Payment binding changed.');
            }
            foreach (['merchant_id', 'merchant_key', 'merchant_pem'] as $field) {
                if ((string) $current->$field !== (string) $gateway->$field) {
                    throw new \RuntimeException('Payment credentials changed.');
                }
            }
            $this->assertBoundGateway($locked, $current);
            return true;
        };
    }

    public function assertBoundGateway(Order $order, Pay $pay): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Payment selection requires an order lock.');
        foreach (['stripe_payment_intents' => 'stripe_payment_intents', 'paypal_orders_v2' => 'paypal_orders'] as $flag => $table) {
            if (!config('dujiaoka.' . $flag, false)) continue;
            // The held order row serializes binding creation. Lock existing primary keys only:
            // a missing unique-key locking read takes gap locks across unrelated orders in MySQL RR.
            $id = DB::table($table)->where('order_id', $order->id)->value('id');
            if ($id === null) continue;
            $bound = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            if (!$bound || (string) $bound->order_id !== (string) $order->id) {
                throw new RuleValidationException(__('dujiaoka.prompt.payment_intent_unavailable'));
            }
            if ($bound && (string) $bound->pay_id !== (string) $pay->id) {
                throw new RuleValidationException(__('dujiaoka.prompt.payment_method_locked'));
            }
        }
    }
}
