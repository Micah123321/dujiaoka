<?php
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/LinuxLifecycle/process.php';
require dirname(__DIR__) . '/ScheduleWorkerLifecycle/process.php';
if (PHP_OS_FAMILY !== 'Linux' || PHP_VERSION !== '8.5.11' || posix_geteuid() !== 1000) throw new RuntimeException('Existing Linux UID1000 PHP8.5 required.');
$cfg = pr_manifest($argv[1] ?? '');
if (json_decode(file_get_contents($cfg['run'] . '/runtime-summary.json'), true, 512, JSON_THROW_ON_ERROR)['status'] !== 'PASS') throw new RuntimeException('Successful production release required.');
$marker = bin2hex(random_bytes(12)); $run = $cfg['root'] . '/tests/.runtime/release44/fault-' . gmdate('Ymd-His') . '-' . $marker;
mkdir($run . '/tmp', 0700, true); file_put_contents($run . '/events.jsonl', '');
$GLOBALS['ql_run'] = $run; $GLOBALS['ql_role'] = 'release-fault'; $GLOBALS['ql_config'] = ['root' => $cfg['root'], 'marker' => $marker];
$GLOBALS['ll_processes'] = []; $GLOBALS['sw_owned_children'] = []; $GLOBALS['sw_groups'] = [];
$ports = []; $sockets = [];
foreach ([0,1] as $i) { $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $error); if (!$s) throw new RuntimeException('Fault port required.'); $sockets[] = $s; $ports[] = (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1); }
foreach ($sockets as $s) fclose($s);
$before = []; foreach (['http-events.jsonl', 'access.log', 'runtime-summary.json', 'release/storage/fixture.sqlite'] as $file) $before[$file] = hash_file('sha256', $cfg['run'] . '/' . $file);
$failure = null; $cleanup = []; $injected = false; $started = microtime(true); $interrupted = null;
pcntl_async_signals(true); foreach ([SIGTERM, SIGINT, SIGALRM] as $sig) pcntl_signal($sig, function ($sig) use (&$interrupted) { $interrupted = $sig; }); pcntl_alarm(30);
try {
    foreach (['fpm', 'nginx'] as $label) {
        $text = file_get_contents($cfg['run'] . '/' . $label . '.conf');
        foreach (['fpm.pid', 'fpm.log', 'nginx.pid', 'nginx.log', 'access.log', 'php-errors.log', 'tmp'] as $name) $text = str_replace($cfg['run'] . '/' . $name, $run . '/' . $name, $text);
        $text = str_replace('127.0.0.1:' . $cfg['fpm_port'], '127.0.0.1:' . $ports[1], $text);
        $text = str_replace('127.0.0.1:' . $cfg['port'], '127.0.0.1:' . $ports[0], $text);
        file_put_contents($run . '/' . $label . '.conf', $text);
    }
    $fpm = $cfg['root'] . '/tests/.runtime/http42/rootfs/usr/sbin/php-fpm8.5'; $nginx = $cfg['root'] . '/tests/.runtime/http42/rootfs/usr/sbin/nginx';
    sw_spawn([$fpm, '-c', $cfg['ini'], '-y', $run . '/fpm.conf', '-F'], 'fpm');
    sw_spawn([$nginx, '-p', $run . '/', '-c', $run . '/nginx.conf', '-e', $run . '/nginx.log'], 'nginx');
    ll_until(function () use ($ports, &$interrupted) {
        sw_track_children(); if ($interrupted !== null) throw new RuntimeException('Fault probe interrupted.');
        foreach (['fpm', 'nginx'] as $label) if (!proc_get_status($GLOBALS['ll_processes'][$label]['proc'])['running']) throw new RuntimeException('Fault native startup failed.');
        foreach ($ports as $port) { $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2); if (!$s) return false; fclose($s); }
        return count($GLOBALS['sw_owned_children']) >= 4;
    }, 'actual release services and owned workers ready', 10);
    $injected = true; throw new RuntimeException('Controlled production release post-start failure.');
} catch (Throwable $e) { $failure = ['class' => get_class($e), 'message' => $e->getMessage()]; }
finally {
    pcntl_alarm(0);
    try { sw_track_children(); sw_cleanup_children(); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    foreach (array_keys($GLOBALS['ll_processes']) as $label) try { ll_cleanup_owned($label); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    $processes = array_map(function ($p) { unset($p['proc']); return $p; }, $GLOBALS['ll_processes']);
    $remaining = array_values(array_filter($GLOBALS['sw_owned_children'], 'sw_child_alive'));
    $closed = true; foreach ($ports as $port) { $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.2); if ($s) { fclose($s); $closed = false; } }
    $unchanged = true; foreach ($before as $file => $hash) if (hash_file('sha256', $cfg['run'] . '/' . $file) !== $hash) $unchanged = false;
    $verified = $injected && ($failure['message'] ?? '') === 'Controlled production release post-start failure.' && !$cleanup && !$remaining && $closed && $unchanged && $interrupted === null
        && count($processes) === 2 && !array_filter($processes, function ($p) { return !$p['closed']; });
    ql_json($run . '/fault-summary.json', ['status' => $verified ? 'EXPECTED_FAILURE' : 'FAIL', 'cleanup_verified' => $verified, 'failure' => $failure, 'cleanup_errors' => $cleanup,
        'remaining_owned' => $remaining, 'owned' => array_values($GLOBALS['sw_owned_children']), 'processes' => $processes, 'ports' => $ports, 'ports_closed' => $closed,
        'original_run_unchanged' => $unchanged, 'protected_before' => $before, 'source_run' => $cfg['run'], 'elapsed' => microtime(true) - $started]);
    echo json_encode(['status' => $verified ? 'EXPECTED_FAILURE' : 'FAIL', 'cleanup_verified' => $verified, 'run' => $run, 'failure' => $failure], JSON_THROW_ON_ERROR), PHP_EOL;
}
exit(1);
