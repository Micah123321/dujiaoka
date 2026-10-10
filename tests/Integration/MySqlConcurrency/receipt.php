<?php

use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use App\Service\PaymentGatewayLock;
use Illuminate\Support\Facades\DB;

function fixtureReceiptSnapshot(array $task): array
{
    return [Order::where('order_sn', $task['sn'])->firstOrFail(), Pay::findOrFail($task['pay_id'])];
}

function fixtureReceiptWorker(array $task, array $snapshot, FixtureOrderProcess $process): array
{
    config(['dujiaoka.stripe_payment_intents' => true, 'dujiaoka.paypal_orders_v2' => true]);
    [$order, $pay] = $snapshot;
    $guard = (new PaymentGatewayLock())->receiptGuard($order, $pay, '/pay/bepusdt');
    try {
        $completed = $process->completedOrder($task['sn'], (float) $order->actual_price, $task['trade'], $guard);
        return ['category' => 'receipt', 'outcome' => 'accepted', 'status' => (int) $completed->status];
    } catch (App\Exceptions\RuleValidationException $exception) {
        // Unexpected database errors must fail the harness rather than count as a correct rejection.
        fixtureCheck(in_array($exception->getMessage(), ['Payment binding changed.', 'Payment credentials changed.',
            'Payment transaction does not match.', 'dujiaoka.prompt.payment_method_locked'], true), 'unexpected-receipt-error');
        return ['category' => 'receipt', 'outcome' => 'rejected'];
    }
}

function fixtureReceiptRaces(string $root): array
{
    DB::table('pays')->insert(['id' => 5, 'pay_check' => 'usdt.trc20', 'pay_handleroute' => 'pay/bepusdt',
        'merchant_id' => 'fixture-receipt-id', 'merchant_key' => 'fixture-receipt-key', 'merchant_pem' => 'https://fixture.invalid']);
    $goods = 20; $groups = 0;
    $cases = ['same', 'closed', 'expired', 'method', 'route', 'merchant_id', 'merchant_key', 'merchant_pem',
        'price', 'gateway', 'deleted', 'stripe-binding', 'paypal-binding', 'different-trade', 'zero-trade'];
    foreach ($cases as $case) {
        $sn = 'receipt-' . $case; fixtureSeed($goods, [$sn]);
        DB::table('orders')->where('order_sn', $sn)->update(['pay_id' => 5]);
        DB::table('pays')->where('id', 5)->update(['pay_check' => 'usdt.trc20', 'pay_handleroute' => 'pay/bepusdt',
            'merchant_id' => 'fixture-receipt-id', 'merchant_key' => 'fixture-receipt-key', 'merchant_pem' => 'https://fixture.invalid',
            'is_open' => 1, 'deleted_at' => null]);
        $id = (int) DB::table('orders')->where('order_sn', $sn)->value('id');
        if ($case === 'closed') DB::table('pays')->where('id', 5)->update(['is_open' => 0]);
        if ($case === 'expired') DB::table('orders')->where('id', $id)->update(['status' => Order::STATUS_EXPIRED]);
        if ($case === 'stripe-binding') DB::table('stripe_payment_intents')->insert(['order_id' => $id, 'pay_id' => 2,
            'cny_amount' => '10.00', 'currency' => 'usd', 'amount_minor' => 200, 'reference' => bin2hex(random_bytes(16)),
            'account_fingerprint' => 'fixture']);
        if ($case === 'paypal-binding') DB::table('paypal_orders')->insert(['order_id' => $id, 'pay_id' => 3,
            'cny_amount' => '10.00', 'usd_amount' => '2.00', 'reference' => bin2hex(random_bytes(16)),
            'create_key' => bin2hex(random_bytes(16)), 'capture_key' => bin2hex(random_bytes(16)), 'account_fingerprint' => 'fixture',
            'return_url' => 'https://fixture.test/return', 'cancel_url' => 'https://fixture.test/cancel']);
        $task = ['action' => 'receipt', 'sn' => $sn, 'pay_id' => 5, 'trade' => $case === 'zero-trade' ? '0' : 'fixture-receipt-trade'];
        $tasks = [$task, $task];
        if ($case === 'different-trade') $tasks[1]['trade'] = 'another-receipt-trade';
        // Read snapshots before the worker ready signal, then mutate only after both database waits exist.
        $orderMutation = in_array($case, ['price', 'gateway'], true);
        $table = $orderMutation ? 'orders' : 'pays'; $lockedIds = $orderMutation ? [$id] : [5];
        $results = fixtureRace($root, $sn, $tasks, $table, $lockedIds, function () use ($case, $id) {
            if ($case === 'method') DB::table('pays')->where('id', 5)->update(['pay_check' => 'changed-method']);
            if ($case === 'route') DB::table('pays')->where('id', 5)->update(['pay_handleroute' => 'pay/stripe']);
            if (in_array($case, ['merchant_id', 'merchant_key', 'merchant_pem'], true)) DB::table('pays')->where('id', 5)->update([$case => 'changed-fixture']);
            if ($case === 'price') DB::table('orders')->where('id', $id)->update(['actual_price' => '10.01']);
            if ($case === 'gateway') DB::table('orders')->where('id', $id)->update(['pay_id' => 2]);
            if ($case === 'deleted') DB::table('pays')->where('id', 5)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        });
        $accepted = count(array_filter($results, function ($result) { return $result['outcome'] === 'accepted'; }));
        $normal = in_array($case, ['same', 'closed', 'zero-trade'], true);
        $oneWinner = $case === 'different-trade'; $manual = $case === 'expired';
        fixtureCheck($accepted === ($normal || $manual ? 2 : ($oneWinner ? 1 : 0)), 'receipt-outcomes-' . $case);
        $status = (int) DB::table('orders')->where('id', $id)->value('status');
        fixtureCheck($status === ($normal || $oneWinner ? Order::STATUS_COMPLETED : ($manual ? Order::STATUS_ABNORMAL : Order::STATUS_WAIT_PAY)), 'receipt-state-' . $case);
        fixtureCheck((int) DB::table('goods')->where('id', $goods)->value('sales_volume') === ($normal || $oneWinner ? 1 : 0), 'receipt-sales-' . $case);
        fixtureCheck((int) DB::table('carmis')->where('goods_id', $goods)->value('status') === ($normal || $oneWinner ? Carmis::STATUS_SOLD : Carmis::STATUS_UNSOLD), 'receipt-card-' . $case);
        $trade = DB::table('orders')->where('id', $id)->value('trade_no');
        $expectedTrade = $case === 'zero-trade' ? '0' : ($normal || $manual ? 'fixture-receipt-trade' : '');
        if ($oneWinner) {
            $winners = array_keys(array_filter($results, function ($result) { return $result['outcome'] === 'accepted'; }));
            $expectedTrade = $tasks[$winners[0]]['trade'];
        }
        fixtureCheck($trade === $expectedTrade, 'receipt-reference-' . $case);
        ++$goods; ++$groups;
    }
    return ['groups' => $groups, 'workers' => $groups * 2, 'snapshot_changes_rejected' => 8,
        'closed_paid_recovered' => true, 'expired_stock_preserved' => true, 'same_trade_once' => true,
        'different_trade_excluded' => true, 'zero_trade_preserved' => true, 'modern_bindings_excluded' => true];
}
