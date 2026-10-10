<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaymentQuotesTable extends Migration
{
    public function up(): void
    {
        Schema::create('payment_quotes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('pay_id');
            $table->string('base_currency', 3);
            $table->string('currency', 3);
            $table->string('source', 24);
            $table->decimal('cny_amount', 10, 2);
            $table->decimal('usd_amount', 10, 2);
            $table->string('numerator', 24);
            $table->string('denominator', 24);
            $table->date('rate_date');
            $table->timestamps();
            $table->unique(['order_id', 'pay_id', 'currency'], 'payment_quotes_order_pay_currency_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_quotes');
    }
}
