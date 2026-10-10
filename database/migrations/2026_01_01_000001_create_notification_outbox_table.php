<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotificationOutboxTable extends Migration
{
    public function up(): void
    {
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id');
            $table->string('channel', 24);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('reserved_until')->nullable();
            $table->string('reservation_token', 32)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('last_error', 160)->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'channel']);
            $table->index(['delivered_at', 'failed_at', 'available_at'], 'notification_outbox_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
    }
}
