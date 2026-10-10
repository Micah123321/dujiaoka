<?php

namespace App\Console\Commands;

use App\Service\NotificationOutboxService;
use Illuminate\Console\Command;

class RetryNotification extends Command
{
    protected $signature = 'notifications:retry {id}';
    protected $description = 'Reset one exhausted notification intent for the next outbox sweep';

    public function handle(NotificationOutboxService $outbox): int
    {
        if (!$outbox->retryFailed((int) $this->argument('id'))) {
            $this->error('No failed notification intent reset; check the ID and feature setting.');
            return 1;
        }
        $this->info('Notification intent reset; the next sweep will publish it.');
        return 0;
    }
}
