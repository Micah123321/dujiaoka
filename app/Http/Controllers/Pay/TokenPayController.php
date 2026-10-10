<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Pay;
use App\Payments\JsonNotification;
use App\Payments\TokenPayLegacyClient;
use App\Service\PaymentGatewayLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TokenPayController extends PayController
{
    public function gateway(string $payway, string $orderSN)
    {
        try {
            $this->loadGateWay($orderSN, $payway);
            if (trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/tokenpay' || strpos($payway, 'tokenpay-') !== 0) throw new \RuntimeException();
            $url = app(TokenPayLegacyClient::class)->create($this->credentials($this->payGateway), [
                'order_sn' => (string) $this->order->order_sn, 'amount' => (string) $this->order->actual_price,
                // The original API requires a nonempty user key; an order ID also selects a distinct dynamic address.
                'email' => trim((string) $this->order->email) === '' ? (string) $this->order->order_sn : (string) $this->order->email,
                'return_url' => route('tokenpay-return', ['order_id' => $this->order->order_sn]),
                'notify_url' => url($this->payGateway->pay_handleroute . '/notify_url'),
            ]);
            return redirect()->away($url);
        } catch (RuleValidationException $exception) { return $this->err($exception->getMessage()); }
        catch (Throwable $exception) {
            Log::warning('TokenPay legacy checkout failed.', ['category' => get_class($exception)]);
            return $this->err(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }

    public function notifyUrl(Request $request)
    {
        try {
            if (strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) !== 'application/json') return 'fail';
            $data = JsonNotification::parse((string) $request->getContent(), ['ActualAmount']);
            $sn = $data['OutOrderId'] ?? '';
            if (!is_string($sn) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $sn)) return 'fail';
            $order = $this->orderService->detailOrderSN($sn);
            $pay = $order ? Pay::find($order->pay_id) : null;
            if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/tokenpay' || strpos((string) $pay->pay_check, 'tokenpay-') !== 0) return 'fail';
            $result = app(TokenPayLegacyClient::class)->verify($data, $this->credentials($pay));
            if (!in_array($result['Status'] ?? null, [1, '1'], true) || $result['OutOrderId'] !== $sn
                || ($result['BaseCurrency'] ?? null) !== 'CNY' || ($result['Currency'] ?? null) !== (string) $pay->merchant_id) return 'fail';
            // ActualAmount is fiat; Amount and PayAmount are crypto quantities, not shop prices.
            $this->orderProcessService->completedOrder($sn, (float) $result['ActualAmount'], (string) $result['Id'],
                app(PaymentGatewayLock::class)->receiptGuard($order, $pay, '/pay/tokenpay'));
            return 'ok';
        } catch (Throwable $exception) {
            Log::warning('TokenPay legacy receipt rejected.', ['category' => get_class($exception)]);
            return 'fail';
        }
    }

    public function returnUrl(Request $request)
    {
        return redirect(url('detail-order-sn', ['orderSN' => (string) $request->query('order_id', '')]));
    }

    private function credentials(Pay $pay): array
    {
        return ['currency' => (string) $pay->merchant_id, 'key' => (string) $pay->merchant_key, 'endpoint' => (string) $pay->merchant_pem];
    }
}
