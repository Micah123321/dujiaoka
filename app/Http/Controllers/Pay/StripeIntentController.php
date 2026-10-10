<?php

namespace App\Http\Controllers\Pay;

use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use App\Models\Order;
use App\Models\Pay;
use App\Models\StripePaymentIntent;
use App\Payments\CurrencyAmount;
use App\Service\PaymentQuoteService;
use App\Service\StripePaymentIntentService;
use Illuminate\Http\Request;
use Throwable;

class StripeIntentController extends PayController
{
    public function checkout(Order $order, Pay $gateway, string $payway)
    {
        try {
            $quotes = app(PaymentQuoteService::class);
            if (!$quotes->enabled()) {
                throw new RuleValidationException(__('dujiaoka.prompt.payment_intent_unavailable'));
            }
            $binding = StripePaymentIntent::where('order_id', $order->id)->first();
            $usd = null;
            if (!$binding || $binding->currency === 'usd') {
                try {
                    $usd = $quotes->cnyToUsd($order, $gateway, 'cmb');
                } catch (Throwable $exception) {
                    if ($binding) {
                        throw $exception;
                    }
                }
            }
            $clientConfig = ['orderid' => $order->order_sn, 'publishableKey' => $gateway->merchant_id,
                'currency' => $binding ? $binding->currency : ($payway === 'alipay' || $usd === null ? 'cny' : 'usd'),
                'beginUrl' => route('stripe-intent-begin'), 'statusUrl' => route('stripe-intent-status'),
                'returnUrl' => route('stripe-intent-return', ['orderid' => $order->order_sn]),
                'csrf' => csrf_token()];
            return response()->view('common.stripe-intent', ['order' => $order, 'usd' => $usd,
                'cny' => CurrencyAmount::cny($order->actual_price), 'binding' => $binding, 'clientConfig' => $clientConfig])
                ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        } catch (Throwable $exception) {
            return $this->err(__('dujiaoka.prompt.payment_intent_unavailable'));
        }
    }

    public function begin(Request $request)
    {
        try {
            list($order, $gateway) = $this->context($request);
            $currency = $request->input('currency');
            if (!is_string($currency)) {
                throw new RuleValidationException(__('dujiaoka.prompt.payment_intent_invalid'));
            }
            return $this->json(app(StripePaymentIntentService::class)->begin($order, $gateway, $currency));
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function status(Request $request)
    {
        try {
            list($order, $gateway) = $this->context($request);
            $state = app(StripePaymentIntentService::class)->sync($order, $gateway);
            $state['order_url'] = url('detail-order-sn/' . rawurlencode($order->order_sn));
            return $this->json($state);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function returnUrl(Request $request)
    {
        try {
            list($order, $gateway) = $this->context($request);
            app(StripePaymentIntentService::class)->sync($order, $gateway);
            return redirect('detail-order-sn/' . rawurlencode($order->order_sn))->header('Cache-Control', 'no-store');
        } catch (Throwable $exception) {
            return $this->err(__('dujiaoka.prompt.payment_intent_unavailable'));
        }
    }

    private function context(Request $request): array
    {
        if (!app(StripePaymentIntentService::class)->enabled()) {
            throw new RuleValidationException(__('dujiaoka.prompt.payment_intent_unavailable'));
        }
        $sn = $request->input('orderid');
        if (!is_string($sn) || !preg_match('/\A[a-zA-Z0-9_-]{1,128}\z/D', $sn)) {
            throw new RuleValidationException(__('dujiaoka.prompt.payment_intent_invalid'));
        }
        $order = $this->orderService->detailOrderSN($sn);
        $gateway = $order ? Pay::find($order->pay_id) : null;
        if (!$order || !$gateway || trim((string) $gateway->pay_handleroute, '/') !== 'pay/stripe') {
            throw new RuleValidationException(__('dujiaoka.prompt.payment_intent_invalid'));
        }
        return [$order, $gateway];
    }

    private function json(array $data, int $status = 200)
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, private');
    }

    private function failure(Throwable $exception)
    {
        $message = $exception instanceof RuleValidationException ? $exception->getMessage()
            : __('dujiaoka.prompt.payment_intent_unavailable');
        $invalid = [__('dujiaoka.prompt.payment_intent_invalid'), __('dujiaoka.prompt.payment_method_locked')];
        return $this->json(['message' => $message], in_array($message, $invalid, true) ? 422 : 503);
    }
}
