<?php
require __DIR__ . '/bootstrap.php';

function cv_check($condition, $name)
{
    if (!$condition) throw new RuntimeException('Cross-version assertion failed: ' . $name);
    $GLOBALS['cv_checks']++;
    $GLOBALS['cv_evidence'][] = ['check' => $name, 'time' => microtime(true)];
}

function cv_child($run, $tree, array $task)
{
    $cfg = json_decode(file_get_contents($run . '/run.json'), true, 512, JSON_THROW_ON_ERROR);
    $number = sprintf('%03d', ++$GLOBALS['cv_tasks']);
    $path = $run . '/tasks/' . $number . '-' . $tree . '.json';
    ql_json($path, $task);
    $runtime = $cfg['runtimes'][$tree];
    $args = [$runtime['binary'], '-c', $runtime['ini'], __DIR__ . '/worker.php', $run, $tree, $path];
    $pipes = [];
    $env = getenv(); $env['PHP_INI_SCAN_DIR'] = '';
    $proc = proc_open($args, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', $path . '.stdout', 'w'], 2 => ['file', $path . '.stderr', 'w']], $pipes, $cfg['root'], $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) throw new RuntimeException('Cross-version process spawn failed.');
    $status = proc_get_status($proc);
    $GLOBALS['cv_processes'][$number] = ['proc' => $proc, 'pid' => $status['pid'], 'tree' => $tree, 'task' => $path, 'exit' => null, 'closed' => false];
    $deadline = microtime(true) + 45;
    do {
        $status = proc_get_status($proc);
        if (!$status['running']) break;
        usleep(50000);
    } while (microtime(true) < $deadline && microtime(true) < $GLOBALS['cv_deadline']);
    if ($status['running']) throw new RuntimeException('Cross-version child timed out.');
    $exit = $status['exitcode']; $closed = proc_close($proc);
    if ($exit < 0 && $closed >= 0) $exit = $closed;
    $GLOBALS['cv_processes'][$number]['closed'] = true; $GLOBALS['cv_processes'][$number]['exit'] = $exit;
    $resultPath = $path . '.result.json';
    $result = is_file($resultPath) ? json_decode(file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR) : null;
    cv_check($exit === 0 && $result && $result['status'] === 'PASS', 'fresh_' . $tree . '_' . $task['operation'] . '_exit0_' . $number);
    cv_check(!filesize($path . '.stderr') && !$result['issues'], 'fresh_process_no_reported_issue_' . $number);
    cv_check($result['php'] === ($tree === 'root' ? '7.4.33' : '8.5.11') && $result['framework'] === ($tree === 'root' ? '6.20.42' : '13.35.0'), 'actual_version_' . $number);
    cv_check($result['pid'] === $status['pid'] && $result['binary'] === $runtime['binary'] && $result['ini'] === $runtime['ini'], 'actual_child_identity_' . $number);
    cv_check($result['storage'] === $run . '/storage' && $result['database'] === $run . '/fixture.sqlite'
        && ql_normalize($result['session_path']) === $run . '/storage/framework/sessions' && ql_normalize($result['cache_path']) === $run . '/storage/framework/cache/data', 'same_private_storage_' . $number);
    return [$result, $path];
}

function cv_request($run, $tree, &$jar, $path, $method = 'GET', array $data = [], $accept = 'application/json', $changedKey = false)
{
    list($result, $file) = cv_child($run, $tree, ['operation' => 'request', 'path' => $path, 'method' => $method,
        'data' => $data, 'cookies' => $jar, 'accept' => $accept, 'changed_key' => $changedKey]);
    foreach ($result['cookies'] as $name => $cookie) {
        if ($cookie['expires'] > 0 && $cookie['expires'] <= time()) unset($jar[$name]);
        else $jar[$name] = $cookie['value'];
    }
    $result['captured_body'] = $file . '.body';
    return $result;
}

function cv_business($run)
{
    $db = new PDO('sqlite:' . $run . '/fixture.sqlite');
    $db->exec('PRAGMA query_only=ON'); $data = [];
    foreach (['goods', 'goods_group', 'carmis', 'coupons', 'coupons_goods', 'pays', 'orders'] as $table) $data[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    return $data;
}

$root = ql_normalize(realpath(dirname(__DIR__, 3)));
$base = $root . '/tests/.runtime/cross-version-state';
if (!is_dir($base)) mkdir($base, 0700, true);
$run = $base . '/run-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(12));
mkdir($run, 0700);
$GLOBALS['cv_checks'] = 0; $GLOBALS['cv_tasks'] = 0; $GLOBALS['cv_processes'] = []; $GLOBALS['cv_evidence'] = [];
$GLOBALS['cv_deadline'] = microtime(true) + 240;
$started = microtime(true); $failure = null; $cleanup = []; $directions = [];
try {
    $runtimes = ['root' => ['binary' => $root . '/tests/.runtime/php74/php.exe', 'ini' => $root . '/tests/.runtime/php74/php.ini'],
        'candidate' => ['binary' => $root . '/tests/.runtime/php85/php.exe', 'ini' => $root . '/tests/.runtime/php85-fixture.ini']];
    foreach ([['root', 'candidate'], ['candidate', 'root']] as $pair) {
        list($writer, $reader) = $pair;
        $directionRun = $base . '/run-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(12));
        mkdir($directionRun, 0700);
        foreach (['tasks', 'root/views', 'candidate/views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs', 'storage/app'] as $dir) mkdir($directionRun . '/' . $dir, 0700, true);
        $marker = bin2hex(random_bytes(12));
        $cfg = ['root' => $root, 'run' => $directionRun, 'marker' => $marker, 'key' => 'base64:' . base64_encode(str_repeat('S', 32)), 'runtimes' => $runtimes];
        ql_json($directionRun . '/run.json', $cfg); file_put_contents($directionRun . '/marker', $marker); touch($directionRun . '/fixture.sqlite');
        cv_child($directionRun, $writer, ['operation' => 'seed']);
        $business = cv_business($directionRun);
        list($cacheWriter) = cv_child($directionRun, $writer, ['operation' => 'cache-write']);
        list($cacheReader) = cv_child($directionRun, $reader, ['operation' => 'cache-read']);
        cv_check($cacheReader['payload'] === $cacheWriter['payload'] && $cacheReader['payload_types'] === $cacheWriter['payload_types']
            && $cacheReader['catalog'] === $cacheWriter['catalog'] && $cacheReader['catalog_types'] === $cacheWriter['catalog_types'], $writer . '_to_' . $reader . '_cache_types_and_real_catalog');
        foreach ($cacheWriter['paths'] as $key => $proof) cv_check(hash_file('sha256', $proof['path']) === $proof['sha256'], 'cache_read_bytes_unchanged_' . $key);
        $jar = [];
        $loginPage = cv_request($directionRun, $writer, $jar, '/admin/auth/login', 'GET', [], 'text/html');
        cv_check($loginPage['http_status'] === 200, 'original_dcat_login_page_' . $writer);
        preg_match('/name="_token"[^>]*value="([^"]+)"/', file_get_contents($loginPage['captured_body']), $token);
        cv_check(!empty($token[1]), 'actual_login_page_csrf_' . $writer);
        $login = cv_request($directionRun, $writer, $jar, '/admin/auth/login', 'POST', ['_token' => $token[1], 'username' => 'fixture-admin', 'password' => 'fixture-password', 'remember' => 1]);
        cv_check($login['http_status'] === 200 && !empty($login['json']['status']), 'original_dcat_credentials_login_' . $writer);
        $rememberName = $login['recaller_name'];
        cv_check(isset($jar[$rememberName]), 'original_remember_cookie_issued_' . $writer);
        $sessionName = 'cross_version_' . $marker;
        $source = cv_request($directionRun, $writer, $jar, '/__state43');
        cv_check($source['http_status'] === 200 && $source['json']['id'] === 1 && $source['json']['username'] === 'fixture-admin' && !$source['json']['via_remember'], 'original_source_authenticated_session_' . $writer);
        $session = $source['json']['session']; $csrf = $source['json']['csrf'];
        $write = cv_request($directionRun, $writer, $jar, '/__state43', 'POST', ['_token' => $csrf]);
        cv_check($write['http_status'] === 200 && $write['json']['revision'] === 1, 'source_session_write_real_csrf_' . $writer);
        $cross = cv_request($directionRun, $reader, $jar, '/__state43');
        cv_check($cross['http_status'] === 200 && $cross['json']['id'] === 1 && $cross['json']['session'] === $session
            && $cross['json']['csrf'] === $csrf && $cross['json']['payload'] === cv_payload() && $cross['json']['revision'] === 1
            && $cross['json']['errors'] === ['Cross-version validation message'] && $cross['json']['payload_types'] === cv_types(cv_payload()), 'cross_encrypted_cookie_file_session_auth_token_types_objects_' . $reader);
        cv_check($source['guard_name'] === $cross['guard_name'] && $source['recaller_name'] === $cross['recaller_name'], 'original_guard_keys_equal');
        $goods = cv_request($directionRun, $reader, $jar, '/admin/goods', 'GET', [], 'text/html');
        cv_check($goods['http_status'] === 200 && strpos(file_get_contents($goods['captured_body']), 'Fixture Auto Goods') !== false && !$goods['location'], 'original_admin_grid_cross_session_' . $reader);
        $bad = cv_request($directionRun, $reader, $jar, '/__state43', 'POST', ['_token' => 'invalid-state-token']);
        cv_check($bad['http_status'] === 419, 'cross_version_wrong_csrf419_' . $reader);
        $update = cv_request($directionRun, $reader, $jar, '/__state43', 'POST', ['_token' => $csrf]);
        cv_check($update['http_status'] === 200 && $update['json']['session'] === $session && $update['json']['revision'] === 2, 'cross_version_old_csrf_accept_and_update_' . $reader);
        $back = cv_request($directionRun, $writer, $jar, '/__state43');
        cv_check($back['http_status'] === 200 && $back['json']['session'] === $session && $back['json']['id'] === 1
            && $back['json']['revision'] === 2 && $back['json']['payload'] === cv_payload() && $back['json']['csrf'] === $csrf, 'reverse_session_update_read_' . $writer);
        list($reverseCache) = cv_child($directionRun, $reader, ['operation' => 'cache-write']);
        list($reverseReader) = cv_child($directionRun, $writer, ['operation' => 'cache-read']);
        cv_check($reverseCache['payload'] === $reverseReader['payload'] && $reverseCache['payload_types'] === $reverseReader['payload_types']
            && $reverseCache['catalog'] === $reverseReader['catalog'] && $reverseCache['catalog_types'] === $reverseReader['catalog_types'], 'reverse_cache_original_read_' . $writer);
        $rememberJar = [$rememberName => $jar[$rememberName]];
        $remember = cv_request($directionRun, $reader, $rememberJar, '/__state43');
        cv_check($remember['http_status'] === 200 && $remember['json']['id'] === 1 && $remember['json']['via_remember'] && $remember['json']['session'] !== $session, 'cross_remember_only_original_guard_' . $reader);
        $control = [$sessionName => $jar[$sessionName]];
        $changed = cv_request($directionRun, $reader, $control, '/__state43', 'GET', [], 'application/json', true);
        cv_check($changed['http_status'] === 200 && $changed['json']['id'] === null && $changed['json']['session'] !== $session, 'changed_key_invalidates_original_cookie_' . $reader);
        $control = [$sessionName => substr($jar[$sessionName], 0, -3) . 'xxx'];
        $corrupt = cv_request($directionRun, $reader, $control, '/__state43');
        cv_check($corrupt['http_status'] === 200 && $corrupt['json']['id'] === null && $corrupt['json']['session'] !== $session, 'corrupt_ciphertext_invalidates_original_cookie_' . $reader);
        $oldSessionOnly = [$sessionName => $jar[$sessionName]];
        $oldRememberOnly = [$rememberName => $jar[$rememberName]];
        $logout = cv_request($directionRun, $reader, $jar, '/admin/auth/logout');
        cv_check($logout['http_status'] === 302 && strpos($logout['location'], 'auth/login') !== false, 'original_cross_logout_' . $reader);
        $loggedOut = cv_request($directionRun, $writer, $jar, '/__state43');
        cv_check($loggedOut['http_status'] === 200 && $loggedOut['json']['id'] === null && $loggedOut['json']['session'] !== $session, 'reverse_logout_invalidation_' . $writer);
        $revokedSession = cv_request($directionRun, $writer, $oldSessionOnly, '/__state43');
        cv_check($revokedSession['http_status'] === 200 && $revokedSession['json']['id'] === null && $revokedSession['json']['revision'] === 0, 'old_session_revoked_after_other_tree_logout');
        $revokedRemember = cv_request($directionRun, $writer, $oldRememberOnly, '/__state43');
        cv_check($revokedRemember['http_status'] === 200 && $revokedRemember['json']['id'] === null, 'old_remember_revoked_after_other_tree_logout');
        cv_check($business === cv_business($directionRun), 'business_sql_unchanged_both_runtime_requests');
        $direction = ['writer' => $writer, 'reader' => $reader, 'run' => $directionRun, 'session' => $session, 'guard' => $source['guard_name'],
            'session_sha_after' => is_file($directionRun . '/storage/framework/sessions/' . $session) ? hash_file('sha256', $directionRun . '/storage/framework/sessions/' . $session) : null,
            'cache' => $cacheWriter['paths'], 'business_sha256' => hash('sha256', serialize($business)), 'remember_accepted' => $remember['json']['id'] === 1];
        ql_json($directionRun . '/direction-proof.json', $direction); $directions[] = $direction;
    }
} catch (Throwable $e) { $failure = ['class' => get_class($e), 'message' => $e->getMessage()]; fwrite(STDERR, $e->getMessage() . PHP_EOL); }
finally {
    foreach ($GLOBALS['cv_processes'] as &$child) {
        if ($child['closed']) continue;
        if (proc_get_status($child['proc'])['running']) proc_terminate($child['proc']);
        $deadline = microtime(true) + 5;
        do { $status = proc_get_status($child['proc']); if (!$status['running']) break; usleep(50000); } while (microtime(true) < $deadline);
        if ($status['running']) { $cleanup[] = 'Unclosed owned child ' . $child['pid']; continue; }
        $exit = $status['exitcode']; $close = proc_close($child['proc']); $child['closed'] = true; $child['exit'] = $exit < 0 && $close >= 0 ? $close : $exit;
    }
    unset($child);
    $processes = array_map(function ($child) { unset($child['proc']); return $child; }, $GLOBALS['cv_processes']);
    $status = !$failure && !$cleanup && count($directions) === 2 ? 'PASS' : 'FAIL';
    $summary = ['status' => $status, 'checks' => $GLOBALS['cv_checks'], 'elapsed' => microtime(true) - $started, 'failure' => $failure,
        'cleanup_errors' => $cleanup, 'processes' => $processes, 'directions' => $directions, 'evidence' => $GLOBALS['cv_evidence']];
    ql_json($run . '/summary.json', $summary);
    echo json_encode(['status' => $status, 'checks' => $summary['checks'], 'children' => count($processes), 'run' => $run], JSON_THROW_ON_ERROR), PHP_EOL;
}
exit($status === 'PASS' ? 0 : 1);
