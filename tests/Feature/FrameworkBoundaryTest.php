<?php

namespace Tests\Feature;

use App\Exceptions\AppException;
use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FrameworkBoundaryTest extends TestCase
{
    public function testBusinessErrorIsAnHttpResponseAndUsesTheConfiguredTheme(): void
    {
        Cache::put('system-setting', ['template' => 'modern'], 60);
        $response = app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render(Request::create('/'), new AppException('fixture business error'));
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('fixture business error', $response->getContent());
    }

    public function testBusinessErrorWithoutSettingsUsesTheExistingDefaultTheme(): void
    {
        Cache::forget('system-setting');
        $response = app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render(Request::create('/'), new AppException('fixture default error'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('fixture default error', $response->getContent());
    }

    public function testNativeThrowableStaysAGenericHttp500WithDebugOff(): void
    {
        Route::get('/__fixture/internal-error', function () { throw new \TypeError('fixture private error'); });
        $this->getJson('/__fixture/internal-error')->assertStatus(500)->assertJson(['message' => 'Server Error'])->assertDontSee('fixture private error', false);
    }

    public function testBareThrowableCanBeReportedAndRenderedAcrossBothHandlerContracts(): void
    {
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $exception = new \TypeError('fixture direct private error');
        $handler->report($exception);
        $request = Request::create('/__fixture/direct-error'); $request->headers->set('Accept', 'application/json');
        $response = $handler->render($request, $exception);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['message' => 'Server Error'], json_decode($response->getContent(), true));
    }

    public function testUntrustedForwardedFieldsDoNotOverrideTheOriginalRequest(): void
    {
        config(['trustedproxy.proxies' => ['192.0.2.10']]);
        $request = $this->proxyRequest('198.51.100.10');
        app(TrustProxies::class)->handle($request, function (Request $request) {
            $this->assertSame('http', $request->getScheme()); $this->assertSame('shop.test', $request->getHost());
            $this->assertSame(80, $request->getPort()); $this->assertSame('198.51.100.10', $request->getClientIp());
        });
    }

    public function testConfiguredProxyAppliesTheSameFourForwardedFields(): void
    {
        config(['trustedproxy.proxies' => ['192.0.2.10']]);
        $request = $this->proxyRequest('192.0.2.10');
        app(TrustProxies::class)->handle($request, function (Request $request) {
            $this->assertSame('https', $request->getScheme()); $this->assertSame('checkout.test', $request->getHost());
            $this->assertSame(443, $request->getPort()); $this->assertSame('198.51.100.20', $request->getClientIp());
        });
    }

    public function testMaintenanceStopsTheRequestBeforeTheBusinessHandler(): void
    {
        $path = storage_path('framework/down');
        $this->assertStringContainsString('/tests/.runtime/', str_replace('\\', '/', $path));
        $previous = is_file($path) ? file_get_contents($path) : null;
        $called = false;
        Route::get('/__fixture/maintenance', function () use (&$called) { $called = true; return 'fixture'; });
        try {
            file_put_contents($path, json_encode(['time' => time(), 'retry' => 60, 'message' => 'fixture maintenance', 'allowed' => []]));
            $this->get('/__fixture/maintenance')->assertStatus(503);
            $this->assertFalse($called);
        } finally {
            if ($previous === null) unlink($path); else file_put_contents($path, $previous);
        }
    }

    private function proxyRequest(string $remote): Request
    {
        return Request::create('http://shop.test/', 'GET', [], [], [], [
            'REMOTE_ADDR' => $remote, 'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
            'HTTP_X_FORWARDED_HOST' => 'checkout.test', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '443',
        ]);
    }
}
