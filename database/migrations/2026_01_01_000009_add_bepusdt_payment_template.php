<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddBepusdtPaymentTemplate extends Migration
{
    public function up(): void
    {
        // The unique payment identifier also protects configured and soft-deleted channels.
        DB::table('pays')->insertOrIgnore([
            'pay_name' => 'BEpusdt[USDT-TRC20]',
            'pay_check' => 'usdt.trc20',
            'pay_method' => 1,
            'pay_client' => 3,
            'merchant_id' => 'BEpusdt API Token',
            'merchant_key' => '',
            'merchant_pem' => 'https://pay.example.com',
            'pay_handleroute' => 'pay/bepusdt',
            'is_open' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Retain channels that may now hold merchant settings or be referenced by orders.
    }
}
