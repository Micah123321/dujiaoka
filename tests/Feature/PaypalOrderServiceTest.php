<?php

namespace Tests\Feature;

use App\Exceptions\RuleValidationException;
use App\Models\Order;
use App\Models\Pay;
use App\Models\PaypalOrder;
use App\Payments\PaypalOrdersClient;
use App\Service\OrderProcessService;
use App\Service\PaymentQuoteService;
use App\Service\PaypalOrderService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaypalOrderServiceTest extends TestCase
{
    private $quotes;
    private $client;
    private $service;
    private $order;
    private $pay;
    private const ID = '5O190127TN364715T';
    private const CAPTURE = '3C679366HH908993F';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'dujiaoka.paypal_orders_v2' => true, 'dujiaoka.paypal_orders_mode' => 'sandbox',
            'dujiaoka.stripe_payment_intents' => false, 'dujiaoka.payment_quote_freeze' => true]);
        DB::purge('sqlite');
        Event::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
        require_once base_path('database/migrations/2026_01_01_000006_create_paypal_orders_table.php');
        (new \CreatePaypalOrdersTable())->up();
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('order_sn'); $t->unsignedBigInteger('pay_id');
            $t->decimal('actual_price', 10, 2); $t->integer('status'); $t->string('trade_no')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('pays', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('pay_handleroute'); $t->integer('is_open');
            $t->string('merchant_id'); $t->string('merchant_key'); $t->string('merchant_pem');
            $t->timestamps(); $t->softDeletes();
        });
        DB::table('pays')->insert(['id' => 1, 'pay_handleroute' => 'pay/paypal', 'is_open' => 1,
            'merchant_id' => 'TESTMERCHANT1', 'merchant_key' => 'fixture-client', 'merchant_pem' => 'fixture-secret']);
        DB::table('orders')->insert(['id' => 1, 'order_sn' => 'PAYPAL-1', 'pay_id' => 1,
            'actual_price' => '70.00', 'status' => Order::STATUS_WAIT_PAY]);
        $this->order = Order::findOrFail(1);
        $this->pay = Pay::findOrFail(1);
        $this->quotes = \Mockery::mock(PaymentQuoteService::class);
        $this->quotes->shouldReceive('enabled')->andReturn(true)->byDefault();
        $this->quotes->shouldReceive('cnyToUsd')->with(\Mockery::type(Order::class), \Mockery::type(Pay::class), 'frankfurter')
            ->andReturnUsing(function () { $this->assertSame(0, DB::transactionLevel()); return '10.00'; })->byDefault();
        $this->quotes->shouldReceive('frozenUsd')->andReturn('10.00')->byDefault();
        $this->client = \Mockery::mock(PaypalOrdersClient::class);
        $this->service = new PaypalOrderService($this->quotes, $this->client);
        $completion = \Mockery::mock(OrderProcessService::class);
        $completion->shouldNotReceive('completedOrder');
        $this->app->instance(OrderProcessService::class, $completion);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->app) {
            (new \CreatePaypalOrdersTable())->down();
            $this->assertFalse(Schema::hasTable('paypal_orders'));
        }
        parent::tearDown();
    }

    private function begin(): array
    {
        return $this->service->begin($this->order, $this->pay, 'https://shop.test/return', 'https://shop.test/cancel');
    }

    private function receipt(string $status = 'CREATED'): array
    {
        $row = PaypalOrder::firstOrFail();
        $receipt = ['id' => self::ID, 'intent' => 'CAPTURE', 'status' => $status,
            'purchase_units' => [['reference_id' => $row->reference, 'custom_id' => $row->reference,
                'invoice_id' => 'PAYPAL-1', 'payee' => ['merchant_id' => 'TESTMERCHANT1'],
                'amount' => ['currency_code' => 'USD', 'value' => '10.00']]],
            'links' => [['rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=' . self::ID]]];
        if ($status === 'COMPLETED') {
            $receipt['purchase_units'][0]['payments']['captures'] = [$this->captureReceipt()];
        }
        return $receipt;
    }

    private function captureReceipt(): array
    {
        return ['id' => self::CAPTURE, 'status' => 'COMPLETED', 'final_capture' => true,
            'amount' => ['currency_code' => 'USD', 'value' => '10.00'],
            'supplementary_data' => ['related_ids' => ['order_id' => self::ID]]];
    }

    private function create(): array
    {
        $this->client->shouldReceive('create')->once()->andReturnUsing(function ($credentials, $params, $key) {
            $this->assertSame(0, DB::transactionLevel());
            $row = PaypalOrder::firstOrFail();
            $this->assertSame($row->create_key, $key);
            $this->assertSame('fixture-client', $credentials['client_id']);
            $this->assertSame('CAPTURE', $params['intent']);
            $this->assertCount(1, $params['purchase_units']);
            $this->assertSame($row->reference, $params['purchase_units'][0]['custom_id']);
            $this->assertSame(['currency_code' => 'USD', 'value' => '10.00'], $params['purchase_units'][0]['amount']);
            $this->assertSame(['user_action' => 'PAY_NOW', 'shipping_preference' => 'NO_SHIPPING',
                'return_url' => 'https://shop.test/return', 'cancel_url' => 'https://shop.test/cancel'],
                $params['payment_source']['paypal']['experience_context']);
            return $this->receipt();
        });
        return $this->begin();
    }

    private function rule(callable $action, string $key = 'payment_intent_invalid'): void
    {
        try { $action(); $this->fail('Expected validation error'); }
        catch (RuleValidationException $e) {
            $this->assertSame(__('dujiaoka.prompt.' . $key), $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    private function expectGet(string $status, int $times = 1): void
    {
        $this->client->shouldReceive('get')->times($times)->with(\Mockery::type('array'), self::ID)
            ->andReturnUsing(function () use ($status) {
                $this->assertSame(0, DB::transactionLevel()); return $this->receipt($status);
            });
    }

    private function completion(int $times = 1, ?callable $beforeGuard = null): void
    {
        $completion = \Mockery::mock(OrderProcessService::class);
        $completion->shouldReceive('completedOrder')->times($times)
            ->with('PAYPAL-1', 70.0, self::CAPTURE, \Mockery::type(\Closure::class))
            ->andReturnUsing(function ($sn, $amount, $capture, $guard) use ($beforeGuard) {
                $this->assertSame(0, DB::transactionLevel());
                if ($beforeGuard) { $beforeGuard(); }
                return DB::transaction(function () use ($guard, $capture) {
                    $locked = Order::whereKey(1)->lockForUpdate()->first();
                    $guard($locked);
                    $this->assertSame(1, DB::transactionLevel());
                    $locked->status = Order::STATUS_COMPLETED;
                    $locked->trade_no = $capture;
                    $locked->save();
                    return $locked;
                });
            });
        $this->app->instance(OrderProcessService::class, $completion);
    }

    public function testRepeatedBeginKeepsOneRowAndFrozenKeys(): void
    {
        $first = $this->create();
        $attributes = PaypalOrder::first()->getAttributes();
        $this->expectGet('CREATED', 2);
        $this->assertSame($first, $this->begin());
        $this->assertSame($first, $this->begin());
        $this->assertSame($attributes, PaypalOrder::first()->getAttributes());
        $this->assertSame(1, PaypalOrder::count());
        $this->assertArrayNotHasKey('secret', $attributes);
        $this->assertArrayNotHasKey('token', $attributes);
        $this->rule(function () { $this->service->begin($this->order, $this->pay, 'https://changed.test/', 'https://shop.test/cancel'); });
    }

    public function testDisconnectedCreateReusesParametersAndFiveHourDeadline(): void
    {
        $seen = null;
        $this->client->shouldReceive('create')->twice()->andReturnUsing(function ($credentials, $params, $key) use (&$seen) {
            if ($seen !== null) { $this->assertSame($seen, [$credentials, $params, $key]); }
            $seen = [$credentials, $params, $key];
            throw new \RuntimeException('secret response');
        });
        $this->rule(function () { $this->begin(); }, 'payment_intent_unavailable');
        $this->rule(function () { $this->begin(); }, 'payment_intent_unavailable');
        Carbon::setTestNow(Carbon::now()->addHours(5));
        $this->rule(function () { $this->begin(); }, 'payment_intent_unavailable');
        PaypalOrder::query()->update(['provider_id' => self::ID]);
        Carbon::setTestNow(Carbon::now()->addYears(2));
        DB::table('pays')->update(['is_open' => 0]);
        $this->pay = Pay::find(1);
        $this->expectGet('CREATED');
        $this->assertSame(self::ID, $this->begin()['provider_id']);
    }

    public function testDisabledQuotesAndOuterTransactionPreventNetwork(): void
    {
        $this->quotes->shouldReceive('enabled')->andReturn(false);
        $this->assertFalse($this->service->enabled());
        $this->rule(function () { $this->begin(); }, 'payment_intent_unavailable');
        $this->quotes->shouldReceive('enabled')->andReturn(true);
        DB::beginTransaction();
        try { $this->rule(function () { $this->begin(); }, 'payment_intent_unavailable'); }
        finally { DB::rollBack(); }
        config(['dujiaoka.paypal_orders_v2' => false]);
        $this->rule(function () { $this->service->sync($this->order, $this->pay, true); }, 'payment_intent_unavailable');
        $this->assertSame(0, PaypalOrder::count());
    }

    public function testCaptureLeaseBlocksCompetingPostAndExpiredAttemptStopsPost(): void
    {
        $this->create();
        PaypalOrder::query()->update(['capture_lease_token' => str_repeat('a', 32),
            'capture_leased_until' => Carbon::now()->addSeconds(90), 'capture_attempted_at' => Carbon::now()]);
        $this->expectGet('APPROVED', 2);
        $this->assertSame('APPROVED', $this->service->sync($this->order, $this->pay, true)['status']);
        Carbon::setTestNow(Carbon::now()->addHours(5));
        $this->rule(function () { $this->service->sync($this->order, $this->pay, true); }, 'payment_intent_unavailable');
    }

    public function testDisconnectedCaptureKeepsKeyAndRegetCompletesWithoutSecondPost(): void
    {
        $this->create();
        $before = PaypalOrder::first();
        $this->expectGet('APPROVED');
        $this->client->shouldReceive('capture')->once()->andReturnUsing(function ($credentials, $id, $key) use ($before) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($before->capture_key, $key);
            $this->assertSame(self::ID, $id);
            $this->assertNotNull(PaypalOrder::first()->capture_attempted_at);
            throw new \RuntimeException('connection secret');
        });
        $this->rule(function () { $this->service->sync($this->order, $this->pay, true); }, 'payment_intent_unavailable');
        $row = PaypalOrder::first();
        $this->assertSame($before->capture_key, $row->capture_key);
        $this->assertNull($row->capture_lease_token);
        $this->assertNotNull($row->capture_attempted_at);
        Carbon::setTestNow(Carbon::now()->addYears(1));
        DB::table('pays')->update(['is_open' => 0]);
        $this->pay = Pay::find(1);
        $this->expectGet('COMPLETED');
        $this->client->shouldReceive('getCapture')->once()->andReturnUsing(function () {
            $this->assertSame(0, DB::transactionLevel()); return $this->captureReceipt();
        });
        $this->completion();
        $this->assertSame(Order::STATUS_COMPLETED, $this->service->sync($this->order, $this->pay, true)['order_status']);
        $this->assertSame(self::CAPTURE, PaypalOrder::first()->capture_id);
    }

    public function testCaptureResponseIsIgnoredUntilOrderAndIndependentCaptureGet(): void
    {
        $this->create();
        $this->expectGet('APPROVED');
        $this->client->shouldReceive('capture')->once()->andReturn(['status' => 'COMPLETED']);
        $this->expectGet('COMPLETED');
        $this->client->shouldReceive('getCapture')->once()->andReturn($this->captureReceipt());
        $this->completion();
        $this->assertSame(Order::STATUS_COMPLETED, $this->service->sync($this->order, $this->pay, true)['order_status']);
    }

    /** @dataProvider ioMutations */
    #[\PHPUnit\Framework\Attributes\DataProvider('ioMutations')]
    public function testNetworkMutationsStopBeforeCapture(string $table, array $change): void
    {
        $this->create();
        $this->client->shouldReceive('get')->once()->andReturnUsing(function () use ($table, $change) {
            DB::table($table)->where('id', 1)->update($change);
            return $this->receipt('APPROVED');
        });
        $this->rule(function () { $this->service->sync($this->order, $this->pay, true); });
    }

    public static function ioMutations(): array
    {
        return [['pays', ['is_open' => 0]], ['pays', ['merchant_pem' => 'changed']],
            ['orders', ['actual_price' => '71.00']], ['orders', ['pay_id' => 2]],
            ['paypal_orders', ['create_key' => str_repeat('f', 32)]]];
    }

    public function testIndependentCaptureUnderpaymentNeverCompletes(): void
    {
        $this->create();
        $this->expectGet('COMPLETED');
        $capture = $this->captureReceipt();
        $capture['amount']['value'] = '9.99';
        $this->client->shouldReceive('getCapture')->once()->andReturn($capture);
        $this->rule(function () { $this->service->sync($this->order, $this->pay); });
        $this->assertNull(PaypalOrder::first()->capture_id);
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::find(1)->status);
    }

    public function testFulfillmentGuardRechecksBindingAfterReceiptNetwork(): void
    {
        $this->create();
        $this->expectGet('COMPLETED');
        $this->client->shouldReceive('getCapture')->once()->andReturn($this->captureReceipt());
        $this->completion(1, function () { DB::table('pays')->update(['merchant_key' => 'changed']); });
        $this->rule(function () { $this->service->sync($this->order, $this->pay); });
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::find(1)->status);
    }

    /** @dataProvider invalidReceipts */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidReceipts')]
    public function testUnsettledOrMalformedOrderReceiptsNeverFulfill(string $kind): void
    {
        $this->create();
        $this->client->shouldReceive('get')->once()->andReturnUsing(function () use ($kind) {
            $receipt = $this->receipt('COMPLETED');
            $unit =& $receipt['purchase_units'][0];
            if ($kind === 'payee') { $unit['payee']['merchant_id'] = 'OTHERACCOUNT1'; }
            if ($kind === 'metadata') { $unit['custom_id'] = str_repeat('f', 32); }
            if ($kind === 'amount') { $unit['amount']['value'] = '9.99'; }
            if ($kind === 'final') { $unit['payments']['captures'][0]['final_capture'] = false; }
            if ($kind === 'pending') { $unit['payments']['captures'][0]['status'] = 'PENDING'; }
            if ($kind === 'multiple') { $receipt['purchase_units'][] = $unit; }
            return $receipt;
        });
        if ($kind === 'pending') {
            $result = $this->service->sync($this->order, $this->pay, true);
            $this->assertSame('CAPTURE_PENDING', $result['status']);
            $this->assertSame(Order::STATUS_WAIT_PAY, $result['order_status']);
        } else {
            $this->rule(function () { $this->service->sync($this->order, $this->pay, true); });
        }
        $this->assertNull(PaypalOrder::first()->capture_id);
    }

    public static function invalidReceipts(): array
    {
        return [['payee'], ['metadata'], ['amount'], ['final'], ['pending'], ['multiple']];
    }

    public function testClosedAndAlreadyPaidApprovedOrdersNeverCapture(): void
    {
        $this->create();
        DB::table('pays')->update(['is_open' => 0]);
        $this->pay = Pay::find(1);
        $this->expectGet('APPROVED', 2);
        $this->assertSame('APPROVED', $this->service->sync($this->order, $this->pay, true)['status']);
        DB::table('pays')->update(['is_open' => 1]);
        $this->pay = Pay::find(1);
        DB::table('orders')->update(['status' => Order::STATUS_COMPLETED]);
        $this->assertSame(Order::STATUS_COMPLETED, $this->service->sync($this->order, $this->pay, true)['order_status']);
        $this->assertNull(PaypalOrder::first()->capture_attempted_at);
    }

    public function testCaptureRetryReusesKeyAndPreservesReplacementLease(): void
    {
        $this->create();
        $key = PaypalOrder::first()->capture_key;
        $attempted = null;
        $this->expectGet('APPROVED', 3);
        $this->client->shouldReceive('capture')->twice()->andReturnUsing(function ($credentials, $id, $actualKey) use ($key, &$attempted) {
            $this->assertSame($key, $actualKey);
            $row = PaypalOrder::first();
            if ($attempted !== null) {
                $this->assertSame($attempted, (string) $row->capture_attempted_at);
                PaypalOrder::query()->update(['capture_lease_token' => str_repeat('e', 32),
                    'capture_leased_until' => Carbon::now()->addSeconds(90)]);
            } else {
                $attempted = (string) $row->capture_attempted_at;
            }
            throw new \RuntimeException('timeout');
        });
        $this->rule(function () { $this->service->sync($this->order, $this->pay, true); }, 'payment_intent_unavailable');
        Carbon::setTestNow(Carbon::now()->addSeconds(1));
        $this->rule(function () { $this->service->sync($this->order, $this->pay, true); }, 'payment_intent_unavailable');
        $this->assertSame(str_repeat('e', 32), PaypalOrder::first()->capture_lease_token);
        $this->assertSame('APPROVED', $this->service->sync($this->order, $this->pay, true)['status']);
    }

    public function testFrozenQuoteMismatchStopsBeforeProviderIo(): void
    {
        $this->create();
        $this->quotes->shouldReceive('frozenUsd')->andReturn('11.00');
        $this->rule(function () { $this->service->sync($this->order, $this->pay); });
    }

    public function testOriginalCompletionRunsGuardInsideItsOwnTransactionForPaidOrder(): void
    {
        $this->create();
        DB::table('orders')->update(['status' => Order::STATUS_COMPLETED, 'trade_no' => self::CAPTURE]);
        DB::table('pays')->update(['is_open' => 0]);
        $this->pay = Pay::find(1);
        $this->expectGet('COMPLETED');
        $this->client->shouldReceive('getCapture')->once()->andReturn($this->captureReceipt());
        $this->app->instance(OrderProcessService::class, new OrderProcessService());
        $lock = new class extends \App\Service\PaymentGatewayLock {
            public $completionChecks = 0;
            public function assertBoundGateway(Order $order, Pay $pay): void
            {
                parent::assertBoundGateway($order, $pay);
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                    if (($frame['function'] ?? '') === 'completedOrder') {
                        if (DB::transactionLevel() !== 1) { throw new \RuntimeException('Guard lost transaction'); }
                        $this->completionChecks++;
                    }
                }
            }
        };
        $this->app->instance(\App\Service\PaymentGatewayLock::class, $lock);
        $result = $this->service->sync($this->order, $this->pay, true);
        $this->assertSame(Order::STATUS_COMPLETED, $result['order_status']);
        $this->assertSame(1, $lock->completionChecks);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testMigrationEnforcesUniqueBindingsAndKeys(): void
    {
        $this->create();
        $row = PaypalOrder::first()->getAttributes();
        unset($row['id']);
        foreach (['order_id', 'reference', 'create_key', 'capture_key', 'provider_id'] as $field) {
            $copy = array_replace($row, ['order_id' => 2, 'reference' => str_repeat('a', 32),
                'create_key' => str_repeat('b', 32), 'capture_key' => str_repeat('c', 32), 'provider_id' => '9O190127TN364715T']);
            $copy[$field] = $row[$field];
            try { DB::table('paypal_orders')->insert($copy); $this->fail('Expected unique constraint'); }
            catch (QueryException $e) { $this->assertSame(1, PaypalOrder::count()); }
        }
    }
}
