<?php

namespace Tests\Feature;

use App\Jobs\ApiHook;
use App\Models\Order;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Tests\TestCase;

class ApiHookTest extends TestCase
{
    private function job(): ApiHook
    {
        $service = \Mockery::mock();
        $service->shouldReceive('detail')->with(1)->andReturn((object) ['id' => 1, 'gd_name' => 'Fixture', 'api_hook' => 'https://fixture.invalid/hook']);
        $this->app->instance('Service\GoodsService', $service);
        $order = new Order();
        $order->forceFill(['goods_id' => 1, 'order_sn' => 'FIXTURE', 'title' => 'Fixture', 'email' => 'fixture@example.test', 'actual_price' => '10.00', 'info' => 'fixture']);
        return new ApiHook($order);
    }

    public function testWebhookPreservesJsonPayloadAndBoundsNetworkTime(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(204)]));
        $stack->push(Middleware::history($history));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));
        $job = $this->job();
        $job->handle();
        $this->assertCount(1, $history);
        $this->assertSame('POST', $history[0]['request']->getMethod());
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('FIXTURE', $body['order_sn']);
        $this->assertSame(1, $body['good_id']);
        $this->assertSame(5, $history[0]['options']['connect_timeout']);
        $this->assertSame(15, $history[0]['options']['timeout']);
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $this->assertSame(30, $job->retryAfter);
    }

    public function testDatabaseWorkerDefersWebhookRetryAndRecoversWithoutImmediateSecondRequest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
        try {
            Schema::create('orders', function (Blueprint $table) {
                $table->increments('id'); $table->integer('goods_id'); $table->string('order_sn');
                $table->string('title'); $table->string('email'); $table->decimal('actual_price', 10, 2);
                $table->text('info'); $table->timestamps(); $table->softDeletes();
            });
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id'); $table->string('queue')->index(); $table->longText('payload');
                $table->unsignedTinyInteger('attempts'); $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at'); $table->unsignedInteger('created_at');
            });
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->bigIncrements('id'); $table->text('connection'); $table->text('queue');
                $table->longText('payload'); $table->longText('exception'); $table->timestamp('failed_at')->useCurrent();
            });
            // Persist the model so queue deserialization exercises the real model identifier path.
            $this->job();
            DB::table('orders')->insert(['id' => 1, 'goods_id' => 1, 'order_sn' => 'WORKER-RETRY',
                'title' => 'Fixture', 'email' => 'fixture@example.test', 'actual_price' => '10.00',
                'info' => 'Fixture', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
            $history = [];
            $stack = HandlerStack::create(new MockHandler([new Response(503), new Response(204)]));
            $stack->push(Middleware::history($history));
            $this->app->instance(Client::class, new Client(['handler' => $stack]));
            config(['queue.default' => 'database', 'queue.connections.database.connection' => 'sqlite',
                'queue.failed.driver' => 'database', 'queue.failed.database' => 'sqlite']);
            app(Dispatcher::class)->dispatch((new ApiHook(Order::query()->findOrFail(1)))->onQueue('webhook-retry-fixture'));
            $options = ['connection' => 'database', '--queue' => 'webhook-retry-fixture',
                '--once' => true, '--sleep' => 0, '--tries' => 1];
            $this->assertSame(0, Artisan::call('queue:work', $options));
            $this->assertCount(1, $history);
            $row = DB::table('jobs')->first();
            $this->assertNotNull($row);
            $this->assertSame(1, (int) $row->attempts);
            $this->assertNull($row->reserved_at);
            $this->assertSame(Carbon::now()->getTimestamp() + 30, (int) $row->available_at);
            $this->assertSame(0, DB::table('failed_jobs')->count());
            $this->assertSame(0, Artisan::call('queue:work', $options));
            $this->assertCount(1, $history);
            $this->assertSame(1, DB::table('jobs')->count());
            // Move only this fixture deadline; no wall-clock thirty-second wait is claimed.
            DB::table('jobs')->where('id', $row->id)->update(['available_at' => Carbon::now()->getTimestamp() - 1]);
            $this->assertSame(0, Artisan::call('queue:work', $options));
            $this->assertCount(2, $history);
            $this->assertSame(0, DB::table('jobs')->count());
            $this->assertSame(0, DB::table('failed_jobs')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testConnectionFailurePropagatesToQueueWorker(): void
    {
        $error = new \GuzzleHttp\Exception\ConnectException('Fixture timeout', new \GuzzleHttp\Psr7\Request('POST', 'https://fixture.invalid/hook'));
        $this->app->instance(Client::class, new Client(['handler' => HandlerStack::create(new MockHandler([$error]))]));
        $this->expectException(\GuzzleHttp\Exception\ConnectException::class);
        $this->job()->handle();
    }

    public function testErrorAndRedirectResponsesFailWithoutLeakingBody(): void
    {
        foreach ([302, 400, 500] as $status) {
            $this->app->instance(Client::class, new Client(['handler' => HandlerStack::create(new MockHandler([new Response($status, [], 'PRIVATE-RESPONSE')]))]));
            try {
                $this->job()->handle();
                $this->fail('Non-success response accepted');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Order webhook returned HTTP ' . $status, $exception->getMessage());
            }
        }
    }
}
