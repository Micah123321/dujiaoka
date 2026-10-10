<?php

namespace Tests\Feature;

use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use App\Models\PaymentQuote;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Stripe\ApiRequestor;
use Tests\Concerns\GatewayTransactionFixture;
use Tests\TestCase;

class FrozenStripeReceiptTest extends TestCase
{
    use GatewayTransactionFixture;

    private $requests = [];
    private $notifications = 0;
    private $completions = 0;
    private $sourceType = 'wechat';
    private $sourceStatus = 'chargeable';
    private $chargeChanges = [];
    private $onGet;
    private $onPost;
    private $postOrder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
        Event::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', 'UTC'));
        config(['dujiaoka.payment_quote_freeze' => true, 'dujiaoka.stripe_payment_intents' => false,
            'dujiaoka.paypal_orders_v2' => false, 'dujiaoka.api_hook_outbox' => false,
            'dujiaoka.payment_mail_outbox' => false, 'dujiaoka.manual_status_mail_outbox' => false]);
        $this->createGatewayTables();
        require_once base_path('database/migrations/2026_01_01_000003_create_payment_quotes_table.php');
        (new \CreatePaymentQuotesTable())->up();
        foreach ([7, 8] as $id) {
            Pay::forceCreate(['id' => $id, 'pay_check' => 'card', 'pay_handleroute' => '/pay/stripe',
                'merchant_id' => 'fixture-public', 'merchant_key' => 'fixture-account', 'merchant_pem' => 'fixture-secret']);
        }
        Order::forceCreate(['id' => 1, 'order_sn' => 'stripe-receipt', 'pay_id' => 7,
            'actual_price' => '100.00', 'status' => Order::STATUS_WAIT_PAY]);
        PaymentQuote::forceCreate(['order_id' => 1, 'pay_id' => 7, 'base_currency' => 'CNY', 'currency' => 'USD',
            'source' => 'cmb', 'cny_amount' => '100.00', 'usd_amount' => '14.90',
            'numerator' => '100', 'denominator' => '671.050', 'rate_date' => '2026-10-09']);
        DB::table('goods')->insert(['id' => 1]);
        DB::table('carmis')->insert(['goods_id' => 1, 'carmi' => 'fixture-stripe-card']);
        $this->bindGatewayProcessor();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->app->instance(Client::class, new Client(['handler' => HandlerStack::create(new MockHandler([]))]));
        $http = Mockery::mock(\Stripe\HttpClient\ClientInterface::class);
        $http->shouldReceive('request')->andReturnUsing(function ($method, $url, $headers, $params) {
            $this->assertSame(0, DB::transactionLevel());
            $this->requests[] = compact('method', 'url', 'headers', 'params');
            if ($method === 'get') {
                $this->assertSame('https://api.stripe.com/v1/sources/src_fixture', $url);
                if ($this->onGet) ($this->onGet)();
                return [json_encode(['id' => 'src_fixture', 'object' => 'source', 'type' => $this->sourceType,
                    'status' => $this->sourceStatus, 'currency' => $this->currency(), 'amount' => (int) $this->minor(),
                    'owner' => ['name' => 'stripe-receipt']]), 200, []];
            }
            $this->assertSame('post', $method);
            $this->assertSame('https://api.stripe.com/v1/charges', $url);
            $this->assertSame(['amount' => $this->minor(), 'currency' => $this->currency(),
                'source' => $this->sourceType === 'token' ? 'tok_fixture' : 'src_fixture'], $params);
            $this->assertContains('Authorization: Bearer fixture-secret', $headers);
            $this->assertContains('Idempotency-Key: dujiaoka-v1-' . hash('sha256', '1:7:' . $params['source']), $headers);
            if ($this->onPost) ($this->onPost)();
            $this->postOrder = (array) DB::table('orders')->where('id', 1)->first();
            return [json_encode(array_merge(['id' => 'ch_fixture', 'object' => 'charge', 'status' => 'succeeded',
                'paid' => true, 'captured' => true, 'refunded' => false, 'amount_refunded' => 0,
                'currency' => $this->currency(), 'amount' => (int) $this->minor(),
                'source' => ['id' => $params['source'], 'object' => 'source']], $this->chargeChanges)), 200, []];
        });
        ApiRequestor::setHttpClient($http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        \Stripe\Stripe::setApiKey(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function beforeCompletion(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        ++$this->completions;
    }

    public function notified(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        ++$this->notifications;
    }

    private function currency(): string { return $this->sourceType === 'alipay' ? 'cny' : 'usd'; }
    private function minor(): string { return $this->sourceType === 'alipay' ? '10000' : '1490'; }
    private function posts(): array { return array_values(array_filter($this->requests, function ($r) { return $r['method'] === 'post'; })); }

    private function payment(string $type = 'wechat')
    {
        $this->sourceType = $type;
        return $this->get($type === 'token'
            ? '/pay/stripe/charge?orderid=stripe-receipt&stripeToken=tok_fixture'
            : '/pay/stripe/check?orderid=stripe-receipt&source=src_fixture')->assertOk();
    }

    private function assertNoDelivery(): void
    {
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(5, (int) DB::table('goods')->value('in_stock'));
        $this->assertSame(0, $this->notifications);
        $this->assertSame('', (string) Order::first()->trade_no);
    }

    public function testFrozenTokenReceiptCompletesWithTheRealOrderProcessor(): void
    {
        $this->payment('token')->assertSeeText('success');
        $this->assertCount(1, $this->posts());
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('ch_fixture', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_SOLD, (int) Carmis::first()->status);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(1, $this->notifications);
        $this->assertSame(1, $this->completions);
    }

    public static function bindingChanges(): array
    {
        $cases = [];
        foreach (['token', 'wechat', 'alipay'] as $type) {
            foreach (['pay_id', 'pay_handleroute', 'pay_check', 'merchant_id', 'merchant_key', 'merchant_pem',
                'actual_price', 'corrupt_quote', 'replaced_quote'] as $field) {
                $cases[$type . '-' . $field] = [$type, $field];
            }
        }
        return $cases;
    }

    /** @dataProvider bindingChanges */
    #[\PHPUnit\Framework\Attributes\DataProvider('bindingChanges')]
    public function testBindingChangedDuringPostCannotDeliver(string $type, string $field): void
    {
        $this->onPost = function () use ($field) {
            if ($field === 'pay_id' || $field === 'actual_price') {
                Order::whereKey(1)->update([$field => $field === 'pay_id' ? 8 : '101.00']);
            } elseif ($field === 'corrupt_quote') {
                PaymentQuote::query()->update(['usd_amount' => '15.00']);
            } elseif ($field === 'replaced_quote') {
                PaymentQuote::query()->update(['usd_amount' => '15.00', 'numerator' => '15', 'denominator' => '100']);
            } else {
                Pay::whereKey(7)->update([$field => $field === 'pay_handleroute' ? '/pay/paypal' : 'changed-fixture']);
            }
        };
        $this->payment($type)->assertDontSeeText('success');
        $this->assertCount(1, $this->posts());
        $this->assertSame($this->postOrder, (array) DB::table('orders')->where('id', 1)->first());
        $this->assertNoDelivery();
    }

    public static function blockedCaptures(): array
    {
        $cases = [];
        foreach (['wechat', 'alipay'] as $type) {
            foreach (['expired', 'closed', 'abnormal', 'paid', 'expired-during-get', 'closed-during-get', 'binding-during-get'] as $state) {
                $cases[$type . '-' . $state] = [$type, $state];
            }
        }
        return $cases;
    }

    /** @dataProvider blockedCaptures */
    #[\PHPUnit\Framework\Attributes\DataProvider('blockedCaptures')]
    public function testChargeableSourceRequiresCurrentWaitingOpenBinding(string $type, string $state): void
    {
        $change = function () use ($state) {
            if (strpos($state, 'closed') === 0) Pay::whereKey(7)->update(['is_open' => 0]);
            elseif ($state === 'binding-during-get') Order::whereKey(1)->update(['pay_id' => 8]);
            else Order::whereKey(1)->update(['status' => $state === 'abnormal' ? Order::STATUS_ABNORMAL
                : ($state === 'paid' ? Order::STATUS_COMPLETED : Order::STATUS_EXPIRED)]);
        };
        if (strpos($state, '-during-get') !== false) $this->onGet = $change;
        else $change();
        $this->payment($type)->assertSeeText('fail');
        $this->assertCount(0, $this->posts());
        $this->assertNoDelivery();
    }

    public static function sourceTypes(): array { return [['wechat'], ['alipay']]; }

    /** @dataProvider sourceTypes */
    #[\PHPUnit\Framework\Attributes\DataProvider('sourceTypes')]
    public function testClosedConsumedSourceRecoversLateReceiptWithoutDeliveryTwice(string $type): void
    {
        Pay::whereKey(7)->update(['is_open' => 0]);
        Order::whereKey(1)->update(['status' => Order::STATUS_EXPIRED]);
        $this->sourceStatus = 'consumed';
        $this->payment($type)->assertSeeText('success');
        $first = (array) DB::table('orders')->first();
        $this->assertSame(Order::STATUS_ABNORMAL, (int) $first['status']);
        $this->assertSame('ch_fixture', $first['trade_no']);
        $this->assertStringContainsString('Late payment received', $first['info']);
        $this->payment($type)->assertSeeText('success');
        $posts = $this->posts();
        $this->assertCount(2, $posts);
        $this->assertSame($posts[0], $posts[1]);
        $this->assertSame($first, (array) DB::table('orders')->first());
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(5, (int) DB::table('goods')->value('in_stock'));
        $this->assertSame(1, $this->notifications);
        $this->assertSame(2, $this->completions);
    }

    /** @dataProvider sourceTypes */
    #[\PHPUnit\Framework\Attributes\DataProvider('sourceTypes')]
    public function testClosedConsumedDuplicateOfCompletedOrderValidatesReceiptWithoutDoubleCounters(string $type): void
    {
        $this->payment($type)->assertSeeText('success');
        Pay::whereKey(7)->update(['is_open' => 0]);
        $this->sourceStatus = 'consumed';
        $this->payment($type)->assertSeeText('success');
        $this->assertCount(2, $this->posts());
        $this->assertSame($this->posts()[0], $this->posts()[1]);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
        $this->assertSame('ch_fixture', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_SOLD, (int) Carmis::first()->status);
        $this->assertSame(1, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(1, $this->notifications);
        $this->assertSame(2, $this->completions);
    }

    /** @dataProvider sourceTypes */
    #[\PHPUnit\Framework\Attributes\DataProvider('sourceTypes')]
    public function testClosedConsumedSourceRequiresActualSuccessfulReceipt(string $type): void
    {
        Pay::whereKey(7)->update(['is_open' => 0]);
        Order::whereKey(1)->update(['status' => Order::STATUS_EXPIRED]);
        $this->sourceStatus = 'consumed';
        $this->chargeChanges = ['paid' => false];
        $this->payment($type)->assertSeeText('fail');
        $this->assertCount(1, $this->posts());
        $this->assertSame(Order::STATUS_EXPIRED, (int) Order::first()->status);
        $this->assertNoDelivery();
    }

    public static function outsideReplayWindow(): array
    {
        return [['wechat', -1], ['wechat', 23 * 3600], ['wechat', 24 * 3600],
            ['alipay', -1], ['alipay', 23 * 3600], ['alipay', 24 * 3600]];
    }

    /** @dataProvider outsideReplayWindow */
    #[\PHPUnit\Framework\Attributes\DataProvider('outsideReplayWindow')]
    public function testConsumedSourceOutsideConservativeWindowNeverPosts(string $type, int $age): void
    {
        Pay::whereKey(7)->update(['is_open' => 0]);
        Order::whereKey(1)->update(['status' => Order::STATUS_EXPIRED, 'created_at' => Carbon::now()->subSeconds($age)]);
        $this->sourceStatus = 'consumed';
        $this->payment($type)->assertSeeText('fail');
        $this->assertCount(0, $this->posts());
        $this->assertNoDelivery();
    }

    /** @dataProvider sourceTypes */
    #[\PHPUnit\Framework\Attributes\DataProvider('sourceTypes')]
    public function testConsumedReceiptStillRecoversJustInsideReplayWindow(string $type): void
    {
        Pay::whereKey(7)->update(['is_open' => 0]);
        Order::whereKey(1)->update(['status' => Order::STATUS_EXPIRED, 'created_at' => Carbon::now()->subSeconds(23 * 3600 - 1)]);
        $this->sourceStatus = 'consumed';
        $this->payment($type)->assertSeeText('success');
        $this->assertCount(1, $this->posts());
        $this->assertSame(Order::STATUS_ABNORMAL, (int) Order::first()->status);
        $this->assertSame('ch_fixture', Order::first()->trade_no);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) Carmis::first()->status);
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
    }

    public static function paymentTypes(): array { return [['token'], ['wechat'], ['alipay']]; }

    /** @dataProvider paymentTypes */
    #[\PHPUnit\Framework\Attributes\DataProvider('paymentTypes')]
    public function testCorruptFrozenQuoteStopsBeforeAnyStripeIo(string $type): void
    {
        PaymentQuote::query()->update(['usd_amount' => '15.00']);
        $this->payment($type)->assertDontSeeText('success');
        $this->assertCount(0, $this->requests);
        $this->assertNoDelivery();
    }
}
