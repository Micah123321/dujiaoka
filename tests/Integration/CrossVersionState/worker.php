<?php
require __DIR__ . '/bootstrap.php';
$run = $argv[1] ?? '';
$tree = $argv[2] ?? '';
$taskFile = $argv[3] ?? '';
$resultFile = $taskFile . '.result.json';
$app = null;
try {
    if (dirname(ql_normalize(realpath($taskFile) ?: '')) !== ql_normalize(realpath($run) ?: '') . '/tasks') throw new RuntimeException('Owned task required.');
    $task = json_decode(file_get_contents($taskFile), true, 512, JSON_THROW_ON_ERROR);
    list($app, $cfg) = cv_boot($run, $tree);
    set_exception_handler(function ($e) { throw $e; });
    $issues = [];
    $logFile = $app->storagePath() . '/logs/laravel.log';
    $logStart = is_file($logFile) ? filesize($logFile) : 0;
    $previous = set_error_handler(function ($severity, $message, $file, $line) use (&$issues, &$previous) {
        if ($severity & error_reporting()) $issues[] = compact('severity', 'message', 'file', 'line');
        return $previous ? $previous($severity, $message, $file, $line) : false;
    });
    $result = ['status' => 'PASS', 'tree' => $tree, 'pid' => getmypid(), 'php' => PHP_VERSION, 'framework' => $app->version(),
        'binary' => ql_normalize(realpath(PHP_BINARY)), 'ini' => ql_normalize(php_ini_loaded_file()),
        'base' => $app->basePath(), 'storage' => $app->storagePath(), 'database' => config('database.connections.sqlite.database'),
        'cache_path' => config('cache.stores.file.path'), 'session_path' => config('session.files'),
        'session_serialization' => config('session.serialization', 'php'), 'guard_name' => Dcat\Admin\Admin::guard()->getName(),
        'recaller_name' => Dcat\Admin\Admin::guard()->getRecallerName(), 'operation' => $task['operation']];
    if ($task['operation'] === 'seed') {
        if (Illuminate\Support\Facades\DB::select("SELECT name FROM sqlite_master WHERE type='table'")) throw new RuntimeException('Fresh synthetic DB required.');
        require dirname(__DIR__, 2) . '/Fixtures/AdminData.php';
        Tests\Fixtures\AdminData::create(false);
        Illuminate\Support\Facades\Cache::forever('system-setting', ['title' => 'Cross Version Store', 'text_logo' => 'Cross Version Store', 'template' => 'modern', 'language' => 'zh_CN', 'driver' => 'array', 'is_open_geetest' => 0, 'is_open_img_code' => 0]);
    } elseif ($task['operation'] === 'cache-write') {
        Illuminate\Support\Facades\Cache::put('state43-types', cv_payload(), 300);
        $app->setLocale('zh_CN');
        config(['dujiaoka.catalog_cache_seconds' => 60]);
        $catalog = $app->make(App\Service\GoodsService::class)->withGroup();
        $result['payload'] = cv_payload(); $result['catalog'] = $catalog;
        $result['paths'] = [];
        foreach (['state43-types', 'system-setting', 'storefront:catalog:v1:zh_CN'] as $key) {
            $store = Illuminate\Support\Facades\Cache::store('file')->getStore();
            $method = new ReflectionMethod($store, 'path');
            if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
            $path = $method->invoke($store, $key);
            $snapshot = $taskFile . '.cache-' . count($result['paths']) . '.bin';
            if (!copy($path, $snapshot)) throw new RuntimeException('Owned cache snapshot failed.');
            $result['paths'][$key] = ['path' => $path, 'sha256' => hash_file('sha256', $path), 'snapshot' => $snapshot];
        }
    } elseif ($task['operation'] === 'cache-read') {
        $result['payload'] = Illuminate\Support\Facades\Cache::get('state43-types');
        $result['settings'] = Illuminate\Support\Facades\Cache::get('system-setting');
        $app->setLocale('zh_CN');
        Illuminate\Support\Facades\DB::enableQueryLog();
        $result['catalog'] = $app->make(App\Service\GoodsService::class)->withGroup();
        $result['queries'] = Illuminate\Support\Facades\DB::getQueryLog();
        if ($result['payload'] !== cv_payload() || !$result['catalog'] || $result['queries']) throw new RuntimeException('Cross-version cache value/cache-hit mismatch.');
    } elseif ($task['operation'] === 'request') {
        Illuminate\Support\Facades\Route::middleware('web')->group(function () {
            Illuminate\Support\Facades\Route::get('/__state43', function (Illuminate\Http\Request $request) {
                $guard = Dcat\Admin\Admin::guard();
                return response()->json(['id' => $guard->id(), 'username' => $guard->user() ? $guard->user()->username : null,
                    'via_remember' => $guard->viaRemember(), 'session' => $request->session()->getId(), 'csrf' => csrf_token(),
                    'payload' => $request->session()->get('state43'), 'revision' => $request->session()->get('revision', 0),
                    'errors' => $request->session()->has('errors') ? $request->session()->get('errors')->all() : [],
                    'payload_types' => cv_types($request->session()->get('state43'))], 200, [], JSON_PRESERVE_ZERO_FRACTION);
            });
            Illuminate\Support\Facades\Route::post('/__state43', function (Illuminate\Http\Request $request) {
                if (!Dcat\Admin\Admin::user()) abort(401);
                $request->session()->put('state43', cv_payload());
                $request->session()->put('revision', (int) $request->session()->get('revision', 0) + 1);
                $errors = new Illuminate\Support\ViewErrorBag();
                $errors->put('default', new Illuminate\Support\MessageBag(['fixture' => ['Cross-version validation message']]));
                $request->session()->put('errors', $errors);
                return response()->json(['id' => Dcat\Admin\Admin::user()->getKey(), 'session' => $request->session()->getId(), 'csrf' => csrf_token(), 'revision' => $request->session()->get('revision')]);
            });
        });
        if (!empty($task['changed_key'])) {
            config(['app.key' => 'base64:' . base64_encode(str_repeat('X', 32))]);
            $app->forgetInstance('encrypter');
        }
        $server = ['HTTP_ACCEPT' => $task['accept'] ?? 'application/json', 'HTTP_USER_AGENT' => 'CrossVersionFixture43', 'REMOTE_ADDR' => '127.0.0.1'];
        $request = Illuminate\Http\Request::create('http://state.fixture.invalid' . $task['path'], $task['method'] ?? 'GET', $task['data'] ?? [], $task['cookies'] ?? [], [], $server);
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        if ($request->hasSession()) {
            $sessionFile = config('session.files') . '/' . $request->session()->getId();
            if (is_file($sessionFile)) {
                $snapshot = $taskFile . '.session.bin';
                if (!copy($sessionFile, $snapshot)) throw new RuntimeException('Owned session snapshot failed.');
                $result['session_snapshot'] = ['id' => $request->session()->getId(), 'path' => $sessionFile, 'snapshot' => $snapshot, 'sha256' => hash_file('sha256', $sessionFile)];
            }
        }
        $result['http_status'] = $response->getStatusCode();
        $result['location'] = $response->headers->get('Location');
        $result['json'] = strpos((string) $response->headers->get('Content-Type'), 'application/json') !== false ? json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR) : null;
        $result['body_sha256'] = hash('sha256', $response->getContent());
        file_put_contents($taskFile . '.body', $response->getContent());
        $result['cookies'] = [];
        foreach ($response->headers->getCookies() as $cookie) $result['cookies'][$cookie->getName()] = ['value' => $cookie->getValue(), 'expires' => $cookie->getExpiresTime(), 'path' => $cookie->getPath(), 'secure' => $cookie->isSecure(), 'http_only' => $cookie->isHttpOnly()];
    } else throw new RuntimeException('Unknown cross-version operation.');
    clearstatcache();
    $newLog = is_file($logFile) ? substr(file_get_contents($logFile), $logStart) : '';
    file_put_contents($taskFile . '.application-log', $newLog);
    $result['reported_application_errors'] = preg_match('/[.](ERROR|CRITICAL|ALERT|EMERGENCY):/', $newLog) === 1;
    if ($result['reported_application_errors']) throw new RuntimeException('Application exception reported during cross-version task.');
    if (isset($result['catalog'])) $result['catalog_types'] = cv_types($result['catalog']);
    if (isset($result['payload'])) $result['payload_types'] = cv_types($result['payload']);
    $result['issues'] = $issues;
    if ($issues) throw new RuntimeException('Reported PHP issue in cross-version process.');
    file_put_contents($resultFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    echo json_encode(['status' => 'PASS', 'tree' => $tree, 'task' => basename($taskFile), 'pid' => getmypid()], JSON_THROW_ON_ERROR), PHP_EOL;
    $app->make('db')->disconnect();
    exit(0);
} catch (Throwable $e) {
    if (is_dir(dirname($resultFile))) ql_json($resultFile, array_merge($result ?? [], ['status' => 'FAIL', 'tree' => $tree, 'issues' => $issues ?? [], 'failure' => ['class' => get_class($e), 'message' => $e->getMessage()]]));
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    if ($app) $app->make('db')->disconnect();
    exit(1);
}
