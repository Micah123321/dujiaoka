<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Pay;
use App\Models\PaypalOrder;
use App\Service\PaypalOrderService;
use App\Service\PaypalReconciliationService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaypalReconciliationServiceTest extends TestCase
{
    private $orders;
    private $service;
    private $pay;
    private $binding;
    private $calls = 0;
    private $response;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Carbon::setTestNow('2026-01-01 12:00:00');
        config(['dujiaoka.paypal_reconciliation' => true, 'dujiaoka.paypal_orders_v2' => true,
            'dujiaoka.payment_quote_freeze' => true]);
        Schema::create('pays', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('pay_handleroute'); $t->string('merchant_id');
            $t->string('merchant_pem'); $t->string('merchant_key'); $t->integer('is_open')->default(1);
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->integer('pay_id'); $t->string('order_sn');
            $t->decimal('actual_price', 10, 2); $t->integer('status'); $t->timestamps(); $t->softDeletes();
        });
        require_once base_path('database/migrations/2026_01_01_000006_create_paypal_orders_table.php');
        require_once base_path('database/migrations/2026_01_01_000007_create_paypal_reconciliation_tables.php');
        (new \CreatePaypalOrdersTable())->up();
        (new \CreatePaypalReconciliationTables())->up();
        $this->pay = Pay::forceCreate(['pay_handleroute' => 'pay/paypal', 'merchant_id' => 'client-fixture',
            'merchant_pem' => 'secret-fixture', 'merchant_key' => 'merchant-fixture']);
        $order = Order::forceCreate(['pay_id' => $this->pay->id, 'order_sn' => 'RECON-PAYPAL',
            'actual_price' => '70.00', 'status' => Order::STATUS_WAIT_PAY]);
        $this->binding = PaypalOrder::forceCreate(['order_id' => $order->id, 'pay_id' => $this->pay->id,
            'cny_amount' => '70.00', 'usd_amount' => '10.00', 'reference' => str_repeat('a', 32),
            'create_key' => str_repeat('b', 32), 'capture_key' => str_repeat('c', 32),
            'account_fingerprint' => $this->fingerprint($this->pay), 'provider_id' => str_repeat('A', 17),
            'return_url' => 'https://example.test/return', 'cancel_url' => 'https://example.test/cancel']);
        $this->orders = \Mockery::mock(PaypalOrderService::class);
        $this->orders->shouldReceive('enabled')->andReturn(true);
        $this->orders->shouldReceive('accountFingerprint')->andReturnUsing(function (Pay $pay) { return $this->fingerprint($pay); });
        $this->response = function () { return ['status' => 'COMPLETED']; };
        $this->orders->shouldReceive('sync')->andReturnUsing(function (Order $order, Pay $pay, bool $capture) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue($capture);
            ++$this->calls;
            return ($this->response)();
        });
        $this->service = new PaypalReconciliationService($this->orders);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_paused_backlog_does_not_starve_a_later_provider_recovery_event(): void
    {
        $this->service->check($this->binding->id);
        DB::table('paypal_reconciliations')->update(['finished_at' => null, 'failed_at' => Carbon::now(), 'available_at' => Carbon::now(), 'last_error' => 'order_sync_failed']);
        for ($i = 0; $i < 25; ++$i) $this->service->accept($this->pay, $this->event('EV-PAUSED-' . $i));
        $later = $this->event('EV-LATER'); $later['provider_id'] = str_repeat('C', 17);
        $resolved = 0;
        $this->orders->shouldReceive('resolveEvent')->andReturnUsing(function () use (&$resolved) { ++$resolved; return null; });
        $this->service->accept($this->pay, $later);
        $this->service->reconcileDue(25);
        $this->assertSame(0, $resolved);
        Carbon::setTestNow(Carbon::now()->addSeconds(91));
        $this->service->reconcileDue(25);
        $this->assertSame(1, $resolved);
        $this->assertNotNull(DB::table('paypal_webhook_events')->where('event_id', 'EV-LATER')->value('processed_at'));
        $this->assertSame(1, $this->calls);
    }

    private function fingerprint(Pay $pay): string
    {
        return hash('sha256', $pay->merchant_id . ':' . $pay->merchant_pem . ':' . $pay->merchant_key);
    }

    private function event(string $id = 'EV-1'): array
    {
        return ['id' => $id, 'type' => 'PAYMENT.CAPTURE.COMPLETED',
            'provider_id' => str_repeat('A', 17), 'resource_id' => str_repeat('B', 17)];
    }

    private function state()
    {
        return DB::table('paypal_reconciliations')->where('order_binding_id', $this->binding->id)->first();
    }

    public function test_distinct_events_share_failure_backoff_and_completed_events_are_acknowledged(): void
    {
        $this->response = function () { throw new \RuntimeException('secret-provider-body'); };
        for ($i = 0; $i < 10; ++$i) $this->service->accept($this->pay, $this->event('EV-' . $i));
        $this->service->reconcileDue();
        $this->assertSame(1, $this->calls);
        $this->assertSame(1, (int) $this->state()->failures);
        $this->assertSame(1, (int) DB::table('paypal_webhook_events')->sum('attempts'));
        $this->assertSame('order_sync_failed', $this->state()->last_error);
        $this->service->reconcileDue();
        $this->assertSame(1, $this->calls);
        Carbon::setTestNow(Carbon::now()->addSeconds(60));
        $this->response = function () { return ['status' => 'COMPLETED']; };
        $this->service->reconcileDue();
        $this->assertSame(2, $this->calls);
        $this->assertSame(10, DB::table('paypal_webhook_events')->whereNotNull('processed_at')->count());
    }

    public function test_manual_pause_requires_explicit_retry_and_free_lease(): void
    {
        $this->response = function () { throw new \RuntimeException('secret'); };
        for ($i = 0; $i < 10; ++$i) {
            $this->assertSame($i === 9 ? 'manual' : 'retry', $this->service->check($this->binding->id));
            Carbon::setTestNow(Carbon::now()->addHour());
        }
        $this->assertNotNull($this->state()->failed_at);
        $this->service->accept($this->pay, $this->event());
        $this->service->reconcileDue();
        $this->assertSame(10, $this->calls);
        DB::table('paypal_reconciliations')->update(['leased_until' => Carbon::now()->addSeconds(90)]);
        $this->assertFalse($this->service->retry($this->binding->id));
        Carbon::setTestNow(Carbon::now()->addSeconds(90));
        $this->assertTrue($this->service->retry($this->binding->id));
        $this->assertSame(0, (int) $this->state()->failures);
        $this->response = function () { return ['status' => 'VOIDED']; };
        $this->assertSame('checked', $this->service->check($this->binding->id));
        $this->assertNotNull($this->state()->finished_at);
    }

    public function test_missed_event_seeds_closed_gateway_and_unknown_id_recovery(): void
    {
        $this->pay->is_open = 0; $this->pay->save();
        $this->binding->provider_id = null; $this->binding->save();
        $this->assertSame(1, $this->service->reconcileDue()['checked']);
        $this->assertSame(0, $this->calls);
        Carbon::setTestNow(Carbon::now()->addHours(5));
        $this->assertSame(1, $this->service->reconcileDue()['manual']);
        $this->assertSame('provider_id_unknown', $this->state()->last_error);
        $this->binding->provider_id = str_repeat('A', 17); $this->binding->save();
        $this->assertSame('completed', $this->service->check($this->binding->id, true));
        $this->assertNull($this->state()->failed_at);
    }

    public function test_wrong_resolved_candidate_is_ignored(): void
    {
        $this->service->check($this->binding->id);
        $event = $this->event(); $event['provider_id'] = str_repeat('C', 17);
        $this->orders->shouldReceive('resolveEvent')->once()->andReturnUsing(function () {
            $this->assertSame(0, DB::transactionLevel()); return $this->binding;
        });
        $this->service->accept($this->pay, $event);
        $this->service->reconcileDue();
        $this->assertSame(1, $this->calls);
        $this->assertNotNull(DB::table('paypal_webhook_events')->first()->processed_at);
    }

    public function test_expired_and_replaced_state_leases_cannot_acknowledge_success_or_failure(): void
    {
        $this->response = function () {
            Carbon::setTestNow(Carbon::now()->addSeconds(90)); return ['status' => 'COMPLETED'];
        };
        $this->assertSame('busy', $this->service->check($this->binding->id));
        $this->assertNull($this->state()->finished_at);
        $this->response = function () {
            DB::table('paypal_reconciliations')->update(['lease_token' => str_repeat('f', 32)]);
            throw new \RuntimeException('secret');
        };
        $this->assertSame('busy', $this->service->check($this->binding->id));
        $this->assertSame(0, (int) $this->state()->failures);
        $this->assertSame(str_repeat('f', 32), $this->state()->lease_token);
        Carbon::setTestNow(Carbon::now()->addSeconds(90));
        $this->response = function () { return ['status' => 'COMPLETED']; };
        $this->assertSame('completed', $this->service->check($this->binding->id));
    }

    public function test_expired_event_ack_is_reclaimed(): void
    {
        $this->service->accept($this->pay, $this->event());
        $this->response = function () {
            Carbon::setTestNow(Carbon::now()->addSeconds(90)); return ['status' => 'COMPLETED'];
        };
        $method = new \ReflectionMethod($this->service, 'processEvent'); $method->setAccessible(true);
        $id = DB::table('paypal_webhook_events')->value('id');
        $this->assertSame('busy', $method->invoke($this->service, $id));
        $this->assertNull(DB::table('paypal_webhook_events')->first()->processed_at);
        $this->response = function () { return ['status' => 'COMPLETED']; };
        $this->assertSame('completed', $method->invoke($this->service, $id));
        $this->assertNotNull(DB::table('paypal_webhook_events')->first()->processed_at);
        $this->assertSame(2, $this->calls);
    }

    public function test_duplicate_source_conflict_is_sanitized_and_accept_has_no_network(): void
    {
        $this->assertTrue($this->service->accept($this->pay, $this->event()));
        $this->assertFalse($this->service->accept($this->pay, $this->event()));
        $event = $this->event(); $event['resource_id'] = str_repeat('D', 17);
        try {
            $this->service->accept($this->pay, $event); $this->fail('Expected source conflict');
        } catch (\RuntimeException $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertSame('PayPal event persistence failed.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame(0, $this->calls);
    }

    public function test_event_failure_budget_and_explicit_retry(): void
    {
        $this->service->check($this->binding->id);
        $event = $this->event(); $event['provider_id'] = null;
        $this->orders->shouldReceive('resolveEvent')->andThrow(new \RuntimeException('secret'));
        $this->service->accept($this->pay, $event);
        for ($i = 0; $i < 10; ++$i) {
            $this->service->reconcileDue(); Carbon::setTestNow(Carbon::now()->addHour());
        }
        $row = DB::table('paypal_webhook_events')->first();
        $this->assertSame(10, (int) $row->attempts);
        $this->assertNotNull($row->failed_at);
        $this->assertSame('event_sync_failed', $row->last_error);
        DB::table('paypal_webhook_events')->update(['leased_until' => Carbon::now()->addSeconds(90)]);
        $this->assertFalse($this->service->retryEvent($row->id));
        Carbon::setTestNow(Carbon::now()->addSeconds(90));
        $this->assertTrue($this->service->retryEvent($row->id));
        $this->assertSame(0, (int) DB::table('paypal_webhook_events')->first()->attempts);
    }

    public function test_closed_gateway_accepts_but_changed_snapshot_fails(): void
    {
        $this->pay->is_open = 0; $this->pay->save();
        $this->assertTrue($this->service->accept($this->pay, $this->event()));
        Pay::whereKey($this->pay->id)->update(['merchant_pem' => 'changed-fixture']);
        try {
            $this->service->accept($this->pay, $this->event('EV-2'));
            $this->fail('Expected changed account to fail');
        } catch (\RuntimeException $e) {
            $this->assertSame(503, $e->getCode());
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame(1, DB::table('paypal_webhook_events')->count());
        $this->assertSame(0, $this->calls);
    }

    public function test_approved_interval_and_case_variant_event_conflict(): void
    {
        $this->response = function () { return ['status' => 'APPROVED']; };
        $this->assertSame('checked', $this->service->check($this->binding->id));
        $this->assertSame(Carbon::now()->addMinutes(5)->toDateTimeString(), $this->state()->available_at);
        // SQLite's explicit NOCASE index reproduces MySQL's case-insensitive event lookup.
        Schema::drop('paypal_webhook_events');
        DB::statement('CREATE TABLE paypal_webhook_events (id INTEGER PRIMARY KEY, pay_id INTEGER, event_id TEXT COLLATE NOCASE, provider_id TEXT, resource_id TEXT, event_type TEXT, account_fingerprint TEXT, available_at DATETIME, created_at DATETIME, updated_at DATETIME, UNIQUE(pay_id,event_id))');
        $this->assertTrue($this->service->accept($this->pay, $this->event('EV-Case')));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PayPal event persistence failed.');
        $this->service->accept($this->pay, $this->event('ev-case'));
    }

    public function test_disabled_needs_no_tables(): void
    {
        config(['dujiaoka.paypal_reconciliation' => false]);
        (new \CreatePaypalReconciliationTables())->down();
        (new \CreatePaypalOrdersTable())->down();
        $this->assertSame(['checked' => 0, 'completed' => 0, 'retry' => 0, 'manual' => 0, 'ignored' => 0], $this->service->reconcileDue());
        $this->assertFalse($this->service->retry(1));
        $this->assertFalse($this->service->retryEvent(1));
        $this->assertSame('ignored', $this->service->check(1));
    }

    public function test_schema_fault_is_not_ignored_and_transaction_blocks_network(): void
    {
        DB::beginTransaction();
        try {
            $this->service->check($this->binding->id); $this->fail('Expected transaction guard');
        } catch (\RuntimeException $e) {
            $this->assertSame(503, $e->getCode());
        } finally { DB::rollBack(); }
        Schema::drop('paypal_reconciliations');
        Schema::create('paypal_reconciliations', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('order_binding_id')->unique();
            $t->dateTime('available_at'); $t->timestamps(); $t->string('required_field');
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PayPal reconciliation persistence failed.');
        $this->service->check($this->binding->id);
    }
}
