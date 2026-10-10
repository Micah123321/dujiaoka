<?php

namespace App\Service;

use App\Events\OrderUpdated;
use App\Jobs\ApiHook;
use App\Jobs\DeliverNotification;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class NotificationOutboxService
{
    const CHANNEL = 'api-hook';
    const PAYMENT_MAIL_CHANNEL = 'payment-mail';
    const STATUS_MAIL_CHANNEL = 'order-status-mail';
    const MAX_ATTEMPTS = 5;
    const LEASE_SECONDS = 300;

    public function enabled(): bool
    {
        return $this->apiHookEnabled() || $this->mailEnabled() || $this->statusMailEnabled();
    }

    public function apiHookEnabled(): bool
    {
        return app()->bound('config') && (bool) config('dujiaoka.api_hook_outbox', false);
    }

    public function mailEnabled(): bool
    {
        return app()->bound('config') && (bool) config('dujiaoka.payment_mail_outbox', false);
    }

    public function statusMailEnabled(): bool
    {
        return app()->bound('config') && (bool) config('dujiaoka.manual_status_mail_outbox', false);
    }

    public function recordOrderStatus(OrderUpdated $event): void
    {
        if (!$this->statusMailEnabled() || $event->statusTransition === null ||
            !$event->order->isStatusMailCaptureActive($event->eventKey)) {
            return;
        }
        $connection = DB::connection($event->connectionName);
        if ($connection->transactionLevel() === 0) {
            return;
        }
        $connection->transaction(function () use ($connection, $event) {
            // Serialize repeated dispatches of one captured event on the order's connection.
            $connection->table('orders')->where('id', $event->orderId)->lockForUpdate()->first();
            $existing = $connection->table('notification_outbox')->where('order_id', $event->orderId)
                ->where('channel', self::STATUS_MAIL_CHANNEL)->where('event_key', $event->eventKey)->exists();
            if (!$existing) {
                $connection->table('notification_outbox')->insert([
                    'order_id' => $event->orderId, 'channel' => self::STATUS_MAIL_CHANNEL,
                    'event_key' => $event->eventKey, 'payload' => $this->encryptedRecipe($event->snapshot),
                    'attempts' => 0, 'available_at' => Carbon::now(),
                    'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
                ]);
            }
        });
    }

    public function recordPayment(Order $order): void
    {
        if (!$this->enabled()) {
            return;
        }
        if ($order->getConnection()->transactionLevel() === 0) {
            throw new \LogicException('Notification intent requires the payment transaction.');
        }
        if ($this->apiHookEnabled()) {
            $goods = app('Service\GoodsService')->detail($order->goods_id);
            if (!empty($goods->api_hook)) {
                $this->insertIntent($order, self::CHANNEL);
            }
        }
        if ($this->mailEnabled() && in_array((int) $order->status, [Order::STATUS_PENDING, Order::STATUS_COMPLETED], true)) {
            $recipe = app(PaymentMailService::class)->recipe($order);
            $json = json_encode($recipe);
            if ($json === false) {
                throw new \UnexpectedValueException('Payment mail snapshot has invalid JSON.');
            }
            $payload = Crypt::encryptString($json);
            $this->insertIntent($order, self::PAYMENT_MAIL_CHANNEL, $payload);
        }
        // Payment saves suppress model events; freeze its pending buyer intent here.
        if ($this->statusMailEnabled() && (int) $order->type === Order::MANUAL_PROCESSING &&
            (int) $order->status === Order::STATUS_PENDING) {
            $stored = $order->newQuery()->findOrFail($order->getKey());
            $this->insertIntent($stored, self::STATUS_MAIL_CHANNEL,
                $this->encryptedRecipe(app(PaymentMailService::class)->statusRecipe($stored)), Str::random(64));
        }
    }

    public function publishDue(int $limit = 100, ?int $orderId = null): array
    {
        $result = ['published' => 0, 'failed' => 0];
        if (!$this->enabled()) {
            return $result;
        }
        $this->requireCommittedState();
        $query = $this->due();
        if ($orderId !== null) {
            $query->where('order_id', $orderId);
        }
        // A worker may die on its last attempt before it records failure.
        (clone $query)->where('attempts', '>=', self::MAX_ATTEMPTS)->update([
            'failed_at' => Carbon::now(),
            'reservation_token' => null,
            'reserved_until' => null,
            'last_error' => 'Delivery attempt limit reached.',
            'updated_at' => Carbon::now(),
        ]);
        $ids = $query->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')->limit(max(1, min(1000, $limit)))->pluck('id');
        foreach ($ids as $id) {
            $token = DB::transaction(function () use ($id) {
                $row = $this->due()->where('id', $id)->where('attempts', '<', self::MAX_ATTEMPTS)
                    ->lockForUpdate()->first();
                if (!$row) {
                    return null;
                }
                // Reuse an unconsumed token so a long queue backlog can still drain.
                $token = $row->reservation_token ?: Str::random(32);
                DB::table('notification_outbox')->where('id', $id)->update([
                    'reservation_token' => $token,
                    'reserved_until' => Carbon::now()->addSeconds(self::LEASE_SECONDS),
                    'updated_at' => Carbon::now(),
                ]);
                return $token;
            });
            if ($token === null) {
                continue;
            }
            try {
                // Keep the reservation until delivery, including a synchronous queue.
                app(Dispatcher::class)->dispatch(new DeliverNotification((int) $id, $token));
                ++$result['published'];
            } catch (\Throwable $exception) {
                DB::table('notification_outbox')->where('id', $id)->where('reservation_token', $token)->update([
                    'reservation_token' => null,
                    'reserved_until' => null,
                    'available_at' => Carbon::now()->addSeconds(30),
                    'last_error' => $this->errorCategory('Queue dispatch failed', $exception),
                    'updated_at' => Carbon::now(),
                ]);
                ++$result['failed'];
            }
        }
        return $result;
    }

    public function deliver(int $id, string $token): void
    {
        $this->requireCommittedState();
        $executionToken = Str::random(32);
        $channels = [self::CHANNEL, self::PAYMENT_MAIL_CHANNEL];
        if ($this->statusMailEnabled()) {
            $channels[] = self::STATUS_MAIL_CHANNEL;
        }
        // Rotating the token atomically also rejects duplicate copies of one queued job.
        $claimed = DB::table('notification_outbox')->where('id', $id)
            ->whereIn('channel', $channels)->where('reservation_token', $token)
            ->whereNull('delivered_at')->whereNull('failed_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)->update([
                'reservation_token' => $executionToken,
                'reserved_until' => Carbon::now()->addSeconds(self::LEASE_SECONDS),
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => Carbon::now(),
            ]);
        if (!$claimed) {
            return;
        }
        $row = DB::table('notification_outbox')->where('id', $id)
            ->where('reservation_token', $executionToken)->where('reserved_until', '>', Carbon::now())->first();
        if (!$row) {
            return;
        }
        try {
            if ($row->channel === self::CHANNEL) {
                $order = Order::query()->findOrFail($row->order_id);
                (new ApiHook($order, true))->handle();
            } else {
                $recipe = json_decode(Crypt::decryptString($row->payload), true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($recipe)) {
                    throw new \UnexpectedValueException('Invalid payment mail snapshot.');
                }
                app(PaymentMailService::class)->job($recipe)->handle();
            }
        } catch (\Throwable $exception) {
            $exhausted = (int) $row->attempts >= self::MAX_ATTEMPTS;
            DB::table('notification_outbox')->where('id', $id)->where('reservation_token', $executionToken)->update([
                'reservation_token' => null,
                'reserved_until' => null,
                'available_at' => Carbon::now()->addSeconds(60 * (2 ** ((int) $row->attempts - 1))),
                'failed_at' => $exhausted ? Carbon::now() : null,
                'last_error' => $this->errorCategory('Delivery failed', $exception),
                'updated_at' => Carbon::now(),
            ]);
            return;
        }
        $completed = [
            'delivered_at' => Carbon::now(),
            'reservation_token' => null,
            'reserved_until' => null,
            'last_error' => null,
            'updated_at' => Carbon::now(),
        ];
        if (in_array($row->channel, [self::PAYMENT_MAIL_CHANNEL, self::STATUS_MAIL_CHANNEL], true)) {
            $completed['payload'] = null;
        }
        DB::table('notification_outbox')->where('id', $id)->where('reservation_token', $executionToken)->update($completed);
    }

    public function retryFailed(int $id): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $this->requireCommittedState();
        return (bool) DB::table('notification_outbox')->where('id', $id)->whereIn('channel', $this->enabledChannels())
            ->whereNotNull('failed_at')->whereNull('delivered_at')->update([
                'attempts' => 0,
                'failed_at' => null,
                'available_at' => Carbon::now(),
                'reservation_token' => null,
                'reserved_until' => null,
                'last_error' => null,
                'updated_at' => Carbon::now(),
            ]);
    }

    private function encryptedRecipe(array $recipe): string
    {
        $json = json_encode($recipe);
        if ($json === false) {
            throw new \UnexpectedValueException('Status mail snapshot has invalid JSON.');
        }
        return Crypt::encryptString($json);
    }

    private function insertIntent(Order $order, string $channel, ?string $payload = null, ?string $eventKey = null): void
    {
        $values = [
            'order_id' => $order->id, 'channel' => $channel, 'attempts' => 0,
            'available_at' => Carbon::now(), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ];
        if ($payload !== null) {
            $values['payload'] = $payload;
        }
        if ($eventKey !== null) {
            $values['event_key'] = $eventKey;
        }
        $order->getConnection()->table('notification_outbox')->insert($values);
    }

    private function enabledChannels(): array
    {
        $channels = [];
        if ($this->apiHookEnabled()) {
            $channels[] = self::CHANNEL;
        }
        if ($this->mailEnabled()) {
            $channels[] = self::PAYMENT_MAIL_CHANNEL;
        }
        if ($this->statusMailEnabled()) {
            $channels[] = self::STATUS_MAIL_CHANNEL;
        }
        return $channels;
    }

    private function due(): Builder
    {
        return DB::table('notification_outbox')->whereIn('channel', $this->enabledChannels())
            ->whereNull('delivered_at')->whereNull('failed_at')
            ->where('available_at', '<=', Carbon::now())->where(function (Builder $query) {
                $query->whereNull('reserved_until')->orWhere('reserved_until', '<=', Carbon::now());
            });
    }

    private function requireCommittedState(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Notification delivery requires a committed transaction.');
        }
    }

    private function errorCategory(string $prefix, \Throwable $exception): string
    {
        // Exception messages and chained transport errors may contain URLs or card data.
        return substr($prefix . ': ' . (new \ReflectionClass($exception))->getShortName(), 0, 160);
    }
}
