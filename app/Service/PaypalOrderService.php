<?php

namespace App\Service;

use App\Exceptions\RuleValidationException;
use App\Models\Order;
use App\Models\Pay;
use App\Models\PaypalOrder;
use App\Payments\CurrencyAmount;
use App\Payments\PaypalOrdersClient;
use App\Payments\PaypalOrderReceipt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class PaypalOrderService
{
    private $quotes;
    private $client;

    public function __construct(PaymentQuoteService $quotes, PaypalOrdersClient $client)
    {
        $this->quotes = $quotes;
        $this->client = $client;
    }

    public function enabled(): bool
    {
        return (bool) config('dujiaoka.paypal_orders_v2', false) && $this->quotes->enabled();
    }

    public function begin(Order $order, Pay $pay, string $return, string $cancel): array
    {
        $order = clone $order;
        $pay = clone $pay;
        return $this->guard(function () use ($order, $pay, $return, $cancel) {
            $this->available();
            $row = DB::transaction(function () use ($order, $pay) {
                $this->checkContext($order, $pay, false);
                $row = PaypalOrder::where('order_id', $order->id)->lockForUpdate()->first();
                if ($row) {
                    $this->checkRow($row, $order, $pay);
                }
                return $row;
            });
            if (!$row) {
                $usd = CurrencyAmount::cny($this->quotes->cnyToUsd($order, $pay, 'frankfurter'));
                $row = DB::transaction(function () use ($order, $pay, $usd, $return, $cancel) {
                    $this->checkContext($order, $pay, true);
                    $row = PaypalOrder::where('order_id', $order->id)->lockForUpdate()->first();
                    if (!$row) {
                        $row = PaypalOrder::create([
                            'order_id' => $order->id, 'pay_id' => $pay->id,
                            'cny_amount' => CurrencyAmount::cny($order->actual_price), 'usd_amount' => $usd,
                            'reference' => bin2hex(random_bytes(16)), 'create_key' => bin2hex(random_bytes(16)),
                            'capture_key' => bin2hex(random_bytes(16)), 'account_fingerprint' => $this->fingerprint($pay),
                            'return_url' => $return, 'cancel_url' => $cancel,
                        ]);
                    }
                    $this->checkRow($row, $order, $pay);
                    if (CurrencyAmount::cny($row->usd_amount) !== $usd) {
                        $this->fail();
                    }
                    return $row;
                });
            }
            if ($row->return_url !== $return || $row->cancel_url !== $cancel) {
                $this->fail();
            }
            $this->checkQuote($row, $order, $pay);
            DB::transaction(function () use ($order, $pay, $row) {
                $this->checkContext($order, $pay, $row->provider_id === null);
                $this->reload($row, $order, $pay);
                if ($row->provider_id === null) {
                    $this->withinWindow($row->created_at);
                }
            });
            $this->outsideTransaction();
            $receipt = $row->provider_id === null
                ? $this->client->create($this->credentials($pay), $this->params($row, $order, $pay), $row->create_key)
                : $this->client->get($this->credentials($pay), $row->provider_id);
            $row = DB::transaction(function () use ($receipt, $row, $order, $pay) {
                $this->checkContext($order, $pay, false);
                $current = $this->reload($row, $order, $pay);
                $id = $current->provider_id ?: ($receipt['id'] ?? '');
                $this->receipt($receipt, $id, $current, $order, $pay);
                if ($current->provider_id === null) {
                    $current->provider_id = $id;
                    $current->save();
                }
                return $current;
            });
            $url = PaypalOrderReceipt::approvalUrl($receipt, $this->credentials($pay)['mode']);
            return ['provider_id' => $row->provider_id, 'approval_url' => $url, 'status' => $receipt['status']];
        });
    }

    public function sync(Order $order, Pay $pay, bool $capture = false): array
    {
        $order = clone $order;
        $pay = clone $pay;
        return $this->guard(function () use ($order, $pay, $capture) {
            $this->available();
            $row = DB::transaction(function () use ($order, $pay) {
                $this->checkContext($order, $pay, false);
                $row = PaypalOrder::where('order_id', $order->id)->lockForUpdate()->first();
                if (!$row || $row->provider_id === null) {
                    $this->fail();
                }
                $this->checkRow($row, $order, $pay);
                return $row;
            });
            $this->checkQuote($row, $order, $pay);
            $receipt = $this->fetch($row, $order, $pay);
            if ($capture && $receipt['status'] === 'APPROVED') {
                $lease = DB::transaction(function () use ($row, $order, $pay) {
                    $fresh = $this->checkContext($order, $pay, false);
                    $current = $this->reload($row, $order, $pay);
                    $gate = Pay::whereKey($pay->id)->lockForUpdate()->first();
                    if ((int) $fresh->status !== Order::STATUS_WAIT_PAY || (int) $gate->is_open !== Pay::STATUS_OPEN) {
                        return null;
                    }
                    if ($current->capture_leased_until && $current->capture_leased_until->gt(Carbon::now())) {
                        return null;
                    }
                    if ($current->capture_attempted_at) {
                        $this->withinWindow($current->capture_attempted_at);
                    }
                    $this->checkContext($order, $pay, true);
                    $current->capture_attempted_at = $current->capture_attempted_at ?: Carbon::now();
                    $current->capture_lease_token = bin2hex(random_bytes(16));
                    $current->capture_leased_until = Carbon::now()->addSeconds(90);
                    $current->save();
                    return $current;
                });
                if ($lease) {
                    try {
                        $this->outsideTransaction();
                        $this->client->capture($this->credentials($pay), $row->provider_id, $row->capture_key);
                    } finally {
                        // A stale worker must not clear a replacement worker's lease.
                        PaypalOrder::whereKey($lease->id)->where('capture_lease_token', $lease->capture_lease_token)
                            ->where('capture_leased_until', $lease->capture_leased_until)
                            ->update(['capture_lease_token' => null, 'capture_leased_until' => null]);
                    }
                    $receipt = $this->fetch($row, $order, $pay);
                }
            }
            $captureId = null;
            $pending = PaypalOrderReceipt::pendingCaptureId($receipt, $row->provider_id, $order->order_sn,
                $row->reference, CurrencyAmount::cny($row->usd_amount), $this->credentials($pay)['merchant_id']) !== null;
            if ($receipt['status'] === 'COMPLETED' && !$pending) {
                $captureId = PaypalOrderReceipt::captureId($receipt, $row->provider_id, $order->order_sn,
                    $row->reference, CurrencyAmount::cny($row->usd_amount), $this->credentials($pay)['merchant_id']);
                if ($captureId === null) {
                    $this->fail();
                }
                $this->outsideTransaction();
                $detail = $this->client->getCapture($this->credentials($pay), $captureId);
                if (!PaypalOrderReceipt::captureMatches($detail, $captureId, $row->provider_id, CurrencyAmount::cny($row->usd_amount))) {
                    $this->fail();
                }
            }
            $fresh = DB::transaction(function () use ($row, $order, $pay, $captureId) {
                $fresh = $this->checkContext($order, $pay, false);
                $current = $this->reload($row, $order, $pay);
                if ($captureId !== null) {
                    if ($current->capture_id !== null && $current->capture_id !== $captureId) {
                        $this->fail();
                    }
                    $current->capture_id = $captureId;
                    $current->save();
                }
                return $fresh;
            });
            if ($captureId !== null) {
                $fresh = app(OrderProcessService::class)->completedOrder($order->order_sn, (float) $row->cny_amount, $captureId,
                    function (Order $locked) use ($row, $order, $pay, $captureId) {
                        $this->checkContext($order, $pay, false);
                        $current = $this->reload($row, $order, $pay);
                        $this->checkQuote($current, $order, $pay);
                        if ((string) $locked->id !== (string) $order->id || $current->capture_id !== $captureId) {
                            $this->fail();
                        }
                    });
            }
            return ['status' => $pending ? 'CAPTURE_PENDING' : $receipt['status'], 'order_status' => (int) $fresh->status, 'provider_id' => $row->provider_id];
        });
    }

    private function fetch(PaypalOrder $row, Order $order, Pay $pay): array
    {
        DB::transaction(function () use ($row, $order, $pay) {
            $this->checkContext($order, $pay, false);
            $this->reload($row, $order, $pay);
        });
        $this->outsideTransaction();
        $receipt = $this->client->get($this->credentials($pay), $row->provider_id);
        DB::transaction(function () use ($receipt, $row, $order, $pay) {
            $this->checkContext($order, $pay, false);
            $current = $this->reload($row, $order, $pay);
            $this->receipt($receipt, $current->provider_id, $current, $order, $pay);
        });
        return $receipt;
    }

    private function checkContext(Order $snapshot, Pay $gateway, bool $waiting): Order
    {
        $order = Order::whereKey($snapshot->id)->lockForUpdate()->first();
        $pay = Pay::whereKey($gateway->id)->lockForUpdate()->first();
        if (!$order || !$pay || (string) $order->order_sn !== (string) $snapshot->order_sn
            || (string) $order->pay_id !== (string) $gateway->id || (string) $snapshot->pay_id !== (string) $gateway->id
            || trim((string) $pay->pay_handleroute, '/') !== 'pay/paypal'
            || trim((string) $gateway->pay_handleroute, '/') !== 'pay/paypal'
            || (int) $pay->is_open !== (int) $gateway->is_open
            || $this->fingerprint($pay) !== $this->fingerprint($gateway)
            || CurrencyAmount::cny($order->actual_price) !== CurrencyAmount::cny($snapshot->actual_price)
            || ($waiting && ((int) $order->status !== Order::STATUS_WAIT_PAY
                || (int) $pay->is_open !== Pay::STATUS_OPEN || (int) $gateway->is_open !== Pay::STATUS_OPEN))) {
            $this->fail();
        }
        app(PaymentGatewayLock::class)->assertBoundGateway($order, $pay);
        return $order;
    }

    public function credentials(Pay $pay): array
    {
        $mode = config('dujiaoka.paypal_orders_mode', 'live');
        if (!in_array($mode, ['live', 'sandbox'], true) || !preg_match('/\A[A-Z0-9]{13}\z/', (string) $pay->merchant_id)
            || !is_string($pay->merchant_key) || $pay->merchant_key === ''
            || !is_string($pay->merchant_pem) || $pay->merchant_pem === '') {
            $this->fail();
        }
        return ['client_id' => $pay->merchant_key, 'secret' => $pay->merchant_pem,
            'merchant_id' => $pay->merchant_id, 'mode' => $mode];
    }

    public function accountFingerprint(Pay $pay): string
    {
        return $this->fingerprint($pay);
    }

    public function resolveEvent(Pay $pay, array $event): ?PaypalOrder
    {
        return $this->guard(function () use ($pay, $event) {
            $this->available();
            $id = $event['provider_id'] ?? null;
            if ($id === null && strncmp($event['type'] ?? '', 'PAYMENT.CAPTURE.', 16) === 0) {
                $resource = $event['resource_id'] ?? null;
                if (!is_string($resource) || !preg_match('/\A[A-Z0-9]{17}\z/', $resource)) $this->fail();
                $detail = $this->client->getCapture($this->credentials($pay), $resource);
                if (($detail['id'] ?? null) !== $resource) $this->fail();
                $id = $detail['supplementary_data']['related_ids']['order_id'] ?? null;
            }
            if (!is_string($id) || !preg_match('/\A[A-Z0-9]{17}\z/', $id)) $this->fail();
            return $this->recoverProvider($pay, $id);
        });
    }

    public function recoverProvider(Pay $pay, string $providerId): ?PaypalOrder
    {
        $pay = clone $pay;
        return $this->guard(function () use ($pay, $providerId) {
            $this->available();
            if (!preg_match('/\A[A-Z0-9]{17}\z/', $providerId)) $this->fail();
            $known = PaypalOrder::where('pay_id', $pay->id)->where('provider_id', $providerId)->first();
            if ($known) return $known;
            // A signed event only names a candidate. Recover identity from the current server Order.
            $receipt = $this->client->get($this->credentials($pay), $providerId);
            $units = $receipt['purchase_units'] ?? null;
            if (($receipt['id'] ?? null) !== $providerId || !is_array($units) || array_keys($units) !== [0]) $this->fail();
            $reference = $units[0]['reference_id'] ?? null;
            if (!is_string($reference) || !preg_match('/\A[a-f0-9]{32}\z/', $reference)) return null;
            $row = PaypalOrder::where('pay_id', $pay->id)->where('reference', $reference)->first();
            if (!$row) return null;
            $order = Order::find($row->order_id);
            if (!$order) $this->fail();
            $this->receipt($receipt, $providerId, $row, $order, $pay);
            $this->checkQuote($row, $order, $pay);
            return DB::transaction(function () use ($row, $order, $pay, $providerId) {
                $this->checkContext($order, $pay, false);
                $current = $this->reload($row, $order, $pay);
                $this->checkQuote($current, $order, $pay);
                if ($current->provider_id !== null && $current->provider_id !== $providerId) $this->fail();
                $current->provider_id = $providerId;
                $current->save();
                return $current;
            });
        });
    }

    private function fingerprint(Pay $pay): string
    {
        return hash('sha256', json_encode($this->credentials($pay), JSON_THROW_ON_ERROR));
    }

    private function checkRow(PaypalOrder $row, Order $order, Pay $pay): void
    {
        if ((string) $row->order_id !== (string) $order->id || (string) $row->pay_id !== (string) $pay->id
            || CurrencyAmount::cny($row->cny_amount) !== CurrencyAmount::cny($order->actual_price)
            || $row->account_fingerprint !== $this->fingerprint($pay) || !$row->created_at
            || ($row->provider_id !== null && !preg_match('/\A[A-Z0-9]{17}\z/', $row->provider_id))) {
            $this->fail();
        }
        CurrencyAmount::cny($row->usd_amount);
        foreach (['reference', 'create_key', 'capture_key'] as $field) {
            if (!preg_match('/\A[a-f0-9]{32}\z/', (string) $row->$field)) {
                $this->fail();
            }
        }
    }

    private function reload(PaypalOrder $before, Order $order, Pay $pay): PaypalOrder
    {
        $row = PaypalOrder::where('order_id', $order->id)->lockForUpdate()->first();
        if (!$row) {
            $this->fail();
        }
        $this->checkRow($row, $order, $pay);
        foreach (['id', 'order_id', 'pay_id', 'cny_amount', 'usd_amount', 'reference', 'create_key', 'capture_key',
            'account_fingerprint', 'return_url', 'cancel_url', 'created_at'] as $field) {
            if ((string) $row->$field !== (string) $before->$field) {
                $this->fail();
            }
        }
        foreach (['provider_id', 'capture_id'] as $field) {
            if ($before->$field !== null && $row->$field !== $before->$field) {
                $this->fail();
            }
        }
        return $row;
    }

    private function checkQuote(PaypalOrder $row, Order $order, Pay $pay): void
    {
        if (CurrencyAmount::cny($this->quotes->frozenUsd($order, $pay, 'frankfurter')) !== CurrencyAmount::cny($row->usd_amount)) {
            $this->fail();
        }
    }

    private function receipt(array $receipt, string $id, PaypalOrder $row, Order $order, Pay $pay): void
    {
        if (!preg_match('/\A[A-Z0-9]{17}\z/', $id)
            || !PaypalOrderReceipt::matches($receipt, $id, $order->order_sn, $row->reference,
                CurrencyAmount::cny($row->usd_amount), $this->credentials($pay)['merchant_id'])
            || !in_array($receipt['status'] ?? null, ['CREATED', 'SAVED', 'APPROVED', 'VOIDED', 'COMPLETED', 'PAYER_ACTION_REQUIRED'], true)) {
            $this->fail();
        }
    }

    private function params(PaypalOrder $row, Order $order, Pay $pay): array
    {
        return ['intent' => 'CAPTURE', 'purchase_units' => [[
            'reference_id' => $row->reference, 'custom_id' => $row->reference, 'invoice_id' => $order->order_sn,
            'amount' => ['currency_code' => 'USD', 'value' => CurrencyAmount::cny($row->usd_amount)],
            'payee' => ['merchant_id' => $this->credentials($pay)['merchant_id']],
        ]], 'payment_source' => ['paypal' => ['experience_context' => [
            'user_action' => 'PAY_NOW', 'shipping_preference' => 'NO_SHIPPING',
            'return_url' => $row->return_url, 'cancel_url' => $row->cancel_url,
        ]]]];
    }

    private function withinWindow(Carbon $time): void
    {
        $age = Carbon::now()->getTimestamp() - $time->getTimestamp();
        if ($age < 0 || $age >= 5 * 3600) {
            $this->fail('payment_intent_unavailable');
        }
    }

    private function outsideTransaction(): void
    {
        if (DB::transactionLevel() !== 0) {
            $this->fail('payment_intent_unavailable');
        }
    }

    private function available(): void
    {
        if (!$this->enabled()) {
            $this->fail('payment_intent_unavailable');
        }
        $this->outsideTransaction();
    }

    private function fail(string $key = 'payment_intent_invalid'): void
    {
        throw new RuleValidationException(__('dujiaoka.prompt.' . $key));
    }

    private function guard(callable $action)
    {
        try {
            return $action();
        } catch (Throwable $exception) {
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
