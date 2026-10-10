<?php

namespace App\Http\Controllers\Pay;

use App\Http\Controllers\Controller;
use App\Models\Pay;
use App\Payments\PaypalWebhookVerifier;
use App\Service\PaypalOrderService;
use App\Service\PaypalReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class PaypalWebhookController extends Controller
{
    public function receive(Request $request, string $gateway, PaypalWebhookVerifier $verifier, PaypalReconciliationService $reconciliation, PaypalOrderService $orders)
    {
        if (!$reconciliation->enabled() || DB::transactionLevel() !== 0) return $this->reply(503);
        if (!preg_match('/\A[1-9][0-9]{0,18}\z/', $gateway)) return $this->reply(404);
        try { $pay = Pay::find($gateway); }
        catch (Throwable $exception) { return $this->reply(503); }
        if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/paypal') return $this->reply(404);
        $ids = config('dujiaoka.paypal_webhook_ids');
        $webhookId = is_array($ids) ? ($ids[$gateway] ?? null) : null;
        if (!is_string($webhookId) || !preg_match('/\A[A-Z0-9]{1,128}\z/', $webhookId)) return $this->reply(503);
        $headers = [];
        foreach (['transmission_id', 'transmission_time', 'transmission_sig', 'cert_url', 'auth_algo'] as $field) {
            $headers[$field] = (string) $request->header('PayPal-' . str_replace('_', '-', $field));
        }
        try { $credentials = $orders->credentials($pay); }
        catch (Throwable $exception) { return $this->reply(503); }
        try { $event = $verifier->verify($credentials, $request->getContent(), $webhookId, $headers); }
        catch (InvalidArgumentException $exception) { return $this->reply(400); }
        catch (Throwable $exception) { return $this->reply(503); }
        try {
            $reconciliation->accept($pay, $event, $webhookId);
            return $this->reply(200);
        } catch (Throwable $exception) { return $this->reply(503); }
    }

    private function reply(int $status)
    {
        return response()->json(['received' => $status === 200], $status, ['Cache-Control' => 'no-store, private']);
    }
}
