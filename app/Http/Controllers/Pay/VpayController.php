<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Pay;
use App\Payments\FormNotification;
use App\Payments\VpayClient;
use App\Service\PaymentGatewayLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class VpayController extends PayController
{
    public function gateway(string $payway, string $orderSN)
    {
        try {
            $this->loadGateWay($orderSN, $payway);
            if (trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/vpay') throw new \RuntimeException();
            $url = app(VpayClient::class)->create($payway, $this->credentials($this->payGateway), [
                'payment_id' => date('YmdHis') . random_int(1, 65535), 'order_sn' => (string) $this->order->order_sn,
                'amount' => (string) $this->order->actual_price, 'return_url' => route('vpay-return', ['order_id' => $this->order->order_sn]),
                'notify_url' => url($this->payGateway->pay_handleroute . '/notify_url'),
            ]);
            return redirect()->away($url);
        } catch (RuleValidationException $exception) { return $this->err($exception->getMessage()); }
        catch (Throwable $exception) {
            Log::warning('Vpay checkout failed.', ['category' => get_class($exception)]);
            return $this->err(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }

    public function notifyUrl(Request $request)
    {
        try {
            $data = FormNotification::parse((string) $request->server->get('QUERY_STRING', ''));
            $sn = $data['param'] ?? '';
            if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $sn)) return 'fail';
            $order = $this->orderService->detailOrderSN($sn);
            $pay = $order ? Pay::find($order->pay_id) : null;
            if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/vpay') return 'fail';
            $result = app(VpayClient::class)->verify($data, $this->credentials($pay));
            $types = ['vzfb' => '2', 'vwx' => '1'];
            if (!isset($types[$pay->pay_check]) || (string) $result['type'] !== $types[$pay->pay_check]
                || $result['param'] !== $sn) return 'fail';
            // reallyPrice is the provider's adjusted collection amount; price is the signed shop price.
            $this->orderProcessService->completedOrder($sn, (float) $result['price'], (string) $result['payId'],
                app(PaymentGatewayLock::class)->receiptGuard($order, $pay, '/pay/vpay'));
            return 'success';
        } catch (Throwable $exception) {
            Log::warning('Vpay receipt rejected.', ['category' => get_class($exception)]);
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
