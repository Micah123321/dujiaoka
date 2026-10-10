<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPayloadToNotificationOutbox extends Migration
{
    public function up(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->longText('payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
}
