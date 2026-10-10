<?php
require_once __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/LinuxLifecycle/process.php';
require dirname(__DIR__) . '/ScheduleWorkerLifecycle/process.php';
require __DIR__ . '/command.php';
if (($argv[1] ?? '') === '--issue') { trigger_error('PRODUCTION_RELEASE_CLI_ISSUE', E_USER_DEPRECATED); echo 'probe still returns0', PHP_EOL; exit(0); }
if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 1000) throw new RuntimeException('Linux UID1000 required.');
$root = ql_normalize(realpath(dirname(__DIR__, 3))); $marker = bin2hex(random_bytes(12));
$run = $root . '/tests/.runtime/production-release/run-controls-' . gmdate('Ymd-His') . '-' . $marker;
mkdir($run . '/release', 0700, true);
file_put_contents($run . '/marker', $marker); file_put_contents($run . '/events.jsonl', '');
$cfg = ['root' => $root, 'run' => $run, 'release' => $run . '/release', 'marker' => $marker, 'key' => 'base64:' . base64_encode(str_repeat('C', 32)),
    'port' => 1, 'url' => 'http://127.0.0.1:1', 'ini' => php_ini_loaded_file(), 'php' => PHP_BINARY];
ql_json($run . '/run.json', $cfg);
$GLOBALS['ql_run'] = $run; $GLOBALS['ql_role'] = 'release-controls'; $GLOBALS['ql_config'] = ['root' => $root, 'marker' => $marker];
$GLOBALS['ll_processes'] = []; $GLOBALS['sw_owned_children'] = []; $GLOBALS['sw_groups'] = []; $failure = null; $cleanup = [];
try {
    mkdir($run . '/reference/vendor/dcat-admin', 0700, true); file_put_contents($run . '/reference/vendor/dcat-admin/sentinel', 'Owned independent control bytes');
    $sentinel = $run . '/reference/vendor/dcat-admin/sentinel'; $hash = hash_file('sha256', $sentinel);
    foreach (['asset' => 'release/public/vendor/dcat-admin', 'vendor' => 'release/public/vendor', 'public' => 'release/public', 'root' => 'release'] as $label => $target) {
        $cfgControl = $cfg; $cfgControl['release'] = $run . '/' . $label . '/release';
        $link = $run . '/' . $label . '/' . $target;
        mkdir(dirname($link), 0700, true);
        $reference = $label === 'asset' ? $run . '/reference/vendor/dcat-admin' : ($label === 'vendor' ? $run . '/reference/vendor' : $run . '/reference');
        if (!symlink($reference, $link)) throw new RuntimeException('Owned link control setup failed.');
        $caught = null;
        try { pr_owned_path($cfgControl, $cfgControl['release'] . '/public/vendor/dcat-admin'); } catch (RuntimeException $e) { $caught = $e->getMessage(); }
        ql_check($caught === 'Owned release ancestor link detected.', 'actual_' . $label . '_ancestor_link_blocked');
        ql_check(hash_file('sha256', $sentinel) === $hash, 'reference_bytes_unchanged_' . $label);
        unlink($link);
    }
    mkdir($cfg['release'] . '/public/vendor', 0700, true);
    ql_check(pr_owned_path($cfg, $cfg['release'] . '/public/vendor/dcat-admin') === $cfg['release'] . '/public/vendor/dcat-admin', 'original_owned_missing_asset_target_allowed');
    $caught = null;
    try { pr_command([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--issue'], 'cli-issue', 10); } catch (RuntimeException $e) { $caught = $e->getMessage(); }
    $proof = json_decode(file_get_contents($run . '/cli-issue.php-proof.json'), true, 512, JSON_THROW_ON_ERROR);
    ql_check($GLOBALS['ll_processes']['cli-issue']['closed'] && $GLOBALS['ll_processes']['cli-issue']['exit'] === 0, 'real_php_issue_child_exit0');
    ql_check($proof['last_php_error']['type'] === E_USER_DEPRECATED && $proof['last_php_error']['message'] === 'PRODUCTION_RELEASE_CLI_ISSUE', 'real_cli_shutdown_issue_retained');
    ql_check(in_array($caught, ['Assertion failed: native_cli-issue_no_reported_php_diagnostic', 'Assertion failed: native_cli-issue_raw_php_shutdown_proof'], true), 'actual_pr_command_rejects_exit0_php_issue');
} catch (Throwable $e) { $failure = ['class' => get_class($e), 'message' => $e->getMessage()]; }
finally {
    try { sw_track_children(); sw_cleanup_children(); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    foreach (array_keys($GLOBALS['ll_processes']) as $label) try { ll_cleanup_owned($label); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    $status = !$failure && !$cleanup ? 'PASS' : 'FAIL';
    $events = array_map(function ($line) { return json_decode($line, true, 512, JSON_THROW_ON_ERROR); }, file($run . '/events.jsonl', FILE_SKIP_EMPTY_LINES));
    $checks = count(array_filter($events, function ($e) { return $e['event'] === 'CheckPassed'; }));
    ql_json($run . '/controls-summary.json', ['status' => $status, 'checks' => $checks, 'failure' => $failure, 'cleanup_errors' => $cleanup,
        'processes' => array_map(function ($p) { unset($p['proc']); return $p; }, $GLOBALS['ll_processes'])]);
    echo json_encode(['status' => $status, 'checks' => $checks, 'run' => $run, 'failure' => $failure], JSON_THROW_ON_ERROR), PHP_EOL;
}
exit($status === 'PASS' ? 0 : 1);
