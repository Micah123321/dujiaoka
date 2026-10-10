<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddEventKeyToNotificationOutbox extends Migration
{
    public function up(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->string('event_key', 64)->default('');
        });
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->unique(['order_id', 'channel', 'event_key']);
        });
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'channel']);
        });
    }

    public function down(): void
    {
        if (DB::table('notification_outbox')->where('event_key', '<>', '')
            ->orWhere('channel', 'order-status-mail')->exists()) {
            throw new \RuntimeException('Retain status notification records before rolling back event_key.');
        }
        // Laravel 6 / DBAL 3 cannot reliably drop this SQLite column; retain the schema intact.
        if (DB::connection()->getDriverName() === 'sqlite') {
            throw new \RuntimeException('SQLite event_key rollback requires a separately verified table rebuild.');
        }
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->unique(['order_id', 'channel']);
        });
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'channel', 'event_key']);
            $table->dropColumn('event_key');
        });
    }
}
