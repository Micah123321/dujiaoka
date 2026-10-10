<?php
require __DIR__ . '/http-prepend.php';

$checks = []; $processes = []; $clients = []; $native = []; $fpmAccess = []; $nginxAccess = [];
$failure = null; $cleanup = []; $completed = false; $interrupted = null; $ownsRun = false;
$cfg = ['run' => '/probe/http.private']; $ports = []; $reservations = []; $before = []; $after = [];
$seed = []; $identities = []; $sourceIncludes = []; $filesSHA = []; $app = null; $db = null;
$started = microtime(true); $signals = false;

function mih_check($ok, $label)
{
    if (!$ok) throw new RuntimeException('Original image HTTP contract: ' . $label);
    $GLOBALS['checks'][] = $label;
}

function mih_tick()
{
    if ($GLOBALS['interrupted'] !== null) throw new RuntimeException('HTTP fixture interrupted: ' . $GLOBALS['interrupted']);
}

function mih_identity($pid)
{
    $stat = file_get_contents('/proc/' . $pid . '/stat');
    $tail = preg_split('/\s+/', substr($stat, strrpos($stat, ')') + 2));
    $status = file_get_contents('/proc/' . $pid . '/status');
    preg_match('/^Uid:\s+(.*)$/m', $status, $uid); preg_match('/^Gid:\s+(.*)$/m', $status, $gid);
    return ['pid' => (int) $pid, 'ppid' => (int) $tail[1], 'starttime' => $tail[19],
        'uids' => array_map('intval', preg_split('/\s+/', trim($uid[1]))),
        'gids' => array_map('intval', preg_split('/\s+/', trim($gid[1]))),
        'exe' => readlink('/proc/' . $pid . '/exe'), 'cmdline' => str_replace(chr(0), ' ', file_get_contents('/proc/' . $pid . '/cmdline'))];
}

function mih_spawn(array $args, $label, array $env)
{
    $run = $GLOBALS['cfg']['run'];
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $run . '/' . $label . '.stdout', 'w'], 2 => ['file', $run . '/' . $label . '.stderr', 'w']];
    $proc = proc_open($args, $descriptors, $pipes, '/app', $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) throw new RuntimeException('Original process spawn failed: ' . $label);
    $status = proc_get_status($proc);
    $GLOBALS['processes'][$label] = ['handle' => $proc, 'pid' => $status['pid'], 'args' => $args, 'exit' => null, 'closed' => false, 'signals' => []];
    if (!$status['running']) {
        $GLOBALS['processes'][$label]['exit'] = $status['exitcode'];
        return;
    }
    $stat = file_get_contents('/proc/' . $status['pid'] . '/stat');
    $tail = preg_split('/\s+/', substr($stat, strrpos($stat, ')') + 2));
    $GLOBALS['processes'][$label]['identity'] = ['pid' => $status['pid'], 'starttime' => $tail[19]];
}

function mih_running($label)
{
    $p =& $GLOBALS['processes'][$label];
    if ($p['closed']) return false;
    $status = proc_get_status($p['handle']);
    if (!$status['running'] && $p['exit'] === null) $p['exit'] = $status['exitcode'];
    return $status['running'];
}

function mih_wait($label, $seconds, $interruptible = true)
{
    $deadline = microtime(true) + $seconds;
    while (mih_running($label)) {
        if ($interruptible) mih_tick();
        if (microtime(true) >= $deadline) throw new RuntimeException('Owned process timeout: ' . $label);
        usleep(20000);
    }
    $p =& $GLOBALS['processes'][$label];
    $close = proc_close($p['handle']); $p['closed'] = true; unset($p['handle']);
    if ($p['exit'] === null || $p['exit'] < 0) $p['exit'] = $close;
    return $p['exit'];
}

function mih_command(array $args, $label, array $env, $seconds = 30)
{
    mih_spawn($args, $label, $env);
    mih_check(mih_wait($label, $seconds) === 0, 'original_command_exit0_' . $label);
}

function mih_signal($label, $signal)
{
    $p =& $GLOBALS['processes'][$label];
    if (!mih_running($label)) return false;
    // The signal uses only an open proc_open handle, with PID/starttime checked for diagnostics.
    $now = mih_identity($p['pid']);
    if ($now['starttime'] !== $p['identity']['starttime']) throw new RuntimeException('Owned process identity changed: ' . $label);
    if (!proc_terminate($p['handle'], $signal)) throw new RuntimeException('Owned process signal failed: ' . $label);
    $p['signals'][] = $signal;
    return true;
}

function mih_port_open($port)
{
    // A closed-port probe is expected; keep its connect warning out of application diagnostics.
    $previous = null;
    $previous = set_error_handler(function ($level, $message, $file, $line) use (&$previous) {
        if ($level === E_WARNING && $file === __FILE__ && strpos($message, 'stream_socket_client():') === 0) return true;
        return $previous ? $previous($level, $message, $file, $line) : false;
    });
    try { $socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1); }
    finally { restore_error_handler(); }
    if (!$socket) return false;
    fclose($socket); return true;
}

