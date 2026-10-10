<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Order;
use App\Models\Pay;
use App\Payments\CurrencyAmount;
use App\Payments\PaymentReceipt;
use App\Payments\PaypalPaymentsClient;
use App\Service\PaymentGatewayLock;
use App\Service\PaymentQuoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaypalPayController extends PayController
{
    const Currency = 'USD';

    public function gateway(string $payway, string $orderSN)
    {
        if (config('dujiaoka.paypal_orders_v2', false)) {
            return app(PaypalOrdersController::class)->gateway($payway, $orderSN);
        }
        try {
            $this->loadGateWay($orderSN, $payway);
            if (trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/paypal') {
                throw new RuleValidationException(__('dujiaoka.prompt.payment_quote_invalid'));
            }
            if ($this->order->trade_no) return $this->detail($orderSN);
            $total = app(PaymentQuoteService::class)->cnyToUsd($this->order, $this->payGateway, 'frankfurter');
            $payment = [
                'intent' => 'sale', 'payer' => ['payment_method' => 'paypal'],
                'redirect_urls' => [
                    'return_url' => route('paypal-return', ['success' => 'ok', 'orderSN' => $orderSN]),
                    'cancel_url' => route('paypal-return', ['success' => 'no', 'orderSN' => $orderSN]),
                ],
                'transactions' => [[
                    'amount' => ['currency' => self::Currency, 'total' => $total,
                        'details' => ['shipping' => '0.00', 'subtotal' => $total]],
                    'item_list' => ['items' => [['name' => $this->order->title, 'currency' => self::Currency,
                        'quantity' => 1, 'price' => $total]]],
                    'description' => $this->order->title, 'invoice_number' => $orderSN,
                ]],
            ];
            $created = $this->createPayment($payment, $this->credentials($this->payGateway));
            $url = PaypalPaymentsClient::approvalUrl($created, 'live');
            if ($url === null) throw new \RuntimeException();
            return redirect()->away($url)->header('Cache-Control', 'no-store, private');
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        } catch (Throwable $exception) {
            return $this->err(__('dujiaoka.prompt.payment_intent_unavailable'));
        }
    }

    protected function createPayment(array $payment, array $credentials): array
    {
        $key = substr(hash('sha256', 'create' . "\0" . $credentials['client_id'] . "\0" . json_encode($payment)), 0, 32);
        return app(PaypalPaymentsClient::class)->create($credentials, $payment, $key);
    }

    protected function getPayment(string $id, array $credentials): array
    {
        return app(PaypalPaymentsClient::class)->get($credentials, $id);
    }

    protected function executePayment(array $payment, string $payerId, array $credentials): array
    {
        $id = $payment['id'];
        $key = substr(hash('sha256', 'execute' . "\0" . $credentials['client_id'] . "\0" . $id . "\0" . $payerId), 0, 32);
        return app(PaypalPaymentsClient::class)->execute($credentials, $id, $payerId, $key);
    }

    public function returnUrl(Request $request)
    {
        if (config('dujiaoka.paypal_orders_v2', false)) {
            return response(__('dujiaoka.prompt.payment_method_locked'), 409)->header('Cache-Control', 'no-store, private');
        }
        $orderSN = $request->input('orderSN');
        $paymentId = $request->input('paymentId');
        $payerId = $request->input('PayerID');
        if (!is_string($orderSN) || $orderSN === '') return 'error';
        if ($request->input('success') !== 'ok' || !is_string($paymentId) || $paymentId === ''
            || !is_string($payerId) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $payerId)) return $this->detail($orderSN);
        try {
            $order = $this->orderService->detailOrderSN($orderSN);
            if (!$order) return 'error';
            // A closed gateway can still confirm a payment created before it was closed.
            $pay = Pay::find($order->pay_id);
            if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/paypal') return 'error';
            $quotes = app(PaymentQuoteService::class);
            $frozen = $quotes->enabled();
            $expected = $frozen ? $quotes->frozenUsd($order, $pay, 'frankfurter') : null;
            $credentials = $this->credentials($pay);
            $payment = $this->getPayment($paymentId, $credentials);
            // Pre-freeze payments use their authenticated provider amount, never today's FX rate.
            $usd = $expected ?? CurrencyAmount::cny($payment['transactions'][0]['amount']['total'] ?? null);
            if (!PaymentReceipt::paypalOrderMatches($payment, $paymentId, $orderSN, $usd)
                || !in_array($payment['state'] ?? null, ['created', 'approved'], true)) {
                throw new \RuntimeException();
            }
            if ($payment['state'] === 'created') {
                if (!$this->reserveExecution($order, $pay, $quotes, $frozen ? $usd : null, $paymentId)) {
                    return $this->detail($orderSN);
                }
                $payment = $this->executePayment($payment, $payerId, $credentials);
            }
            $sale = PaymentReceipt::paypalSaleId($payment, $paymentId, $orderSN, $usd);
            if ($sale === null) throw new \RuntimeException();
            // Keep legacy trade identifiers for previously completed non-frozen orders.
            $trade = $frozen ? $sale : $paymentId;
            $this->orderProcessService->completedOrder($orderSN, $order->actual_price, $trade,
                function (Order $locked) use ($order, $pay, $usd, $frozen, $quotes, $paymentId, $trade) {
                    $current = Pay::whereKey($pay->id)->lockForUpdate()->first();
                    if (!$current || (string) $locked->pay_id !== (string) $pay->id
                        || bccomp((string) $locked->actual_price, (string) $order->actual_price, 2) !== 0
                        || trim((string) $current->pay_handleroute, '/') !== 'pay/paypal'
                        || $this->credentials($current) !== $this->credentials($pay)
                        || ($locked->trade_no && !in_array($locked->trade_no, [$paymentId, $trade], true))
                        || $quotes->enabled() !== $frozen
                        || ($frozen && $quotes->frozenUsd($locked, $current, 'frankfurter') !== $usd)) {
                        throw new \RuntimeException('Payment binding changed.');
                    }
                    app(PaymentGatewayLock::class)->assertBoundGateway($locked, $current);
                });
        } catch (Throwable $exception) {
            Log::error('Legacy PayPal confirmation failed.', ['category' => class_basename($exception)]);
        }
        return $this->detail($orderSN);
    }

    protected function reserveExecution(Order $snapshot, Pay $pay, PaymentQuoteService $quotes, ?string $usd, string $id): bool
    {
        if (DB::transactionLevel() !== 0) throw new \LogicException('Payment attempt must commit before provider I/O.');
        return DB::transaction(function () use ($snapshot, $pay, $quotes, $usd, $id) {
            $locked = Order::whereKey($snapshot->id)->lockForUpdate()->first();
            $current = Pay::whereKey($pay->id)->lockForUpdate()->first();
            if (!$locked || !$current || (string) $locked->order_sn !== (string) $snapshot->order_sn
                || (string) $locked->pay_id !== (string) $pay->id
                || bccomp((string) $locked->actual_price, (string) $snapshot->actual_price, 2) !== 0
                || trim((string) $current->pay_handleroute, '/') !== 'pay/paypal'
                || $this->credentials($current) !== $this->credentials($pay)
                || $quotes->enabled() !== ($usd !== null)
                || ($usd !== null && $quotes->frozenUsd($locked, $current, 'frankfurter') !== $usd)) {
                throw new \RuntimeException('Payment binding changed.');
            }
            app(PaymentGatewayLock::class)->assertBoundGateway($locked, $current);
            // Persist before provider I/O; an unknown outcome must never expire into another POST.
            if ($locked->trade_no) {
                if ($locked->trade_no !== $id) throw new \RuntimeException('Payment attempt does not match.');
                return false;
            }
            if ((int) $locked->status !== Order::STATUS_WAIT_PAY || (int) $current->is_open !== Pay::STATUS_OPEN) return false;
            $locked->trade_no = $id;
            Order::withoutEvents(function () use ($locked) { $locked->save(); });
            return true;
        });
    }

    public function notifyUrl(Request $request)
    {
        if (config('dujiaoka.paypal_orders_v2', false)) {
            return response(__('dujiaoka.prompt.payment_method_locked'), 409)->header('Cache-Control', 'no-store, private');
        }
        // Unsigned legacy notifications are not proof of payment and must not expose raw payloads.
        return response('', 200);
    }

    private function credentials(Pay $pay): array
    {
        return ['client_id' => (string) $pay->merchant_key, 'secret' => (string) $pay->merchant_pem, 'mode' => 'live'];
    }

    private function detail(string $sn)
    {
        return redirect('detail-order-sn/' . rawurlencode($sn))->header('Cache-Control', 'no-store, private');
    }
}
