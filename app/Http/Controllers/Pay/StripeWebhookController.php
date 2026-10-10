<?php

namespace App\Http\Controllers\Pay;

use App\Http\Controllers\Controller;
use App\Models\Pay;
use App\Payments\StripeWebhookVerifier;
use App\Service\StripeReconciliationService;
use Illuminate\Http\Request;
use Throwable;

class StripeWebhookController extends Controller
{
    public function receive(Request $request, string $gateway, StripeWebhookVerifier $verifier, StripeReconciliationService $reconciliation)
    {
        if (!$reconciliation->enabled()) {
            return $this->reply(503);
        }
        if (!preg_match('/\A[1-9][0-9]{0,18}\z/', $gateway)) {
            return $this->reply(404);
        }
        try {
            $pay = Pay::find($gateway);
        } catch (Throwable $exception) {
            return $this->reply(503);
        }
        if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/stripe') {
            return $this->reply(404);
        }
        if (!preg_match('/\Awhsec_[A-Za-z0-9_]{1,249}\z/', (string) $pay->merchant_key)) {
            return $this->reply(503);
        }
        try {
            $event = $verifier->verify($request->getContent(), (string) $request->header('Stripe-Signature'), (string) $pay->merchant_key);
        } catch (Throwable $exception) {
            return $this->reply(400);
        }
        try {
            $reconciliation->accept($pay, $event);
            return $this->reply(200);
        } catch (Throwable $exception) {
            // No success acknowledgement until the event is durably recorded; Stripe may retry.
            return $this->reply(503);
        }
    }

    private function reply(int $status)
    {
        return response()->json(['received' => $status === 200], $status, ['Cache-Control' => 'no-store, private']);
    }
}
