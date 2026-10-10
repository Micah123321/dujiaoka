<?php

namespace App\Console\Commands;

use App\Service\StripeReconciliationService;
use Illuminate\Console\Command;
use Throwable;

class ReconcileStripePayments extends Command
{
    protected $signature = 'payments:reconcile-stripe {--limit=25} {--retry-id=} {--retry-event-id=}';
    protected $description = 'Reconcile stored Stripe payment objects and authenticated event inbox';

    public function handle(StripeReconciliationService $reconciliation): int
    {
        if (!$reconciliation->enabled()) {
            $this->info('Stripe reconciliation is disabled.');
            return 0;
        }
        try {
            $retry = $this->option('retry-id');
            if ($retry !== null && (!ctype_digit((string) $retry) || (int) $retry < 1 || !$reconciliation->retry((int) $retry))) {
                $this->error('No failed, unleased payment binding was rearmed.');
                return 1;
            }
            $eventRetry = $this->option('retry-event-id');
            if ($eventRetry !== null && (!ctype_digit((string) $eventRetry) || (int) $eventRetry < 1 || !$reconciliation->retryEvent((int) $eventRetry))) {
                $this->error('No failed, unleased event was rearmed.');
                return 1;
            }
            $counts = $reconciliation->reconcileDue((int) $this->option('limit'));
            $this->info('Checked: ' . $counts['checked'] . '; completed: ' . $counts['completed']
                . '; retry: ' . $counts['retry'] . '; manual review: ' . $counts['manual'] . '; ignored: ' . $counts['ignored']);
            return ($counts['retry'] || $counts['manual']) ? 1 : 0;
        } catch (Throwable $exception) {
            $this->error('Stripe reconciliation failed; check migration and payment configuration.');
            return 1;
        }
    }
}
