<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Pay;
use App\Service\PaypalOrderService;
use Illuminate\Http\Request;
use Throwable;

class PaypalOrdersController extends PayController
{
    public function gateway(string $payway, string $sn)
    {
        try {
            $this->loadGateWay($sn, $payway);
            if (trim((string) $this->payGateway->pay_handleroute, '/') !== 'pay/paypal') throw new \RuntimeException();
            $params = ['orderid' => $sn];
            $return = route('paypal-v2-return', $params + ['state' => $this->state($sn, $this->payGateway->id, 'return')]);
            $cancel = route('paypal-v2-cancel', $params + ['state' => $this->state($sn, $this->payGateway->id, 'cancel')]);
            $result = app(PaypalOrderService::class)->begin($this->order, $this->payGateway, $return, $cancel);
            if (($result['approval_url'] ?? null) !== null) return redirect()->away($result['approval_url'])->header('Cache-Control', 'no-store');
            app(PaypalOrderService::class)->sync($this->order, $this->payGateway, true);
            return $this->detail($sn);
        } catch (Throwable $exception) { return $this->err(__('dujiaoka.prompt.payment_intent_unavailable')); }
    }

    public function returnUrl(Request $request) { return $this->handle($request, 'return', true); }
    public function cancelUrl(Request $request) { return $this->handle($request, 'cancel', false); }

    private function handle(Request $request, string $action, bool $capture)
    {
        try {
            if (!config('dujiaoka.paypal_orders_v2', false)) throw new \RuntimeException();
            $sn = $request->query('orderid'); $state = $request->query('state');
            if (!is_string($sn) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $sn) || !is_string($state)) throw new \RuntimeException();
            $order = $this->orderService->detailOrderSN($sn); $pay = $order ? Pay::find($order->pay_id) : null;
            if (!$order || !$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/paypal'
                || !hash_equals($this->state($sn, $pay->id, $action), $state)) throw new \RuntimeException();
            // Provider token/PayerID are routing data only; GET and capture use the saved local binding.
            app(PaypalOrderService::class)->sync($order, $pay, $capture);
            return $this->detail($sn);
        } catch (Throwable $exception) {
            return response(__('dujiaoka.prompt.payment_intent_unavailable'), 503)->header('Cache-Control', 'no-store, private');
        }
    }

    private function state(string $sn, $payId, string $action): string
    {
        $key = config('app.key');
        if (!is_string($key) || $key === '') throw new \RuntimeException();
        return hash_hmac('sha256', $sn . "\0" . $payId . "\0" . $action, $key);
    }

    private function detail(string $sn)
    {
        return redirect('detail-order-sn/' . rawurlencode($sn))->header('Cache-Control', 'no-store, private');
    }
}
