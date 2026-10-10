<?php

namespace Tests\Feature;

use App\Exceptions\RuleValidationException;
use App\Http\Middleware\DujiaoBoot;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GeetestValidationTest extends TestCase
{
    private $history = [];
    private const RAW = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const KEY = 'synthetic-fixture-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(DujiaoBoot::class);
        config(['geetest.id' => 'synthetic-fixture-id', 'geetest.key' => self::KEY]);
        Cache::forever('system-setting', ['is_open_geetest' => 1]);
        Route::middleware('web')->post('/fixture-geetest-order', function (Request $request) {
            try {
                app('Service\OrderService')->validatorCreateOrder($request);
                return response()->json(['valid' => true]);
            } catch (RuleValidationException $exception) {
                return response()->json(['valid' => false]);
            }
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function network(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));
    }

    private function fields(?string $challenge = null): array
    {
        $challenge = $challenge ?? md5(self::RAW . self::KEY) . 'ab';
        return ['gid' => 1, 'email' => 'buyer@fixture.invalid', 'payway' => 1, 'by_amount' => 1,
            'geetest_seccode' => 'synthetic-validation|jordan',
            'geetest_validate' => md5(self::KEY . 'geetest' . $challenge), 'geetest_challenge' => $challenge];
    }

    public function testExistingRegistrationAndOrderRuleUseTheNewClient(): void
    {
        $this->network([new Response(200, [], self::RAW), new Response(200, [], json_encode(['seccode' => md5('synthetic-validation|jordan')]))]);
        $registered = $this->get('/check-geetest')->assertStatus(200)->decodeResponseJson();
        $this->assertSame(1, $registered['success']);
        $this->assertSame(md5(self::RAW . self::KEY), $registered['challenge']);
        $this->assertSame('synthetic-fixture-id', $registered['gt']);
        $this->post('/fixture-geetest-order', $this->fields())->assertJson(['valid' => true]);
        $this->assertCount(2, $this->history);
        parse_str((string) $this->history[1]['request']->getBody(), $sent);
        $this->assertSame('UnLoginUser', $sent['user_id']);
        $this->assertSame('https', $this->history[1]['request']->getUri()->getScheme());
        $this->post('/fixture-geetest-order', $this->fields())->assertJson(['valid' => false]);
        $this->assertCount(2, $this->history);
    }

    public function testLegacyGeetestAliasKeepsAWebSession(): void
    {
        $this->network([new Response(200, [], self::RAW)]);
        $this->get('/geetest')->assertStatus(200);
        $this->assertNotNull(session('geetest_state'));
    }

    public function testMissingHandshakeNeverCallsRemoteValidation(): void
    {
        $this->network([]);
        $this->post('/fixture-geetest-order', $this->fields())->assertJson(['valid' => false]);
        $this->assertCount(0, $this->history);
    }

    public function testChallengeFromAnotherSessionIsRejected(): void
    {
        $this->network([new Response(200, [], self::RAW)]);
        $this->get('/check-geetest');
        $this->post('/fixture-geetest-order', $this->fields(str_repeat('b', 32) . 'ab'))->assertJson(['valid' => false]);
        $this->assertCount(1, $this->history);
    }

    public function testExpiredHandshakeCannotTriggerAnHttpRequest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $this->network([new Response(200, [], self::RAW)]);
        $this->get('/check-geetest');
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:05:01'));
        $this->post('/fixture-geetest-order', $this->fields())->assertJson(['valid' => false]);
        $this->assertCount(1, $this->history);
    }

    public function testChangingTheConfiguredKeyInvalidatesAHandshake(): void
    {
        $this->network([new Response(200, [], self::RAW)]);
        $this->get('/check-geetest');
        config(['geetest.key' => 'replacement-fixture-key']);
        $this->post('/fixture-geetest-order', $this->fields())->assertJson(['valid' => false]);
        $this->assertCount(1, $this->history);
    }

    public function testRegisterAgainReplacesTheOldChallenge(): void
    {
        $this->network([new Response(200, [], self::RAW), new Response(200, [], str_repeat('b', 32))]);
        $this->get('/check-geetest');
        $this->get('/check-geetest');
        $this->post('/fixture-geetest-order', $this->fields())->assertJson(['valid' => false]);
        $this->assertCount(2, $this->history);
    }

    public function testArrayValidationInputIsRejectedWithoutTypeErrors(): void
    {
        $this->network([new Response(200, [], self::RAW)]);
        $this->get('/check-geetest');
        $fields = $this->fields();
        $fields['geetest_validate'] = ['invalid'];
        $this->post('/fixture-geetest-order', $fields)->assertJson(['valid' => false]);
        $this->assertCount(1, $this->history);
    }

    public function testRemoteFailureDoesNotPassOrderValidation(): void
    {
        $this->network([new Response(200, [], self::RAW), new Response(200, [], '{invalid')]);
        $this->get('/check-geetest');
        $this->post('/fixture-geetest-order', $this->fields())->assertJson(['valid' => false]);
        $this->assertCount(2, $this->history);
    }

    public function testOfflineFallbackRequiresItsOwnIssuedChallenge(): void
    {
        $this->network([new Response(503)]);
        $registered = $this->get('/check-geetest')->assertStatus(200)->decodeResponseJson();
        $this->assertSame(0, $registered['success']);
        $fields = $this->fields($registered['challenge']);
        $fields['geetest_validate'] = md5($registered['challenge']);
        $this->post('/fixture-geetest-order', $fields)->assertJson(['valid' => true]);
        $this->post('/fixture-geetest-order', $fields)->assertJson(['valid' => false]);
        $this->assertCount(1, $this->history);
    }

    public function testMissingCredentialsCannotProduceAValidOfflineSession(): void
    {
        config(['geetest.key' => '']);
        $this->network([]);
        $registered = $this->get('/check-geetest')->assertStatus(200)->decodeResponseJson();
        $fields = $this->fields($registered['challenge']);
        $fields['geetest_validate'] = md5($registered['challenge']);
        $this->post('/fixture-geetest-order', $fields)->assertJson(['valid' => false]);
        $this->assertCount(0, $this->history);
    }

    public function testDisabledCaptchaDoesNotRequireAHandshake(): void
    {
        Cache::forever('system-setting', ['is_open_geetest' => 0]);
        $this->network([]);
        $this->post('/fixture-geetest-order', $this->fields())->assertJson(['valid' => true]);
        $this->assertCount(0, $this->history);
    }
}
