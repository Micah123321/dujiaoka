<?php

// This router serves only an isolated application and matching installed admin assets.
$cli = PHP_SAPI === 'cli';
$candidate = $cli ? in_array('--candidate', $argv, true) : getenv('DUJIAOKA_ADMIN_TREE') === 'candidate';
$initialize = $cli && in_array('--init-isolated-admin', $argv, true);
$inspect = $cli && in_array('--inspect-isolated-admin', $argv, true);
$extended = $cli ? in_array('--extended', $argv, true) : getenv('DUJIAOKA_ADMIN_EXTENDED') === '1';
$statusMail = $cli ? in_array('--status-mail', $argv, true) : getenv('DUJIAOKA_ADMIN_STATUS_MAIL') === '1';
$deletion = $cli ? in_array('--deletion', $argv, true) : getenv('DUJIAOKA_ADMIN_DELETION') === '1';
$forms = $cli ? in_array('--forms', $argv, true) : getenv('DUJIAOKA_ADMIN_FORMS') === '1';
$uploadFixture = $extended || $deletion;
$fixtureAction = $cli ? (in_array('--publish-status-mail', $argv, true) ? 'publish' : (in_array('--work-status-mail', $argv, true) ? 'work' : (in_array('--advance-status-mail', $argv, true) ? 'advance' : null))) : null;
if ((int) $statusMail + (int) $extended + (int) $deletion + (int) $forms > 1) throw new RuntimeException('Choose one isolated fixture mode.');
$port = $forms ? 8202 : ($deletion ? 8201 : ($statusMail ? 8200 : ($extended ? 8199 : 8198)));
$fixtureUrl = 'http://127.0.0.1:' . $port;
$runtimeName = 'admin-' . ($forms ? 'forms-' : ($deletion ? 'deletion-' : ($statusMail ? 'status-mail-' : ($extended ? 'extended-' : '')))) . ($candidate ? 'candidate' : 'root');
$runtime = __DIR__ . '/.runtime/' . $runtimeName;
if ($cli && !$initialize && !$inspect && !($statusMail && $fixtureAction)) { fwrite(STDERR, "Use --init-isolated-admin or --inspect-isolated-admin [--candidate] [--extended|--status-mail|--deletion|--forms]\n"); exit(1); }
if (!$cli && (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:' . $port)) {
    http_response_code(403); exit('Fixture host mismatch');
}
$root = dirname(__DIR__);
$vendor = $candidate ? __DIR__ . '/Compatibility/Laravel12/vendor' : $root . '/vendor';
if (!$cli) {
    $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    if ($uploadFixture && strpos($path, '/fixture-uploads/') === 0) {
        $mount = strpos($path, '/fixture-uploads/admin/') === 0 ? 'admin' : (strpos($path, '/fixture-uploads/public/') === 0 ? 'public' : null);
        $directory = $mount ? realpath($runtime . '/storage/app/' . $mount) : false;
        $file = $directory ? realpath($directory . '/' . substr($path, strlen('/fixture-uploads/' . $mount . '/'))) : false;
        $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'txt' => 'text/plain'];
        $extension = strtolower(pathinfo($file ?: '', PATHINFO_EXTENSION));
        if (!$directory || !$file || !is_file($file) || strpos($file, $directory . DIRECTORY_SEPARATOR) !== 0 || !isset($types[$extension])) { http_response_code(404); exit; }
        header('Content-Type: ' . $types[$extension]);
        header('X-Fixture-Asset-Tree: ' . ($candidate ? 'candidate' : 'root'));
        readfile($file); return;
    }
    if ($path !== '/admin' && strpos($path, '/admin/') !== 0 && strpos($path, '/vendor/') !== 0) { http_response_code(404); exit('Fixture route not found'); }
    $mounts = ['/vendor/dcat-admin/' => $vendor . ($candidate ? '/dcat-x/laravel-admin/resources/dist/' : '/dcat/laravel-admin/resources/dist/'), '/vendor/dujiaoka-admin/' => $root . '/public/vendor/dujiaoka-admin/'];
    foreach ($mounts as $prefix => $directory) {
        if (strpos($path, $prefix) !== 0) continue;
        $base = realpath($directory);
        $file = realpath($directory . substr($path, strlen($prefix)));
        $types = ['js' => 'application/javascript', 'css' => 'text/css', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'gif' => 'image/gif', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf'];
        $extension = strtolower(pathinfo($file ?: '', PATHINFO_EXTENSION));
        if (!$base || !$file || !is_file($file) || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0 || !isset($types[$extension])) { http_response_code(404); exit; }
        header('Content-Type: ' . $types[$extension]);
        header('X-Fixture-Asset-Tree: ' . ($candidate ? 'candidate' : 'root'));
        readfile($file); return;
    }
}
require $candidate ? __DIR__ . '/Compatibility/Laravel12/bootstrap.php' : __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Fixtures/AdminData.php';
$runtime = __DIR__ . '/.runtime/' . $runtimeName;
foreach (['', '/storage/framework/views', '/storage/framework/cache/data', '/storage/framework/sessions', '/storage/logs', '/storage/app'] as $suffix) if (!is_dir($runtime . $suffix)) mkdir($runtime . $suffix, 0777, true);
$settings = [
    'APP_URL' => $fixtureUrl, 'APP_ENV' => 'admin-browser-fixture',
    'APP_CONFIG_CACHE' => 'tests/.runtime/' . $runtimeName . '/config.php',
    'APP_ROUTES_CACHE' => 'tests/.runtime/' . $runtimeName . '/routes.php',
    'APP_PACKAGES_CACHE' => 'tests/.runtime/' . $runtimeName . '/packages.php',
    'APP_SERVICES_CACHE' => 'tests/.runtime/' . $runtimeName . '/services.php',
    'APP_EVENTS_CACHE' => 'tests/.runtime/' . $runtimeName . '/events.php',
    'LARAVEL_STORAGE_PATH' => $runtime . '/storage', 'DB_DATABASE' => $runtime . '/fixture.sqlite',
    'ADMIN_ROUTE_PREFIX' => 'admin', 'ADMIN_ROUTE_DOMAIN' => '', 'ADMIN_HTTPS' => 'false', 'ADMIN_ASSETS_SERVER' => '',
    'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => $forms ? 'dujiaoka_admin_forms_fixture' : ($deletion ? 'dujiaoka_admin_deletion_fixture' : ($statusMail ? 'dujiaoka_admin_status_fixture' : ($extended ? 'dujiaoka_admin_extended_fixture' : 'dujiaoka_admin_fixture'))), 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_DOMAIN' => '',
    'CACHE_DRIVER' => 'file', 'CACHE_STORE' => 'file',
];
foreach ($settings as $key => $value) { putenv($key . '=' . $value); $_ENV[$key] = $value; $_SERVER[$key] = $value; }
if (!is_file($settings['DB_DATABASE'])) { if (!$initialize) { http_response_code(503); exit('Initialize isolated fixture first'); } touch($settings['DB_DATABASE']); }
$app = require $root . '/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__);
$app->loadEnvironmentFrom('.env.admin-fixture-not-present');
$app->useStoragePath($runtime . '/storage');
$app->instance('path.vendor', $vendor);
$app->make(Illuminate\Foundation\PackageManifest::class)->build();
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($cli) set_exception_handler(function ($error) { fwrite(STDERR, "Fixture initialization failed: " . $error->getMessage() . "\n"); exit(1); });
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $settings['DB_DATABASE']) throw new RuntimeException('Fixture database mismatch');
if ($initialize) {
    if (Illuminate\Support\Facades\Schema::hasTable('fixture_marker')) {
        if (Illuminate\Support\Facades\DB::table('fixture_marker')->value('name') !== 'dujiaoka-admin-browser-v1') throw new RuntimeException('Fixture marker mismatch');
        foreach (Illuminate\Support\Facades\DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name<>'sqlite_sequence'") as $table) Illuminate\Support\Facades\Schema::drop($table->name);
    } elseif (Illuminate\Support\Facades\DB::select("SELECT name FROM sqlite_master WHERE type='table'")) throw new RuntimeException('Unmarked fixture is not empty');
    Tests\Fixtures\AdminData::create($extended);
    if ($forms) {
        $third = (array) Illuminate\Support\Facades\DB::table('goods')->where('id', 2)->first();
        $third['id'] = 3; $third['gd_name'] = 'Fixture Third Goods';
        Illuminate\Support\Facades\DB::table('goods')->insert($third);
    }
    Illuminate\Support\Facades\Cache::flush();
} elseif (Illuminate\Support\Facades\DB::table('fixture_marker')->value('name') !== 'dujiaoka-admin-browser-v1') throw new RuntimeException('Fixture marker mismatch');
config(['filesystems.disks.admin.root' => $runtime . '/storage/app' . ($uploadFixture ? '/admin' : ''), 'filesystems.disks.admin.url' => $fixtureUrl . '/fixture-uploads' . ($uploadFixture ? '/admin' : '')]);
if ($uploadFixture) config(['filesystems.disks.public.root' => $runtime . '/storage/app/public', 'filesystems.disks.public.url' => $fixtureUrl . '/fixture-uploads/public']);
if ($uploadFixture && $initialize) {
    foreach (['admin', 'public'] as $disk) {
        $storage = Illuminate\Support\Facades\Storage::disk($disk);
        $storage->delete($storage->allFiles());
    }
}
if (!$inspect && !Illuminate\Support\Facades\Cache::has('system-setting')) Illuminate\Support\Facades\Cache::forever('system-setting', ['title' => 'Fixture Store', 'text_logo' => 'Fixture Store', 'template' => 'modern', 'language' => 'zh_CN', 'order_expire_time' => 5, 'driver' => 'array', 'is_open_img_code' => 0, 'is_open_geetest' => 0, 'is_open_server_jiang' => 0, 'is_open_telegram_push' => 0, 'is_open_bark_push' => 0, 'is_open_qywxbot_push' => 0]);
if ($deletion) {
    require_once __DIR__ . '/Fixtures/AdminDeletionData.php';
    Tests\Fixtures\AdminDeletionData::configure($runtime, $initialize);
    if ($inspect) { echo json_encode(Tests\Fixtures\AdminDeletionData::state($runtime)), PHP_EOL; return; }
}
if ($statusMail) {
    require_once __DIR__ . '/Fixtures/OrderStatusMailData.php';
    Tests\Fixtures\OrderStatusMailData::configure($runtime, $initialize);
    if ($fixtureAction) {
        $console = $app->make(Illuminate\Contracts\Console\Kernel::class);
        if ($fixtureAction === 'publish') $exit = $console->call('notifications:dispatch', ['--limit' => 100]);
        elseif ($fixtureAction === 'work') $exit = $console->call('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 60]);
        else {
            Illuminate\Support\Facades\DB::table('notification_outbox')->where('channel', 'order-status-mail')->whereNull('delivered_at')->whereNull('failed_at')->whereNull('reservation_token')->where('attempts', '>', 0)->update(['available_at' => date('Y-m-d H:i:s', time() - 1)]);
            $exit = 0;
        }
        echo json_encode(['command' => $fixtureAction, 'exit' => $exit, 'state' => Tests\Fixtures\OrderStatusMailData::state($runtime)]), PHP_EOL;
        exit($exit);
    }
    if ($inspect) { echo json_encode(Tests\Fixtures\OrderStatusMailData::state($runtime)), PHP_EOL; return; }
}
if ($inspect) {
    $db = Illuminate\Support\Facades\DB::getFacadeRoot();
    $files = [];
    foreach (['admin', 'public'] as $disk) foreach (Illuminate\Support\Facades\Storage::disk($disk)->allFiles() as $file) {
        $files[$disk][$file] = hash('sha256', Illuminate\Support\Facades\Storage::disk($disk)->get($file));
    }
    echo json_encode(['php' => PHP_VERSION, 'binary' => PHP_BINARY, 'ini' => php_ini_loaded_file(), 'framework' => $app->version(),
        'database' => config('database.connections.sqlite.database'), 'storage' => $app->storagePath(),
        'goods' => $db->table('goods')->orderBy('id')->get(['id','gd_name','is_open','ord','picture','deleted_at']),
        'carmis' => $db->table('carmis')->orderBy('id')->get(['id','goods_id','carmi','status','deleted_at']), 'files' => $files]), PHP_EOL; return;
}
if ($cli) { echo json_encode(['framework' => $app->version(), 'admin' => $candidate ? 'dcat-x' : 'dcat', 'schema' => 'shipped-columns-with-fixture-seeds', 'database' => 'isolated-sqlite', 'status' => 'initialized']), PHP_EOL; return; }
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
$response->headers->set('X-Fixture-Tree', $candidate ? 'candidate' : 'root');
$response->headers->set('X-Fixture-PHP', PHP_VERSION);
$response->headers->set('X-Fixture-Framework', $app->version());
$response->send();
$kernel->terminate($request, $response);
