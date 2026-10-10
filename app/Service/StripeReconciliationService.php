<?php

namespace App\Service;

use App\Models\Order;
use App\Models\Pay;
use App\Models\StripePaymentIntent;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class StripeReconciliationService
{
    private const LEASE_SECONDS = 90;
    private const MAX_FAILURES = 10;
    private $intents;

    public function __construct(StripePaymentIntentService $intents)
    {
        $this->intents = $intents;
    }

    public function enabled(): bool
    {
        return (bool) config('dujiaoka.stripe_reconciliation', false)
            && $this->intents->enabled() && (bool) config('dujiaoka.payment_quote_freeze', false);
    }

    public function accept(Pay $pay, array $event): bool
    {
        if (!$this->enabled()) {
            throw new RuntimeException('Stripe reconciliation is disabled.');
        }
        $this->committed();
        if (!in_array($event['type'], ['payment_intent.created', 'payment_intent.succeeded', 'payment_intent.processing',
            'payment_intent.payment_failed', 'payment_intent.canceled', 'payment_intent.amount_capturable_updated',
            'payment_intent.requires_action'], true) || $event['account'] !== null) {
            return false;
        }
        if (($event['livemode'] ? 'live' : 'test') !== $this->mode($pay)) {
            throw new RuntimeException('Stripe event mode mismatch.');
        }
        try {
            return DB::transaction(function () use ($pay, $event) {
                // Serializing per gateway avoids insert races without relying on broad INSERT IGNORE semantics.
                $current = Pay::whereKey($pay->id)->lockForUpdate()->first();
                if (!$current || trim((string) $current->pay_handleroute, '/') !== 'pay/stripe'
                    || $this->fingerprint($current) !== $this->fingerprint($pay)
                    || (string) $current->merchant_key !== (string) $pay->merchant_key) {
                    throw new RuntimeException();
                }
                $existing = DB::table('stripe_webhook_events')->where('pay_id', $pay->id)->where('event_id', $event['id'])->first();
                if ($existing) {
                    if ($existing->provider_id !== $event['intent_id'] || $existing->event_type !== $event['type']
                        || (bool) $existing->livemode !== $event['livemode'] || $existing->account_fingerprint !== $this->fingerprint($pay)) {
                        throw new RuntimeException();
                    }
                    return false;
                }
                DB::table('stripe_webhook_events')->insert([
                    'pay_id' => $pay->id, 'event_id' => $event['id'], 'provider_id' => $event['intent_id'],
                    'event_type' => $event['type'], 'livemode' => $event['livemode'], 'account_fingerprint' => $this->fingerprint($pay),
                    'available_at' => Carbon::now(), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
                ]);
                return true;
            });
        } catch (Throwable $exception) {
            throw new RuntimeException('Stripe event persistence failed.');
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
        $events = $this->dueEvents()->orderBy('id')->limit($limit)->pluck('id');
        foreach ($events as $id) {
            $outcome = $this->processEvent((int) $id);
            if (isset($counts[$outcome])) {
                ++$counts[$outcome];
            }
        }
        // Older bindings and missed webhooks are recoverable without a browser or queue publish.
        $newIds = DB::table('stripe_payment_intents')->whereNotExists(function (Builder $q) {
            $q->select(DB::raw('1'))->from('stripe_reconciliations')
                ->whereColumn('stripe_reconciliations.intent_id', 'stripe_payment_intents.id');
        })->orderBy('id')->limit($limit)->pluck('id');
        foreach ($newIds as $id) {
            $this->ensureState((int) $id);
        }
        $due = $this->dueStates()->orderBy('available_at')->orderBy('id')->limit($limit)->pluck('intent_id');
        foreach ($due as $id) {
            $outcome = $this->check((int) $id);
            if (isset($counts[$outcome])) {
                ++$counts[$outcome];
            }
        }
        return $counts;
    }

    public function retry(int $intentId): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $this->committed();
        return (bool) DB::table('stripe_reconciliations')->where('intent_id', $intentId)->whereNotNull('failed_at')
            ->where(function (Builder $q) { $q->whereNull('leased_until')->orWhere('leased_until', '<=', Carbon::now()); })
            ->update(['failed_at' => null, 'finished_at' => null, 'failures' => 0, 'last_error' => null,
                'available_at' => Carbon::now(), 'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
    }

    public function retryEvent(int $eventId): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $this->committed();
        return (bool) $this->freeLease(DB::table('stripe_webhook_events'))->where('id', $eventId)
            ->whereNotNull('failed_at')->whereNull('processed_at')->update(['failed_at' => null, 'attempts' => 0,
                'last_error' => null, 'available_at' => Carbon::now(), 'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
    }

    private function processEvent(int $id): string
    {
        $token = bin2hex(random_bytes(16));
        if (!$this->dueEvents()->where('id', $id)->update(['lease_token' => $token, 'leased_until' => Carbon::now()->addSeconds(self::LEASE_SECONDS),
            'attempts' => DB::raw('attempts + 1'), 'updated_at' => Carbon::now()])) {
            return 'ignored';
        }
        $event = DB::table('stripe_webhook_events')->where('id', $id)->where('lease_token', $token)->first();
        try {
            $pay = Pay::find($event->pay_id);
            if (!$pay || $event->account_fingerprint !== $this->fingerprint($pay)
                || trim((string) $pay->pay_handleroute, '/') !== 'pay/stripe') {
                throw new RuntimeException();
            }
            $binding = $this->intents->recoverProvider($pay, $event->provider_id);
            $outcome = $binding ? $this->check((int) $binding->id, true) : 'ignored';
            if (in_array($outcome, ['busy', 'retry', 'manual'], true)) {
                throw new RuntimeException();
            }
            $saved = $this->ownedEvent($id, $token)->update(['processed_at' => Carbon::now(), 'last_error' => null,
                'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
            return $saved ? $outcome : 'busy';
        } catch (Throwable $exception) {
            $manual = (int) $event->attempts >= self::MAX_FAILURES;
            $this->ownedEvent($id, $token)->update(['last_error' => 'event_sync_failed', 'failed_at' => $manual ? Carbon::now() : null,
                'available_at' => Carbon::now()->addSeconds($this->backoff((int) $event->attempts)),
                'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
            return $manual ? 'manual' : 'retry';
        }
    }

    private function ensureState(int $intentId): void
    {
        DB::table('stripe_reconciliations')->insertOrIgnore(['intent_id' => $intentId, 'available_at' => Carbon::now(),
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
    }

    private function check(int $intentId, bool $force = false): string
    {
        $this->ensureState($intentId);
        $token = bin2hex(random_bytes(16));
        $current = DB::table('stripe_reconciliations')->where('intent_id', $intentId)->first();
        if ($force && $current->finished_at !== null) {
            return 'ignored';
        }
        $query = $force ? $this->freeLease(DB::table('stripe_reconciliations'))->whereNull('finished_at')
            ->where(function (Builder $q) {
                $q->where(function (Builder $retry) {
                    $retry->whereNull('failed_at')->where(function (Builder $when) {
                        $when->where('available_at', '<=', Carbon::now())->orWhere('failures', 0);
                    });
                })->orWhere('last_error', 'provider_id_unknown');
            }) : $this->dueStates();
        if (!$query->where('intent_id', $intentId)->update(['lease_token' => $token,
            'leased_until' => Carbon::now()->addSeconds(self::LEASE_SECONDS), 'checks' => DB::raw('checks + 1'), 'updated_at' => Carbon::now()])) {
            return 'busy';
        }
        $state = DB::table('stripe_reconciliations')->where('intent_id', $intentId)->where('lease_token', $token)->first();
        try {
            $binding = StripePaymentIntent::find($intentId);
            $order = $binding ? Order::find($binding->order_id) : null;
            $pay = $binding ? Pay::find($binding->pay_id) : null;
            if (!$binding || !$order || !$pay) {
                throw new RuntimeException();
            }
            if ($binding->provider_id === null) {
                $age = Carbon::now()->getTimestamp() - $binding->created_at->getTimestamp();
                $manual = $age < 0 || $age >= 23 * 3600;
                $saved = $this->ownedState($intentId, $token)->update(['last_status' => 'awaiting_provider_id',
                    'checked_at' => Carbon::now(), 'failed_at' => $manual ? Carbon::now() : null,
                    'available_at' => Carbon::now()->addMinutes(15), 'last_error' => $manual ? 'provider_id_unknown' : null,
                    'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
                return $saved ? ($manual ? 'manual' : 'checked') : 'busy';
            }
            $result = $this->intents->sync($order, $pay);
            $finished = in_array($result['status'], ['succeeded', 'canceled'], true);
            $saved = $this->ownedState($intentId, $token)->update(['last_status' => $result['status'], 'checked_at' => Carbon::now(),
                'finished_at' => $finished ? Carbon::now() : null, 'failed_at' => null, 'failures' => 0, 'last_error' => null,
                'available_at' => Carbon::now()->addSeconds($result['status'] === 'processing' ? 300 : 900),
                'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
            return $saved ? ($result['status'] === 'succeeded' ? 'completed' : 'checked') : 'busy';
        } catch (Throwable $exception) {
            $failures = (int) $state->failures + 1;
            $manual = $failures >= self::MAX_FAILURES;
            $this->ownedState($intentId, $token)->update(['failures' => $failures, 'checked_at' => Carbon::now(),
                'failed_at' => $manual ? Carbon::now() : null, 'last_error' => 'intent_sync_failed',
                'available_at' => Carbon::now()->addSeconds($this->backoff($failures)),
                'lease_token' => null, 'leased_until' => null, 'updated_at' => Carbon::now()]);
            return $manual ? 'manual' : 'retry';
        }
    }

    private function dueEvents(): Builder
    {
        return $this->freeLease(DB::table('stripe_webhook_events')->whereNull('processed_at')->whereNull('failed_at')
            ->where('available_at', '<=', Carbon::now()));
    }

    private function dueStates(): Builder
    {
        return $this->freeLease(DB::table('stripe_reconciliations')->whereNull('finished_at')->whereNull('failed_at')
            ->where('available_at', '<=', Carbon::now()));
    }

    private function freeLease(Builder $query): Builder
    {
        return $query->where(function (Builder $q) { $q->whereNull('leased_until')->orWhere('leased_until', '<=', Carbon::now()); });
    }

    private function ownedEvent(int $id, string $token): Builder
    {
        return DB::table('stripe_webhook_events')->where('id', $id)->where('lease_token', $token)->where('leased_until', '>', Carbon::now());
    }

    private function ownedState(int $id, string $token): Builder
    {
        return DB::table('stripe_reconciliations')->where('intent_id', $id)->where('lease_token', $token)->where('leased_until', '>', Carbon::now());
    }

    private function backoff(int $failures): int
    {
        return min(3600, 60 * (2 ** min(6, max(0, $failures - 1))));
    }

    private function mode(Pay $pay): string
    {
        if (!preg_match('/\A(?:sk|rk)_(test|live)_[A-Za-z0-9_]+\z/', (string) $pay->merchant_pem, $m)
            || !preg_match('/\Apk_' . $m[1] . '_[A-Za-z0-9_]+\z/', (string) $pay->merchant_id)) {
            throw new RuntimeException('Stripe configuration mismatch.');
        }
        return $m[1];
    }

    private function fingerprint(Pay $pay): string
    {
        return hash('sha256', (string) $pay->merchant_id . "\0" . (string) $pay->merchant_pem);
    }

    private function committed(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Stripe reconciliation requires committed state.');
        }
    }
}
