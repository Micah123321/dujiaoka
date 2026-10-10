<?php

require dirname(__DIR__) . '/QueueLifecycle/bootstrap.php';
require __DIR__ . '/process.php';

if (PHP_OS_FAMILY !== 'Linux' || !extension_loaded('pcntl')) throw new RuntimeException('Linux PCNTL is required.');
if (isset($argv[1]) && strpos($argv[1], '--child=') === 0) {
    $run = realpath(substr($argv[1], 8));
    $base = realpath(dirname(__DIR__, 2) . '/.runtime/queue-lifecycle');
    if (!$run || dirname($run) !== $base || !preg_match('/^run-cleanup-[a-f0-9]{24}$/D', basename($run))) {
        throw new RuntimeException('Invalid owned cleanup probe.');
    }
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, SIG_IGN);
    ql_json($run . '/ready.json', ['pid' => getmypid(), 'binary' => PHP_BINARY]);
    while (true) usleep(50000);
}

$root = ql_normalize(realpath(dirname(__DIR__, 3)));
$marker = bin2hex(random_bytes(12));
$run = $root . '/tests/.runtime/queue-lifecycle/run-cleanup-' . $marker;
if (!mkdir($run, 0700, true)) throw new RuntimeException('Fresh cleanup probe directory failed.');
$GLOBALS['ql_run'] = $run;
$GLOBALS['ql_role'] = 'cleanup-parent';
$GLOBALS['ql_config'] = ['run' => $run, 'root' => $root, 'marker' => $marker];
$GLOBALS['ll_processes'] = [];
$exit = 1;
try {
    ll_spawn([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--child=' . $run], 'ignores-TERM');
    $ready = ll_until(function () use ($run) { return is_file($run . '/ready.json'); }, 'probe ready');
    $pid = $GLOBALS['ll_processes']['ignores-TERM']['pid'];
    $ready = json_decode(file_get_contents($run . '/ready.json'), true, 512, JSON_THROW_ON_ERROR);
    ql_check($ready['pid'] === $pid && $ready['binary'] === PHP_BINARY, 'owned_real_probe_child');
    $GLOBALS['ll_processes']['ignores-TERM']['identity']['argv'] = ['fixture-stale-pre-exec-snapshot'];
    ql_check(!ll_same_process($GLOBALS['ll_processes']['ignores-TERM']['identity']), 'stale_identity_precondition');
    $start = microtime(true);
    ll_cleanup_owned('ignores-TERM');
    $elapsed = microtime(true) - $start;
    $child = $GLOBALS['ll_processes']['ignores-TERM'];
    ql_check($child['closed'] && $child['signaled'] && $child['term_signal'] === SIGKILL, 'TERM_ignored_KILL_reaped');
    ql_check($elapsed >= 5 && $elapsed < 10, 'cleanup_has_bounded_wait', ['seconds' => $elapsed]);
    clearstatcache();
    ql_check(!is_dir('/proc/' . $pid), 'owned_probe_PID_gone');
    $exit = 0;
} finally {
    if (isset($GLOBALS['ll_processes']['ignores-TERM']) && !$GLOBALS['ll_processes']['ignores-TERM']['closed']) {
        ll_cleanup_owned('ignores-TERM');
    }
    $process = array_diff_key($GLOBALS['ll_processes']['ignores-TERM'], ['proc' => true]);
    ql_json($run . '/summary.json', ['status' => $exit === 0 ? 'PASS' : 'FAIL', 'php' => PHP_VERSION,
        'process' => $process, 'seconds' => isset($elapsed) ? $elapsed : null, 'stale_identity' => true]);
    echo ($exit === 0 ? 'PASS' : 'FAIL'), ' summary=', $run, '/summary.json', PHP_EOL;
}
exit($exit);
