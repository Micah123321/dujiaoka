<?php
require dirname(__DIR__) . '/QueueLifecycle/bootstrap.php';
require dirname(__DIR__) . '/LinuxLifecycle/process.php';
require __DIR__ . '/process.php';

if (in_array('--owned-child', $argv, true) || in_array('--owned-parent', $argv, true)) {
    $run = null;
    foreach ($argv as $arg) if (strpos($arg, '--run=') === 0) $run = substr($arg, 6);
    if (!$run || !preg_match('#/tests/\.runtime/queue-lifecycle/run-cleanup-[a-zA-Z0-9-]+$#D', $run)
        || !is_file($run . '/marker')) throw new RuntimeException('Owned synthetic probe directory required.');
    if (in_array('--owned-child', $argv, true)) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, SIG_IGN);
        ql_json($run . '/child-ready.json', ['pid' => getmypid(), 'uid' => posix_geteuid()]);
    } else {
        $pipes = [];
        $proc = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--owned-child', '--run=' . $run],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $run . '/child.stdout', 'a'], 2 => ['file', $run . '/child.stderr', 'a']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('Owned probe child spawn failed.');
        ql_json($run . '/parent-ready.json', ['pid' => getmypid(), 'child' => proc_get_status($proc)['pid']]);
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () use ($run) {
            $late = pcntl_fork();
            if ($late === 0) {
                pcntl_signal(SIGTERM, SIG_IGN);
                ql_json($run . '/late-ready.json', ['pid' => getmypid()]);
                $until = microtime(true) + 40;
                while (microtime(true) < $until) usleep(50000);
                exit(2);
            }
            exit(0);
        });
    }
    $until = microtime(true) + 45;
    while (microtime(true) < $until) usleep(50000);
    exit(2);
}

$root = ql_normalize(realpath(dirname(__DIR__, 3)));
$marker = bin2hex(random_bytes(12));
$run = $root . '/tests/.runtime/queue-lifecycle/run-cleanup-' . gmdate('Ymd-His') . '-' . $marker;
if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 1000 || !mkdir($run, 0700, true)) throw new RuntimeException('Linux UID1000 fresh probe required.');
file_put_contents($run . '/marker', $marker);
$GLOBALS['ql_run'] = $run;
$GLOBALS['ql_role'] = 'cleanup-probe';
$GLOBALS['ql_config'] = ['root' => $root, 'marker' => $marker];
$GLOBALS['ll_processes'] = [];
$GLOBALS['sw_owned_children'] = [];
$GLOBALS['sw_groups'] = [];
$error = null;
$injected = false;
$started = microtime(true);
try {
    sw_spawn([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--owned-parent', '--run=' . $run], 'probe-parent');
    ll_until(function () use ($run) {
        sw_track_children();
        return is_file($run . '/child-ready.json') && count($GLOBALS['sw_owned_children']) === 2;
    }, 'owned TERM-resistant child ready', 5);
    ql_check(count($GLOBALS['sw_owned_children']) === 2, 'exact_owned_descendant_recorded');
    $injected = true;
    throw new RuntimeException('Injected parent interruption.');
} catch (Throwable $e) {
    if (!$injected || $e->getMessage() !== 'Injected parent interruption.') $error = $e->getMessage();
} finally {
    try {
        sw_track_children();
        proc_terminate($GLOBALS['ll_processes']['probe-parent']['proc'], SIGTERM);
        sw_cleanup_children();
        foreach (array_keys($GLOBALS['ll_processes']) as $label) ll_cleanup_owned($label);
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$remaining = array_values(array_filter($GLOBALS['sw_owned_children'], 'sw_child_alive'));
$elapsed = microtime(true) - $started;
$late = is_file($run . '/late-ready.json') ? json_decode(file_get_contents($run . '/late-ready.json'), true) : null;
$lateTracked = $late && isset($GLOBALS['sw_owned_children'][$late['pid']]);
$summary = ['status' => !$error && $injected && !$remaining && $lateTracked && $elapsed < 12 ? 'PASS' : 'FAIL',
    'late_child' => $late, 'late_child_tracked' => (bool) $lateTracked,
    'injected' => $injected, 'failure' => $error, 'elapsed' => $elapsed,
    'owned_descendants' => array_values($GLOBALS['sw_owned_children']), 'remaining_owned' => $remaining,
    'processes' => array_map(function ($p) { unset($p['proc']); return $p; }, $GLOBALS['ll_processes'])];
foreach ($summary['processes'] as $p) if (!$p['closed']) $summary['status'] = 'FAIL';
ql_json($run . '/summary.json', $summary);
echo json_encode(['status' => $summary['status'], 'elapsed' => $elapsed, 'run' => $run], JSON_THROW_ON_ERROR), PHP_EOL;
exit($summary['status'] === 'PASS' ? 0 : 1);
