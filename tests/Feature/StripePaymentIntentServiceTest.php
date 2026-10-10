<?php

namespace Tests\Feature;

use App\Exceptions\RuleValidationException;
use App\Models\Order;
use App\Models\Pay;
use App\Models\StripePaymentIntent;
use App\Payments\StripeIntentClient;
use App\Service\OrderProcessService;
use App\Service\PaymentQuoteService;
use App\Service\StripePaymentIntentService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StripePaymentIntentServiceTest extends TestCase
{
    private $quotes;
    private $stripe;
    private $service;
    private $order;
    private $pay;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'dujiaoka.stripe_payment_intents' => true, 'dujiaoka.payment_quote_freeze' => true]);
        DB::purge('sqlite');
        Event::fake();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
        require_once base_path('database/migrations/2026_01_01_000004_create_stripe_payment_intents_table.php');
        (new \CreateStripePaymentIntentsTable())->up();
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('order_sn'); $t->unsignedBigInteger('pay_id');
            $t->decimal('actual_price', 10, 2); $t->integer('status'); $t->string('trade_no')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('pays', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('pay_handleroute'); $t->integer('is_open');
            $t->string('merchant_id'); $t->string('merchant_pem'); $t->timestamps(); $t->softDeletes();
        });
        foreach ([1, 2] as $id) {
            DB::table('pays')->insert(['id' => $id, 'is_open' => 1, 'pay_handleroute' => 'pay/stripe',
                'merchant_id' => 'pk_test_fixture', 'merchant_pem' => 'sk_test_fixture']);
        }
        DB::table('orders')->insert(['id' => 1, 'order_sn' => 'INTENT-1', 'pay_id' => 1,
            'actual_price' => '70.00', 'status' => Order::STATUS_WAIT_PAY]);
        $this->order = Order::findOrFail(1);
        $this->pay = Pay::findOrFail(1);
        $this->quotes = \Mockery::mock(PaymentQuoteService::class);
        $this->quotes->shouldReceive('enabled')->andReturn(true)->byDefault();
        $this->quotes->shouldReceive('cnyToUsd')->with(\Mockery::type(Order::class), \Mockery::type(Pay::class), 'cmb')
            ->andReturnUsing(function () { $this->assertSame(0, DB::transactionLevel()); return '10.00'; })->byDefault();
        $this->quotes->shouldReceive('frozenUsd')->andReturn('10.00')->byDefault();
        $this->stripe = \Mockery::mock(StripeIntentClient::class);
        $this->service = new StripePaymentIntentService($this->quotes, $this->stripe);
        $completion = \Mockery::mock(OrderProcessService::class);
        $completion->shouldNotReceive('completedOrder');
        $this->app->instance(OrderProcessService::class, $completion);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->app) {
            (new \CreateStripePaymentIntentsTable())->down();
            $this->assertFalse(Schema::hasTable('stripe_payment_intents'));
        }
        parent::tearDown();
    }

    private function begin(string $currency = 'usd'): array
    {
        return $this->service->begin($this->order, $this->pay, $currency);
    }

    private function intent(array $overrides = []): array
    {
        $row = StripePaymentIntent::firstOrFail();
        return array_replace_recursive([
            'id' => 'pi_fixture', 'object' => 'payment_intent', 'client_secret' => 'pi_fixture_secret_fixture',
            'currency' => $row->currency, 'amount' => (int) $row->amount_minor, 'livemode' => false,
            'capture_method' => 'automatic', 'confirmation_method' => 'automatic',
            'payment_method_types' => $row->currency === 'usd' ? ['card', 'wechat_pay'] : ['alipay'],
            'metadata' => ['order_sn' => 'INTENT-1', 'binding_reference' => $row->reference],
            'status' => 'requires_payment_method',
        ], $overrides);
    }

    private function createIntent(string $currency = 'usd'): array
    {
        $this->stripe->shouldReceive('create')->once()->andReturnUsing(function ($key, $params, $idempotency) {
            $this->assertSame(0, DB::transactionLevel());
            $row = StripePaymentIntent::firstOrFail();
            $this->assertSame('sk_test_fixture', $key);
            $this->assertSame($row->reference, $idempotency);
            $this->assertFalse($params['confirm']);
            $this->assertSame((string) $row->amount_minor, $params['amount']);
            $this->assertSame('automatic', $params['confirmation_method']);
            $this->assertSame('automatic', $params['capture_method']);
            $this->assertSame(['order_sn' => 'INTENT-1', 'binding_reference' => $row->reference], $params['metadata']);
            $this->assertArrayNotHasKey('receipt_email', $params);
            if ($row->currency === 'usd') {
                $this->assertSame(['card', 'wechat_pay'], $params['payment_method_types']);
                $this->assertSame(['wechat_pay' => ['client' => 'web']], $params['payment_method_options']);
            } else {
                $this->assertSame(['alipay'], $params['payment_method_types']);
                $this->assertArrayNotHasKey('payment_method_options', $params);
            }
            return $this->intent();
        });
        return $this->begin($currency);
    }

    private function expectRule(callable $action, string $key = 'payment_intent_invalid'): void
    {
        try {
            $action();
            $this->fail('Expected a public validation error.');
        } catch (RuleValidationException $exception) {
            $this->assertSame(__('dujiaoka.prompt.' . $key), $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function testDisabledPathsNeverQueryIntentTable(): void
    {
        (new \CreateStripePaymentIntentsTable())->down();
        config(['dujiaoka.stripe_payment_intents' => false]);
        $this->assertFalse($this->service->enabled());
        $this->expectRule(function () { $this->begin(); }, 'payment_intent_unavailable');
        $this->expectRule(function () { $this->service->sync($this->order, $this->pay); }, 'payment_intent_unavailable');
        $this->assertSame(2, (int) $this->service->selectGateway($this->order, Pay::find(2))->pay_id);
    }

    public function testQuotesFlagAndOuterTransactionsStopBeforeNetwork(): void
    {
        $this->quotes->shouldReceive('enabled')->andReturn(false);
        $this->expectRule(function () { $this->begin(); }, 'payment_intent_unavailable');
        DB::beginTransaction();
        try {
            $this->expectRule(function () { $this->begin(); }, 'payment_intent_unavailable');
            $this->expectRule(function () { $this->service->sync($this->order, $this->pay); }, 'payment_intent_unavailable');
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, StripePaymentIntent::count());
    }

    public function testRepeatedBeginsReuseOneBindingAndRetrieveDatabaseId(): void
    {
        $result = $this->createIntent();
        $before = StripePaymentIntent::first()->getAttributes();
        $this->assertSame(['intent_id', 'client_secret', 'currency', 'amount', 'status'], array_keys($result));
        $this->assertSame('1000', $result['amount']);
        $this->stripe->shouldReceive('retrieve')->twice()->with('sk_test_fixture', 'pi_fixture')
            ->andReturnUsing(function () { $this->assertSame(0, DB::transactionLevel()); return $this->intent(); });
        $this->assertSame($result, $this->begin());
        $this->assertSame($result, (new StripePaymentIntentService($this->quotes, $this->stripe))->begin($this->order, $this->pay, 'usd'));
        $this->assertSame(1, StripePaymentIntent::count());
        $this->assertSame($before, StripePaymentIntent::first()->getAttributes());
        $this->assertArrayNotHasKey('client_secret', $before);
        $this->assertArrayNotHasKey('token', $before);
    }

    public function testCurrencyGatewayAndAccountCannotChangeAfterBinding(): void
    {
        $this->createIntent();
        $this->expectRule(function () { $this->begin('cny'); });
        $this->expectRule(function () { $this->service->selectGateway($this->order, Pay::find(2)); }, 'payment_method_locked');
        DB::table('pays')->where('id', 1)->update(['merchant_pem' => 'sk_test_changed']);
        $this->expectRule(function () { $this->begin(); });
        $this->pay = Pay::find(1);
        $this->expectRule(function () { $this->begin(); });
        $this->assertSame(1, StripePaymentIntent::count());
    }

    public function testDisconnectedCreationRetainsStableKeyAndExactParameters(): void
    {
        $seen = null;
        $this->stripe->shouldReceive('create')->twice()->andReturnUsing(function ($key, $params, $idempotency) use (&$seen) {
            $this->assertSame(0, DB::transactionLevel());
            if ($seen === null) {
                $seen = [$key, $params, $idempotency];
                throw new \RuntimeException('APIKEY client_secret SQL response-body');
            }
            $this->assertSame($seen, [$key, $params, $idempotency]);
            return $this->intent();
        });
        $this->expectRule(function () { $this->begin(); }, 'payment_intent_unavailable');
        $this->assertNull(StripePaymentIntent::first()->provider_id);
        $this->assertSame('pi_fixture', $this->begin()['intent_id']);
        $this->assertSame(1, StripePaymentIntent::count());
    }

    public function testUnknownIdStopsAtTwentyThreeHoursButBoundIdAlwaysRetrieves(): void
    {
        $this->stripe->shouldReceive('create')->once()->andThrow(new \RuntimeException('timeout'));
        $this->expectRule(function () { $this->begin(); }, 'payment_intent_unavailable');
        Carbon::setTestNow(Carbon::now()->addHours(23));
        $this->expectRule(function () { $this->begin(); }, 'payment_intent_unavailable');
        StripePaymentIntent::query()->update(['provider_id' => 'pi_fixture']);
        Carbon::setTestNow(Carbon::now()->addYears(5));
        $this->stripe->shouldReceive('retrieve')->once()->with('sk_test_fixture', 'pi_fixture')->andReturnUsing(function () { return $this->intent(); });
        $this->assertSame('pi_fixture', $this->begin()['intent_id']);
    }

    /** @dataProvider invalidIntents */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIntents')]
    public function testMalformedOrMismatchedResponseNeverBinds(array $change): void
    {
        $this->stripe->shouldReceive('create')->once()->andReturnUsing(function () use ($change) { return array_replace($this->intent(), $change); });
        $this->expectRule(function () { $this->begin(); });
        $this->assertNull(StripePaymentIntent::first()->provider_id);
    }

    public static function invalidIntents(): array
    {
        return [
            [['id' => 'ch_bad']], [['object' => 'charge']], [['amount' => 999]], [['amount' => 1000.0]],
            [['currency' => 'cny']], [['capture_method' => 'manual']], [['confirmation_method' => 'manual']],
            [['payment_method_types' => ['card']]], [['payment_method_types' => 'card']], [['metadata' => null]],
            [['metadata' => ['order_sn' => 'other', 'binding_reference' => 'bad']]], [['livemode' => true]],
            [['status' => 'secret body']], [['client_secret' => 'another_secret']], [['client_secret' => null]],
        ];
    }

    /** @dataProvider networkChanges */
    #[\PHPUnit\Framework\Attributes\DataProvider('networkChanges')]
    public function testCreationRechecksChangesDuringNetwork(string $table, array $change): void
    {
        $this->stripe->shouldReceive('create')->once()->andReturnUsing(function () use ($table, $change) {
            $this->assertSame(0, DB::transactionLevel());
            $response = $this->intent();
            DB::table($table)->where('id', 1)->update($change);
            return $response;
        });
        $this->expectRule(function () { $this->begin(); });
        $this->assertNull(StripePaymentIntent::first()->provider_id);
    }

    public static function networkChanges(): array
    {
        return [['orders', ['actual_price' => '71.00']], ['orders', ['pay_id' => 2]],
            ['orders', ['status' => Order::STATUS_COMPLETED]], ['orders', ['order_sn' => 'OTHER']],
            ['pays', ['is_open' => 0]], ['pays', ['merchant_pem' => 'sk_test_rotated']],
            ['pays', ['pay_handleroute' => 'pay/paypal']]];
    }

    public function testConcurrentSameProviderBindingReusesAndDifferentIdFails(): void
    {
        $this->stripe->shouldReceive('create')->once()->andReturnUsing(function () {
            StripePaymentIntent::query()->update(['provider_id' => 'pi_fixture']);
            return $this->intent();
        });
        $this->assertSame('pi_fixture', $this->begin()['intent_id']);
        $this->stripe->shouldReceive('retrieve')->once()->andReturnUsing(function () {
            StripePaymentIntent::query()->update(['provider_id' => 'pi_other']);
            return $this->intent();
        });
        $this->expectRule(function () { $this->begin(); });
    }

    public function testDifferentProviderIdWinningCreationIsNotOverwritten(): void
    {
        $this->stripe->shouldReceive('create')->once()->andReturnUsing(function () {
            StripePaymentIntent::query()->update(['provider_id' => 'pi_other']);
            return $this->intent();
        });
        $this->expectRule(function () { $this->begin(); });
        $this->assertSame('pi_other', StripePaymentIntent::first()->provider_id);
    }

    public function testSyncDoesNotCreateAndPendingNeverCompletes(): void
    {
        $this->expectRule(function () { $this->service->sync($this->order, $this->pay); });
        $this->createIntent();
        foreach (['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing', 'requires_capture', 'canceled'] as $status) {
            $this->stripe->shouldReceive('retrieve')->once()->with('sk_test_fixture', 'pi_fixture')
                ->andReturnUsing(function () use ($status) { $this->assertSame(0, DB::transactionLevel()); return $this->intent(['status' => $status]); });
            $this->assertSame(['status' => $status, 'order_status' => Order::STATUS_WAIT_PAY], $this->service->sync($this->order, $this->pay));
        }
    }

    private function succeeded(array $changes = []): array
    {
        $row = StripePaymentIntent::firstOrFail();
        return array_replace_recursive($this->intent(), ['status' => 'succeeded', 'amount_received' => (int) $row->amount_minor,
            'latest_charge' => ['id' => 'ch_fixture', 'object' => 'charge', 'payment_intent' => 'pi_fixture',
                'status' => 'succeeded', 'paid' => true, 'captured' => true, 'refunded' => false, 'amount_refunded' => 0,
                'amount' => (int) $row->amount_minor, 'currency' => $row->currency]], $changes);
    }

    /** @dataProvider invalidCharges */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCharges')]
    public function testSucceededRequiresExactReceivedAmountAndRealCharge(array $change): void
    {
        $this->createIntent();
        $this->stripe->shouldReceive('retrieve')->once()->andReturnUsing(function () use ($change) { return $this->succeeded($change); });
        $this->expectRule(function () { $this->service->sync($this->order, $this->pay); });
    }

    public static function invalidCharges(): array
    {
        return [[['amount_received' => 999]], [['latest_charge' => 'ch_fixture']], [['latest_charge' => null]],
            [['latest_charge' => ['id' => 'pi_bad']]], [['latest_charge' => ['object' => 'payment_intent']]],
            [['latest_charge' => ['status' => 'pending']]], [['latest_charge' => ['paid' => false]]],
            [['latest_charge' => ['captured' => false]]], [['latest_charge' => ['refunded' => true]]],
            [['latest_charge' => ['amount_refunded' => 1]]], [['latest_charge' => ['amount' => 999]]],
            [['latest_charge' => ['currency' => 'cny']]], [['latest_charge' => ['payment_intent' => 'pi_other']]]];
    }

    /** @dataProvider completionStates */
    #[\PHPUnit\Framework\Attributes\DataProvider('completionStates')]
    public function testSuccessPassesOriginalCnyAndChargeForNormalExpiredAndRepeatedOrders(string $currency, int $status): void
    {
        $this->createIntent($currency);
        DB::table('orders')->where('id', 1)->update(['status' => $status]);
        $this->stripe->shouldReceive('retrieve')->twice()->with('sk_test_fixture', 'pi_fixture')->andReturnUsing(function () { return $this->succeeded(); });
        $completion = \Mockery::mock(OrderProcessService::class);
        $completion->shouldReceive('completedOrder')->twice()->with('INTENT-1', 70.0, 'ch_fixture', \Mockery::type(\Closure::class))->andReturnUsing(function ($sn, $amount, $charge, $guard) use ($status) {
            $this->assertSame(0, DB::transactionLevel());
            DB::transaction(function () use ($guard) { $guard(Order::find(1)); $this->assertSame(1, DB::transactionLevel()); });
            DB::table('orders')->where('id', 1)->update(['status' => $status === Order::STATUS_EXPIRED ? Order::STATUS_ABNORMAL : Order::STATUS_COMPLETED]);
            return Order::find(1);
        });
        $this->app->instance(OrderProcessService::class, $completion);
        $result = $this->service->sync($this->order, $this->pay);
        $this->assertSame('succeeded', $result['status']);
        $this->assertSame($status === Order::STATUS_EXPIRED ? Order::STATUS_ABNORMAL : Order::STATUS_COMPLETED, $result['order_status']);
        $this->assertSame($result, $this->service->sync($this->order, $this->pay));
    }

    public static function completionStates(): array
    {
        return [['usd', Order::STATUS_WAIT_PAY], ['usd', Order::STATUS_EXPIRED], ['usd', Order::STATUS_COMPLETED],
            ['cny', Order::STATUS_WAIT_PAY], ['cny', Order::STATUS_EXPIRED]];
    }

    public function testSyncRevalidatesAfterNetworkBeforeCompleting(): void
    {
        $this->createIntent();
        $this->stripe->shouldReceive('retrieve')->once()->andReturnUsing(function () {
            DB::table('orders')->where('id', 1)->update(['actual_price' => '71.00']);
            return $this->succeeded();
        });
        $this->expectRule(function () { $this->service->sync($this->order, $this->pay); });
    }

    public function testSyncRejectsWrongDatabaseIdAndCompletionErrorsAreSanitized(): void
    {
        $this->createIntent();
        $this->stripe->shouldReceive('retrieve')->once()->andReturnUsing(function () { return $this->intent(['id' => 'pi_other']); });
        $this->expectRule(function () { $this->service->sync($this->order, $this->pay); });
        $this->stripe->shouldReceive('retrieve')->once()->andReturnUsing(function () { return $this->succeeded(); });
        $completion = \Mockery::mock(OrderProcessService::class);
        $completion->shouldReceive('completedOrder')->once()->andThrow(new RuleValidationException('SQL password secret'));
        $this->app->instance(OrderProcessService::class, $completion);
        $this->expectRule(function () { $this->service->sync($this->order, $this->pay); }, 'payment_intent_unavailable');
    }

    public function testSelectGatewayUsesLockedFreshOrderInsteadOfStaleSnapshot(): void
    {
        $stale = clone $this->order;
        $this->createIntent();
        $this->expectRule(function () use ($stale) { $this->service->selectGateway($stale, Pay::find(2)); }, 'payment_method_locked');
        $this->assertSame(1, (int) $this->service->selectGateway($stale, $this->pay)->pay_id);
        DB::table('orders')->where('id', 1)->update(['status' => Order::STATUS_COMPLETED]);
        $this->expectRule(function () use ($stale) { $this->service->selectGateway($stale, $this->pay); });
    }

    public function testGatewaySelectionWinningBeforeBeginInvalidatesOldSnapshot(): void
    {
        $this->service->selectGateway($this->order, Pay::find(2));
        $this->expectRule(function () { $this->begin(); });
        $this->assertSame(0, StripePaymentIntent::count());
    }

    public function testMigrationEnforcesOrderAndProviderUniqueness(): void
    {
        $this->createIntent();
        $row = StripePaymentIntent::first()->getAttributes();
        unset($row['id']);
        foreach ([['reference' => str_repeat('a', 32), 'provider_id' => 'pi_second'],
            ['order_id' => 2, 'reference' => str_repeat('b', 32)]] as $change) {
            try {
                DB::table('stripe_payment_intents')->insert(array_replace($row, $change));
                $this->fail('Expected unique constraint.');
            } catch (QueryException $exception) {
                $this->assertSame(1, StripePaymentIntent::count());
            }
        }
    }
}
