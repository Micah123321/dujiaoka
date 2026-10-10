<?php

namespace App\Console\Commands;

use App\Service\NotificationOutboxService;
use Illuminate\Console\Command;

class DispatchNotifications extends Command
{
    protected $signature = 'notifications:dispatch {--limit=100}';
    protected $description = 'Publish due payment notification intents from the durable outbox';

    public function handle(NotificationOutboxService $outbox): int
    {
        if (!$outbox->enabled()) {
            $this->info('Notification outbox is disabled.');
            return 0;
        }
        $result = $outbox->publishDue((int) $this->option('limit'));
        $this->info('Published: ' . $result['published'] . '; dispatch failed: ' . $result['failed']);
        return $result['failed'] ? 1 : 0;
    }
}
