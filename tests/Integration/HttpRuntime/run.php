<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/LinuxLifecycle/process.php';
require dirname(__DIR__) . '/ScheduleWorkerLifecycle/process.php';

function hr_write($path, $text)
{
    if (file_put_contents($path, $text, LOCK_EX) === false) throw new RuntimeException('HTTP fixture write failed.');
}

function hr_port()
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Private port reservation failed.');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    return $port;
}

function hr_request($path, $method = 'GET', array $headers = [], array $data = [])
{
    $cfg = $GLOBALS['hr_config'];
    sw_track_children();
    if ($GLOBALS['hr_interrupted'] !== null) throw new RuntimeException('HTTP fixture interrupted.');
    $label = sprintf('%02d', ++$GLOBALS['hr_requests']);
    $requestId = $cfg['marker'] . ':' . $label;
    $headers[] = 'X-HTTP-Fixture-Request: ' . $requestId;
    $curl = curl_init($cfg['url'] . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 45, CURLOPT_PROXY => '', CURLOPT_USERAGENT => 'HttpRuntimeFixture42',
        CURLOPT_COOKIEFILE => $cfg['run'] . '/cookies', CURLOPT_COOKIEJAR => $cfg['run'] . '/cookies', CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException('Private HTTP failed: ' . curl_error($curl));
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'head' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    unset($curl);
    hr_write($cfg['run'] . '/response-' . $label . '.headers', $result['head']);
    hr_write($cfg['run'] . '/response-' . $label . '.body', $result['body']);
    ql_log('Response', ['request' => $label, 'request_id' => $requestId, 'path' => $path, 'method' => $method, 'status' => $result['status'], 'body_sha256' => hash('sha256', $result['body'])]);
    return $result;
}

function hr_data($response)
{
    if (!preg_match('#<script id="modern-data" type="application/json">(.*?)</script>#s', $response['body'], $match)) throw new RuntimeException('Original Vue data missing.');
    return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
}

function hr_check_command(array $args, $label)
{
    sw_spawn($args, $label);
    ql_check(sw_wait($label, 15) === 0, 'native_config_' . $label);
}

if (PHP_OS_FAMILY !== 'Linux' || PHP_VERSION !== '8.5.11' || posix_geteuid() !== 1000) throw new RuntimeException('Existing Linux PHP8.5.11 UID1000 required.');
$root = ql_normalize(realpath(dirname(__DIR__, 3)));
$marker = bin2hex(random_bytes(12));
$run = $root . '/tests/.runtime/http-runtime/run-' . gmdate('Ymd-His') . '-' . $marker;
mkdir($run, 0700, true);
$GLOBALS['ql_run'] = $run;
$GLOBALS['ql_role'] = 'http-parent';
$GLOBALS['ql_config'] = ['root' => $root, 'marker' => $marker];
$GLOBALS['ll_processes'] = [];
$GLOBALS['sw_owned_children'] = [];
$GLOBALS['sw_groups'] = [];
$GLOBALS['hr_requests'] = 0;
hr_write($run . '/events.jsonl', '');
$failure = null;
$cleanup = [];
$app = null;
$summary = [];
$interrupted = null;
$GLOBALS['hr_interrupted'] =& $interrupted;
pcntl_async_signals(true);
foreach ([SIGTERM, SIGINT, SIGALRM] as $signal) pcntl_signal($signal, function ($signal) use (&$interrupted) { $interrupted = $signal; });
pcntl_alarm(270);
try {
    foreach (['release/public', 'release/routes', 'cache', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs', 'tmp'] as $dir) mkdir($run . '/' . $dir, 0700, true);
    foreach (['app', 'bootstrap', 'config', 'resources'] as $dir) if (!symlink($root . '/' . $dir, $run . '/release/' . $dir)) throw new RuntimeException('Owned source mapping failed.');
    foreach (['common', 'api.php', 'channels.php', 'console.php'] as $route) if (file_exists($root . '/routes/' . $route)) symlink($root . '/routes/' . $route, $run . '/release/routes/' . $route);
    hr_write($run . '/release/routes/web.php', file_get_contents($root . '/routes/web.php') . "\nrequire " . var_export(__DIR__ . '/routes.php', true) . ";\n");
    symlink($root . '/tests/Compatibility/Laravel12/vendor', $run . '/release/vendor');
    symlink($root . '/composer.json', $run . '/release/composer.json');
    foreach (['assets', 'favicon.ico', 'robots.txt'] as $public) symlink($root . '/public/' . $public, $run . '/release/public/' . $public);
    hr_write($run . '/release/public/index.php', file_get_contents($root . '/public/index.php'));
    hr_write($run . '/release/install.lock', 'Synthetic HTTP fixture');
    hr_write($run . '/marker', $marker);
    touch($run . '/fixture.sqlite');
    $port = hr_port();
    $fpmPort = hr_port();
    $cfg = ['root' => $root, 'run' => $run, 'release' => $run . '/release', 'marker' => $marker,
        'key' => 'base64:' . base64_encode(str_repeat('H', 32)), 'vendor' => $root . '/tests/Compatibility/Laravel12/vendor',
        'port' => $port, 'fpm_port' => $fpmPort, 'url' => 'http://127.0.0.1:' . $port, 'trusted' => false,
        'ini' => ql_normalize(php_ini_loaded_file()), 'fpm' => $root . '/tests/.runtime/http42/rootfs/usr/sbin/php-fpm8.5',
        'nginx' => $root . '/tests/.runtime/http42/rootfs/usr/sbin/nginx'];
    ql_json($run . '/run.json', $cfg);
    hr_environment($cfg);
    $app = require $cfg['release'] . '/bootstrap/app.php';
    $app->make(Illuminate\Foundation\PackageManifest::class)->build();
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    ql_check($app->basePath() === $cfg['release'] && $app->storagePath() === $run . '/storage', 'private_application_paths');
    ql_check(!is_file($cfg['release'] . '/.env') && config('database.connections.sqlite.database') === $run . '/fixture.sqlite', 'missing_env_private_database');
    require_once $root . '/tests/Fixtures/AdminData.php';
    Tests\Fixtures\AdminData::create(false);
    Illuminate\Support\Facades\Cache::forever('system-setting', ['title' => 'Fixture Store', 'text_logo' => 'Fixture Store',
        'template' => 'modern', 'language' => 'zh_CN', 'order_expire_time' => 5, 'driver' => 'array', 'is_open_img_code' => 0,
        'is_open_geetest' => 0, 'is_open_server_jiang' => 0, 'is_open_telegram_push' => 0, 'is_open_bark_push' => 0, 'is_open_qywxbot_push' => 0]);
    $initialOrders = Illuminate\Support\Facades\DB::table('orders')->orderBy('id')->get()->toArray();
    $started = microtime(true);
    $fpm = "[global]\npid = $run/fpm.pid\nerror_log = $run/fpm.log\ndaemonize = no\n[fixture]\nlisten = 127.0.0.1:$fpmPort\nlisten.allowed_clients = 127.0.0.1\npm = static\npm.max_children = 1\nclear_env = yes\nenv[HTTP_RUNTIME_RUN] = $run\ncatch_workers_output = yes\nrequest_terminate_timeout = 45s\nsecurity.limit_extensions = .php\nphp_admin_value[auto_prepend_file] = " . __DIR__ . "/prepend.php\nphp_admin_value[upload_tmp_dir] = $run/tmp\nphp_admin_value[session.save_path] = $run/storage/framework/sessions\nphp_admin_flag[display_errors] = off\nphp_admin_flag[log_errors] = on\nphp_admin_value[error_log] = $run/php-errors.log\nphp_admin_value[opcache.validate_timestamps] = 0\n";
    hr_write($run . '/fpm.conf', $fpm);
    $public = $cfg['release'] . '/public';
    $logFormat = <<<'NGINX'
log_format fixture escape=json '{"request_id":"$http_x_http_fixture_request","method":"$request_method","uri":"$request_uri","status":$status}';
NGINX;
    $nginx = "daemon off;\nmaster_process on;\nworker_processes 1;\npid $run/nginx.pid;\nerror_log $run/nginx.log notice;\nevents { worker_connections 32; }\nhttp {\n $logFormat\n access_log $run/access.log fixture;\n client_body_temp_path $run/tmp/client;\n proxy_temp_path $run/tmp/proxy;\n fastcgi_temp_path $run/tmp/fastcgi;\n uwsgi_temp_path $run/tmp/uwsgi;\n scgi_temp_path $run/tmp/scgi;\n types { application/javascript js; text/css css; application/json json; image/x-icon ico; }\n server {\n  listen 127.0.0.1:$port;\n  server_name 127.0.0.1;\n  root $public;\n  index index.php;\n  location / { try_files \$uri \$uri/ /index.php?\$query_string; }\n  location ~ /\\. { return 404; }\n  location ~ \\.php$ { return 404; }\n  location = /index.php {\n   fastcgi_pass 127.0.0.1:$fpmPort;\n   fastcgi_param SCRIPT_FILENAME $public/index.php;\n   fastcgi_param SCRIPT_NAME /index.php;\n   fastcgi_param QUERY_STRING \$query_string;\n   fastcgi_param REQUEST_METHOD \$request_method;\n   fastcgi_param CONTENT_TYPE \$content_type;\n   fastcgi_param CONTENT_LENGTH \$content_length;\n   fastcgi_param REQUEST_URI \$request_uri;\n   fastcgi_param DOCUMENT_URI \$document_uri;\n   fastcgi_param DOCUMENT_ROOT $public;\n   fastcgi_param SERVER_PROTOCOL \$server_protocol;\n   fastcgi_param GATEWAY_INTERFACE CGI/1.1;\n   fastcgi_param SERVER_SOFTWARE nginx;\n   fastcgi_param REMOTE_ADDR \$remote_addr;\n   fastcgi_param REMOTE_PORT \$remote_port;\n   fastcgi_param SERVER_ADDR \$server_addr;\n   fastcgi_param SERVER_PORT \$server_port;\n   fastcgi_param SERVER_NAME \$server_name;\n   fastcgi_read_timeout 45s;\n  }\n }\n}\n";
    hr_write($run . '/nginx.conf', $nginx);
    $fpmArgs = [$cfg['fpm'], '-c', $cfg['ini'], '-y', $run . '/fpm.conf'];
    $nginxArgs = [$cfg['nginx'], '-p', $run . '/', '-c', $run . '/nginx.conf', '-e', $run . '/nginx.log'];
    hr_check_command(array_merge($fpmArgs, ['-tt']), 'fpm-config');
    hr_check_command(array_merge($nginxArgs, ['-t']), 'nginx-config');
    sw_spawn(array_merge($fpmArgs, ['-F']), 'fpm');
    sw_spawn($nginxArgs, 'nginx');
    ll_until(function () use ($port, $fpmPort, &$interrupted) {
        sw_track_children();
        if ($interrupted !== null) throw new RuntimeException('HTTP fixture interrupted.');
        foreach (['fpm', 'nginx'] as $label) if (!proc_get_status($GLOBALS['ll_processes'][$label]['proc'])['running']) throw new RuntimeException('Native service stopped before ready: ' . $label);
        foreach ([$port, $fpmPort] as $listen) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $listen, $errno, $error, 0.2);
            if (!$socket) return false;
            fclose($socket);
        }
        return true;
    }, 'private nginx and fpm ready', 10);
    if (in_array('--fail-after-start', $argv, true)) throw new RuntimeException('Controlled HTTP post-start failure.');
    $home = hr_request('/');
    ql_check($home['status'] === 200 && strpos($home['head'], 'X-HTTP-SAPI: fpm-fcgi') !== false, 'original_front_through_real_fpm');
    $modern = hr_data($home);
    ql_check($modern['page'] === 'home' && strpos($home['body'], 'Fixture Auto Goods') !== false && strpos($home['body'], 'Fixture Manual Goods') !== false, 'original_home_catalog');
    ql_check($modern['site']['home'] === $cfg['url'] && strlen($modern['csrf']) >= 40, 'original_home_url_csrf');
    $manifest = json_decode(file_get_contents($public . '/assets/modern/manifest.json'), true, 512, JSON_THROW_ON_ERROR)['src/main.ts'];
    foreach (array_merge([$manifest['file']], $manifest['css']) as $asset) {
        $response = hr_request('/assets/modern/' . $asset);
        $type = substr($asset, -3) === '.js' ? 'application/javascript' : 'text/css';
        ql_check($response['status'] === 200 && strpos($response['head'], $type) !== false
            && hash('sha256', $response['body']) === hash_file('sha256', $public . '/assets/modern/' . $asset), 'nginx_original_vue_asset_hash_mime');
    }
    $buy = hr_request('/buy/1');
    ql_check($buy['status'] === 200 && hr_data($buy)['page'] === 'buy' && strpos($buy['body'], 'Fixture Pay') !== false, 'original_buy_payment_catalog');
    $identity = hr_request('/__runtime42/identity', 'GET', ['Accept: application/json']);
    $id = json_decode($identity['body'], true, 512, JSON_THROW_ON_ERROR);
    $denied = hr_request('/__runtime42/session', 'POST', ['Accept: application/json']);
    ql_check($denied['status'] === 419, 'real_csrf_missing_token_419');
    $posted = hr_request('/__runtime42/session', 'POST', ['Accept: application/json'], ['_token' => $id['csrf']]);
    $post = json_decode($posted['body'], true, 512, JSON_THROW_ON_ERROR);
    ql_check($posted['status'] === 200 && $post['counter'] === 1 && $post['session'] === $id['session'], 'real_csrf_file_session_roundtrip');
    $again = hr_request('/__runtime42/identity', 'GET', ['Accept: application/json']);
    ql_check(json_decode($again['body'], true)['counter'] === 1 && is_file($run . '/storage/framework/sessions/' . $id['session']), 'fpm_file_session_persists');
    $forward = ['Accept: application/json', 'X-Forwarded-Proto: https', 'X-Forwarded-Host: shop.fixture.invalid', 'X-Forwarded-Port: 8443', 'X-Forwarded-For: 198.51.100.24'];
    $untrusted = json_decode(hr_request('/__runtime42/identity', 'GET', $forward)['body'], true);
    ql_check($untrusted['scheme'] === 'http' && $untrusted['host'] === '127.0.0.1' && $untrusted['ip'] === '127.0.0.1' && $untrusted['port'] === $port, 'untrusted_forwarding_ignored');
    $cfg['trusted'] = true; ql_json($run . '/run.json', $cfg);
    $trusted = json_decode(hr_request('/__runtime42/identity', 'GET', $forward)['body'], true);
    ql_check($trusted['scheme'] === 'https' && $trusted['host'] === 'shop.fixture.invalid' && $trusted['port'] === 8443 && $trusted['ip'] === '198.51.100.24', 'original_trusted_four_headers');
    $reset = json_decode(hr_request('/__runtime42/identity', 'GET', ['Accept: application/json'])['body'], true);
    ql_check($reset['scheme'] === 'http' && $reset['host'] === '127.0.0.1' && $reset['counter'] === 1, 'fpm_requests_do_not_leak_proxy_state');
    $error = hr_request('/__runtime42/failure', 'GET', ['Accept: application/json']);
    ql_check($error['status'] === 500 && strpos($error['head'], 'Content-Type: application/json') !== false
        && json_decode($error['body'], true, 512, JSON_THROW_ON_ERROR) === ['message' => 'Server Error'], 'original_sanitized_json500');
    $app->maintenanceMode()->activate(['time' => time(), 'retry' => 60, 'status' => 503]);
    $maintenance = hr_request('/', 'GET', ['Accept: application/json']);
    ql_check($maintenance['status'] === 503, 'original_file_maintenance503');
    $app->maintenanceMode()->deactivate();
    ql_check(hr_request('/')['status'] === 200, 'maintenance_recovery_real_home');
    foreach (['/.env', '/storage/logs/laravel.log', '/other.php'] as $path) ql_check(hr_request($path)['status'] === 404, 'private_public_root_boundary');
    $native = array_map(function ($line) { return json_decode($line, true, 512, JSON_THROW_ON_ERROR); }, file($run . '/http-events.jsonl', FILE_SKIP_EMPTY_LINES));
    $client = array_values(array_filter(array_map(function ($line) { return json_decode($line, true, 512, JSON_THROW_ON_ERROR); }, file($run . '/events.jsonl', FILE_SKIP_EMPTY_LINES)), function ($event) { return $event['event'] === 'Response'; }));
    $access = array_map(function ($line) { return json_decode($line, true, 512, JSON_THROW_ON_ERROR); }, file($run . '/access.log', FILE_SKIP_EMPTY_LINES));
    ql_check(count($client) === 17 && count($access) === 17 && count($native) === 13, 'complete_client_nginx_fpm_cardinality');
    $seen = [];
    foreach ($client as $response) {
        $matches = array_values(array_filter($access, function ($event) use ($response) { return $event['request_id'] === $response['request_id']; }));
        $fpmMatches = array_values(array_filter($native, function ($event) use ($response) { return $event['request_id'] === $response['request_id']; }));
        ql_check(count($matches) === 1 && !isset($seen[$response['request_id']]), 'unique_client_nginx_request');
        $seen[$response['request_id']] = true;
        ql_check($matches[0]['method'] === $response['method'] && $matches[0]['uri'] === $response['path'] && $matches[0]['status'] === $response['status'], 'client_nginx_request_status_match');
        $dynamic = strpos($response['path'], '/assets/') !== 0 && !in_array($response['path'], ['/.env', '/other.php'], true);
        ql_check(count($fpmMatches) === ($dynamic ? 1 : 0), 'unique_expected_fpm_request');
        if ($dynamic) ql_check($fpmMatches[0]['method'] === $response['method'] && $fpmMatches[0]['route'] === $response['path'] && $fpmMatches[0]['status'] === $response['status'], 'client_nginx_fpm_three_way_match');
    }
    foreach ($native as $event) {
        ql_check($event['sapi'] === 'fpm-fcgi' && $event['framework'] === '13.35.0' && $event['uid'] === 1000 && $event['gid'] === 1000, 'real_candidate_fpm_identity');
        ql_check($event['base'] === $cfg['release'] && $event['storage'] === $run . '/storage' && $event['db'] === $run . '/fixture.sqlite'
            && $event['ini'] === $cfg['ini'] && !$event['scanned_ini'], 'real_fpm_private_paths_ini');
        ql_check($event['source_index_sha256'] === hash_file('sha256', $root . '/public/index.php'), 'original_public_index_bytes');
        $last = $event['last_php_error'];
        $expectedProbe = $last && $last['type'] === E_WARNING
            && $last['file'] === $cfg['vendor'] . '/vlucas/phpdotenv/src/Store/File/Reader.php' && $last['line'] === 73
            && $last['message'] === 'file_get_contents(' . $cfg['release'] . '/.env): Failed to open stream: No such file or directory';
        ql_check($last === null || $expectedProbe, 'fpm_only_expected_missing_private_dotenv_probe');
        if ($expectedProbe) ql_log('ExpectedPrivateEnvProbe', ['request_id' => $event['request_id'], 'source' => $last['file'], 'line' => $last['line']]);
    }
    ql_check(!is_file($run . '/php-errors.log') || filesize($run . '/php-errors.log') === 0, 'fpm_no_reported_php_engine_issues');
    ql_check($interrupted === null, 'no_interrupt_before_native_shutdown');
    ql_check($initialOrders == Illuminate\Support\Facades\DB::table('orders')->orderBy('id')->get()->toArray(), 'synthetic_orders_unchanged');
    ql_check(count(glob($run . '/storage/framework/views/*.php')) > 0 && count(glob($run . '/storage/framework/cache/data/*/*/*')) > 0, 'real_blade_and_file_cache_writes');
    ql_check(proc_terminate($GLOBALS['ll_processes']['nginx']['proc'], SIGQUIT) && sw_wait('nginx', 10) === 0, 'nginx_graceful_quit0');
    ql_check(proc_terminate($GLOBALS['ll_processes']['fpm']['proc'], SIGQUIT) && sw_wait('fpm', 10) === 0, 'fpm_graceful_quit0');
    $summary = ['status' => 'PASS', 'requests' => $GLOBALS['hr_requests'], 'native_requests' => count($native),
        'elapsed' => microtime(true) - $started, 'url' => $cfg['url'], 'trusted' => $trusted, 'untrusted' => $untrusted,
        'native' => $native, 'original_index_sha256' => hash_file('sha256', $root . '/public/index.php')];
} catch (Throwable $e) { $failure = ['class' => get_class($e), 'message' => $e->getMessage()]; fwrite(STDERR, $e->getMessage() . PHP_EOL); }
finally {
    pcntl_alarm(0);
    try { sw_track_children(); sw_cleanup_children(); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    foreach (array_keys($GLOBALS['ll_processes']) as $label) try { ll_cleanup_owned($label); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    if ($app) $app->make('db')->disconnect();
    $summary['failure'] = $failure;
    $summary['cleanup_errors'] = $cleanup;
    $summary['remaining_owned'] = array_values(array_filter($GLOBALS['sw_owned_children'], 'sw_child_alive'));
    $summary['owned'] = array_values($GLOBALS['sw_owned_children']);
    $summary['processes'] = array_map(function ($p) { unset($p['proc']); return $p; }, $GLOBALS['ll_processes']);
    $summary['checks'] = count(array_filter(array_map(function ($line) { return json_decode($line, true); }, (is_file($run . '/events.jsonl') ? file($run . '/events.jsonl', FILE_SKIP_EMPTY_LINES) : [])), function ($e) { return $e['event'] === 'CheckPassed'; }));
    $summary['interrupted'] = $interrupted;
    $summary['status'] = !$failure && $interrupted === null && !$cleanup && !$summary['remaining_owned'] && !array_filter($summary['processes'], function ($p) { return !$p['closed']; }) ? 'PASS' : 'FAIL';
    ql_json($run . '/summary.json', $summary);
    echo json_encode(['status' => $summary['status'], 'checks' => $summary['checks'], 'run' => $run], JSON_THROW_ON_ERROR), PHP_EOL;
}
exit($summary['status'] === 'PASS' ? 0 : 1);
