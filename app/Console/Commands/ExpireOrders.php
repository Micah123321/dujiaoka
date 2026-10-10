<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Service\OrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExpireOrders extends Command
{
    protected $signature = 'orders:expire';
    protected $description = 'Expire overdue unpaid orders and return coupons atomically';

    public function handle(OrderService $service): int
    {
        $failed = 0;
        $expired = 0;
        $cutoff = Carbon::now()->subMinutes($service->expirationMinutes());
        Order::query()->where('status', Order::STATUS_WAIT_PAY)
            ->where('created_at', '<=', $cutoff)
            ->select(['id', 'order_sn'])
            ->chunkById(100, function ($orders) use ($service, &$failed, &$expired) {
                foreach ($orders as $order) {
                    try {
                        $expired += (int) $service->expiredOrderSN($order->order_sn);
                    } catch (\Throwable $exception) {
                        ++$failed;
                        report($exception);
                    }
                }
            });
        $this->info('Expired: ' . $expired . '; failed: ' . $failed);
        return $failed ? 1 : 0;
    }
}
