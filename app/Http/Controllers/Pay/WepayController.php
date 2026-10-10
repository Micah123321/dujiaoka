<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Pay;
use App\Payments\CurrencyAmount;
use App\Payments\WechatV2Client;
use App\Service\PaymentGatewayLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class WepayController extends PayController
{
    public function gateway(string $payway, string $orderSN)
    {
        try {
            $this->loadGateWay($orderSN, $payway);
            if ($payway !== 'wescan' || trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/wepay') {
                throw new \RuntimeException('Payment gateway does not match.');
            }
            $result = app(WechatV2Client::class)->create($this->credentials($this->payGateway), [
                'out_trade_no' => $this->order->order_sn,
                'body' => $this->order->order_sn,
                'total_fee' => bcmul(CurrencyAmount::cny($this->order->actual_price), '100', 0),
                'notify_url' => url('pay/wepay/notify_url'),
                'spbill_create_ip' => request()->server('SERVER_ADDR', '127.0.0.1'),
            ]);
            $result['qr_code'] = $result['code_url'];
            $result['payname'] = $this->payGateway->pay_name;
            $result['actual_price'] = (float) $this->order->actual_price;
            $result['orderid'] = $this->order->order_sn;
            return $this->render('static_pages/qrpay', $result, __('dujiaoka.scan_qrcode_to_pay'));
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Wechat checkout failed.', ['category' => get_class($exception)]);
            return $this->err(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }

    public function notifyUrl(Request $request)
    {
        try {
            $xml = $request->getContent();
            $params = WechatV2Client::parseXml($xml);
            $orderSN = $params['out_trade_no'] ?? null;
            if (!is_string($orderSN) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $orderSN)) return $this->ack(false);
            $order = $this->orderService->detailOrderSN($orderSN);
            $pay = $order ? Pay::find($order->pay_id) : null;
            if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/wepay') return $this->ack(false);
            $result = app(WechatV2Client::class)->verify($xml, $this->credentials($pay));
            if (($result['return_code'] ?? null) !== 'SUCCESS' || ($result['result_code'] ?? null) !== 'SUCCESS'
                || ($result['trade_type'] ?? null) !== 'NATIVE' || ($result['fee_type'] ?? 'CNY') !== 'CNY'
                || ($result['out_trade_no'] ?? null) !== (string) $order->order_sn) return $this->ack(false);
            $fee = $result['total_fee'] ?? null;
            $trade = $result['transaction_id'] ?? null;
            if (!is_string($fee) || !preg_match('/\A[1-9][0-9]{0,9}\z/', $fee) || bccomp($fee, '2147483647', 0) > 0
                || !is_string($trade) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $trade)) return $this->ack(false);
            $this->orderProcessService->completedOrder($orderSN, (float) bcdiv($fee, '100', 2), $trade,
                app(PaymentGatewayLock::class)->receiptGuard($order, $pay, '/pay/wepay'));
            return $this->ack(true);
        } catch (Throwable $exception) {
            Log::warning('Wechat receipt rejected.', ['category' => get_class($exception)]);
            return $this->ack(false);
        }
    }

    private function credentials(Pay $pay): array
    {
        return ['app_id' => (string) $pay->merchant_id, 'mch_id' => (string) $pay->merchant_key, 'key' => (string) $pay->merchant_pem];
    }

    private function ack(bool $success)
    {
        return response(WechatV2Client::toXml(['return_code' => $success ? 'SUCCESS' : 'FAIL', 'return_msg' => $success ? 'OK' : 'FAIL']),
            200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
