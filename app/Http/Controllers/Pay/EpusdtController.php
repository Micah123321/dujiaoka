<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Pay;
use App\Payments\EpusdtLegacyClient;
use App\Payments\JsonNotification;
use App\Service\PaymentGatewayLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class EpusdtController extends PayController
{
    public function gateway(string $payway, string $orderSN)
    {
        try {
            $this->loadGateWay($orderSN, $payway);
            if (trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/epusdt' || $payway !== 'epusdt') throw new \RuntimeException();
            $url = app(EpusdtLegacyClient::class)->create($this->credentials($this->payGateway), [
                'order_sn' => (string) $this->order->order_sn, 'amount' => (string) $this->order->actual_price,
                'return_url' => route('epusdt-return', ['order_id' => $this->order->order_sn]),
                'notify_url' => url($this->payGateway->pay_handleroute . '/notify_url'),
            ]);
            return redirect()->away($url);
        } catch (RuleValidationException $exception) { return $this->err($exception->getMessage()); }
        catch (Throwable $exception) {
            Log::warning('Epusdt legacy checkout failed.', ['category' => get_class($exception)]);
            return $this->err(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }

    public function notifyUrl(Request $request)
    {
        try {
            if (strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) !== 'application/json') return 'fail';
            $data = JsonNotification::parse((string) $request->getContent(), ['amount']);
            $sn = $data['order_id'] ?? '';
            if (!is_string($sn) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $sn)) return 'fail';
            $order = $this->orderService->detailOrderSN($sn);
            $pay = $order ? Pay::find($order->pay_id) : null;
            if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/epusdt' || $pay->pay_check !== 'epusdt') return 'fail';
            $result = app(EpusdtLegacyClient::class)->verify($data, $this->credentials($pay));
            if (!in_array($result['status'] ?? null, [2, '2'], true) || $result['order_id'] !== $sn) return 'fail';
            $this->orderProcessService->completedOrder($sn, (float) $result['amount'], (string) $result['trade_id'],
                app(PaymentGatewayLock::class)->receiptGuard($order, $pay, '/pay/epusdt'));
            return 'ok';
        } catch (Throwable $exception) {
            Log::warning('Epusdt legacy receipt rejected.', ['category' => get_class($exception)]);
            return 'fail';
        }
    }

    public function returnUrl(Request $request)
    {
        return redirect(url('detail-order-sn', ['orderSN' => (string) $request->query('order_id', '')]));
    }

    private function credentials(Pay $pay): array
    {
        return ['key' => (string) $pay->merchant_id, 'endpoint' => (string) $pay->merchant_pem];
    }
}
