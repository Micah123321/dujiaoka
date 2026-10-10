<?php
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/receipt.php';
require __DIR__ . '/legacy-paypal.php';

use Illuminate\Support\Facades\DB;
use App\Service\NotificationOutboxService;

$result = ['category' => 'error'];
$path = null;
try {
    fixtureBoot($argv);
    $dir = $argv[2] ?? '';
    $id = $argv[3] ?? '';
    fixtureCheck(preg_match('/^[0-9]+$/', $id) === 1 && is_dir($dir), 'worker-arguments');
    $path = $dir . '/' . $id;
    $task = json_decode(file_get_contents($path . '.task'), true, 512, JSON_THROW_ON_ERROR);
    $process = new FixtureOrderProcess();
    app()->instance('Service\OrderProcessService', $process);
    app()->instance(App\Service\OrderProcessService::class, $process);
    $receiptSnapshot = $task['action'] === 'receipt' ? fixtureReceiptSnapshot($task) : null;
    $outbox = new NotificationOutboxService();
    config(['dujiaoka.api_hook_outbox' => !empty($task['outbox']) || in_array($task['action'], ['publish', 'deliver'], true)]);
    file_put_contents($path . '.ready', json_encode([
        'pid' => getmypid(), 'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id,
    ]));
    fixtureWait(function () use ($dir) { return is_file($dir . '/gate'); }, 'gate-timeout');
    file_put_contents($path . '.entered', (string) microtime(true));
    switch ($task['action']) {
        case 'legacy-paypal':
            $result = fixtureLegacyPaypalWorker($task, $path, $dir, $process);
            break;
        case 'receipt':
            $result = fixtureReceiptWorker($task, $receiptSnapshot, $process);
            break;
        case 'pay':
            $order = $process->completedOrder($task['sn'], 10, 'fixture-trade');
            $result = ['category' => 'payment', 'status' => (int) $order->status];
            break;
        case 'expire':
            $expired = app('Service\OrderService')->expiredOrderSN($task['sn'], time() - 60);
            $result = ['category' => 'expiration', 'changed' => $expired];
            break;
        case 'return':
            $result = ['category' => 'coupon-return', 'changed' => app('Service\OrderService')->returnCoupon($task['sn'])];
            break;
        case 'publish':
            $result = ['category' => 'publisher', 'counts' => $outbox->publishDue()];
            break;
        case 'deliver':
            $outbox->deliver($task['outbox_id'], $task['token']);
            $result = ['category' => 'delivery'];
            break;
        case 'quote':
            config(['dujiaoka.payment_quote_freeze' => true]);
            $rates = Mockery::mock(App\Service\ExchangeRateService::class);
            $rates->shouldReceive('quoteCnyToUsd')->once()->andReturnUsing(function ($amount, $source) use ($task, $path, $dir) {
                fixtureCheck(DB::transactionLevel() === 0, 'quote-network-outside-transaction');
                file_put_contents($path . '.fetched', 'transaction-level=0');
                fixtureWait(function () use ($dir) { return count(glob($dir . '/*.fetched')) === 2; }, 'quote-fetch-barrier');
                return ['cny_amount' => App\Payments\CurrencyAmount::cny($amount),
                    'usd_amount' => App\Payments\CurrencyAmount::toUsd($amount, '1', $task['denominator']),
                    'source' => $source, 'numerator' => '1', 'denominator' => $task['denominator'],
                    'date' => date('Y-m-d'), 'fetched_at' => time()];
            });
            $order = App\Models\Order::where('order_sn', $task['sn'])->firstOrFail();
            $gateway = App\Models\Pay::findOrFail(1);
            $usd = (new App\Service\PaymentQuoteService($rates))->cnyToUsd($order, $gateway, 'frankfurter');
            $result = ['category' => 'quote', 'usd' => $usd];
            break;
        case 'intent':
            config(['dujiaoka.payment_quote_freeze' => true, 'dujiaoka.stripe_payment_intents' => true]);
            $quotes = Mockery::mock(App\Service\PaymentQuoteService::class);
            $quotes->shouldReceive('enabled')->andReturn(true);
            $client = Mockery::mock(App\Payments\StripeIntentClient::class);
            $client->shouldReceive('create')->once()->andReturnUsing(function ($key, $params, $idempotencyKey) use ($path, $dir) {
                fixtureCheck(DB::transactionLevel() === 0, 'intent-network-outside-transaction');
                file_put_contents($path . '.requested', json_encode(['key' => $idempotencyKey, 'params' => $params, 'transaction_level' => 0]));
                fixtureWait(function () use ($dir) { return count(glob($dir . '/*.requested')) === 2; }, 'intent-api-barrier');
                return ['id' => 'pi_fixture_' . $idempotencyKey, 'object' => 'payment_intent', 'amount' => (int) $params['amount'],
                    'currency' => $params['currency'], 'metadata' => $params['metadata'], 'payment_method_types' => $params['payment_method_types'],
                    'capture_method' => 'automatic', 'confirmation_method' => 'automatic', 'livemode' => false,
                    'status' => 'requires_payment_method', 'client_secret' => 'pi_fixture_' . $idempotencyKey . '_secret_fixture'];
            });
            $order = App\Models\Order::where('order_sn', $task['sn'])->firstOrFail();
            $gateway = App\Models\Pay::findOrFail(2);
            $intent = (new App\Service\StripePaymentIntentService($quotes, $client))->begin($order, $gateway, 'cny');
            $result = ['category' => 'intent', 'provider_id' => $intent['intent_id']];
            break;
        case 'inbox':
        case 'reconcile':
            config(['dujiaoka.payment_quote_freeze' => true, 'dujiaoka.stripe_payment_intents' => true, 'dujiaoka.stripe_reconciliation' => true]);
            $quotes = Mockery::mock(App\Service\PaymentQuoteService::class);
            $quotes->shouldReceive('enabled')->andReturn(true);
            $client = Mockery::mock(App\Payments\StripeIntentClient::class);
            if ($task['action'] === 'reconcile') {
                $client->shouldReceive('retrieve')->atMost()->once()->andReturnUsing(function ($key, $id) use ($path, $dir) {
                    fixtureCheck(DB::transactionLevel() === 0, 'reconcile-network-outside-transaction');
                    file_put_contents($path . '.retrieved', 'transaction-level=0');
                    fixtureWait(function () use ($dir) { return count(glob($dir . '/*.result')) === 1; }, 'reconcile-loser-completes');
                    $row = App\Models\StripePaymentIntent::where('provider_id', $id)->firstOrFail();
                    $order = App\Models\Order::findOrFail($row->order_id);
                    return ['id' => $id, 'object' => 'payment_intent', 'amount' => (int) $row->amount_minor, 'amount_received' => (int) $row->amount_minor,
                        'currency' => $row->currency, 'metadata' => ['order_sn' => $order->order_sn, 'binding_reference' => $row->reference],
                        'payment_method_types' => ['alipay'], 'capture_method' => 'automatic', 'confirmation_method' => 'automatic',
                        'livemode' => false, 'status' => 'succeeded', 'latest_charge' => ['id' => 'ch_reconciled', 'object' => 'charge',
                            'payment_intent' => $id, 'amount' => (int) $row->amount_minor, 'currency' => $row->currency,
                            'status' => 'succeeded', 'paid' => true, 'captured' => true, 'refunded' => false, 'amount_refunded' => 0]];
                });
                app()->instance(App\Service\OrderProcessService::class, $process);
            }
            $sync = new App\Service\StripeReconciliationService(new App\Service\StripePaymentIntentService($quotes, $client));
            if ($task['action'] === 'inbox') {
                $event = ['id' => 'evt_concurrent', 'type' => 'payment_intent.succeeded', 'intent_id' => $task['provider_id'], 'livemode' => false, 'account' => null];
                $result = ['category' => 'inbox', 'inserted' => $sync->accept(App\Models\Pay::findOrFail(2), $event)];
            } else {
                $result = ['category' => 'reconcile', 'counts' => $sync->reconcileDue()];
            }
            break;
        case 'parallel-binding':
            config(['dujiaoka.paypal_orders_v2' => true, 'dujiaoka.stripe_payment_intents' => true]);
            DB::transaction(function () use ($task, $path, $dir) {
                $order = App\Models\Order::where('order_sn', $task['sn'])->lockForUpdate()->firstOrFail();
                $pay = App\Models\Pay::whereKey($task['pay_id'])->lockForUpdate()->firstOrFail();
                app(App\Service\PaymentGatewayLock::class)->assertBoundGateway($order, $pay);
                file_put_contents($path . '.checked', '1');
                fixtureWait(function () use ($dir) { return count(glob($dir . '/*.checked')) === 2; }, 'parallel-bindings-check-complete');
                $row = ['order_id' => $order->id, 'pay_id' => $pay->id, 'cny_amount' => '10.00', 'reference' => bin2hex(random_bytes(16)),
                    'account_fingerprint' => str_repeat('f', 64), 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
                if ($task['pay_id'] === 2) {
                    $row += ['currency' => 'cny', 'amount_minor' => '1000']; $table = 'stripe_payment_intents';
                } else {
                    $row += ['usd_amount' => '2.00', 'create_key' => bin2hex(random_bytes(16)), 'capture_key' => bin2hex(random_bytes(16)),
                        'return_url' => 'https://fixture.test/return', 'cancel_url' => 'https://fixture.test/cancel']; $table = 'paypal_orders';
                }
                DB::table($table)->insert($row);
            });
            $result = ['category' => 'parallel-binding'];
            break;
        case 'select-payment':
            config(['dujiaoka.paypal_orders_v2' => true, 'dujiaoka.stripe_payment_intents' => true]);
            try {
                $selected = app(App\Service\PaymentGatewayLock::class)->selectGateway(App\Models\Order::where('order_sn', $task['sn'])->firstOrFail(), App\Models\Pay::findOrFail($task['pay_id']));
                $result = ['category' => 'selected', 'pay_id' => (int) $selected->pay_id];
            } catch (App\Exceptions\RuleValidationException $e) { $result = ['category' => 'locked']; }
            break;
        case 'paypal-create':
        case 'paypal-capture':
            config(['dujiaoka.payment_quote_freeze' => true, 'dujiaoka.paypal_orders_v2' => true,
                'dujiaoka.paypal_orders_mode' => 'sandbox', 'dujiaoka.stripe_payment_intents' => true]);
            $quotes = Mockery::mock(App\Service\PaymentQuoteService::class);
            $quotes->shouldReceive('enabled')->andReturn(true);
            $quotes->shouldReceive('cnyToUsd')->andReturn('2.00'); $quotes->shouldReceive('frozenUsd')->andReturn('2.00');
            $makeReceipt = function ($status) use ($task) {
                $order = App\Models\Order::where('order_sn', $task['sn'])->firstOrFail();
                $row = App\Models\PaypalOrder::where('order_id', $order->id)->firstOrFail();
                $capture = ['id' => '3C679366HH908993F', 'status' => 'COMPLETED', 'final_capture' => true,
                    'amount' => ['currency_code' => 'USD', 'value' => '2.00'],
                    'supplementary_data' => ['related_ids' => ['order_id' => '5O190127TN364715T']]];
                $unit = ['reference_id' => $row->reference, 'custom_id' => $row->reference, 'invoice_id' => $task['sn'],
                    'amount' => ['currency_code' => 'USD', 'value' => '2.00'], 'payee' => ['merchant_id' => 'M123456789012']];
                if ($status === 'COMPLETED') $unit['payments'] = ['captures' => [$capture]];
                return ['id' => '5O190127TN364715T', 'intent' => 'CAPTURE', 'status' => $status, 'purchase_units' => [$unit],
                    'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T']]];
            };
            $client = Mockery::mock(App\Payments\PaypalOrdersClient::class);
            $client->shouldReceive('create')->andReturnUsing(function ($credentials, $params, $key) use ($makeReceipt, $path, $dir) {
                fixtureCheck(DB::transactionLevel() === 0, 'paypal-create-outside-transaction');
                file_put_contents($path . '.requested', json_encode(['params' => $params, 'key' => $key]));
                fixtureWait(function () use ($dir) { return count(glob($dir . '/*.requested')) === 2; }, 'paypal-create-both-requests');
                return $makeReceipt('CREATED');
            });
            $client->shouldReceive('get')->andReturnUsing(function () use ($makeReceipt, $dir) {
                fixtureCheck(DB::transactionLevel() === 0, 'paypal-get-outside-transaction');
                return $makeReceipt(is_file($dir . '/remote-completed') ? 'COMPLETED' : 'APPROVED');
            });
            $client->shouldReceive('capture')->atMost()->once()->andReturnUsing(function ($credentials, $id, $key) use ($makeReceipt, $dir, $path) {
                fixtureCheck(DB::transactionLevel() === 0, 'paypal-capture-outside-transaction');
                file_put_contents($path . '.captured', json_encode(['provider_id' => $id, 'key' => $key, 'transaction_level' => 0]));
                fixtureWait(function () use ($dir) { return count(glob($dir . '/*.result')) === 1; }, 'paypal-capture-loser-completes');
                file_put_contents($dir . '/remote-completed', '1');
                return $makeReceipt('COMPLETED');
            });
            $client->shouldReceive('getCapture')->andReturnUsing(function () use ($makeReceipt) {
                fixtureCheck(DB::transactionLevel() === 0, 'paypal-receipt-outside-transaction');
                return $makeReceipt('COMPLETED')['purchase_units'][0]['payments']['captures'][0];
            });
            app()->instance(App\Service\OrderProcessService::class, $process);
            $service = new App\Service\PaypalOrderService($quotes, $client);
            $order = App\Models\Order::where('order_sn', $task['sn'])->firstOrFail(); $pay = App\Models\Pay::findOrFail(3);
            $data = $task['action'] === 'paypal-create' ? $service->begin($order, $pay, 'https://fixture.test/paypal-return', 'https://fixture.test/paypal-cancel')
                : $service->sync($order, $pay, true);
            $result = ['category' => $task['action'], 'data' => $data];
            break;
        case 'paypal-inbox':
        case 'paypal-reconcile':
            config(['dujiaoka.payment_quote_freeze' => true, 'dujiaoka.paypal_orders_v2' => true,
                'dujiaoka.paypal_reconciliation' => true, 'dujiaoka.paypal_orders_mode' => 'sandbox', 'dujiaoka.stripe_payment_intents' => true]);
            $quotes = Mockery::mock(App\Service\PaymentQuoteService::class);
            $quotes->shouldReceive('enabled')->andReturn(true); $quotes->shouldReceive('frozenUsd')->andReturn('2.00');
            $client = Mockery::mock(App\Payments\PaypalOrdersClient::class);
            $capture = ['id' => '4C679366HH908993F', 'status' => 'COMPLETED', 'final_capture' => true,
                'amount' => ['currency_code' => 'USD', 'value' => '2.00'],
                'supplementary_data' => ['related_ids' => ['order_id' => '6O190127TN364715T']]];
            if ($task['action'] === 'paypal-reconcile') {
                $client->shouldReceive('get')->atMost()->once()->andReturnUsing(function ($credentials, $id) use ($task, $path, $dir, $capture) {
                    fixtureCheck(DB::transactionLevel() === 0, 'paypal-reconciliation-get-outside-transaction');
                    file_put_contents($path . '.retrieved', 'transaction-level=0');
                    fixtureWait(function () use ($dir) { return count(glob($dir . '/*.result')) === 1; }, 'paypal-reconciliation-loser-exits');
                    $row = App\Models\PaypalOrder::findOrFail($task['binding_id']);
                    return ['id' => $id, 'intent' => 'CAPTURE', 'status' => 'COMPLETED', 'purchase_units' => [[
                        'reference_id' => $row->reference, 'custom_id' => $row->reference, 'invoice_id' => $task['sn'],
                        'payee' => ['merchant_id' => 'M123456789012'], 'amount' => ['currency_code' => 'USD', 'value' => '2.00'],
                        'payments' => ['captures' => [$capture]],
                    ]]];
                });
                $client->shouldReceive('getCapture')->once()->andReturnUsing(function () use ($capture) {
                    fixtureCheck(DB::transactionLevel() === 0, 'paypal-reconciliation-capture-detail-outside-transaction'); return $capture;
                });
                app()->instance(App\Service\OrderProcessService::class, $process);
            }
            $service = new App\Service\PaypalReconciliationService(new App\Service\PaypalOrderService($quotes, $client));
            if ($task['action'] === 'paypal-inbox') {
                $event = ['id' => 'WH-CONCURRENT', 'type' => 'PAYMENT.CAPTURE.COMPLETED',
                    'provider_id' => '6O190127TN364715T', 'resource_id' => '4C679366HH908993F'];
                $result = ['category' => 'paypal-inbox', 'inserted' => $service->accept(App\Models\Pay::findOrFail(3), $event)];
            } else {
                $result = ['category' => 'paypal-reconcile', 'outcome' => $service->check($task['binding_id'])];
            }
            break;
        default: throw new RuntimeException('unknown-action');
    }
} catch (Throwable $e) {
    // Never serialize exception messages, SQL bindings, mail addresses or card data.
    $result = ['category' => 'error', 'exception' => get_class($e)];
}
$json = json_encode($result);
if ($path !== null) {
    file_put_contents($path . '.result.tmp', $json);
    rename($path . '.result.tmp', $path . '.result');
}
echo $json . PHP_EOL;
exit($result['category'] === 'error' ? 1 : 0);
