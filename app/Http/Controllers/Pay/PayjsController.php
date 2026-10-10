<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Pay;
use App\Payments\CurrencyAmount;
use App\Payments\FormNotification;
use App\Payments\PayjsClient;
use App\Service\PaymentGatewayLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PayjsController extends PayController
{
    public function gateway(string $payway, string $orderSN)
    {
        try {
            $this->loadGateWay($orderSN, $payway);
            if ($payway !== 'payjswescan' || trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/payjs') {
                throw new \RuntimeException('Payment gateway does not match.');
            }
            $result = app(PayjsClient::class)->create($this->credentials($this->payGateway), [
                'body' => $this->order->order_sn,
                'total_fee' => bcmul(CurrencyAmount::cny($this->order->actual_price), '100', 0),
                'out_trade_no' => $this->order->order_sn,
                'notify_url' => url('pay/payjs/notify_url'),
            ]);
            $result['payname'] = $this->payGateway->pay_name;
            $result['actual_price'] = (float) $this->order->actual_price;
            $result['orderid'] = $this->order->order_sn;
            $result['qr_code'] = $result['code_url'];
            return $this->render('static_pages/qrpay', $result, __('dujiaoka.scan_qrcode_to_pay'));
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Payjs checkout failed.', ['category' => get_class($exception)]);
            return $this->err(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }

    public function notifyUrl(Request $request)
    {
        try {
            if (strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) !== 'application/x-www-form-urlencoded') return 'fail';
            $params = FormNotification::parse($request->getContent());
            $orderSN = $params['out_trade_no'] ?? null;
            if (!is_string($orderSN) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $orderSN)) return 'fail';
            $order = $this->orderService->detailOrderSN($orderSN);
            $pay = $order ? Pay::find($order->pay_id) : null;
            if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/payjs') return 'fail';
            $result = app(PayjsClient::class)->verify($params, $this->credentials($pay));
            if (($result['return_code'] ?? null) !== '1' || ($result['out_trade_no'] ?? null) !== (string) $order->order_sn
                || ($result['fee_type'] ?? 'CNY') !== 'CNY') return 'fail';
            $fee = $result['total_fee'] ?? null;
            $trade = $result['payjs_order_id'] ?? null;
            if (!is_string($fee) || !preg_match('/\A[1-9][0-9]{0,9}\z/', $fee) || bccomp($fee, '2147483647', 0) > 0
                || !is_string($trade) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $trade)) return 'fail';
            $this->orderProcessService->completedOrder($orderSN, (float) bcdiv($fee, '100', 2), $trade,
                app(PaymentGatewayLock::class)->receiptGuard($order, $pay, '/pay/payjs'));
            return 'success';
        } catch (Throwable $exception) {
            Log::warning('Payjs receipt rejected.', ['category' => get_class($exception)]);
            return 'fail';
        }
    }

    private function credentials(Pay $pay): array
    {
        return ['mchid' => (string) $pay->merchant_id, 'key' => (string) $pay->merchant_pem];
    }
}
