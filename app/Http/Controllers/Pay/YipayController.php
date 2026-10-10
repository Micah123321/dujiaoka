<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Pay;
use App\Payments\FormNotification;
use App\Payments\YipayClient;
use App\Service\PaymentGatewayLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class YipayController extends PayController
{
    public function gateway(string $payway, string $orderSN)
    {
        try {
            $this->loadGateWay($orderSN, $payway);
            if (trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/yipay') throw new \RuntimeException();
            $form = app(YipayClient::class)->create($payway, $this->credentials($this->payGateway), [
                'order_sn' => (string) $this->order->order_sn, 'amount' => (string) $this->order->actual_price,
                'return_url' => route('yipay-return', ['order_id' => $this->order->order_sn]),
                'notify_url' => url($this->payGateway->pay_handleroute . '/notify_url'),
            ]);
            return response()->view('common.payment-submit', $form + ['method' => 'get'])->header('Cache-Control', 'no-store, private');
        } catch (RuleValidationException $exception) { return $this->err($exception->getMessage()); }
        catch (Throwable $exception) {
            Log::warning('Yipay checkout failed.', ['category' => get_class($exception)]);
            return $this->err(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }

    public function notifyUrl(Request $request)
    {
        try {
            $data = FormNotification::parse((string) $request->server->get('QUERY_STRING', ''));
            $sn = $data['out_trade_no'] ?? '';
            if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $sn)) return 'fail';
            $order = $this->orderService->detailOrderSN($sn);
            $pay = $order ? Pay::find($order->pay_id) : null;
            if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/yipay') return 'fail';
            $result = app(YipayClient::class)->verify($data, $this->credentials($pay));
            if (trim((string) $result['trade_no']) === '' || preg_match('/[\x00-\x1F\x7F]/', (string) $result['trade_no'])
                || ($result['trade_status'] ?? null) !== 'TRADE_SUCCESS' || ($result['out_trade_no'] ?? null) !== $sn
                || !in_array((string) $pay->pay_check, ['alipay', 'wxpay', 'qqpay'], true)
                || (isset($result['type']) && $result['type'] !== (string) $pay->pay_check)) return 'fail';
            $this->orderProcessService->completedOrder($sn, (float) $result['money'], (string) $result['trade_no'],
                app(PaymentGatewayLock::class)->receiptGuard($order, $pay, '/pay/yipay'));
            return 'success';
        } catch (Throwable $exception) {
            Log::warning('Yipay receipt rejected.', ['category' => get_class($exception)]);
            return 'fail';
        }
    }

    public function returnUrl(Request $request)
    {
        return redirect(url('detail-order-sn', ['orderSN' => (string) $request->query('order_id', '')]));
    }

    private function credentials(Pay $pay): array
    {
        return ['pid' => (string) $pay->merchant_id, 'endpoint' => (string) $pay->merchant_key, 'key' => (string) $pay->merchant_pem];
    }
}
