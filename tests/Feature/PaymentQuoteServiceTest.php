<?php

namespace Tests\Feature;

use App\Exceptions\RuleValidationException;
use App\Models\Order;
use App\Models\Pay;
use App\Models\PaymentQuote;
use App\Service\ExchangeRateService;
use App\Service\PaymentQuoteService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentQuoteServiceTest extends TestCase
{
    private $rates;
    private $service;
    private $order;
    private $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'dujiaoka.payment_quote_freeze' => true]);
        DB::purge('sqlite');
        require_once base_path('database/migrations/2026_01_01_000003_create_payment_quotes_table.php');
        (new \CreatePaymentQuotesTable())->up();
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('order_sn'); $t->unsignedBigInteger('pay_id');
            $t->decimal('actual_price', 10, 2); $t->integer('status');
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('pays', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('pay_handleroute'); $t->timestamps(); $t->softDeletes();
        });
        DB::table('pays')->insert(['id' => 1, 'pay_handleroute' => '/pay/paypal/']);
        DB::table('pays')->insert(['id' => 2, 'pay_handleroute' => 'pay/stripe']);
        DB::table('orders')->insert(['id' => 1, 'order_sn' => 'QUOTE-1', 'pay_id' => 1,
            'actual_price' => '70.00', 'status' => Order::STATUS_WAIT_PAY]);
        $this->order = Order::findOrFail(1);
        $this->gateway = Pay::findOrFail(1);
        $this->rates = \Mockery::mock(ExchangeRateService::class);
        $this->service = new PaymentQuoteService($this->rates);
    }

    private function quote(array $overrides = []): array
    {
        return array_merge(['cny_amount' => '70.00', 'usd_amount' => '10.00', 'source' => 'frankfurter',
            'numerator' => '1', 'denominator' => '7', 'date' => '2026-01-01', 'fetched_at' => 1767268800], $overrides);
    }

    private function freeze(): string
    {
        return $this->service->cnyToUsd($this->order, $this->gateway, 'frankfurter');
    }

    private function expectRule(callable $action, string $key = 'payment_quote_invalid'): void
    {
        try {
            $action();
            $this->fail('Expected a localized validation error.');
        } catch (RuleValidationException $exception) {
            $this->assertSame(__('dujiaoka.prompt.' . $key), $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    private function createQuote(): void
    {
        $this->rates->shouldReceive('quoteCnyToUsd')->once()->andReturn($this->quote());
        $this->assertSame('10.00', $this->freeze());
    }

    public function testDisabledDelegatesWithoutQuoteTable(): void
    {
        (new \CreatePaymentQuotesTable())->down();
        config(['dujiaoka.payment_quote_freeze' => false]);
        $this->assertFalse($this->service->enabled());
        $this->rates->shouldReceive('cnyToUsd')->once()->with($this->order->actual_price, 'frankfurter')->andReturn('11.00');
        $this->assertSame('11.00', $this->freeze());
    }

    public function testFreezeSurvivesRepeatedCallsNewInstancesRateChangesAndOutages(): void
    {
        $this->createQuote();
        $before = PaymentQuote::first()->toArray();
        $this->assertSame('10.00', $this->freeze());
        $changed = \Mockery::mock(ExchangeRateService::class);
        $changed->shouldReceive('quoteCnyToUsd')->never();
        $changed->shouldReceive('cnyToUsd')->never();
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2030-01-01 12:00:00'));
        try {
            $this->assertSame('10.00', (new PaymentQuoteService($changed))->cnyToUsd($this->order, $this->gateway, 'frankfurter'));
        } finally {
            \Carbon\Carbon::setTestNow();
        }
        $broken = \Mockery::mock(ExchangeRateService::class);
        $broken->shouldReceive('quoteCnyToUsd')->andThrow(new \RuntimeException('Provider unavailable'));
        $this->assertSame('10.00', (new PaymentQuoteService($broken))->cnyToUsd($this->order, $this->gateway, 'frankfurter'));
        $this->assertSame($before, PaymentQuote::first()->toArray());
        $this->assertSame(1, PaymentQuote::count());
    }

    public function testSameOrderSeparatesGatewaysAndSources(): void
    {
        $this->createQuote();
        DB::table('orders')->where('id', 1)->update(['pay_id' => 2]);
        $order = Order::findOrFail(1);
        $gateway = Pay::findOrFail(2);
        $this->rates->shouldReceive('quoteCnyToUsd')->once()->with($order->actual_price, 'cmb')
            ->andReturn($this->quote(['source' => 'cmb', 'numerator' => '2', 'usd_amount' => '20.00']));
        $this->assertSame('20.00', $this->service->cnyToUsd($order, $gateway, 'cmb'));
        $this->assertSame(2, PaymentQuote::count());
        DB::table('orders')->where('id', 1)->update(['pay_id' => 1]);
        $this->assertSame('10.00', $this->freeze());
    }

    /** @dataProvider changedOrderFields */
    #[\PHPUnit\Framework\Attributes\DataProvider('changedOrderFields')]
    public function testRejectsStaleOrderBeforeFetching(array $changes): void
    {
        DB::table('orders')->where('id', 1)->update($changes);
        $this->rates->shouldReceive('quoteCnyToUsd')->never();
        $this->expectRule(function () { $this->freeze(); });
        $this->assertSame(0, PaymentQuote::count());
    }

    public static function changedOrderFields(): array
    {
        return [[['actual_price' => '71.00']], [['status' => Order::STATUS_COMPLETED]],
            [['pay_id' => 2]], [['order_sn' => 'REPLACED']]];
    }

    public function testChangedAmountWithFreshSnapshotDoesNotReprice(): void
    {
        $this->createQuote();
        DB::table('orders')->where('id', 1)->update(['actual_price' => '71.00']);
        $this->order = Order::findOrFail(1);
        $this->expectRule(function () { $this->freeze(); });
        $this->assertSame('70.00', PaymentQuote::first()->cny_amount);
    }

    public function testWrongSourceOrChangedGatewayRouteIsInvalid(): void
    {
        $this->expectRule(function () { $this->service->cnyToUsd($this->order, $this->gateway, 'cmb'); });
        DB::table('pays')->where('id', 1)->update(['pay_handleroute' => '/pay/stripe']);
        $this->expectRule(function () { $this->freeze(); });
    }

    public function testProviderRunsOutsideTransactionAndWinnerIsReused(): void
    {
        $this->rates->shouldReceive('quoteCnyToUsd')->once()->andReturnUsing(function () {
            $this->assertSame(0, DB::transactionLevel());
            // Simulate another checkout committing while our provider request is in flight.
            DB::transaction(function () {
                Order::whereKey(1)->lockForUpdate()->first();
                PaymentQuote::create(['order_id' => 1, 'pay_id' => 1, 'base_currency' => 'CNY', 'currency' => 'USD',
                    'source' => 'frankfurter', 'cny_amount' => '70.00', 'usd_amount' => '20.00',
                    'numerator' => '2', 'denominator' => '7', 'rate_date' => '2026-01-01']);
            });
            return $this->quote();
        });
        $this->assertSame('20.00', $this->freeze());
        $this->assertSame(1, PaymentQuote::count());
    }

    /** @dataProvider changedOrderFields */
    #[\PHPUnit\Framework\Attributes\DataProvider('changedOrderFields')]
    public function testRechecksOrderAfterProviderRequest(array $changes): void
    {
        $this->rates->shouldReceive('quoteCnyToUsd')->once()->andReturnUsing(function () use ($changes) {
            $this->assertSame(0, DB::transactionLevel());
            DB::table('orders')->where('id', 1)->update($changes);
            return $this->quote();
        });
        $this->expectRule(function () { $this->freeze(); });
        $this->assertSame(0, PaymentQuote::count());
    }

    public function testRateFailureLeavesNoRow(): void
    {
        $this->rates->shouldReceive('quoteCnyToUsd')->once()
            ->andThrow(new RuleValidationException(__('dujiaoka.prompt.currency_rate_unavailable')));
        $this->expectRule(function () { $this->freeze(); }, 'currency_rate_unavailable');
        $this->assertSame(0, PaymentQuote::count());
        $this->assertSame(0, DB::transactionLevel());
    }

    /** @dataProvider corruptQuoteFields */
    #[\PHPUnit\Framework\Attributes\DataProvider('corruptQuoteFields')]
    public function testInvalidProviderQuoteNeverPersists(array $changes): void
    {
        $this->rates->shouldReceive('quoteCnyToUsd')->once()->andReturn($this->quote($changes));
        $this->expectRule(function () { $this->freeze(); });
        $this->assertSame(0, PaymentQuote::count());
    }

    public static function corruptQuoteFields(): array
    {
        return [[['usd_amount' => '10.01']], [['cny_amount' => '71.00']], [['source' => 'cmb']],
            [['numerator' => '0']], [['denominator' => '0']], [['date' => '2026-02-30']],
            [['numerator' => 1]], [['denominator' => 'secret-provider-payload']], [['fetched_at' => null]]];
    }

    /** @dataProvider corruptStoredFields */
    #[\PHPUnit\Framework\Attributes\DataProvider('corruptStoredFields')]
    public function testCorruptStoredQuoteIsRejected(array $changes): void
    {
        $this->createQuote();
        DB::table('payment_quotes')->update($changes);
        $this->expectRule(function () { $this->freeze(); });
        $this->expectRule(function () { $this->service->frozenUsd($this->order, $this->gateway, 'frankfurter'); });
    }

    public static function corruptStoredFields(): array
    {
        return [[['base_currency' => 'EUR']], [['currency' => 'EUR']], [['usd_amount' => '10.01']], [['cny_amount' => '71.00']],
            [['source' => 'cmb']], [['denominator' => '0']], [['rate_date' => 'invalid']]];
    }

    public function testFrozenReadNeverCreatesAndAcceptsCompletedOrExpiredOrders(): void
    {
        $this->expectRule(function () { $this->service->frozenUsd($this->order, $this->gateway, 'frankfurter'); });
        $this->assertSame(0, PaymentQuote::count());
        $this->createQuote();
        foreach ([Order::STATUS_COMPLETED, Order::STATUS_EXPIRED] as $status) {
            DB::table('orders')->where('id', 1)->update(['status' => $status]);
            $this->assertSame('10.00', $this->service->frozenUsd($this->order, $this->gateway, 'frankfurter'));
            $this->expectRule(function () { $this->freeze(); });
        }
        DB::table('orders')->where('id', 1)->update(['actual_price' => '71.00']);
        $this->expectRule(function () { $this->service->frozenUsd($this->order, $this->gateway, 'frankfurter'); });
    }

    public function testDatabaseFailureIsSanitized(): void
    {
        (new \CreatePaymentQuotesTable())->down();
        $this->expectRule(function () { $this->freeze(); }, 'payment_quote_unavailable');
    }

    public function testOuterTransactionNeverFetches(): void
    {
        $this->rates->shouldReceive('quoteCnyToUsd')->never();
        DB::beginTransaction();
        try {
            $this->expectRule(function () { $this->freeze(); }, 'payment_quote_unavailable');
        } finally {
            DB::rollBack();
        }
        $this->assertSame(0, PaymentQuote::count());
    }

    public function testMigrationUniqueConstraintAndUpDown(): void
    {
        $this->createQuote();
        $row = PaymentQuote::first()->getAttributes();
        unset($row['id']);
        try {
            DB::table('payment_quotes')->insert($row);
            $this->fail('Duplicate quote should violate the unique constraint.');
        } catch (QueryException $exception) {
            $this->assertSame(1, PaymentQuote::count());
        }
        (new \CreatePaymentQuotesTable())->down();
        $this->assertFalse(Schema::hasTable('payment_quotes'));
        (new \CreatePaymentQuotesTable())->up();
        $this->assertTrue(Schema::hasTable('payment_quotes'));
        $this->assertSame(0, PaymentQuote::count());
    }
}
