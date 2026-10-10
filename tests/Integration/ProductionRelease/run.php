<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/LinuxLifecycle/process.php';
require dirname(__DIR__) . '/ScheduleWorkerLifecycle/process.php';

require __DIR__ . '/command.php';

function pr_request($path, $method = 'GET', array $data = [], $accept = 'text/html')
{
    $cfg = $GLOBALS['pr_cfg'];
    if ($GLOBALS['pr_interrupted'] !== null) throw new RuntimeException('Release acceptance interrupted.');
    sw_track_children();
    $label = sprintf('%03d', ++$GLOBALS['pr_requests']); $id = $cfg['marker'] . ':' . $label;
    $curl = curl_init($cfg['url'] . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 45, CURLOPT_PROXY => '', CURLOPT_USERAGENT => 'ProductionReleaseFixture44', CURLOPT_COOKIEFILE => $cfg['run'] . '/cookies',
        CURLOPT_COOKIEJAR => $cfg['run'] . '/cookies', CURLOPT_HTTPHEADER => ['Accept: ' . $accept, 'X-Release-Request: ' . $id]]);
    if ($method === 'POST') curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    $raw = curl_exec($curl); if ($raw === false) throw new RuntimeException('Release HTTP failed: ' . curl_error($curl));
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'head' => substr($raw, 0, $size), 'body' => substr($raw, $size)]; unset($curl);
    file_put_contents($cfg['run'] . '/request-' . $label . '.json', json_encode(['path' => $path, 'method' => $method, 'data' => $data, 'accept' => $accept], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    file_put_contents($cfg['run'] . '/response-' . $label . '.body', $result['body']);
    file_put_contents($cfg['run'] . '/response-' . $label . '.headers', $result['head']);
    ql_log('Response', ['request' => $label, 'request_id' => $id, 'path' => $path, 'method' => $method, 'status' => $result['status'], 'body_sha256' => hash('sha256', $result['body'])]);
    return $result;
}

function pr_lines($file)
{
    return array_map(function ($line) { return json_decode($line, true, 512, JSON_THROW_ON_ERROR); }, file($file, FILE_SKIP_EMPTY_LINES));
}

if (PHP_OS_FAMILY !== 'Linux' || PHP_VERSION !== '8.5.11' || posix_geteuid() !== 1000) throw new RuntimeException('Existing Linux PHP8.5.11 UID1000 required.');
$cfg = pr_manifest($argv[1] ?? '');
$run = $cfg['run']; $release = $cfg['release'];
$stage = json_decode(file_get_contents($run . '/stage-summary.json'), true, 512, JSON_THROW_ON_ERROR);
if ($stage['status'] !== 'PASS' || file_exists($run . '/runtime-summary.json')) throw new RuntimeException('Fresh successfully staged release required.');
$cfg['transactions'] = in_array('--transactions', $argv, true);
ql_json($run . '/run.json', $cfg);
$GLOBALS['ql_run'] = $run; $GLOBALS['ql_role'] = 'release-runtime'; $GLOBALS['ql_config'] = ['root' => $cfg['root'], 'marker' => $cfg['marker']];
$GLOBALS['ll_processes'] = []; $GLOBALS['sw_owned_children'] = []; $GLOBALS['sw_groups'] = [];
$GLOBALS['pr_requests'] = 0; $interrupted = null; $GLOBALS['pr_interrupted'] =& $interrupted;
$failure = null; $cleanup = []; $before = []; $started = microtime(true); $native = []; $clients = [];
pcntl_async_signals(true);
foreach ([SIGTERM, SIGINT, SIGALRM] as $signal) pcntl_signal($signal, function ($signal) use (&$interrupted) { $interrupted = $signal; });
pcntl_alarm(270);
try {
    pr_environment($cfg);
    foreach (['composer.json', 'composer.lock', 'artisan', 'public/index.php', 'bootstrap/app.php', 'app/Http/Kernel.php', 'app/Providers/AppServiceProvider.php', 'config/admin.php', 'routes/web.php'] as $file) $before[$file] = hash_file('sha256', $cfg['root'] . '/' . $file);
    ql_json($run . '/protected-before-runtime.json', $before);
    $php = [PHP_BINARY, '-c', $cfg['ini']];
    pr_command(array_merge($php, [__DIR__ . '/seed.php', $run]), 'seed');
    pr_command(array_merge($php, [__DIR__ . '/captcha-contract.php', $run, 'cold']), 'captcha-cold');
    $captchaConfigHash = hash_file('sha256', $release . '/config/captcha.php');
    pr_command(array_merge($php, [$release . '/artisan', 'vendor:publish', '--provider=Mews' . chr(92) . 'Captcha' . chr(92) . 'CaptchaServiceProvider', '--tag=config', '--no-interaction']), 'captcha-publish');
    $captchaPublish = file_get_contents($run . '/captcha-publish.stdout');
    ql_check(strpos($captchaPublish, 'Publishing') !== false && strpos($captchaPublish, 'No publishable resources') === false
        && hash_file('sha256', $release . '/config/captcha.php') === $captchaConfigHash, 'native_original_provider_publish_preserves_existing_config');
    pr_command(array_merge($php, [$release . '/artisan', 'package:discover', '--no-interaction']), 'discover');
    pr_owned_path($cfg, $release . '/public/vendor/dcat-admin');
    $stale = pr_hashes($release . '/public/vendor/dcat-admin');
    $dist = pr_hashes($release . '/vendor/dcat-x/laravel-admin/resources/dist');
    ql_json($run . '/assets-before.json', ['published' => $stale, 'dist' => $dist, 'match' => $stale === $dist]);
    ql_check($stale !== $dist && $dist, 'original_public_assets_are_not_candidate_dist');
    // A new release must not retain files removed from the newer Dcat asset distribution.
    $assetDirectory = $release . '/public/vendor/dcat-admin';
    if (is_dir($assetDirectory)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assetDirectory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) { if ($file->isLink()) throw new RuntimeException('Owned asset link detected.'); if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
        rmdir($assetDirectory);
    }
    pr_owned_path($cfg, $assetDirectory);
    pr_command(array_merge($php, [$release . '/artisan', 'admin:publish', '--assets', '--force', '--no-interaction']), 'publish-assets');
    pr_owned_path($cfg, $assetDirectory);
    $published = pr_hashes($release . '/public/vendor/dcat-admin');
    ql_check($published === $dist, 'original_admin_publish_exact_candidate_path_and_bytes');
    ql_json($run . '/assets-published.json', ['status' => 'PASS', 'hashes' => $published]);
    foreach (['config:cache', 'route:cache', 'view:cache'] as $command) pr_command(array_merge($php, [$release . '/artisan', $command, '--no-interaction']), str_replace(':', '-', $command), 60);
    pr_command(array_merge($php, [__DIR__ . '/captcha-contract.php', $run, 'cached']), 'captcha-cached');
    ql_check(is_file($release . '/bootstrap/cache/config.php') && is_file($release . '/bootstrap/cache/routes.php')
        && is_file($release . '/bootstrap/cache/packages.php') && count(glob($release . '/storage/framework/views/*.php')) > 0, 'actual_original_config_route_package_view_caches');
    $db = new PDO('sqlite:' . $release . '/storage/fixture.sqlite');
    $initial = $db->query('SELECT * FROM orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Release FPM port required.');
    $fpmPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
    $cfg['fpm_port'] = $fpmPort; ql_json($run . '/run.json', $cfg); $GLOBALS['pr_cfg'] = $cfg;
    $port = $cfg['port'];
    $fpm = $cfg['root'] . '/tests/.runtime/http42/rootfs/usr/sbin/php-fpm8.5';
    $nginx = $cfg['root'] . '/tests/.runtime/http42/rootfs/usr/sbin/nginx';
    file_put_contents($run . '/fpm.conf', "[global]\npid = $run/fpm.pid\nerror_log = $run/fpm.log\ndaemonize = no\n[release]\nlisten = 127.0.0.1:$fpmPort\nlisten.allowed_clients = 127.0.0.1\npm = static\npm.max_children = 1\nclear_env = yes\nenv[PRODUCTION_RELEASE_RUN] = $run\ncatch_workers_output = yes\nrequest_terminate_timeout = 45s\nsecurity.limit_extensions = .php\nphp_admin_value[auto_prepend_file] = " . __DIR__ . "/prepend.php\nphp_admin_value[upload_tmp_dir] = $run/tmp\nphp_admin_flag[display_errors] = off\nphp_admin_flag[log_errors] = on\nphp_admin_value[error_log] = $run/php-errors.log\nphp_admin_value[opcache.validate_timestamps] = 0\n");
    $public = $release . '/public';
    $format = <<<'NGINX'
log_format release escape=json '{"request_id":"$http_x_release_request","method":"$request_method","uri":"$request_uri","status":$status}';
NGINX;
    file_put_contents($run . '/nginx.conf', "daemon off;\nmaster_process on;\nworker_processes 1;\npid $run/nginx.pid;\nerror_log $run/nginx.log notice;\nevents { worker_connections 32; }\nhttp {\n $format\n access_log $run/access.log release;\n client_body_temp_path $run/tmp/client;\n proxy_temp_path $run/tmp/proxy;\n fastcgi_temp_path $run/tmp/fastcgi;\n uwsgi_temp_path $run/tmp/uwsgi;\n scgi_temp_path $run/tmp/scgi;\n types { application/javascript js; text/css css; application/json json; image/svg+xml svg; image/png png; font/woff woff; font/woff2 woff2; }\n server { listen 127.0.0.1:$port; server_name 127.0.0.1; root $public; index index.php;\n location / { try_files \$uri \$uri/ /index.php?\$query_string; }\n location ~ /\\. { return 404; }\n location ~ \\.php$ { return 404; }\n location = /index.php { fastcgi_pass 127.0.0.1:$fpmPort;\n fastcgi_param SCRIPT_FILENAME $public/index.php; fastcgi_param SCRIPT_NAME /index.php;\n fastcgi_param QUERY_STRING \$query_string; fastcgi_param REQUEST_METHOD \$request_method;\n fastcgi_param CONTENT_TYPE \$content_type; fastcgi_param CONTENT_LENGTH \$content_length;\n fastcgi_param REQUEST_URI \$request_uri; fastcgi_param DOCUMENT_URI \$document_uri; fastcgi_param DOCUMENT_ROOT $public;\n fastcgi_param SERVER_PROTOCOL \$server_protocol; fastcgi_param GATEWAY_INTERFACE CGI/1.1; fastcgi_param SERVER_SOFTWARE nginx;\n fastcgi_param REMOTE_ADDR \$remote_addr; fastcgi_param REMOTE_PORT \$remote_port;\n fastcgi_param SERVER_ADDR \$server_addr; fastcgi_param SERVER_PORT \$server_port; fastcgi_param SERVER_NAME \$server_name; fastcgi_read_timeout 45s; }\n }\n}\n");
    $fpmArgs = [$fpm, '-c', $cfg['ini'], '-y', $run . '/fpm.conf']; $nginxArgs = [$nginx, '-p', $run . '/', '-c', $run . '/nginx.conf', '-e', $run . '/nginx.log'];
    pr_command(array_merge($fpmArgs, ['-tt']), 'fpm-config'); pr_command(array_merge($nginxArgs, ['-t']), 'nginx-config');
    sw_spawn(array_merge($fpmArgs, ['-F']), 'fpm'); sw_spawn($nginxArgs, 'nginx');
    ll_until(function () use ($cfg, $fpmPort, &$interrupted) {
        sw_track_children();
        if ($interrupted !== null) throw new RuntimeException('Release startup interrupted.');
        foreach (['fpm', 'nginx'] as $label) if (!proc_get_status($GLOBALS['ll_processes'][$label]['proc'])['running']) throw new RuntimeException('Release service stopped before ready.');
        foreach ([$cfg['port'], $fpmPort] as $port) { $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2); if (!$s) return false; fclose($s); } return true;
    }, 'production release both native listeners', 10);
    if (in_array('--fail-after-start', $argv, true)) throw new RuntimeException('Controlled production release post-start failure.');
    $home = pr_request('/'); ql_check($home['status'] === 200 && strpos($home['body'], 'Fixture Auto Goods') !== false, 'production_cached_original_vue_home');
    $manifest = json_decode(file_get_contents($public . '/assets/modern/manifest.json'), true, 512, JSON_THROW_ON_ERROR)['src/main.ts'];
    foreach (array_merge([$manifest['file']], $manifest['css']) as $asset) { $r = pr_request('/assets/modern/' . $asset); ql_check($r['status'] === 200 && strpos($r['head'], substr($asset, -3) === '.js' ? 'application/javascript' : 'text/css') !== false && hash('sha256', $r['body']) === hash_file('sha256', $public . '/assets/modern/' . $asset), 'production_original_vue_asset_bytes'); }
    $buy = pr_request('/buy/1'); ql_check($buy['status'] === 200 && strpos($buy['body'], 'Fixture Pay') !== false, 'production_cached_original_buy');
    $captcha = pr_request('/captcha/default'); $captchaImage = getimagesizefromstring($captcha['body']);
    ql_check($captcha['status'] === 200 && strpos($captcha['head'], 'Content-Type: image/') !== false
        && $captchaImage !== false && $captchaImage[0] === 120 && $captchaImage[1] === 36, 'production_cached_native_captcha_image');
    $loginPage = pr_request('/admin/auth/login'); ql_check($loginPage['status'] === 200, 'production_original_login_page');
    preg_match('/name="_token"[^>]*value="([^"]+)"/', $loginPage['body'], $token); ql_check(!empty($token[1]), 'production_original_login_csrf');
    $bad = pr_request('/admin/auth/login', 'POST', ['username' => 'fixture-admin', 'password' => 'fixture-password'], 'application/json'); ql_check($bad['status'] === 419, 'production_csrf_without_token419');
    $login = pr_request('/admin/auth/login', 'POST', ['_token' => $token[1], 'username' => 'fixture-admin', 'password' => 'fixture-password'], 'application/json');
    ql_check($login['status'] === 200 && !empty(json_decode($login['body'], true)['status']), 'production_original_credentials_login');
    $goods = pr_request('/admin/goods'); ql_check($goods['status'] === 200 && strpos($goods['body'], 'Fixture Auto Goods') !== false, 'production_original_authenticated_grid');
    $assets = [];
    foreach ([$loginPage['body'], $goods['body']] as $html) { preg_match_all('/(?:src|href)="([^"]+)"/', $html, $matches); foreach ($matches[1] as $url) {
        $path = parse_url(html_entity_decode($url, ENT_QUOTES), PHP_URL_PATH); if ($path && strpos($path, '/vendor/') === 0 && preg_match('/\.(js|css)$/', $path)) $assets[$path] = true;
    } }
    ql_check(count($assets) > 3, 'actual_rendered_admin_resource_cardinality');
    foreach (array_keys($assets) as $path) { $r = pr_request($path); $file = $public . $path; ql_check(is_file($file) && $r['status'] === 200 && strpos($r['head'], substr($path, -3) === '.js' ? 'application/javascript' : 'text/css') !== false && hash('sha256', $r['body']) === hash_file('sha256', $file), 'nginx_published_admin_resource_bytes'); }
    ql_json($run . '/requested-assets.json', array_keys($assets));
    $logout = pr_request('/admin/auth/logout'); ql_check($logout['status'] === 302, 'production_original_logout');
    ql_check(pr_request('/admin/goods')['status'] === 302, 'production_logout_revokes_grid_access');
    foreach (['/.env', '/composer.json', '/vendor/autoload.php', '/storage/logs/laravel.log'] as $path) ql_check(pr_request($path)['status'] === 404, 'production_public_boundary');
    ql_check($initial === $db->query('SELECT * FROM orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'readonly_business_orders_unchanged');
    if (!empty($cfg['transactions'])) { require __DIR__ . '/transactions.php'; pr_transactions($cfg, $php); }
    $clients = array_values(array_filter(pr_lines($run . '/events.jsonl'), function ($e) { return $e['event'] === 'Response'; }));
    $access = pr_lines($run . '/access.log'); $native = pr_lines($run . '/http-events.jsonl');
    ql_check(count($clients) === $GLOBALS['pr_requests'] && count($access) === count($clients) && count($native) === 11 + (!empty($cfg['transactions']) ? $GLOBALS['pr_transaction_requests'] : 0), 'full_native_request_cardinality');
    $seen = [];
    foreach ($clients as $client) {
        $a = array_values(array_filter($access, function ($e) use ($client) { return $e['request_id'] === $client['request_id']; }));
        $n = array_values(array_filter($native, function ($e) use ($client) { return $e['request_id'] === $client['request_id']; }));
        ql_check(!isset($seen[$client['request_id']]) && count($a) === 1 && $a[0]['status'] === $client['status'] && $a[0]['uri'] === $client['path'] && $a[0]['method'] === $client['method'], 'unique_client_nginx_status'); $seen[$client['request_id']] = true;
        $static = strpos($client['path'], '/assets/') === 0 || strpos($client['path'], '/vendor/') === 0 || $client['path'] === '/.env';
        ql_check(count($n) === ($static ? 0 : 1), 'matching_dynamic_fpm_or_native_static');
        if ($n) ql_check($n[0]['method'] === $client['method'] && $n[0]['path'] === $client['path'] && $n[0]['status'] === $client['status'], 'three_way_native_request');
    }
    foreach ($native as $event) {
        ql_check($event['base'] === $release && $event['storage'] === $release . '/storage' && $event['db'] === $release . '/storage/fixture.sqlite'
            && $event['environment'] === 'production' && $event['config_cached'] && $event['routes_cached'], 'actual_production_cached_release_paths');
        if (!empty($cfg['transactions'])) ql_check($event['queue'] === 'database' && $event['timezone'] === 'Asia/Shanghai', 'actual_fpm_transaction_database_queue_and_timezone');
        ql_check(!$event['outside'] && !$event['dev_loaded'] && $event['loaders'] === [$release . '/vendor'], 'actual_fpm_no_external_code_or_dev_loader');
        ql_check($event['app_source'] === $release . '/app/Http/Kernel.php' && $event['vendor_source'] === $release . '/vendor/laravel/framework/src/Illuminate/Foundation/Application.php'
            && $event['index_sha256'] === $before['public/index.php'], 'actual_original_release_classes_front_controller');
        ql_check($event['last_php_error'] === null, 'actual_fpm_no_raw_php_issue');
        ql_check($event['php_observer']['installed'] && !$event['php_observer']['issues'], 'actual_fpm_no_framework_handled_php_issue');
    }
    ql_check(!is_file($run . '/php-errors.log') || filesize($run . '/php-errors.log') === 0, 'no_reported_php_engine_issue');
    $appLog = $release . '/storage/logs/laravel.log'; ql_check(!is_file($appLog) || !preg_match('/[.](ERROR|CRITICAL|ALERT|EMERGENCY):/', file_get_contents($appLog)), 'no_reported_application_exception');
    if (empty($cfg['transactions'])) ql_check($initial === $db->query('SELECT * FROM orders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'business_orders_unchanged');
    foreach ($before as $file => $hash) ql_check(hash_file('sha256', $cfg['root'] . '/' . $file) === $hash, 'workspace_source_unchanged_' . $file);
    ql_check($interrupted === null, 'no_release_interrupt_before_shutdown');
    foreach (['fpm', 'nginx'] as $label) { sw_track_children(); posix_kill($GLOBALS['ll_processes'][$label]['pid'], SIGQUIT); ql_check(sw_wait($label, 15) === 0, 'original_native_' . $label . '_graceful_exit0'); }
} catch (Throwable $e) { $failure = ['class' => get_class($e), 'message' => $e->getMessage()]; fwrite(STDERR, $e->getMessage() . PHP_EOL); }
finally {
    pcntl_alarm(0); try { sw_track_children(); sw_cleanup_children(); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    foreach (array_keys($GLOBALS['ll_processes']) as $label) try { ll_cleanup_owned($label); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    $processes = array_map(function ($p) { unset($p['proc']); return $p; }, $GLOBALS['ll_processes']);
    $events = pr_lines($run . '/events.jsonl'); $checks = count(array_filter($events, function ($e) { return $e['event'] === 'CheckPassed' && $e['role'] === 'release-runtime'; }));
    $status = !$failure && !$cleanup && $interrupted === null ? 'PASS' : 'FAIL';
    ql_json($run . '/runtime-summary.json', ['status' => $status, 'checks' => $checks, 'elapsed' => microtime(true) - $started, 'failure' => $failure, 'cleanup_errors' => $cleanup,
        'interrupted' => $interrupted, 'transactions'=>!empty($cfg['transactions']), 'requests' => $GLOBALS['pr_requests'], 'native_requests' => count($native), 'processes' => $processes, 'owned' => array_values($GLOBALS['sw_owned_children']), 'run' => $run]);
    echo json_encode(['status' => $status, 'checks' => $checks, 'requests' => $GLOBALS['pr_requests'], 'run' => $run], JSON_THROW_ON_ERROR), PHP_EOL;
}
exit($status === 'PASS' ? 0 : 1);
