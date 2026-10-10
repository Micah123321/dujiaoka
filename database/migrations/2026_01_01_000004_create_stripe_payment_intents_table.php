<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStripePaymentIntentsTable extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_payment_intents', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('pay_id');
            $table->decimal('cny_amount', 10, 2);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reference', 32)->unique();
            $table->string('account_fingerprint', 64);
            $table->string('provider_id', 255)->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_payment_intents');
    }
}
