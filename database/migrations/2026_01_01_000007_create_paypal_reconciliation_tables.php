<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePaypalReconciliationTables extends Migration
{
    public function up(): void
    {
        Schema::create('paypal_webhook_events', function (Blueprint $t) {
            $t->engine = 'InnoDB'; $t->bigIncrements('id'); $t->unsignedBigInteger('pay_id');
            $t->string('event_id', 128); $t->string('provider_id', 17)->nullable();
            $t->string('resource_id', 17)->nullable(); $t->string('event_type', 128);
            $t->string('account_fingerprint', 64); $t->unsignedInteger('attempts')->default(0);
            $t->dateTime('available_at')->index(); $t->string('lease_token', 32)->nullable(); $t->dateTime('leased_until')->nullable();
            $t->dateTime('processed_at')->nullable(); $t->dateTime('failed_at')->nullable(); $t->string('last_error', 64)->nullable();
            $t->timestamps(); $t->unique(['pay_id', 'event_id']);
        });
        Schema::create('paypal_reconciliations', function (Blueprint $t) {
            $t->engine = 'InnoDB'; $t->bigIncrements('id'); $t->unsignedBigInteger('order_binding_id')->unique();
            $t->string('last_status', 64)->nullable(); $t->unsignedInteger('checks')->default(0); $t->unsignedInteger('failures')->default(0);
            $t->dateTime('available_at')->index(); $t->dateTime('checked_at')->nullable();
            $t->string('lease_token', 32)->nullable(); $t->dateTime('leased_until')->nullable();
            $t->dateTime('finished_at')->nullable(); $t->dateTime('failed_at')->nullable(); $t->string('last_error', 64)->nullable(); $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_reconciliations');
        Schema::dropIfExists('paypal_webhook_events');
    }
}
