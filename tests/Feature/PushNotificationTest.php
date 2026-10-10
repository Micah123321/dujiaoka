<?php

namespace Tests\Feature;

use App\Jobs\BarkPush;
use App\Jobs\WorkWeiXinPush;
use App\Models\Order;
use App\Models\Pay;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    public static function channels(): array
    {
        return [
            'Bark' => [BarkPush::class, 'Bark', '{"code":200,"message":"success"}'],
            'WeChat' => [WorkWeiXinPush::class, 'Enterprise WeChat', '{"errcode":0,"errmsg":"ok"}'],
        ];
    }

    private function order(): Order
    {
        Cache::put('system-setting', [
            'bark_server' => 'https://bark.fixture.invalid',
            'bark_token' => 'FIXTURE-TOKEN',
            'qywxbot_key' => 'FIXTURE-KEY',
            'text_logo' => 'Fixture store',
            'is_open_bark_push_url' => 1,
        ], 60);
        $order = new Order();
        $order->forceFill([
            'id' => 1, 'goods_id' => 1, 'order_sn' => 'FIXTURE-ORDER',
            'title' => 'Fixture product', 'email' => 'buyer@example.test',
            'actual_price' => '10.00', 'created_at' => '2026-01-01 12:00:00',
        ]);
        $order->setRelation('pay', (new Pay())->forceFill(['pay_name' => 'Fixture pay']));
        return $order;
    }

    private function goodsService(): void
    {
        $service = \Mockery::mock();
        $service->shouldReceive('detail')->once()->with(1)->andReturn((object) [
            'gd_name' => 'Fixture product', 'in_stock' => 8,
        ]);
        $this->app->instance('Service\GoodsService', $service);
    }

    private function client(array $responses, array &$history): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));
    }

    /** @dataProvider channels */
    #[\PHPUnit\Framework\Attributes\DataProvider('channels')]
    public function testRequestsKeepPayloadAndUseBoundedVerifiedConnections(string $class, string $label, string $success): void
    {
        $history = [];
        $this->client([new Response(200, [], $success)], $history);
        $this->goodsService();
        (new $class($this->order()))->handle();
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertTrue($history[0]['options']['verify']);
        $this->assertSame(5, $history[0]['options']['connect_timeout']);
        $this->assertSame(15, $history[0]['options']['timeout']);
        $this->assertFalse($history[0]['options']['allow_redirects']);
        $body = (string) $request->getBody();
        if ($class === BarkPush::class) {
            $this->assertSame('https://bark.fixture.invalid/FIXTURE-TOKEN', (string) $request->getUri());
            parse_str($body, $params);
            $this->assertStringContainsString('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
            $this->assertSame('Fixture store', $params['group']);
            $this->assertSame('timeSensitive', $params['level']);
            $this->assertSame(url('detail-order-sn/FIXTURE-ORDER'), $params['url']);
            $content = $params['body'];
        } else {
            $this->assertSame('https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=FIXTURE-KEY', (string) $request->getUri());
            $this->assertStringContainsString('application/json', $request->getHeaderLine('Content-Type'));
            $params = json_decode($body, true);
            $this->assertSame('markdown', $params['msgtype']);
            $content = $params['markdown']['content'];
        }
        foreach (['FIXTURE-ORDER', 'Fixture product', 'Fixture pay', 'buyer@example.test', '10.00'] as $value) {
            $this->assertStringContainsString($value, $content);
        }
    }

    /** @dataProvider channels */
    #[\PHPUnit\Framework\Attributes\DataProvider('channels')]
    public function testRedirectsAndHttpErrorsFailWithoutResponseOrTokenDetails(string $class, string $label, string $success): void
    {
        foreach ([302, 400, 429, 500] as $status) {
            $history = [];
            $this->client([new Response($status, ['Location' => 'https://other.fixture.invalid/PRIVATE'], 'PRIVATE-RESPONSE')], $history);
            $this->goodsService();
            try {
                (new $class($this->order()))->handle();
                $this->fail('Non-success HTTP status accepted');
            } catch (\RuntimeException $exception) {
                $this->assertSame($label . ' notification returned HTTP ' . $status, $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
            $this->assertCount(1, $history);
        }
    }

    /** @dataProvider channels */
    #[\PHPUnit\Framework\Attributes\DataProvider('channels')]
    public function testBusinessErrorsAndInvalidJsonFailEvenWithHttpSuccess(string $class, string $label, string $success): void
    {
        $error = $class === BarkPush::class ? '{"code":400,"message":"PRIVATE-RESPONSE"}' : '{"errcode":40058,"errmsg":"PRIVATE-RESPONSE"}';
        foreach ([$error, '', 'not json', '{}', '{"code":null,"errcode":null}', '{"code":true,"errcode":false}', '{"code":[],"errcode":[]}'] as $body) {
            $history = [];
            $this->client([new Response(200, [], $body)], $history);
            $this->goodsService();
            try {
                (new $class($this->order()))->handle();
                $this->fail('Missing or unsuccessful acknowledgment accepted');
            } catch (\RuntimeException $exception) {
                $this->assertSame($label . ' notification returned an unsuccessful acknowledgment', $exception->getMessage());
                $this->assertNull($exception->getPrevious());
            }
        }
    }

    /** @dataProvider channels */
    #[\PHPUnit\Framework\Attributes\DataProvider('channels')]
    public function testConnectionFailuresBecomeRedactedWorkerFailures(string $class, string $label, string $success): void
    {
        $history = [];
        $error = new ConnectException('PRIVATE-RESPONSE https://fixture.invalid/FIXTURE-TOKEN', new Request('POST', 'https://fixture.invalid/FIXTURE-KEY'));
        $this->client([$error], $history);
        $this->goodsService();
        try {
            (new $class($this->order()))->handle();
            $this->fail('Connection failure accepted');
        } catch (\RuntimeException $exception) {
            $this->assertSame($label . ' notification request failed', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    /** @dataProvider channels */
    #[\PHPUnit\Framework\Attributes\DataProvider('channels')]
    public function testServiceIsResolvedAtExecutionRatherThanDuringJobConstruction(string $class, string $label, string $success): void
    {
        $this->app->bind('Service\GoodsService', function () { throw new \RuntimeException('Constructor resolved service'); });
        $job = new $class($this->order());
        $this->goodsService();
        $history = [];
        $this->client([new Response(200, [], $success)], $history);
        $job->handle();
        $this->assertCount(1, $history);
    }
}
