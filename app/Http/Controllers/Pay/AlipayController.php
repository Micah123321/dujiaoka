<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Pay;
use App\Payments\AlipayClient;
use App\Payments\CurrencyAmount;
use App\Service\PaymentGatewayLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AlipayController extends PayController
{
    public function gateway(string $payway, string $orderSN)
    {
        try {
            $this->loadGateWay($orderSN, $payway);
            if (trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/alipay') {
                throw new \RuntimeException('Payment gateway does not match.');
            }
            $mode = ['zfbf2f' => 'scan', 'alipayscan' => 'scan', 'aliweb' => 'web', 'aliwap' => 'wap'][$payway] ?? null;
            if ($mode === null) throw new \RuntimeException('Payment mode does not match.');
            $config = $this->credentials($this->payGateway);
            $config['notify_url'] = url('pay/alipay/notify_url');
            $config['return_url'] = url('detail-order-sn', [$this->order->order_sn]);
            $result = app(AlipayClient::class)->checkout($mode, $config, [
                'out_trade_no' => $this->order->order_sn,
                'total_amount' => CurrencyAmount::cny($this->order->actual_price),
                'subject' => $this->order->order_sn,
            ]);
            if ($mode !== 'scan') return response($result, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
            $result['payname'] = $this->order->order_sn;
            $result['actual_price'] = (float) $this->order->actual_price;
            $result['orderid'] = $this->order->order_sn;
            $result['jump_payuri'] = $result['qr_code'];
            return $this->render('static_pages/qrpay', $result, __('dujiaoka.scan_qrcode_to_pay'));
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        } catch (Throwable $exception) {
            Log::warning('Alipay checkout failed.', ['category' => get_class($exception)]);
            return $this->err(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }

    public function notifyUrl(Request $request)
    {
        try {
            if (strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) !== 'application/x-www-form-urlencoded') return 'fail';
            // Global input middleware trims field values; signatures cover the original form bytes.
            $params = AlipayClient::parseNotification($request->getContent());
            $orderSN = $params['out_trade_no'] ?? null;
            if (!is_string($orderSN) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $orderSN)) return 'fail';
            $order = $this->orderService->detailOrderSN($orderSN);
            $pay = $order ? Pay::find($order->pay_id) : null;
            if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/alipay') return 'fail';
            $result = app(AlipayClient::class)->verify($params, $this->credentials($pay));
            if (($result['out_trade_no'] ?? null) !== (string) $order->order_sn
                || ($result['app_id'] ?? null) !== (string) $pay->merchant_id
                || ($result['currency'] ?? 'CNY') !== 'CNY') return 'fail';
            if (!in_array($result['trade_status'] ?? null, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) return 'success';
            $amount = CurrencyAmount::cny($result['total_amount'] ?? null);
            $trade = $result['trade_no'] ?? null;
            if (!is_string($trade) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $trade)) return 'fail';
            $this->orderProcessService->completedOrder($orderSN, (float) $amount, $trade,
                app(PaymentGatewayLock::class)->receiptGuard($order, $pay, '/pay/alipay'));
            return 'success';
        } catch (Throwable $exception) {
            Log::warning('Alipay receipt rejected.', ['category' => get_class($exception)]);
            return 'fail';
        }
    }

    private function credentials(Pay $pay): array
    {
        return ['app_id' => (string) $pay->merchant_id, 'public_key' => (string) $pay->merchant_key, 'private_key' => (string) $pay->merchant_pem];
    }
}
