<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Pay;
use App\Payments\BepusdtClient;
use App\Payments\JsonNotification;
use App\Service\PaymentGatewayLock;
use Illuminate\Support\Facades\Log;
use Throwable;
use Illuminate\Http\Request;

class BepusdtController extends PayController
{
    public function gateway(string $payway, string $orderSN)
    {
        try {
            $this->loadGateWay($orderSN, $payway);
            if (trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/bepusdt') throw new \RuntimeException();
            $paymentUrl = app(BepusdtClient::class)->create($this->credentials($this->payGateway), [
                'order_sn' => (string) $this->order->order_sn, 'amount' => (string) $this->order->actual_price,
                'notify_url' => url('pay/bepusdt/notify_url'),
                'return_url' => route('bepusdt-return', ['order_id' => $this->order->order_sn]),
            ]);
            return redirect()->away($paymentUrl);
        } catch (RuleValidationException $exception) { return $this->err($exception->getMessage()); }
        catch (Throwable $exception) {
            Log::warning('BEpusdt checkout failed.', ['category' => get_class($exception), 'stage' => $exception->getCode(), 'order_sn' => $orderSN]);
            return $this->err(__('dujiaoka.prompt.abnormal_payment_channel'));
        }
    }


    public function notifyUrl(Request $request)
    {
        try {
            if (strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) !== 'application/json') return 'fail';
            $data = JsonNotification::parse((string) $request->getContent(), ['amount']);
            $sn = $data['order_id'] ?? null;
            if (!is_string($sn) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $sn)) return 'fail';
            $order = $this->orderService->detailOrderSN($sn);
            $gateway = $order ? Pay::find($order->pay_id) : null;
            if (!$gateway || trim((string) $gateway->pay_handleroute, '/') !== 'pay/bepusdt') return 'fail';
            $receipt = app(BepusdtClient::class)->verify($data, $this->credentials($gateway));
            if (bccomp((string) $order->actual_price, (string) $receipt['amount'], 2) !== 0) return 'fail';
            // Lifecycle events need an acknowledgement, while only a confirmed payment may fulfill.
            if (in_array($receipt['status'], [2, '2'], true)) {
                $this->orderProcessService->completedOrder($sn, (float) $receipt['amount'], $receipt['trade_id'],
                    app(PaymentGatewayLock::class)->receiptGuard($order, $gateway, '/pay/bepusdt'));
            }
            return 'ok';
        } catch (Throwable $exception) {
            Log::warning('BEpusdt receipt rejected.', ['category' => get_class($exception)]);
            return 'fail';
        }
    }

    private function credentials(Pay $gateway): array
    {
        $method = strtolower(trim((string) $gateway->pay_check));
        return ['key' => (string) $gateway->merchant_id, 'endpoint' => trim((string) $gateway->merchant_pem), 'method' => $method];
    }


    public function returnUrl(Request $request)
    {
        $orderSN = $request->query('order_id');
        if (!is_string($orderSN) || $orderSN === '') {
            abort(400);
        }
        // The signed server callback alone changes payment state.
        return redirect(url('detail-order-sn', ['orderSN' => $orderSN]));
    }
}
