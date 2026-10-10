<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaypalOrdersTable extends Migration
{
    public function up(): void
    {
        Schema::create('paypal_orders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('pay_id');
            $table->decimal('cny_amount', 10, 2);
            $table->decimal('usd_amount', 10, 2);
            foreach (['reference', 'create_key', 'capture_key'] as $field) {
                $table->string($field, 32)->unique();
            }
            $table->string('account_fingerprint', 64);
            $table->string('provider_id', 17)->nullable()->unique();
            $table->string('capture_id', 17)->nullable()->unique();
            $table->string('return_url');
            $table->string('cancel_url');
            $table->timestamp('capture_attempted_at')->nullable();
            $table->string('capture_lease_token', 32)->nullable();
            $table->timestamp('capture_leased_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_orders');
    }
}