function mih_lines($file)
{
    if (!is_file($file)) return [];
    return array_map(function ($line) { return json_decode($line, true, 512, JSON_THROW_ON_ERROR); }, file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

function mih_snapshot(PDO $db)
{
    $result = [];
    $tables = $db->query("SELECT name,sql FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($tables as $table) {
        $quoted = '"' . str_replace('"', '""', $table['name']) . '"';
        $rows = $db->query('SELECT * FROM ' . $quoted)->fetchAll(PDO::FETCH_ASSOC);
        $encoded = array_map(function ($row) { return json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }, $rows);
        sort($encoded, SORT_STRING);
        $result[$table['name']] = ['rows' => count($rows), 'schema_sha256' => hash('sha256', $table['sql']), 'rows_sha256' => hash('sha256', implode("\n", $encoded))];
    }
    return $result;
}

function mih_request($path, $dynamic, $method = 'GET', array $data = [], $accept = 'text/html')
{
    mih_tick(); $cfg = $GLOBALS['cfg']; $label = sprintf('%03d', count($GLOBALS['clients']) + 1); $id = $cfg['marker'] . ':' . $label;
    $curl = curl_init($cfg['url'] . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '', CURLOPT_USERAGENT => 'OriginalImageHttpFixture',
        CURLOPT_COOKIEFILE => $cfg['run'] . '/cookies', CURLOPT_COOKIEJAR => $cfg['run'] . '/cookies',
        CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'X-Image-Request: ' . $id]]);
    if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    try {
        $raw = curl_exec($curl);
        if ($raw === false) throw new RuntimeException('Real HTTP request failed: ' . $path . ': ' . curl_error($curl));
        $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $response = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
        $client = ['request_id' => $id, 'method' => $method, 'path' => $path, 'status' => $response['status'], 'dynamic' => $dynamic,
            'body_sha256' => hash('sha256', $response['body']), 'content_type' => curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
            'elapsed' => curl_getinfo($curl, CURLINFO_TOTAL_TIME), 'response_body' => $cfg['run'] . '/response-' . $label . '.body',
            'response_headers' => $cfg['run'] . '/response-' . $label . '.headers'];
        $GLOBALS['clients'][] = $client;
        file_put_contents($client['response_body'], $response['body']); file_put_contents($client['response_headers'], $response['headers']);
        file_put_contents($cfg['run'] . '/client-events.jsonl', json_encode($client, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
        curl_setopt($curl, CURLOPT_COOKIELIST, 'FLUSH');
    } finally { unset($curl); }
    if ($dynamic) mih_check(stripos($response['headers'], 'X-Image-Fixture: ' . $cfg['marker']) !== false
        && stripos($response['headers'], 'X-Image-Request: ' . $id) !== false, 'original_fpm_response_marker_' . $label);
    return $response;
}

function mih_asset($path)
{
    mih_check(preg_match('#^/(?:assets/modern|vendor)/[A-Za-z0-9_./-]+\.(?:js|css)$#D', $path)
        && strpos($path, '..') === false && realpath('/app/public' . $path) === '/app/public' . $path, 'owned_original_asset_path_' . $path);
    $sha = hash_file('sha256', '/app/public' . $path);
    $r = mih_request($path, false);
    mih_check($r['status'] === 200 && stripos($r['headers'], substr($path, -3) === '.js' ? 'application/javascript' : 'text/css') !== false
        && hash('sha256', $r['body']) === $sha, 'original_asset_bytes_and_type_' . $path);
    $GLOBALS['filesSHA']['/app/public' . $path] = $sha;
}

try {
    mih_check(PHP_OS_FAMILY === 'Linux' && PHP_VERSION === '8.5.11' && PHP_BINARY === '/usr/local/bin/php' && PHP_SAPI === 'cli'
        && posix_geteuid() === 0 && posix_getegid() === 0, 'original_image_php8511_cli_uid0');
    $uidMap = trim(preg_replace('/\s+/', ':', trim(file_get_contents('/proc/self/uid_map'))));
    $gidMap = trim(preg_replace('/\s+/', ':', trim(file_get_contents('/proc/self/gid_map'))));
    mih_check($uidMap === '0:1000:1' && $gidMap === '0:1000:1', 'single_uid_gid_map_outer1000');
    mih_check(realpath('/probe') === '/probe' && realpath('/app') === '/app' && !is_dir('/app/tests'), 'isolated_app_probe_without_tests');
    mih_check(!file_exists('/probe/http.private') && !is_link('/probe/http.private') && !file_exists('/probe/http-contract.json')
        && !file_exists('/probe/http.started') && !file_exists('/probe/http.completed'), 'fresh_http_run_only');
    if (!mkdir('/probe/http.private', 0700)) throw new RuntimeException('Fresh owned private HTTP directory required.');
    $ownsRun = true; $cfg['marker'] = bin2hex(random_bytes(16));
    file_put_contents($cfg['run'] . '/marker', $cfg['marker']); file_put_contents('/probe/http.started', $cfg['marker']);
    mih_check(fileowner($cfg['run']) === 0 && (fileperms($cfg['run']) & 0777) === 0700, 'owned_private_mode0700');
    foreach (['curl', 'pdo_sqlite', 'gd', 'pcntl', 'posix'] as $extension) mih_check(extension_loaded($extension), 'original_extension_' . $extension);
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGALRM] as $signal) pcntl_signal($signal, function ($signal) { $GLOBALS['interrupted'] = $signal; });
    $signals = true; pcntl_alarm(240);
    foreach (['storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'] as $dir) {
        mih_check(realpath('/app/' . $dir) === '/app/' . $dir, 'original_runtime_directory_' . $dir);
    }
    foreach (['storage/framework/sessions', 'storage/framework/cache/data', 'storage/framework/views'] as $dir) {
        mih_check(count(array_diff(scandir('/app/' . $dir), ['.', '..', '.gitignore'])) === 0, 'fresh_runtime_directory_' . $dir);
    }
    foreach (['config', 'routes', 'packages', 'services', 'events'] as $cache) mih_check(!file_exists('/app/bootstrap/cache/' . $cache . '.php'), 'fresh_cache_' . $cache);
    mih_check(is_file('/app/storage/image-fixture.sqlite') && !is_link('/app/storage/image-fixture.sqlite'), 'fresh_staged_sqlite_file');
    $db = new PDO('sqlite:/app/storage/image-fixture.sqlite'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    mih_check($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN) === [], 'fresh_database_has_no_tables');
    foreach (['/probe/http-contract.php', '/probe/http-prepend.php', '/probe/seed/tests/Fixtures/AdminData.php', '/probe/seed/database/sql/install.sql',
        '/app/database/sql/install.sql', '/app/public/index.php', '/app/bootstrap/app.php', '/app/app/Http/Kernel.php', '/app/routes/web.php',
        '/app/composer.json', '/app/composer.lock', '/app/vendor/autoload.php', '/app/vendor/composer/installed.json',
        '/usr/local/bin/php', '/usr/local/sbin/php-fpm', '/usr/sbin/nginx'] as $file) {
        mih_check(is_file($file) && is_readable($file), 'required_original_input_' . $file); $filesSHA[$file] = hash_file('sha256', $file);
    }
    mih_check($filesSHA['/probe/seed/database/sql/install.sql'] === $filesSHA['/app/database/sql/install.sql'], 'seed_original_schema_bytes');
    $installed = json_decode(file_get_contents('/app/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $lock = json_decode(file_get_contents('/app/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    mih_check($installed['dev'] === false && count($installed['packages']) === 100 && count($lock['packages']) === 100, 'fixed100_no_dev');
    $packages = [];
    foreach ($installed['packages'] as $package) $packages[$package['name']] = $package;
    foreach ($lock['packages'] as $package) mih_check(isset($packages[$package['name']]) && $packages[$package['name']]['version'] === $package['version']
        && ($packages[$package['name']]['source']['reference'] ?? null) === ($package['source']['reference'] ?? null), 'lock_package_' . $package['name']);
    foreach ($lock['packages-dev'] as $package) mih_check(!isset($packages[$package['name']]), 'absent_dev_package_' . $package['name']);
    // Keep both reservations open until the two distinct random high ports have been selected.
    for ($i = 0; $i < 2; $i++) {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) throw new RuntimeException('Loopback port reservation failed: ' . $error);
        $reservations[] = $socket; $ports[] = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    }
    mih_check($ports[0] > 1024 && $ports[1] > 1024 && $ports[0] !== $ports[1], 'distinct_random_high_loopback_ports');
    // Match the untouched helper's native password_hash cost so login does not mutate SQL.
    $bcrypt = password_get_info(password_hash('fixture-cost-probe', PASSWORD_BCRYPT));
    $cfg += ['http_port' => $ports[0], 'fpm_port' => $ports[1], 'url' => 'http://127.0.0.1:' . $ports[0], 'bcrypt_rounds' => $bcrypt['options']['cost']];
    mih_json($cfg['run'] . '/runtime.json', $cfg); $env = mih_environment($cfg);
    mih_observe();
    $loader = require '/app/vendor/autoload.php'; mih_rearm_watch(); $app = require '/app/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    mih_check($app->version() === '13.35.0' && $app->basePath() === '/app' && $app->storagePath() === '/app/storage'
        && $app->environment() === 'production' && $app['config']->get('app.debug') === false
        && $app['config']->get('database.default') === 'sqlite' && $app['config']->get('database.connections.sqlite.database') === '/app/storage/image-fixture.sqlite', 'original_seed_application_identity');
    mih_check(array_keys(Composer\Autoload\ClassLoader::getRegisteredLoaders()) === ['/app/vendor'], 'sole_seed_original_vendor');
    mih_check(!$app->configurationIsCached() && !$app->routesAreCached(), 'original_seed_cold_boot');
    require '/probe/seed/tests/Fixtures/AdminData.php'; Tests\Fixtures\AdminData::create(false);
    mih_check(!file_exists('/app/install.lock')&&!is_link('/app/install.lock'), 'fresh_synthetic_install_marker');
    file_put_contents('/app/install.lock','Synthetic original-image HTTP fixture');
    $filesSHA['/app/install.lock']=hash_file('sha256','/app/install.lock');
    $sql = file_get_contents('/probe/seed/database/sql/install.sql'); $quote = chr(96);
    preg_match_all('/CREATE TABLE ' . $quote . '([^' . $quote . ']+)' . $quote . ' \((.*?)\n\) ENGINE=/s', $sql, $matches, PREG_SET_ORDER);
    $expectedTables = ['fixture_marker']; foreach ($matches as $match) if ($match[1] !== 'failed_jobs') $expectedTables[] = $match[1]; sort($expectedTables);
    $actualTables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    mih_check($actualTables === $expectedTables, 'exact_original_helper_schema_table_set');
    mih_check(!$app['hash']->needsRehash($db->query('SELECT password FROM admin_users')->fetchColumn()), 'native_fixture_password_cost_matches_config');
    mih_check((int) $db->query('SELECT count(*) FROM admin_users')->fetchColumn() === 1
        && $db->query('SELECT username FROM admin_users')->fetchColumn() === 'fixture-admin'
        && (int) $db->query('SELECT count(*) FROM pays')->fetchColumn() === 1
        && $db->query('SELECT merchant_key FROM pays')->fetchColumn() === '', 'synthetic_account_and_payment_only');
    Illuminate\Support\Facades\Cache::forever('system-setting', ['title' => 'Image Fixture Store', 'text_logo' => 'Image Fixture Store',
        'template' => 'modern', 'language' => 'zh_CN', 'order_expire_time' => 5, 'driver' => 'array', 'is_open_img_code' => 0,
        'is_open_geetest' => 0, 'is_open_server_jiang' => 0, 'is_open_telegram_push' => 0, 'is_open_bark_push' => 0, 'is_open_qywxbot_push' => 0]);
    mih_check($GLOBALS['mih_observer']['installed'] && $GLOBALS['mih_observer']['bound_before_vendor']
        && count($GLOBALS['mih_observer']['applications']) === 1 && !$GLOBALS['mih_observer']['issues'] && error_get_last() === null, 'seed_boot_observer_no_issues');
    $seed = ['helper' => '/probe/seed/tests/Fixtures/AdminData.php', 'helper_sha256' => $filesSHA['/probe/seed/tests/Fixtures/AdminData.php'],
        'schema_sha256' => $filesSHA['/probe/seed/database/sql/install.sql'], 'tables' => $actualTables, 'table_count' => count($actualTables),
        'framework' => $app->version(), 'php' => PHP_VERSION, 'observer' => $GLOBALS['mih_observer'], 'included' => get_included_files()];
    $app['db']->disconnect();
    foreach (['config:cache', 'route:cache', 'view:cache'] as $command) mih_command([PHP_BINARY, '/app/artisan', $command, '--no-interaction'], str_replace(':', '-', $command), $env, 45);
    mih_check(is_file('/app/bootstrap/cache/config.php') && is_file('/app/bootstrap/cache/routes.php')
        && is_file('/app/bootstrap/cache/packages.php') && count(glob('/app/storage/framework/views/*.php')) > 0, 'original_commands_produced_real_caches');
    $before = mih_snapshot($db); mih_json($cfg['run'] . '/business-before.json', $before); mih_json($cfg['run'] . '/seed.json', $seed);
    foreach (['client', 'proxy', 'fastcgi', 'uwsgi', 'scgi', 'upload'] as $dir) mkdir($cfg['run'] . '/' . $dir, 0700);
    $run = $cfg['run']; $fpmPort = $cfg['fpm_port']; $httpPort = $cfg['http_port'];
    // Root is meaningful only inside the single0->outer1000 fixture user namespace.
    $fpmConf = "[global]\npid = $run/fpm.pid\nerror_log = $run/fpm.log\ndaemonize = no\n[image-http]\nuser = root\ngroup = root\nlisten = 127.0.0.1:$fpmPort\nlisten.allowed_clients = 127.0.0.1\npm = static\npm.max_children = 1\nclear_env = yes\nenv[IMAGE_HTTP_RUN] = $run\ncatch_workers_output = yes\nrequest_terminate_timeout = 15s\nsecurity.limit_extensions = .php\naccess.log = $run/fpm-access.log\naccess.format = \"%{HTTP_X_IMAGE_REQUEST}e|%p|%m|%{REQUEST_URI}e|%s|%f\"\nphp_admin_value[auto_prepend_file] = /probe/http-prepend.php\nphp_admin_value[upload_tmp_dir] = $run/upload\nphp_admin_value[session.save_path] = /app/storage/framework/sessions\nphp_admin_value[error_reporting] = 32767\nphp_admin_flag[display_errors] = off\nphp_admin_flag[log_errors] = on\nphp_admin_value[error_log] = $run/php-errors.log\nphp_admin_value[opcache.validate_timestamps] = 0\n";
    file_put_contents($run . '/fpm.conf', $fpmConf);
    $nginxConf = <<<'NGINX'
user root root;
daemon off;
master_process on;
worker_processes 1;
pid /probe/http.private/nginx.pid;
error_log /probe/http.private/nginx.log notice;
events { worker_connections 64; }
http {
    log_format image escape=json '{"request_id":"$http_x_image_request","method":"$request_method","uri":"$request_uri","status":$status,"filename":"$request_filename","upstream":"$upstream_addr","upstream_status":"$upstream_status"}';
    access_log /probe/http.private/access.log image;
    client_body_temp_path /probe/http.private/client;
    proxy_temp_path /probe/http.private/proxy;
    fastcgi_temp_path /probe/http.private/fastcgi;
    uwsgi_temp_path /probe/http.private/uwsgi;
    scgi_temp_path /probe/http.private/scgi;
    types { application/javascript js; text/css css; application/json json; image/svg+xml svg; image/png png; font/woff woff; font/woff2 woff2; }
    default_type application/octet-stream;
    server {
        listen 127.0.0.1:HTTP_PORT;
        server_name 127.0.0.1;
        root /app/public;
        index index.php;
        location / { try_files $uri $uri/ /index.php?$query_string; }
        location ~ /\. { return 404; }
        location ~ \.php$ { return 404; }
        location = /index.php {
            fastcgi_pass 127.0.0.1:FPM_PORT;
            fastcgi_read_timeout 15s;
            fastcgi_param SCRIPT_FILENAME /app/public/index.php;
            fastcgi_param SCRIPT_NAME /index.php;
            fastcgi_param QUERY_STRING $query_string;
            fastcgi_param REQUEST_METHOD $request_method;
            fastcgi_param CONTENT_TYPE $content_type;
            fastcgi_param CONTENT_LENGTH $content_length;
            fastcgi_param REQUEST_URI $request_uri;
            fastcgi_param DOCUMENT_URI $document_uri;
            fastcgi_param DOCUMENT_ROOT /app/public;
            fastcgi_param SERVER_PROTOCOL $server_protocol;
            fastcgi_param GATEWAY_INTERFACE CGI/1.1;
            fastcgi_param SERVER_SOFTWARE nginx;
            fastcgi_param REMOTE_ADDR $remote_addr;
            fastcgi_param REMOTE_PORT $remote_port;
            fastcgi_param SERVER_ADDR $server_addr;
            fastcgi_param SERVER_PORT $server_port;
            fastcgi_param SERVER_NAME $server_name;
            fastcgi_param HTTP_HOST $http_host;
            fastcgi_param HTTP_COOKIE $http_cookie;
            fastcgi_param HTTP_ACCEPT $http_accept;
            fastcgi_param HTTP_USER_AGENT $http_user_agent;
            fastcgi_param HTTP_X_IMAGE_REQUEST $http_x_image_request;
            fastcgi_param REDIRECT_STATUS 200;
        }
    }
}
NGINX;
    file_put_contents($run . '/nginx.conf', str_replace(['HTTP_PORT', 'FPM_PORT'], [(string) $httpPort, (string) $fpmPort], $nginxConf) . PHP_EOL);
    $fpmArgs = ['/usr/local/sbin/php-fpm', '-R', '-y', $run . '/fpm.conf'];
    $nginxArgs = ['/usr/sbin/nginx', '-p', $run . '/', '-c', $run . '/nginx.conf', '-e', $run . '/nginx.log'];
    mih_command(array_merge($fpmArgs, ['-tt']), 'fpm-config', $env, 10); mih_command(array_merge($nginxArgs, ['-t']), 'nginx-config', $env, 10);
    foreach ($reservations as $socket) fclose($socket); $reservations = [];
    mih_spawn(array_merge($fpmArgs, ['-F']), 'fpm', $env); mih_spawn($nginxArgs, 'nginx', $env);
    $deadline = microtime(true) + 10;
    do {
        mih_tick();
        if (!mih_running('fpm') || !mih_running('nginx')) throw new RuntimeException('Original service exited before both listeners ready.');
        $ready = mih_port_open($fpmPort) && mih_port_open($httpPort);
        if ($ready) break;
        if (microtime(true) >= $deadline) throw new RuntimeException('Original listener readiness exceeded 10 seconds.');
        usleep(20000);
    } while (true);
    mih_check($ready, 'original_fpm_nginx_both_real_listeners_ready');
    foreach (['fpm', 'nginx'] as $label) {
        $p = $processes[$label]; $identities[$label] = mih_identity($p['pid']);
        $childrenFile = '/proc/' . $p['pid'] . '/task/' . $p['pid'] . '/children';
        $children = trim(file_get_contents($childrenFile));
        foreach ($children === '' ? [] : preg_split('/\s+/', $children) as $pid) $identities[$label . '-child-' . $pid] = mih_identity((int) $pid);
    }
    foreach ($identities as $identity) mih_check($identity['uids'] === [0, 0, 0, 0] && $identity['gids'] === [0, 0, 0, 0], 'mapped_root_process_' . $identity['pid']);
    $home = mih_request('/', true); mih_check($home['status'] === 200 && strpos($home['body'], 'Fixture Auto Goods') !== false, 'original_cached_vue_home200');
    $manifest = json_decode(file_get_contents('/app/public/assets/modern/manifest.json'), true, 512, JSON_THROW_ON_ERROR)['src/main.ts'];
    mih_check(substr($manifest['file'], -3) === '.js' && !empty($manifest['css']), 'original_vue_js_css_manifest');
    foreach (array_merge([$manifest['file']], $manifest['css']) as $asset) mih_asset('/assets/modern/' . $asset);
    $buy = mih_request('/buy/1', true); mih_check($buy['status'] === 200 && strpos($buy['body'], 'Fixture Pay') !== false, 'original_buy200');
    $captcha = mih_request('/captcha/default', true); $size = getimagesizefromstring($captcha['body']);
    mih_check($captcha['status'] === 200 && stripos($captcha['headers'], 'Content-Type: image/') !== false
        && $size !== false && $size[0] === 120 && $size[1] === 36, 'original_native_captcha_decoded120x36');
    $loginPage = mih_request('/admin/auth/login', true); mih_check($loginPage['status'] === 200, 'original_admin_login_page200');
    preg_match('/name="_token"[^>]*value="([^"]+)"/', $loginPage['body'], $token); mih_check(!empty($token[1]), 'original_login_csrf_field');
    $bad = mih_request('/admin/auth/login', true, 'POST', ['username' => 'fixture-admin', 'password' => 'fixture-password'], 'application/json');
    mih_check($bad['status'] === 419, 'original_missing_csrf419');
    $login = mih_request('/admin/auth/login', true, 'POST', ['_token' => html_entity_decode($token[1], ENT_QUOTES), 'username' => 'fixture-admin', 'password' => 'fixture-password'], 'application/json');
    mih_check($login['status'] === 200 && !empty(json_decode($login['body'], true, 512, JSON_THROW_ON_ERROR)['status']), 'original_synthetic_login200');
    $goods = mih_request('/admin/goods', true); mih_check($goods['status'] === 200 && strpos($goods['body'], 'Fixture Auto Goods') !== false, 'original_authenticated_goods_grid200');
    $assets = [];
    foreach ([$loginPage['body'], $goods['body']] as $html) {
        preg_match_all('/(?:src|href)="([^"]+)"/', $html, $matches);
        foreach ($matches[1] as $url) {
            $path = parse_url(html_entity_decode($url, ENT_QUOTES), PHP_URL_PATH);
            if ($path && strpos($path, '/vendor/') === 0 && preg_match('/\.(js|css)$/D', $path)) $assets[$path] = true;
        }
    }
    mih_check(count($assets) > 3, 'actual_rendered_original_admin_assets');
    foreach (array_keys($assets) as $path) mih_asset($path);
    $logout = mih_request('/admin/auth/logout', true); mih_check($logout['status'] === 302, 'original_logout302');
    mih_check(mih_request('/admin/goods', true)['status'] === 302, 'original_logout_revokes_grid302');
    foreach (['/.env' => false, '/composer.json' => true, '/vendor/autoload.php' => false, '/storage/logs/laravel.log' => true] as $path => $dynamic) {
        mih_check(mih_request($path, $dynamic)['status'] === 404, 'public_boundary404_' . $path);
    }
    // fastcgi_finish_request flushes HTTP before PHP shutdown observers finish.
    $drainDeadline=microtime(true)+10;
    do {
        mih_tick();
        if(is_file($run.'/http-events.jsonl')&&count(mih_lines($run.'/http-events.jsonl'))===11
            &&is_file($run.'/fpm-access.log')&&count(file($run.'/fpm-access.log',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES))===11)break;
        if(microtime(true)>=$drainDeadline)throw new RuntimeException('Original shutdown observer/request drain timed out.');
        usleep(20000);
    } while(true);
    // Drain requests before comparing FPM shutdown events and the SQL snapshots.
    foreach (['fpm', 'nginx'] as $label) {
        mih_check(mih_signal($label, SIGQUIT), 'original_' . $label . '_sigquit_sent');
        mih_check(mih_wait($label, 15) === 0, 'original_' . $label . '_graceful_exit0');
    }
    foreach ($ports as $port) mih_check(!mih_port_open($port), 'listener_closed_' . $port);
    $after = mih_snapshot($db); mih_json($run . '/business-after.json', $after);
    mih_check($before === $after, 'all_sql_tables_schema_rows_hashes_unchanged');
    $native = mih_lines($run . '/http-events.jsonl'); $nginxAccess = mih_lines($run . '/access.log');
    foreach (file($run . '/fpm-access.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $fields = explode('|', $line);
        mih_check(count($fields) === 6, 'original_fpm_access_format');
        $fpmAccess[] = ['request_id' => $fields[0], 'pid' => (int) $fields[1], 'method' => $fields[2], 'path' => $fields[3], 'status' => (int) $fields[4], 'script' => $fields[5]];
    }
    mih_check(count($native) === 11 && count($fpmAccess) === 11 && count($nginxAccess) === count($clients), 'exact11_fpm_and_all_nginx_client_requests');
    $seen = [];
    foreach ($clients as $client) {
        $id = $client['request_id'];
        $a = array_values(array_filter($nginxAccess, function ($e) use ($id) { return $e['request_id'] === $id; }));
        $n = array_values(array_filter($native, function ($e) use ($id) { return $e['request_id'] === $id; }));
        $f = array_values(array_filter($fpmAccess, function ($e) use ($id) { return $e['request_id'] === $id; }));
        mih_check(!isset($seen[$id]) && count($a) === 1 && $a[0]['method'] === $client['method'] && $a[0]['uri'] === $client['path']
            && $a[0]['status'] === $client['status'], 'unique_client_nginx_' . $id); $seen[$id] = true;
        mih_check(count($n) === ($client['dynamic'] ? 1 : 0) && count($f) === count($n), 'exact_dynamic_or_static_fpm_' . $id);
        if ($client['dynamic']) {
            mih_check($a[0]['upstream'] === '127.0.0.1:' . $fpmPort && $a[0]['upstream_status'] === (string) $client['status'], 'original_nginx_upstream_' . $id);
            foreach ([$n[0], $f[0]] as $event) mih_check($event['method'] === $client['method'] && $event['path'] === $client['path']
                && $event['status'] === $client['status'] && $event['script'] === '/app/public/index.php', 'three_way_path_status_' . $id);
            mih_check($n[0]['pid'] === $f[0]['pid'] && isset($identities['fpm-child-' . $f[0]['pid']]), 'actual_original_fpm_worker_' . $id);
        } else mih_check($a[0]['upstream'] === '' || $a[0]['upstream'] === '-', 'nginx_only_static_' . $id);
    }
    foreach ($native as $event) {
        mih_check($event['inspection_error'] === null && $event['marker'] === $cfg['marker'] && $event['uid'] === 0 && $event['gid'] === 0
            && $event['php'] === '8.5.11' && $event['sapi'] === 'fpm-fcgi' && $event['framework'] === '13.35.0', 'actual_native_fpm_identity_' . $event['request_id']);
        mih_check($event['base'] === '/app' && $event['storage'] === '/app/storage' && $event['db'] === '/app/storage/image-fixture.sqlite'
            && $event['db_default'] === 'sqlite' && $event['environment'] === 'production' && $event['debug'] === false
            && $event['config_cached'] && $event['routes_cached'] && $event['session'] === 'file'
            && $event['session_files'] === '/app/storage/framework/sessions' && $event['cache'] === 'file' && $event['queue'] === 'sync', 'actual_cached_fixture_paths_' . $event['request_id']);
        mih_check($event['app_type'] === 'Illuminate\\Foundation\\Application' && $event['kernel_type'] === 'App\\Http\\Kernel'
            && $event['app_source'] === '/app/app/Http/Kernel.php' && $event['vendor_source'] === '/app/vendor/laravel/framework/src/Illuminate/Foundation/Application.php'
            && $event['index_sha256'] === $filesSHA['/app/public/index.php'], 'original_app_and_front_controller_' . $event['request_id']);
        mih_check($event['loaders'] === ['/app/vendor'] && $event['loader_types'] === ['Composer\\Autoload\\ClassLoader'] && $event['installed_dev'] === false
            && !$event['outside'] && !$event['loader_paths_outside'] && !$event['dev_loaded'] && !$event['dev_mapped'], 'sole_no_dev_original_http_loader_' . $event['request_id']);
        mih_check($event['php_observer']['installed'] && $event['php_observer']['bound_before_vendor'] && !$event['php_observer']['issues']
            && count($event['php_observer']['applications']) === 1 && $event['php_observer']['applications'][0]['base'] === '/app'
            && $event['last_php_error'] === null, 'fresh_http_app_observer_no_issues_' . $event['request_id']);
        foreach ($event['sourceIncludes'] as $file => $sha) {
            mih_check(!isset($sourceIncludes[$file]) || $sourceIncludes[$file] === $sha, 'stable_loaded_source_' . $file); $sourceIncludes[$file] = $sha;
        }
    }
    foreach ($filesSHA + $sourceIncludes as $file => $sha) mih_check(hash_file('sha256', $file) === $sha, 'original_input_bytes_unchanged_' . $file);
    foreach (glob($run . '/*.stderr') as $file) mih_check(!preg_match('/(?:^|[\r\n])(?:PHP )?(?:Fatal error|Warning|Notice|Deprecated|Parse error):/', file_get_contents($file)), 'no_raw_php_issue_' . basename($file));
    mih_check(!is_file($run . '/php-errors.log') || filesize($run . '/php-errors.log') === 0, 'no_fpm_php_engine_log_issue');
    mih_check(!is_file('/app/storage/logs/laravel.log') || !preg_match('/[.](ERROR|CRITICAL|ALERT|EMERGENCY):/', file_get_contents('/app/storage/logs/laravel.log')), 'no_application_exception_log');
    mih_check(!$GLOBALS['mih_observer']['issues'] && error_get_last() === null && $interrupted === null, 'no_cli_issue_or_interrupt');
    $completed = true;
} catch (Throwable $e) {
    $failure = ['class' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    if ($signals) { pcntl_alarm(0); foreach ([SIGTERM, SIGINT, SIGALRM] as $signal) pcntl_signal($signal, SIG_IGN); }
    foreach ($reservations as $socket) if (is_resource($socket)) fclose($socket);
    foreach (array_keys($processes) as $label) {
        if ($processes[$label]['closed']) continue;
        try {
            if (mih_running($label)) {
                $cleanup[] = 'Required forced cleanup: ' . $label;
                mih_signal($label, SIGTERM);
                try { mih_wait($label, 2, false); }
                catch (Throwable $e) { mih_signal($label, SIGKILL); mih_wait($label, 2, false); }
            } else mih_wait($label, 1, false);
        } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    }
    foreach ($ports as $port) if (mih_port_open($port)) $cleanup[] = 'Listener still open: ' . $port;
    $db = null;
    if ($app) try { $app['db']->disconnect(); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    // The marker is written only after real requests, evidence reconciliation and graceful shutdown.
    $status = $completed && !$failure && !$cleanup && $interrupted === null ? 'PASS' : 'FAIL';
    $logs = [];
    if ($ownsRun) {
        foreach (glob($cfg['run'] . '/*') as $file) if (is_file($file) && !is_link($file)) $logs[$file] = ['bytes' => filesize($file), 'sha256' => hash_file('sha256', $file)];
    }
    $result = ['status' => $status, 'completed' => $status === 'PASS', 'marker' => $cfg['marker'] ?? null, 'completion_marker' => '/probe/http.completed',
        'checks' => count($checks), 'check_names' => $checks, 'requests' => count($clients), 'fpm_requests' => count($native), 'fpm_access_requests' => count($fpmAccess),
        'elapsed' => microtime(true) - $started, 'failure' => $failure, 'cleanup_errors' => $cleanup, 'interrupted' => $interrupted,
        'php' => PHP_VERSION, 'binary' => PHP_BINARY, 'sapi' => PHP_SAPI, 'uid' => function_exists('posix_geteuid') ? posix_geteuid() : null, 'pid' => getmypid(),
        'uid_map' => $uidMap ?? null, 'gid_map' => $gidMap ?? null, 'runtime' => $cfg, 'ports' => $ports,
        'processes' => array_map(function ($p) { unset($p['handle']); return $p; }, $processes), 'processidentities' => $identities,
        'clients' => $clients, 'nginx' => $nginxAccess, 'fpm' => $native, 'fpm_access' => $fpmAccess, 'seed' => $seed,
        'business_before' => $before, 'business_after' => $after, 'filesSHA' => $filesSHA, 'sourceIncludes' => $sourceIncludes, 'logs' => $logs,
        'scope' => 'Original digest-staged image PHP8.5.11/FPM/Nginx, isolated fresh /app plus fixed100 no-dev vendor, real loopback HTTP only. UID0 in single0->outer1000 namespace; -R/root pool and explicit Nginx root user apply only here. Synthetic SQLite/AdminData seed from original schema, file sessions, original public/index.php and unchanged binary/source bytes. No stock entrypoint/user-drop/cron/Engine claim. External parent owns fresh rootfs staging and independent validation.'];
    try {
        if ($ownsRun) {
            mih_json('/probe/http-contract.json', $result);
            if ($status === 'PASS' && file_put_contents('/probe/http.completed', $cfg['marker'], LOCK_EX) !== strlen($cfg['marker'])) throw new RuntimeException('HTTP completion marker write failed.');
        }
    } catch (Throwable $e) {
        $status = 'FAIL'; $result['status'] = 'FAIL'; $result['completed'] = false; $result['failure'] = ['class' => get_class($e), 'message' => $e->getMessage()];
        if ($ownsRun) { if (is_file('/probe/http.completed')) unlink('/probe/http.completed'); mih_json('/probe/http-contract.json', $result); }
    }
    echo json_encode(['status' => $status, 'completed' => $status === 'PASS', 'checks' => count($checks), 'requests' => count($clients), 'fpm_requests' => count($native), 'marker' => $cfg['marker'] ?? null, 'output' => '/probe/http-contract.json'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
}
exit($status === 'PASS' ? 0 : 1);
