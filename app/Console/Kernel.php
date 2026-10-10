<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('orders:expire')->everyMinute()->withoutOverlapping();
        if (config('dujiaoka.stripe_reconciliation', false) && config('dujiaoka.stripe_payment_intents', false)
            && config('dujiaoka.payment_quote_freeze', false)) {
            $schedule->command('payments:reconcile-stripe')->everyMinute()->withoutOverlapping();
        }
        if (config('dujiaoka.paypal_reconciliation', false) && config('dujiaoka.paypal_orders_v2', false)
            && config('dujiaoka.payment_quote_freeze', false)) {
            $schedule->command('payments:reconcile-paypal')->everyMinute()->withoutOverlapping();
        }
        if (config('dujiaoka.api_hook_outbox', false) || config('dujiaoka.payment_mail_outbox', false)
            || config('dujiaoka.manual_status_mail_outbox', false)) {
            $schedule->command('notifications:dispatch')->everyMinute()->withoutOverlapping();
        }
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
