<?php

namespace Tests\Compatibility\Laravel12;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\FileStore;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Session\TokenMismatchException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RuntimeBoundaryCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        // Follow the maintained framework, including the promoted root.
        if (! str_starts_with(Application::VERSION, '13.')) {
            $this->markTestSkipped('This boundary regression requires the Laravel 13 candidate bootstrap.');
        }

        parent::setUp();
    }

    #[DataProvider('csrfRequests')]
    public function test_application_csrf_origin_token_and_payment_exception(
        ?string $fetchSite,
        ?string $tokenKind,
        string $path,
        bool $allowed
    ): void {
        $originalEnvironment = $this->app['env'];
        $reachedNext = false;
        $this->app['env'] = 'runtime-boundary-fixture';

        try {
            // Exercise the runtime guard instead of Laravel's unit-test short circuit.
            $this->assertFalse($this->app->runningUnitTests());
            $session = (new SessionManager($this->app))->driver('array');
            $session->start();
            $parameters = $tokenKind === null ? [] : [
                '_token' => $tokenKind === 'correct' ? $session->token() : $session->token().'-wrong',
            ];
            $server = $fetchSite === null ? [] : ['HTTP_SEC_FETCH_SITE' => $fetchSite];
            $request = Request::create('https://runtime-boundary.example/'.$path, 'POST', $parameters, [], [], $server);
            $request->setLaravelSession($session);
            $nextResponse = new Response('boundary accepted', 204);
            $middleware = $this->app->make(VerifyCsrfToken::class);

            if (! $allowed) {
                $this->expectException(TokenMismatchException::class);
            }

            $response = $middleware->handle($request, function (Request $request) use (&$reachedNext, $nextResponse): Response {
                $reachedNext = true;

                return $nextResponse;
            });

            $this->assertSame($nextResponse, $response);
        } finally {
            $this->app['env'] = $originalEnvironment;

            if (! $allowed) {
                $this->assertFalse($reachedNext, 'A rejected request must not reach the downstream callback.');
            }
        }
    }

    public static function csrfRequests(): array
    {
        return [
            'same-origin without token' => ['same-origin', null, 'admin/runtime-boundary', true],
            'cross-site without token' => ['cross-site', null, 'admin/runtime-boundary', false],
            'cross-site with wrong token' => ['cross-site', 'wrong', 'admin/runtime-boundary', false],
            'cross-site with correct token' => ['cross-site', 'correct', 'admin/runtime-boundary', true],
            'same-site without explicit opt-in' => ['same-site', null, 'admin/runtime-boundary', false],
            'missing origin header with correct token' => [null, 'correct', 'admin/runtime-boundary', true],
            'missing origin header with wrong token' => [null, 'wrong', 'admin/runtime-boundary', false],
            'payment exception without token' => ['cross-site', null, 'pay/runtime-boundary', true],
        ];
    }

    #[DataProvider('fileCacheValues')]
    public function test_file_cache_rereads_values_from_a_new_manager(string $key, mixed $value): void
    {
        $config = $this->app['config'];
        $originalStore = $config->get('cache.stores.file');
        $directory = $this->createRuntimeDirectory();

        try {
            $config->set('cache.stores.file.path', $directory.'/cache');
            $writerManager = new CacheManager($this->app);
            $writer = $writerManager->store('file');
            $this->assertInstanceOf(FileStore::class, $writer->getStore());
            $this->assertTrue($writer->put($key, $value, 300));
            $this->assertFileExists($writer->getStore()->path($key));
            unset($writer, $writerManager);

            $readerManager = new CacheManager($this->app);
            $reader = $readerManager->store('file');
            $this->assertInstanceOf(FileStore::class, $reader->getStore());
            $this->assertSame($value, $reader->get($key));
        } finally {
            $config->set('cache.stores.file', $originalStore);
            $this->removeRuntimeDirectory($directory);
        }
    }

    public static function fileCacheValues(): array
    {
        return [
            'system-setting array' => ['system-setting', [
                'title' => '运行边界商店',
                'enabled' => true,
                'quantity' => 0,
                'zero' => '0',
            ]],
            'catalog nested array' => ['catalog', [
                ['id' => 7, 'title' => '目录商品', 'price' => '12.50', 'stock' => 0, 'tags' => ['fixture']],
            ]],
            'rate scalar' => ['rate', 1.25],
        ];
    }

    public function test_file_session_rereads_identity_text_zero_and_token_from_a_new_manager(): void
    {
        $config = $this->app['config'];
        $originalFiles = $config->get('session.files');
        $directory = $this->createRuntimeDirectory();

        try {
            $sessionDirectory = $directory.'/sessions';
            if (! mkdir($sessionDirectory, 0755)) {
                throw new RuntimeException('Could not create the isolated session directory.');
            }
            $config->set('session.files', $sessionDirectory);
            $sessionId = bin2hex(random_bytes(20));
            $writerManager = new SessionManager($this->app);
            $writer = $writerManager->driver('file');
            $this->assertInstanceOf(FileSessionHandler::class, $writer->getHandler());
            $writer->setId($sessionId);
            $this->assertTrue($writer->start());
            $writer->put(['login' => 42, 'text' => '会话边界：独立重读', 'string-zero' => '0']);
            $token = $writer->token();
            $writer->save();
            $this->assertFileExists($sessionDirectory.'/'.$sessionId);
            unset($writer, $writerManager);

            $readerManager = new SessionManager($this->app);
            $reader = $readerManager->driver('file');
            $this->assertInstanceOf(FileSessionHandler::class, $reader->getHandler());
            $reader->setId($sessionId);
            $this->assertTrue($reader->start());
            $this->assertSame($sessionId, $reader->getId());
            $this->assertSame(42, $reader->get('login'));
            $this->assertSame('会话边界：独立重读', $reader->get('text'));
            $this->assertSame('0', $reader->get('string-zero'));
            $this->assertSame($token, $reader->token());
        } finally {
            $config->set('session.files', $originalFiles);
            $this->removeRuntimeDirectory($directory);
        }
    }

    private function createRuntimeDirectory(): string
    {
        $directory = base_path('tests/.runtime/laravel12/runtime-boundary/'.bin2hex(random_bytes(12)));

        if (! mkdir($directory, 0755, true)) {
            throw new RuntimeException('Could not create the isolated runtime boundary directory.');
        }

        return $directory;
    }

    private function removeRuntimeDirectory(string $directory): void
    {
        if (! $this->app['files']->deleteDirectory($directory)) {
            throw new RuntimeException('Could not remove the isolated runtime boundary directory.');
        }
    }
}
