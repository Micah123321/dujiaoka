<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Pay;
use App\Payments\CoinbaseCommerceClient;
use App\Payments\CurrencyAmount;
use App\Service\PaymentGatewayLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class CoinbaseController extends PayController
{
    public function gateway(string $payway, string $orderSN)
    {
        try {
            $this->loadGateWay($orderSN, $payway);
            if (trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/coinbase') {
                throw new \RuntimeException('Payment route does not match.');
            }
            $return = url('detail-order-sn', ['orderSN' => $this->order->order_sn]);
            $url = app(CoinbaseCommerceClient::class)->create([
                'api_key' => (string) $this->payGateway->merchant_key, 'fee' => (string) $this->payGateway->merchant_id,
            ], ['order_sn' => (string) $this->order->order_sn, 'title' => (string) $this->order->title,
                'amount' => (string) $this->order->actual_price, 'return_url' => $return, 'cancel_url' => $return]);
            return redirect()->away($url);
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Coinbase checkout failed.', ['category' => get_class($exception)]);
            return $this->err(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }

    public function notifyUrl(Request $request)
    {
        try {
            $body = (string) $request->getContent();
            // Locate an account from the same bounded body that will be authenticated below.
            if ($body === '' || strlen($body) > 65536) return 'fail';
            $envelope = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            $orderSN = $envelope['event']['data']['metadata']['customer_id'] ?? null;
            if (!is_string($orderSN) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $orderSN)) return 'fail';
            $order = $this->orderService->detailOrderSN($orderSN);
            $pay = $order ? Pay::find($order->pay_id) : null;
            if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/coinbase') return 'fail';
            $data = app(CoinbaseCommerceClient::class)->verify($body,
                (string) $request->header('X-CC-Webhook-Signature'), (string) $pay->merchant_pem);
            if ($data['metadata']['customer_id'] !== $orderSN) return 'fail';
            $price = CurrencyAmount::cny((string) $order->actual_price);
            $sufficient = false;
            foreach ($data['payments'] as $payment) {
                if (!is_array($payment) || !is_string($payment['status'] ?? null)
                    || !in_array(strtolower($payment['status']), ['confirmed', 'resolved'], true)) continue;
                $local = $payment['value']['local'] ?? null;
                if (!is_array($local) || ($local['currency'] ?? null) !== 'CNY') continue;
                $paid = $local['amount'] ?? null;
                if (!is_string($paid) || strlen($paid) > 128
                    || !preg_match('/\A[0-9]{1,80}(?:\.[0-9]{1,80})?\z/', $paid)) continue;
                // Fee multiplication can exceed exchange-quote precision; compare all signed decimal digits.
                $dot = strpos($paid, '.');
                $scale = $dot === false ? 2 : max(2, strlen($paid) - $dot - 1);
                if (bccomp($paid, $price, $scale) >= 0) { $sufficient = true; break; }
            }
            if (!$sufficient) return 'fail';
            // A qualifying payment controls fulfillment; array order and pending tail entries do not.
            $this->orderProcessService->completedOrder($orderSN, (float) $price, (string) $data['code'],
                app(PaymentGatewayLock::class)->receiptGuard($order, $pay, '/pay/coinbase'));
            return '{"status": 200}';
        } catch (Throwable $exception) {
            Log::warning('Coinbase receipt rejected.', ['category' => get_class($exception)]);
            return 'fail';
        }
    }
}
