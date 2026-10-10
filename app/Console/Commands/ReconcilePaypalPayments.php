<?php

namespace App\Console\Commands;

use App\Service\PaypalReconciliationService;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePaypalPayments extends Command
{
    protected $signature = 'payments:reconcile-paypal {--limit=25} {--retry-id=} {--retry-event-id=}';
    protected $description = 'Reconcile stored PayPal Orders and authenticated event inbox';

    public function handle(PaypalReconciliationService $reconciliation): int
    {
        if (!$reconciliation->enabled()) { $this->info('PayPal reconciliation is disabled.'); return 0; }
        try {
            $limit = (string) $this->option('limit');
            if (!ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) {
                $this->error('Limit must be between 1 and 100.'); return 1;
            }
            $retry = $this->option('retry-id'); $eventRetry = $this->option('retry-event-id');
            if ($retry !== null && (!ctype_digit((string) $retry) || (int) $retry < 1 || !$reconciliation->retry((int) $retry))) {
                $this->error('No failed, unleased payment binding was rearmed.'); return 1;
            }
            if ($eventRetry !== null && (!ctype_digit((string) $eventRetry) || (int) $eventRetry < 1 || !$reconciliation->retryEvent((int) $eventRetry))) {
                $this->error('No failed, unleased event was rearmed.'); return 1;
            }
            $counts = $reconciliation->reconcileDue((int) $limit);
            $this->info('Checked: ' . $counts['checked'] . '; completed: ' . $counts['completed']
                . '; retry: ' . $counts['retry'] . '; manual review: ' . $counts['manual'] . '; ignored: ' . $counts['ignored']);
            return ($counts['retry'] || $counts['manual']) ? 1 : 0;
        } catch (Throwable $exception) {
            $this->error('PayPal reconciliation failed; check migration and payment configuration.'); return 1;
        }
    }
}
