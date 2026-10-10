<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/LinuxLifecycle/process.php';
require dirname(__DIR__) . '/ScheduleWorkerLifecycle/process.php';
if (($argv[1] ?? '') === '--producer') {
    $run = $argv[2];
    $pid = pcntl_fork(); if ($pid === -1) throw new RuntimeException('Probe fork failed.');
    if ($pid === 0) exit(0);
    file_put_contents($run . '/zombie.pid', (string) $pid);
    $until = microtime(true) + 15;
    while (!is_file($run . '/reap') && microtime(true) < $until) usleep(50000);
    pcntl_waitpid($pid, $status); exit(0);
}
if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 1000) throw new RuntimeException('Linux UID1000 required.');
$root = ql_normalize(realpath(dirname(__DIR__, 3)));
$marker = bin2hex(random_bytes(12)); $run = $root . '/tests/.runtime/production-release-process/run-' . gmdate('Ymd-His') . '-' . $marker;
mkdir($run, 0700, true); file_put_contents($run . '/events.jsonl', '');
$GLOBALS['ql_run'] = $run; $GLOBALS['ql_role'] = 'zombie-probe'; $GLOBALS['ql_config'] = ['root' => $root, 'marker' => $marker];
$GLOBALS['ll_processes'] = []; $GLOBALS['sw_owned_children'] = []; $GLOBALS['sw_groups'] = []; $failure = null; $cleanup = [];
try {
    $rawStat = file_get_contents('/proc/self/stat');
    $fields = preg_split('/\s+/', trim(substr($rawStat, strrpos($rawStat, ')') + 1)));
    $statPrefix = substr($rawStat, 0, strrpos($rawStat, ')') + 1) . ' ';
    $oldHandler = set_error_handler(function ($level, $message, $file, $line) { throw new ErrorException($message, 0, $level, $file, $line); });
    try {
        foreach ([false, '', '123 (unfinished', '123 () bad', $statPrefix . implode(' ', array_slice($fields, 0, 19))] as $partial)
            ql_check(sw_parse_stat($partial) === null, 'incomplete_stat_returns_no_identity_without_warning');
        $parsed = sw_parse_stat($rawStat);
        ql_check($parsed !== null && $parsed === sw_stat(getmypid()), 'actual_live_stat_preserves_exact_identity');
    } finally { restore_error_handler(); }
    sw_spawn([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--producer', $run], 'producer');
    ll_until(function () use ($run) {
        if (!is_file($run . '/zombie.pid')) return false;
        $pid = (int) file_get_contents($run . '/zombie.pid'); $stat = sw_stat($pid);
        return $stat && $stat['state'] === 'Z' ? ['pid' => $pid, 'stat' => $stat] : false;
    }, 'real owned zombie remains unreaped', 5);
    sw_track_children();
    $pid = (int) file_get_contents($run . '/zombie.pid');
    ql_check(sw_stat($pid)['state'] === 'Z', 'original_zombie_still_present');
    ql_check(!isset($GLOBALS['sw_owned_children'][$pid]), 'zombie_is_not_live_owned_identity');
    ql_check(isset($GLOBALS['sw_owned_children'][$GLOBALS['ll_processes']['producer']['pid']]), 'live_root_still_tracked');
    file_put_contents($run . '/reap', '1');
    ql_check(sw_wait('producer', 5) === 0, 'original_producer_reap_exit0');
} catch (Throwable $e) { $failure = ['class' => get_class($e), 'message' => $e->getMessage()]; }
finally {
    file_put_contents($run . '/reap', '1');
    try { sw_track_children(); sw_cleanup_children(); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    foreach (array_keys($GLOBALS['ll_processes']) as $label) try { ll_cleanup_owned($label); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    $processes = array_map(function ($p) { unset($p['proc']); return $p; }, $GLOBALS['ll_processes']);
    $status = !$failure && !$cleanup ? 'PASS' : 'FAIL';
    ql_json($run . '/summary.json', ['status' => $status, 'failure' => $failure, 'cleanup_errors' => $cleanup, 'processes' => $processes, 'owned' => array_values($GLOBALS['sw_owned_children'])]);
    echo json_encode(['status' => $status, 'run' => $run, 'failure' => $failure, 'cleanup_errors' => $cleanup], JSON_THROW_ON_ERROR), PHP_EOL;
}
exit($status === 'PASS' ? 0 : 1);
