<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Pay;
use App\Payments\FormNotification;
use App\Payments\MapayClient;
use App\Service\PaymentGatewayLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MapayController extends PayController
{
    public function gateway(string $payway, string $orderSN)
    {
        try {
            $this->loadGateWay($orderSN, $payway);
            if (trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/mapay') {
                throw new \RuntimeException('Payment route does not match.');
            }
            $url = app(MapayClient::class)->create($payway, $this->credentials($this->payGateway), [
                'order_sn' => (string) $this->order->order_sn, 'amount' => (string) $this->order->actual_price,
                'return_url' => url('detail-order-sn', ['orderSN' => $this->order->order_sn]),
                'notify_url' => url($this->payGateway->pay_handleroute . '/notify_url'),
            ]);
            return redirect()->away($url);
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Mapay checkout failed.', ['category' => get_class($exception)]);
            return $this->err(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }

    public function notifyUrl(Request $request)
    {
        try {
            $type = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));
            if ($type !== 'application/x-www-form-urlencoded') return 'fail';
            $data = FormNotification::parse((string) $request->getContent());
            $orderSN = $data['pay_id'] ?? null;
            if (!is_string($orderSN) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $orderSN)) return 'fail';
            $order = $this->orderService->detailOrderSN($orderSN);
            $pay = $order ? Pay::find($order->pay_id) : null;
            if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/mapay') return 'fail';
            $result = app(MapayClient::class)->verify($data, $this->credentials($pay));
            $types = ['mqq' => '2', 'mzfb' => '1', 'mwx' => '3'];
            $mode = (string) $pay->pay_check;
            if (!isset($types[$mode]) || ($result['pay_id'] ?? null) !== $orderSN
                || (isset($result['param']) && $result['param'] !== $mode)
                || (isset($result['type']) && (string) $result['type'] !== $types[$mode])) return 'fail';
            $this->orderProcessService->completedOrder($orderSN, (float) $result['money'], (string) $result['pay_no'],
                app(PaymentGatewayLock::class)->receiptGuard($order, $pay, '/pay/mapay'));
            return 'success';
        } catch (Throwable $exception) {
            Log::warning('Mapay receipt rejected.', ['category' => get_class($exception)]);
            return 'fail';
        }
    }

    private function credentials(Pay $pay): array
    {
        return ['id' => (string) $pay->merchant_id, 'endpoint' => (string) $pay->merchant_key,
            'key' => (string) $pay->merchant_pem];
    }
}
