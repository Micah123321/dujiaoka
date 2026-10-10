<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyPaymentCsrfToken;
use App\Models\Order;
use App\Models\Pay;
use App\Models\StripePaymentIntent;
use App\Service\OrderProcessService;
use App\Service\PaymentQuoteService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stripe\ApiRequestor;
use Tests\TestCase;

class StripeIntentRouteTest extends TestCase
{
    private $http;
    private $keys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\DujiaoBoot::class, \App\Http\Middleware\InstallCheck::class,
            \App\Http\Middleware\PayGateWay::class]);
        $this->app->bind(VerifyPaymentCsrfToken::class, function ($app) {
            return new class($app, $app['encrypter']) extends VerifyPaymentCsrfToken {
                protected function runningUnitTests() { return false; }
            };
        });
        $this->withSession(['_token' => 'fixture-csrf']);
        Event::fake();
        config(['dujiaoka.stripe_payment_intents' => true, 'dujiaoka.payment_quote_freeze' => true]);
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('order_sn'); $t->string('title'); $t->unsignedBigInteger('pay_id');
            $t->decimal('actual_price', 10, 2); $t->integer('status'); $t->string('trade_no')->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('pays', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('pay_check'); $t->string('pay_handleroute'); $t->string('merchant_id');
            $t->string('merchant_pem'); $t->integer('is_open')->default(1); $t->timestamps(); $t->softDeletes();
        });
        foreach (['goods', 'coupons'] as $table) {
            Schema::create($table, function (Blueprint $t) { $t->bigIncrements('id'); $t->timestamps(); $t->softDeletes(); });
        }
        require_once base_path('database/migrations/2026_01_01_000004_create_stripe_payment_intents_table.php');
        (new \CreateStripePaymentIntentsTable())->up();
        foreach ([7 => ['card', 'pay/stripe'], 8 => ['paypal', 'pay/paypal']] as $id => $fields) {
            Pay::forceCreate(['id' => $id, 'pay_check' => $fields[0], 'pay_handleroute' => $fields[1],
                'merchant_id' => 'pk_test_fixture', 'merchant_pem' => 'sk_test_fixture']);
        }
        Order::forceCreate(['id' => 1, 'order_sn' => 'INTENTROUTE', 'title' => 'Native <checkout>',
            'actual_price' => '70.00', 'pay_id' => 7, 'status' => Order::STATUS_WAIT_PAY]);
        $quotes = \Mockery::mock(PaymentQuoteService::class);
        $quotes->shouldReceive('enabled')->andReturn(true);
        $quotes->shouldReceive('cnyToUsd')->andReturn('10.00');
        $quotes->shouldReceive('frozenUsd')->andReturn('10.00');
        $this->app->instance(PaymentQuoteService::class, $quotes);
        $this->http = \Mockery::mock(\Stripe\HttpClient\ClientInterface::class);
        ApiRequestor::setHttpClient($this->http);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function api(array $extra = []): void
    {
        $this->http->shouldReceive('request')->once()->andReturnUsing(function ($method, $url, $headers, $params) use ($extra) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertStringStartsWith('https://api.stripe.com/v1/payment_intents', $url);
            $this->assertContains('Stripe-Version: ' . \Stripe\Util\ApiVersion::CURRENT, $headers);
            foreach ($headers as $header) if (strpos($header, 'Idempotency-Key: ') === 0) $this->keys[] = substr($header, 17);
            if ($method === 'get') $this->assertSame(['latest_charge'], $params['expand']);
            $row = StripePaymentIntent::firstOrFail();
            $intent = ['id' => 'pi_native', 'object' => 'payment_intent', 'client_secret' => 'pi_native_secret_fixture',
                'amount' => (int) $row->amount_minor, 'currency' => $row->currency, 'livemode' => false,
                'capture_method' => 'automatic', 'confirmation_method' => 'automatic', 'status' => 'requires_payment_method',
                'payment_method_types' => $row->currency === 'usd' ? ['card', 'wechat_pay'] : ['alipay'],
                'metadata' => ['order_sn' => 'INTENTROUTE', 'binding_reference' => $row->reference]];
            return [json_encode(array_replace_recursive($intent, $extra)), 200, []];
        });
    }

    private function begin(string $currency = 'usd')
    {
        return $this->postJson('/pay/stripe/intent', ['orderid' => 'INTENTROUTE', 'currency' => $currency], ['X-CSRF-TOKEN' => 'fixture-csrf']);
    }

    public function testCheckoutUsesActualBladeAndNeverEmbedsASecret(): void
    {
        $r = $this->get('/pay/stripe/card/INTENTROUTE')->assertOk();
        $r->assertSee('Native &lt;checkout&gt;', false)->assertSee('USD 10.00');
        $this->assertStringNotContainsString('sk_test_fixture', $r->getContent());
        $this->assertStringNotContainsString('pi_native_secret_fixture', $r->getContent());
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        file_put_contents(base_path('tests/.runtime/stripe-intent-checkout.html'), $r->getContent());
    }

    public function testCnyCheckoutAndCaptureRemainAvailableWhenFxFails(): void
    {
        $quotes = \Mockery::mock(PaymentQuoteService::class);
        $quotes->shouldReceive('enabled')->andReturn(true);
        $quotes->shouldReceive('cnyToUsd')->once()->andThrow(new \RuntimeException('fixture-private-rates-body'));
        $this->app->instance(PaymentQuoteService::class, $quotes);
        $r = $this->get('/pay/stripe/card/INTENTROUTE')->assertOk();
        $r->assertSee('银行卡 / 微信暂不可用')->assertSee('支付宝');
        $this->assertStringNotContainsString('fixture-private-rates-body', $r->getContent());
        $this->api(); $this->begin('cny')->assertOk()->assertJson(['amount' => '7000']);
        $quotes = \Mockery::mock(PaymentQuoteService::class);
        $quotes->shouldReceive('enabled')->andReturn(true);
        $quotes->shouldNotReceive('cnyToUsd');
        $this->app->instance(PaymentQuoteService::class, $quotes);
        $this->get('/pay/stripe/card/INTENTROUTE')->assertOk();
    }

    public function testCsrfIsRequiredEvenThoughLegacyPayCallbacksAreExempt(): void
    {
        $this->postJson('/pay/stripe/intent', ['orderid' => 'INTENTROUTE', 'currency' => 'usd'])->assertStatus(419);
        $this->postJson('/pay/stripe/intent/status', ['orderid' => 'INTENTROUTE'])->assertStatus(419);
        $this->assertSame(0, StripePaymentIntent::count());
    }

    public function testOneIntentPersistsAcrossReloadAndCurrencyOrGatewaySwitchStops(): void
    {
        $this->api();
        $this->begin()->assertOk()->assertJson(['intent_id' => 'pi_native', 'amount' => '1000']);
        $this->api();
        $this->begin()->assertOk()->assertJson(['intent_id' => 'pi_native']);
        $this->assertCount(1, $this->keys);
        $this->assertSame(1, StripePaymentIntent::count());
        $this->begin('cny')->assertStatus(422);
        $this->get('/pay/paypal/paypal/INTENTROUTE')->assertSee(__('dujiaoka.prompt.payment_method_locked'));
        $this->assertSame(7, (int) Order::first()->pay_id);
        foreach (['charge?stripeToken=tok_other', 'check?source=src_other', 'return_url?source=src_other'] as $entry) {
            $this->get('/pay/stripe/' . $entry . '&orderid=INTENTROUTE')->assertStatus(409);
        }
    }

    public function testServerRetrievesStoredIdAndCompletesOriginalCnyAfterVerifiedCapture(): void
    {
        $this->api(); $this->begin('cny')->assertOk()->assertJson(['amount' => '7000']);
        Pay::whereKey(7)->update(['is_open' => 0]);
        $processor = \Mockery::mock(OrderProcessService::class);
        $processor->shouldReceive('completedOrder')->once()->with('INTENTROUTE', 70.00, 'ch_native', \Mockery::type(\Closure::class))->andReturnUsing(function ($sn, $amount, $charge, $guard) {
            DB::transaction(function () use ($guard) { $guard(Order::first()); });
            $order = Order::first(); $order->status = Order::STATUS_COMPLETED; $order->save(); return $order;
        });
        $this->app->instance(OrderProcessService::class, $processor);
        $this->api(['status' => 'succeeded', 'amount_received' => 7000, 'latest_charge' => ['id' => 'ch_native', 'object' => 'charge',
            'payment_intent' => 'pi_native', 'currency' => 'cny', 'amount' => 7000, 'status' => 'succeeded',
            'paid' => true, 'captured' => true, 'refunded' => false, 'amount_refunded' => 0]]);
        $this->postJson('/pay/stripe/intent/status', ['orderid' => 'INTENTROUTE', 'payment_intent' => 'pi_other', 'status' => 'succeeded'],
            ['X-CSRF-TOKEN' => 'fixture-csrf'])->assertOk()->assertJson(['status' => 'succeeded', 'order_status' => Order::STATUS_COMPLETED]);
    }

    public function testProcessingAndRedirectParametersAloneNeverCompletePayment(): void
    {
        $processor = \Mockery::mock(OrderProcessService::class); $processor->shouldNotReceive('completedOrder');
        $this->app->instance(OrderProcessService::class, $processor);
        $this->api(); $this->begin()->assertOk();
        $this->api(['status' => 'processing']);
        $this->get('/pay/stripe/intent-return?orderid=INTENTROUTE&payment_intent=pi_forged&redirect_status=succeeded')
            ->assertRedirect('/detail-order-sn/INTENTROUTE');
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
    }
}
