<?php

namespace App\Jobs;

use App\Service\NotificationOutboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;
    public $timeout = 60;
    public $outboxId;
    public $reservationToken;

    public function __construct(int $outboxId, string $reservationToken)
    {
        $this->outboxId = $outboxId;
        $this->reservationToken = $reservationToken;
    }

    public function handle(NotificationOutboxService $outbox): void
    {
        $outbox->deliver($this->outboxId, $this->reservationToken);
    }
}
