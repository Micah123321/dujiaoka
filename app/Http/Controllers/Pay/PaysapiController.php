<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Pay;
use App\Payments\FormNotification;
use App\Payments\PaysapiClient;
use App\Service\PaymentGatewayLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaysapiController extends PayController
{
    const PAY_URI = PaysapiClient::ENDPOINT;

    public function gateway(string $payway, string $orderSN)
    {
        try {
            $this->loadGateWay($orderSN, $payway);
            if (trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/paysapi') throw new \RuntimeException();
            $form = app(PaysapiClient::class)->create($payway, $this->credentials($this->payGateway), [
                'order_sn' => (string) $this->order->order_sn, 'amount' => (string) $this->order->actual_price,
                'email' => (string) $this->order->email, 'return_url' => route('paysapi-return', ['order_id' => $this->order->order_sn]),
                'notify_url' => url($this->payGateway->pay_handleroute . '/notify_url'),
            ]);
            return response()->view('common.payment-submit', $form + ['method' => 'post'])->header('Cache-Control', 'no-store, private');
        } catch (RuleValidationException $exception) { return $this->err($exception->getMessage()); }
        catch (Throwable $exception) {
            Log::warning('Paysapi checkout failed.', ['category' => get_class($exception)]);
            return $this->err(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }

    public function notifyUrl(Request $request)
    {
        try {
            $type = strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0]));
            if ($type !== 'application/x-www-form-urlencoded') return 'fail';
            $data = FormNotification::parse((string) $request->getContent());
            $sn = $data['orderid'] ?? '';
            if (!preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $sn)) return 'error';
            $order = $this->orderService->detailOrderSN($sn);
            $pay = $order ? Pay::find($order->pay_id) : null;
            if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/paysapi') return 'error';
            $result = app(PaysapiClient::class)->verify($data, $this->credentials($pay));
            if ($result['orderid'] !== $sn || !in_array((string) $pay->pay_check, ['pszfb', 'pswx'], true)) return 'fail';
            // realprice may differ for receipt matching; only the signed shop price enters fulfillment.
            $this->orderProcessService->completedOrder($sn, (float) $result['price'], (string) $result['paysapi_id'],
                app(PaymentGatewayLock::class)->receiptGuard($order, $pay, '/pay/paysapi'));
            return 'success';
        } catch (Throwable $exception) {
            Log::warning('Paysapi receipt rejected.', ['category' => get_class($exception)]);
            return 'fail';
        }
    }

    public function returnUrl(Request $request)
    {
        return redirect(url('detail-order-sn', ['orderSN' => (string) $request->query('order_id', '')]));
    }

    private function credentials(Pay $pay): array
    {
        return ['uid' => (string) $pay->merchant_id, 'key' => (string) $pay->merchant_pem];
    }
}
