<?php

namespace Tests\Fixtures;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminOperationsData
{
    public static function create(): void
    {
        AdminData::create();
        $migrations = [
            '000001_create_notification_outbox_table' => 'CreateNotificationOutboxTable',
            '000002_add_payload_to_notification_outbox' => 'AddPayloadToNotificationOutbox',
            '000003_create_payment_quotes_table' => 'CreatePaymentQuotesTable',
            '000004_create_stripe_payment_intents_table' => 'CreateStripePaymentIntentsTable',
            '000005_create_stripe_reconciliation_tables' => 'CreateStripeReconciliationTables',
            '000006_create_paypal_orders_table' => 'CreatePaypalOrdersTable',
            '000007_create_paypal_reconciliation_tables' => 'CreatePaypalReconciliationTables',
            '000008_add_event_key_to_notification_outbox' => 'AddEventKeyToNotificationOutbox',
        ];
        foreach ($migrations as $file => $class) {
            require_once base_path('database/migrations/2026_01_01_' . $file . '.php');
            (new $class())->up();
        }
        Schema::create('failed_jobs', function ($t) {
            $t->increments('id'); $t->string('uuid')->nullable()->unique(); $t->string('connection');
            $t->string('queue'); $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at');
        });
        Schema::create('jobs', function ($t) {
            $t->increments('id'); $t->string('queue'); $t->longText('payload'); $t->integer('attempts');
            $t->integer('reserved_at')->nullable(); $t->integer('available_at'); $t->integer('created_at');
        });
        DB::table('orders')->update(['created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            'email' => 'private-buyer@example.test', 'search_pwd' => 'PRIVATE-BUYER-PASSWORD', 'info' => 'PRIVATE-CARD-INFO']);
        $stamp = ['created_at' => Carbon::now(), 'updated_at' => Carbon::now()];
        DB::table('pays')->where('id', 1)->update(['pay_handleroute' => 'pay/stripe', 'merchant_id' => 'pk_test_fixture',
            'merchant_pem' => 'sk_test_fixture', 'merchant_key' => 'whsec_PRIVATE']);
        foreach (['stripe', 'paypal'] as $section) {
            $id = self::binding($section);
            self::state($section, $id);
            self::event($section);
        }
        DB::table('notification_outbox')->insert(array_merge($stamp, ['id' => 1, 'order_id' => 1, 'channel' => 'api-hook',
            'attempts' => 5, 'available_at' => Carbon::now(), 'failed_at' => Carbon::now(),
            'last_error' => 'PRIVATE-MERCHANT-ERROR', 'payload' => 'PRIVATE-ENCRYPTED-PAYLOAD']));
        DB::table('payment_quotes')->insert(array_merge($stamp, ['id' => 1, 'order_id' => 1, 'pay_id' => 1,
            'base_currency' => 'cny', 'currency' => 'usd', 'source' => 'fixture', 'cny_amount' => '9.00',
            'usd_amount' => '1.25', 'numerator' => '9', 'denominator' => '125', 'rate_date' => Carbon::today()->format('Y-m-d')]));
    }

    public static function binding(string $section, int $id = 1, ?string $provider = null): int
    {
        $base = ['id' => $id, 'order_id' => $id, 'pay_id' => 1, 'cny_amount' => '9.00',
            'provider_id' => $provider, 'account_fingerprint' => 'PRIVATE-ACCOUNT-FINGERPRINT',
            'reference' => str_pad((string) $id, 32, 'a'), 'created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()];
        if ($section === 'stripe') {
            DB::table('stripe_payment_intents')->insert(array_merge($base, ['currency' => 'cny', 'amount_minor' => 900]));
        } else {
            DB::table('paypal_orders')->insert(array_merge($base, ['usd_amount' => '1.25',
                'create_key' => str_pad((string) $id, 32, 'b'), 'capture_key' => str_pad((string) $id, 32, 'c'),
                'return_url' => 'http://fixture.test/return', 'cancel_url' => 'http://fixture.test/cancel']));
        }
        return $id;
    }

    public static function state(string $section, int $id = 1, array $extra = []): void
    {
        $key = $section === 'stripe' ? 'intent_id' : 'order_binding_id';
        DB::table($section . '_reconciliations')->insert(array_merge([$key => $id, 'available_at' => Carbon::now(),
            'failed_at' => Carbon::now(), 'last_error' => 'PRIVATE-MERCHANT-ERROR',
            'lease_token' => 'PRIVATE-LEASE-TOKEN', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()], $extra));
    }

    public static function event(string $section, int $id = 1, array $extra = []): void
    {
        $base = ['id' => $id, 'pay_id' => 1, 'event_id' => 'evt_fixture_' . $id,
            'provider_id' => $section === 'stripe' ? 'pi_fixture_' . $id : '12345678901234567',
            'event_type' => $section === 'stripe' ? 'payment_intent.succeeded' : 'PAYMENT.CAPTURE.COMPLETED',
            'account_fingerprint' => 'PRIVATE-ACCOUNT-FINGERPRINT', 'available_at' => Carbon::now(),
            'failed_at' => Carbon::now(), 'last_error' => 'PRIVATE-MERCHANT-ERROR', 'lease_token' => 'PRIVATE-LEASE-TOKEN',
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now()];
        if ($section === 'stripe') $base['livemode'] = false;
        else $base['resource_id'] = '76543210987654321';
        DB::table($section . '_webhook_events')->insert(array_merge($base, $extra));
    }

    public static function user(string $slug, array $permissions)
    {
        $userId = DB::table('admin_users')->insertGetId(['username' => $slug, 'name' => $slug, 'password' => '']);
        $roleId = DB::table('admin_roles')->insertGetId(['slug' => $slug, 'name' => $slug]);
        DB::table('admin_role_users')->insert(['role_id' => $roleId, 'user_id' => $userId]);
        foreach ($permissions as $grant) {
            $permissionId = DB::table('admin_permissions')->insertGetId(['slug' => $slug . '-' . uniqid(),
                'name' => 'Fixture permission', 'http_method' => $grant[0], 'http_path' => $grant[1]]);
            DB::table('admin_role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        $model = config('admin.database.users_model');
        return $model::findOrFail($userId);
    }

    public static function failedJob(array $extra = []): int
    {
        return DB::table('failed_jobs')->insertGetId(array_merge(['connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'Fixture job', 'job' => 'Fixture@handle', 'attempts' => 3,
                'data' => ['private' => 'PRIVATE-QUEUE-PAYLOAD']]), 'exception' => 'PRIVATE-EXCEPTION-STACK',
            'failed_at' => Carbon::now()], $extra));
    }
}
