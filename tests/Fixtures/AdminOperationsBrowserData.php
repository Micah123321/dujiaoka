<?php

namespace Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminOperationsBrowserData
{
    public static function configure(string $runtime, bool $initialize): void
    {
        config([
            'dujiaoka.payment_quote_freeze' => true, 'dujiaoka.stripe_payment_intents' => true,
            'dujiaoka.stripe_reconciliation' => true, 'dujiaoka.paypal_orders_v2' => true,
            'dujiaoka.paypal_reconciliation' => true, 'dujiaoka.api_hook_outbox' => true,
            'dujiaoka.payment_mail_outbox' => true, 'dujiaoka.manual_status_mail_outbox' => true,
            'queue.default' => 'database',
        ]);
        // Any provider call stays within this fixture and records only its occurrence.
        app()->instance(\App\Payments\StripeIntentClient::class, new class($runtime) extends \App\Payments\StripeIntentClient {
            private $runtime;
            public function __construct($runtime) { $this->runtime = $runtime; }
            public function retrieve(string $key, string $id): array
            {
                file_put_contents($this->runtime . '/provider-attempts.txt', "stripe\n", FILE_APPEND | LOCK_EX);
                throw new \RuntimeException('Fixture provider unavailable.');
            }
        });
        app()->instance(\App\Payments\PaypalOrdersClient::class, new class($runtime) extends \App\Payments\PaypalOrdersClient {
            private $runtime;
            public function __construct($runtime) { $this->runtime = $runtime; }
            public function get(array $credentials, string $id): array
            {
                file_put_contents($this->runtime . '/provider-attempts.txt', "paypal\n", FILE_APPEND | LOCK_EX);
                throw new \RuntimeException('Fixture provider unavailable.');
            }
            public function capture(array $credentials, string $id, string $key): array
            {
                throw new \RuntimeException('Fixture capture is disabled.');
            }
            public function getCapture(array $credentials, string $id): array
            {
                throw new \RuntimeException('Fixture capture query is disabled.');
            }
        });
        app()->instance(\GuzzleHttp\Client::class, new \GuzzleHttp\Client([
            'handler' => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([])),
        ]));
        if (!$initialize) return;
        $classes = [
            'CreateNotificationOutboxTable', 'AddPayloadToNotificationOutbox', 'CreatePaymentQuotesTable',
            'CreateStripePaymentIntentsTable', 'CreateStripeReconciliationTables', 'CreatePaypalOrdersTable',
            'CreatePaypalReconciliationTables', 'AddEventKeyToNotificationOutbox',
        ];
        $files = glob(base_path('database/migrations/2026_01_01_*.php'));
        sort($files);
        if (count($files) !== count($classes)) throw new \RuntimeException('Fixture migration set changed.');
        foreach ($files as $index => $file) { require_once $file; (new $classes[$index]())->up(); }
        Schema::create('jobs', function ($table) {
            $table->bigIncrements('id'); $table->string('queue'); $table->longText('payload');
            $table->unsignedTinyInteger('attempts'); $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at'); $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function ($table) {
            $table->increments('id'); $table->text('connection'); $table->text('queue');
            $table->longText('payload'); $table->longText('exception'); $table->timestamp('failed_at');
        });
        $stamp = ['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
        $pay = (array) DB::table('pays')->first();
        foreach ([2 => ['Stripe', 'pay/stripe'], 3 => ['PayPal', 'pay/paypal']] as $id => $gateway) {
            $pay['id'] = $id; $pay['pay_name'] = $gateway[0]; $pay['pay_handleroute'] = $gateway[1];
            $pay['merchant_id'] = 'pk_test_FIXTURE_ACCOUNT'; $pay['merchant_key'] = 'FIXTURE-PRIVATE-KEY';
            $pay['merchant_pem'] = 'sk_test_FIXTURE_ACCOUNT'; $pay['pay_check'] = 'card';
            DB::table('pays')->insert($pay);
        }
        DB::table('orders')->where('id', 1)->update(['pay_id' => 2]);
        DB::table('orders')->where('id', 2)->update(['pay_id' => 3]);
        $order = (array) DB::table('orders')->where('id', 1)->first();
        $titles = ['年度会员', '设计资源合集', '云服务订阅', '团队工具授权'];
        for ($id = 3; $id <= 58; $id++) {
            $order['id'] = $id; $order['order_sn'] = 'OPS-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
            $order['title'] = $titles[$id % 4]; $order['actual_price'] = number_format(19 + $id * 2.5, 2, '.', '');
            $order['status'] = [1, 2, 4, 6][$id % 4]; $order['pay_id'] = $id % 2 ? 2 : 3;
            $order['created_at'] = date('Y-m-d H:i:s', time() - ($id % 7) * 86400);
            DB::table('orders')->insert($order);
        }
        $fingerprint = hash('sha256', 'pk_test_FIXTURE_ACCOUNT' . "\0" . 'sk_test_FIXTURE_ACCOUNT');
        for ($id = 1; $id <= 4; $id++) {
            DB::table('stripe_payment_intents')->insert(array_merge($stamp, [
                'id' => $id, 'order_id' => $id === 1 ? 1 : $id + 1, 'pay_id' => 2, 'cny_amount' => '9.00',
                'currency' => 'cny', 'amount_minor' => 900, 'reference' => str_pad(dechex($id), 32, 'a'),
                'provider_id' => 'pi_ops_' . $id, 'account_fingerprint' => $fingerprint,
            ]));
            DB::table('stripe_reconciliations')->insert(array_merge($stamp, [
                'intent_id' => $id, 'last_status' => $id === 2 ? 'succeeded' : 'requires_payment_method',
                'checks' => $id, 'failures' => $id === 1 ? 5 : 0, 'available_at' => $stamp['created_at'],
                'failed_at' => $id === 1 ? $stamp['created_at'] : null,
                'finished_at' => $id === 2 ? $stamp['created_at'] : null,
                'leased_until' => $id === 4 ? date('Y-m-d H:i:s', time() + 3600) : null,
                'lease_token' => $id === 4 ? str_repeat('a', 32) : null,
                'last_error' => $id === 1 ? 'provider_sync_failed' : null,
            ]));
        }
        DB::table('paypal_orders')->insert(array_merge($stamp, [
            'id' => 1, 'order_id' => 2, 'pay_id' => 3, 'cny_amount' => '9.00', 'usd_amount' => '1.35',
            'reference' => str_repeat('p', 32), 'create_key' => str_repeat('c', 32), 'capture_key' => str_repeat('k', 32),
            'provider_id' => '1B56960729604235T', 'return_url' => 'https://fixture.test/return',
            'cancel_url' => 'https://fixture.test/cancel', 'account_fingerprint' => 'fixture-fingerprint',
        ]));
        DB::table('paypal_reconciliations')->insert(array_merge($stamp, [
            'order_binding_id' => 1, 'last_status' => 'APPROVED', 'checks' => 3, 'failures' => 5,
            'failed_at' => $stamp['created_at'], 'last_error' => 'provider_sync_failed', 'available_at' => $stamp['created_at'],
        ]));
        foreach ([1 => 'payment-mail', 2 => 'order-status-mail', 3 => 'api-hook'] as $id => $channel) {
            DB::table('notification_outbox')->insert(array_merge($stamp, [
                'id' => $id, 'order_id' => 2, 'channel' => $channel, 'attempts' => 5,
                'available_at' => $stamp['created_at'], 'failed_at' => $stamp['created_at'],
                'payload' => 'FIXTURE-PRIVATE-PAYLOAD', 'last_error' => 'Delivery failed: RuntimeException',
                'reserved_until' => $id === 3 ? date('Y-m-d H:i:s', time() + 3600) : null,
                'reservation_token' => $id === 3 ? str_repeat('l', 32) : null,
            ]));
        }
        for ($id = 1; $id <= 55; $id++) {
            DB::table('payment_quotes')->insert(array_merge($stamp, [
                'order_id' => $id, 'pay_id' => $id % 2 ? 2 : 3, 'base_currency' => 'CNY', 'currency' => 'USD',
                'source' => $id % 2 ? 'cmb' : 'frankfurter', 'cny_amount' => '9.00', 'usd_amount' => '1.35',
                'numerator' => '0.15', 'denominator' => '1', 'rate_date' => date('Y-m-d'),
            ]));
        }
        DB::table('failed_jobs')->insert([
            'connection' => 'database', 'queue' => 'default', 'payload' => json_encode([
                'displayName' => 'Fixture retry', 'job' => 'Fixture@handle', 'attempts' => 3,
                'data' => ['private' => 'FIXTURE-PRIVATE-JOB'],
            ]), 'exception' => 'FIXTURE-PRIVATE-EXCEPTION', 'failed_at' => $stamp['created_at'],
        ]);
        foreach ([1 => 1, 2 => 2] as $id => $role) {
            $permission = 100 + $id;
            DB::table('admin_permissions')->insert(array_merge($stamp, [
                'id' => $permission, 'parent_id' => 0, 'order' => $permission, 'name' => 'Operations viewer',
                'slug' => 'fixture-operations-' . $id, 'http_method' => 'GET', 'http_path' => '/,/operations/*',
            ]));
            DB::table('admin_role_permissions')->insert(array_merge($stamp, ['role_id' => $role, 'permission_id' => $permission]));
        }
        file_put_contents($runtime . '/provider-attempts.txt', '');
    }

    public static function state(): array
    {
        return [
            'status' => 'fixture', 'php' => PHP_VERSION, 'framework' => app()->version(),
            'database' => config('database.connections.sqlite.database'),
            'notifications' => DB::table('notification_outbox')->orderBy('id')->get(['id', 'failed_at', 'reserved_until', 'attempts'])->all(),
            'stripe' => DB::table('stripe_reconciliations')->orderBy('id')->get(['intent_id', 'failed_at', 'failures', 'leased_until'])->all(),
            'paypal' => DB::table('paypal_reconciliations')->get(['order_binding_id', 'failed_at', 'failures'])->all(),
            'quotes' => DB::table('payment_quotes')->count(), 'jobs' => DB::table('jobs')->count(),
        ];
    }
}
