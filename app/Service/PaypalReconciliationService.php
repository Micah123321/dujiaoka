<?php

namespace App\Service;

use App\Models\Order;
use App\Models\Pay;
use App\Models\PaypalOrder;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PaypalReconciliationService
{
    private const EVENTS = ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING',
        'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED'];
    private $orders;

    public function __construct(PaypalOrderService $orders)
    {
        $this->orders = $orders;
    }

    public function enabled(): bool
    {
        return (bool) config('dujiaoka.paypal_reconciliation', false)
            && (bool) config('dujiaoka.paypal_orders_v2', false)
            && (bool) config('dujiaoka.payment_quote_freeze', false) && $this->orders->enabled();
    }

    public function accept(Pay $pay, array $event, ?string $webhookId = null): bool
    {
        if (!$this->enabled()) {
            throw new RuntimeException('PayPal reconciliation disabled.', 503);
        }
        $this->committed();
        if (!in_array($event['type'] ?? null, self::EVENTS, true)) {
            return false;
        }
        try {
            if (!is_string($event['id'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $event['id'])) {
                throw new RuntimeException();
            }
            foreach (['provider_id', 'resource_id'] as $key) {
                if (!array_key_exists($key, $event) || ($event[$key] !== null
                    && (!is_string($event[$key]) || !preg_match('/\A[A-Z0-9]{17}\z/', $event[$key])))) {
                    throw new RuntimeException();
                }
            }
            if ($event['resource_id'] === null || ($event['type'] === 'CHECKOUT.ORDER.APPROVED'
                && $event['provider_id'] !== null && $event['provider_id'] !== $event['resource_id'])) {
                throw new RuntimeException();
            }
            return DB::transaction(function () use ($pay, $event, $webhookId) {
                $current = Pay::whereKey($pay->id)->lockForUpdate()->first();
                if (!$current || trim((string) $current->pay_handleroute, '/') !== 'pay/paypal'
                    || (string) $current->pay_handleroute !== (string) $pay->pay_handleroute
                    || $this->orders->accountFingerprint($current) !== $this->orders->accountFingerprint($pay)
                    || (string) $current->merchant_key !== (string) $pay->merchant_key) {
                    throw new RuntimeException();
                }
                $ids = config('dujiaoka.paypal_webhook_ids');
                if ($webhookId !== null && (!is_array($ids) || ($ids[$pay->id] ?? null) !== $webhookId)) {
                    throw new RuntimeException();
                }
                $fingerprint = $this->orders->accountFingerprint($current);
                $existing = DB::table('paypal_webhook_events')->where('pay_id', $pay->id)->where('event_id', $event['id'])->first();
                if ($existing) {
                    if ($existing->event_id !== $event['id'] || $existing->provider_id !== $event['provider_id'] || $existing->resource_id !== $event['resource_id']
                        || $existing->event_type !== $event['type'] || $existing->account_fingerprint !== $fingerprint) {
                        throw new RuntimeException();
                    }
                    return false;
                }
                DB::table('paypal_webhook_events')->insert(['pay_id' => $pay->id, 'event_id' => $event['id'],
                    'event_type' => $event['type'], 'provider_id' => $event['provider_id'], 'resource_id' => $event['resource_id'],
                    'account_fingerprint' => $fingerprint, 'available_at' => Carbon::now(),
                    'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
                return true;
            });
        } catch (Throwable $exception) {
            throw new RuntimeException('PayPal event persistence failed.', 503);
        }
    }

    public function reconcileDue(int $limit = 25): array
    {
        $counts = ['checked' => 0, 'completed' => 0, 'retry' => 0, 'manual' => 0, 'ignored' => 0];
        if (!$this->enabled()) {
            return $counts;
        }
        $this->committed();
        $limit = max(1, min(100, $limit));
        foreach ($this->dueEvents()->orderBy('available_at')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $outcome = $this->processEvent((int) $id);
            if (isset($counts[$outcome])) ++$counts[$outcome];
        }
        $newIds = DB::table('paypal_orders')->whereNotExists(function (Builder $q) {
            $q->select(DB::raw('1'))->from('paypal_reconciliations')
                ->whereColumn('paypal_reconciliations.order_binding_id', 'paypal_orders.id');
        })->orderBy('id')->limit($limit)->pluck('id');
        foreach ($newIds as $id) $this->ensureState((int) $id);
        foreach ($this->dueStates()->orderBy('available_at')->orderBy('id')->limit($limit)->pluck('order_binding_id') as $id) {
            $outcome = $this->check((int) $id);
            if (isset($counts[$outcome])) ++$counts[$outcome];
        }
        return $counts;
    }

    public function retry(int $bindingId): bool
    {
        if (!$this->enabled()) return false;
        $this->committed();
        return (bool) $this->freeLease(DB::table('paypal_reconciliations'))->where('order_binding_id', $bindingId)
            ->whereNotNull('failed_at')->update(['failed_at' => null, 'finished_at' => null, 'failures' => 0, 'checks' => 0,
                'last_error' => null, 'available_at' => Carbon::now(), 'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
    }

    public function retryEvent(int $eventId): bool
    {
        if (!$this->enabled()) return false;
        $this->committed();
        return (bool) $this->freeLease(DB::table('paypal_webhook_events'))->where('id', $eventId)
            ->whereNotNull('failed_at')->whereNull('processed_at')->update(['failed_at' => null, 'attempts' => 0,
                'last_error' => null, 'available_at' => Carbon::now(), 'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
    }

    private function processEvent(int $id): string
    {
        $token = bin2hex(random_bytes(16));
        if (!$this->dueEvents()->where('id', $id)->update(['lease_token' => $token,
            'leased_until' => Carbon::now()->addSeconds(90), 'updated_at' => Carbon::now()])) return 'busy';
        $event = DB::table('paypal_webhook_events')->where('id', $id)->where('lease_token', $token)->first();
        try {
            $pay = Pay::find($event->pay_id);
            if (!$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/paypal'
                || $event->account_fingerprint !== $this->orders->accountFingerprint($pay)) throw new RuntimeException();
            $normalized = ['id' => $event->event_id, 'type' => $event->event_type,
                'provider_id' => $event->provider_id, 'resource_id' => $event->resource_id];
            $candidate = $event->event_type === 'CHECKOUT.ORDER.APPROVED' ? $event->resource_id : $event->provider_id;
            // Known IDs need no recovery request; the shared state gates every provider sync.
            $binding = $candidate !== null ? PaypalOrder::where('provider_id', $candidate)->first()
                : PaypalOrder::where('capture_id', $event->resource_id)->first();
            if (!$binding) $binding = $this->orders->resolveEvent($pay, $normalized);
            if ($binding && ((string) $binding->pay_id !== (string) $pay->id
                || ($candidate !== null && $binding->provider_id !== $candidate))) {
                $binding = null;
            }
            $outcome = $binding ? $this->check((int) $binding->id, true) : 'ignored';
            if ($outcome === 'busy') {
                $state = DB::table('paypal_reconciliations')->where('order_binding_id', $binding->id)->first();
                $when = $state && Carbon::parse($state->available_at)->gt(Carbon::now())
                    ? Carbon::parse($state->available_at) : Carbon::now()->addSeconds(90);
                $saved = $this->ownedEvent($id, $token)->update(['available_at' => $when, 'lease_token' => null,
                    'leased_until' => null, 'updated_at' => Carbon::now()]);
                return $saved ? 'ignored' : 'busy';
            }
            if (in_array($outcome, ['retry', 'manual'], true)) throw new RuntimeException();
            $saved = $this->ownedEvent($id, $token)->update(['processed_at' => Carbon::now(), 'last_error' => null,
                'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
            return $saved ? $outcome : 'busy';
        } catch (Throwable $exception) {
            $attempts = (int) $event->attempts + 1;
            $manual = $attempts >= 10;
            $saved = $this->ownedEvent($id, $token)->update(['attempts' => $attempts, 'last_error' => 'event_sync_failed',
                'failed_at' => $manual ? Carbon::now() : null, 'available_at' => Carbon::now()->addSeconds($this->backoff($attempts)),
                'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
            return $saved ? ($manual ? 'manual' : 'retry') : 'busy';
        }
    }

    private function ensureState(int $bindingId): void
    {
        if (DB::table('paypal_reconciliations')->where('order_binding_id', $bindingId)->exists()) return;
        try {
            DB::table('paypal_reconciliations')->insert(['order_binding_id' => $bindingId, 'available_at' => Carbon::now(),
                'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
        } catch (QueryException $exception) {
            $info = $exception->errorInfo;
            $unique = ($info[0] ?? '') === '23000' && ((int) ($info[1] ?? 0) === 1062
                || ((int) ($info[1] ?? 0) === 19 && strpos((string) ($info[2] ?? ''),
                    'UNIQUE constraint failed: paypal_reconciliations.order_binding_id') !== false));
            if (!$unique || !DB::table('paypal_reconciliations')->where('order_binding_id', $bindingId)->exists()) {
                throw new RuntimeException('PayPal reconciliation persistence failed.', 503);
            }
        }
    }

    public function check(int $bindingId, bool $force = false): string
    {
        if (!$this->enabled()) return 'ignored';
        $this->committed();
        $this->ensureState($bindingId);
        $current = DB::table('paypal_reconciliations')->where('order_binding_id', $bindingId)->first();
        if ($current->finished_at !== null) return 'ignored';
        $recovered = $force && $current->last_error === 'provider_id_unknown'
            && PaypalOrder::whereKey($bindingId)->whereNotNull('provider_id')->exists();
        $query = $this->freeLease(DB::table('paypal_reconciliations'))->whereNull('finished_at');
        if ($recovered) {
            $query->where('last_error', 'provider_id_unknown');
        } else {
            $query->whereNull('failed_at')->where(function (Builder $q) use ($force) {
                $q->where('available_at', '<=', Carbon::now());
                if ($force) $q->orWhere('failures', 0);
            });
        }
        $token = bin2hex(random_bytes(16));
        if (!$query->where('order_binding_id', $bindingId)->update(['lease_token' => $token,
            'leased_until' => Carbon::now()->addSeconds(90), 'checks' => DB::raw('checks + 1'), 'updated_at' => Carbon::now()])) return 'busy';
        $state = DB::table('paypal_reconciliations')->where('order_binding_id', $bindingId)->where('lease_token', $token)->first();
        try {
            $binding = PaypalOrder::find($bindingId);
            $order = $binding ? Order::find($binding->order_id) : null;
            $pay = $binding ? Pay::find($binding->pay_id) : null;
            if (!$binding || !$order || !$pay || trim((string) $pay->pay_handleroute, '/') !== 'pay/paypal'
                || $binding->account_fingerprint !== $this->orders->accountFingerprint($pay)) throw new RuntimeException();
            if ($binding->provider_id === null) {
                $age = Carbon::now()->getTimestamp() - $binding->created_at->getTimestamp();
                $manual = $age < 0 || $age >= 5 * 3600;
                $saved = $this->ownedState($bindingId, $token)->update(['last_status' => 'awaiting_provider_id',
                    'checked_at' => Carbon::now(), 'failed_at' => $manual ? Carbon::now() : null,
                    'available_at' => Carbon::now()->addMinutes(15), 'last_error' => $manual ? 'provider_id_unknown' : null,
                    'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
                return $saved ? ($manual ? 'manual' : 'checked') : 'busy';
            }
            $this->committed();
            $result = $this->orders->sync($order, $pay, true);
            $status = $result['status'] ?? null;
            if (!in_array($status, ['CREATED', 'SAVED', 'APPROVED', 'VOIDED', 'COMPLETED', 'PAYER_ACTION_REQUIRED', 'CAPTURE_PENDING'], true)) throw new RuntimeException();
            $finished = in_array($status, ['COMPLETED', 'VOIDED'], true);
            $saved = $this->ownedState($bindingId, $token)->update(['last_status' => $status, 'checked_at' => Carbon::now(),
                'finished_at' => $finished ? Carbon::now() : null, 'failed_at' => null, 'failures' => 0, 'last_error' => null,
                'available_at' => Carbon::now()->addSeconds(in_array($status, ['APPROVED', 'CAPTURE_PENDING'], true) ? 300 : 900),
                'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
            return $saved ? ($status === 'COMPLETED' ? 'completed' : 'checked') : 'busy';
        } catch (Throwable $exception) {
            $failures = (int) $state->failures + 1;
            $manual = $failures >= 10;
            $saved = $this->ownedState($bindingId, $token)->update(['failures' => $failures, 'checked_at' => Carbon::now(),
                'failed_at' => $manual ? Carbon::now() : null, 'last_error' => 'order_sync_failed',
                'available_at' => Carbon::now()->addSeconds($this->backoff($failures)),
                'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
            return $saved ? ($manual ? 'manual' : 'retry') : 'busy';
        }
    }

    private function dueEvents(): Builder
    {
        return $this->freeLease(DB::table('paypal_webhook_events')->whereNull('processed_at')->whereNull('failed_at')
            ->where('available_at', '<=', Carbon::now()));
    }

    private function dueStates(): Builder
    {
        return $this->freeLease(DB::table('paypal_reconciliations')->whereNull('finished_at')->whereNull('failed_at')
            ->where('available_at', '<=', Carbon::now()));
    }

    private function freeLease(Builder $query): Builder
    {
        return $query->where(function (Builder $q) { $q->whereNull('leased_until')->orWhere('leased_until', '<=', Carbon::now()); });
    }

    private function ownedEvent(int $id, string $token): Builder
    {
        return DB::table('paypal_webhook_events')->where('id', $id)->where('lease_token', $token)->where('leased_until', '>', Carbon::now());
    }

    private function ownedState(int $id, string $token): Builder
    {
        return DB::table('paypal_reconciliations')->where('order_binding_id', $id)->where('lease_token', $token)->where('leased_until', '>', Carbon::now());
    }

    private function backoff(int $failures): int
    {
        return min(3600, 60 * (2 ** min(6, max(0, $failures - 1))));
    }

    private function committed(): void
    {
        if (DB::transactionLevel() !== 0) throw new RuntimeException('PayPal reconciliation requires committed state.', 503);
    }
}
