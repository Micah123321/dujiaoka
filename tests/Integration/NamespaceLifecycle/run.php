<?php

require dirname(__DIR__) . '/QueueLifecycle/bootstrap.php';
require dirname(__DIR__) . '/LinuxLifecycle/process.php';

use App\Models\Order;
use App\Service\NotificationOutboxService;
use Illuminate\Support\Facades\DB;

function ns_events($event = null, $role = null)
{
    $handle = fopen($GLOBALS['ql_run'] . '/events.jsonl', 'r');
    if (!$handle || !flock($handle, LOCK_SH)) throw new RuntimeException('Event read lock failed.');
    $text = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    $rows = [];
    foreach (explode("\n", trim($text)) as $line) {
        if ($line === '') continue;
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (($event === null || $row['event'] === $event) && ($role === null || $row['role'] === $role)) $rows[] = $row;
    }
    return $rows;
}

function ns_identity($pid)
{
    $identity = ll_identity($pid);
    if (!$identity) return null;
    $status = @file_get_contents('/proc/' . $pid . '/status');
    if ($status === false || !preg_match('/^NSpid:\s+([0-9 \t]+)$/m', $status, $match)) return null;
    $identity['nspid'] = array_map('intval', preg_split('/\s+/', trim($match[1])));
    $identity['namespaces'] = [];
    foreach (['user', 'mnt', 'pid'] as $name) $identity['namespaces'][$name] = @readlink('/proc/' . $pid . '/ns/' . $name);
    return $identity;
}

function ns_descendants($pid)
{
    $rows = [];
    $pending = [$pid];
    $visited = [];
    while ($pending) {
        $parent = array_shift($pending);
        if (isset($visited[$parent])) continue;
        $visited[$parent] = true;
        $children = trim((string) @file_get_contents('/proc/' . $parent . '/task/' . $parent . '/children'));
        foreach ($children === '' ? [] : preg_split('/\s+/', $children) as $child) {
            $identity = ns_identity((int) $child);
            if (!$identity || $identity['ppid'] !== $parent) continue;
            $rows[$identity['pid']] = $identity;
            $pending[] = $identity['pid'];
        }
    }
    return $rows;
}

function ns_track_descendants()
{
    $launcher = $GLOBALS['ll_processes']['namespace-launcher'];
    $current = ll_identity($launcher['pid']);
    if (!$current || $current['start_ticks'] !== $launcher['identity']['start_ticks']) return [];
    $rows = ns_descendants($launcher['pid']);
    foreach ($rows as $pid => $row) {
        if (in_array('--marker=' . $GLOBALS['ql_config']['marker'], $row['argv'], true)
            || in_array($GLOBALS['ql_config']['marker'], $row['argv'], true)
            || in_array($GLOBALS['ql_run'] . '/supervisord.conf', $row['argv'], true)) {
            $GLOBALS['ns_owned'][$pid] = $row;
        }
    }
    return $rows;
}

function ns_owned_signal(array $identity, $signal)
{
    // NSpid values are evidence only: signals always use the verified HostNS PID.
    $rows = ns_track_descendants();
    ql_check(isset($rows[$identity['pid']]) && ll_same_process($identity)
        && $rows[$identity['pid']]['namespaces'] === $identity['namespaces'], 'exact_owned_host_signal',
        ['host_pid' => $identity['pid'], 'nspid' => $identity['nspid'], 'start_ticks' => $identity['start_ticks'], 'signal' => $signal]);
    $sent = ['wall' => microtime(true), 'monotonic' => hrtime(true)];
    if (!posix_kill($identity['pid'], $signal)) throw new RuntimeException('Owned HostNS signal failed.');
    return $sent;
}

function ns_php_args(array $command, $role)
{
    $cfg = $GLOBALS['ql_config'];
    return array_merge([$cfg['binary'], '-c', $cfg['ini'], __DIR__ . '/console.php', '--candidate',
        '--run=' . $cfg['run'], '--marker=' . $cfg['marker'], '--role=' . $role], $command);
}

function ns_intent($sn)
{
    $id = DB::table('orders')->insertGetId(['order_sn' => $sn, 'goods_id' => 1, 'info' => 'Synthetic namespace fixture',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    $order = Order::query()->findOrFail($id);
    config(['dujiaoka.payment_mail_outbox' => false]);
    DB::transaction(function () use ($order) {
        DB::table('orders')->where('id', $order->id)->update(['status' => Order::STATUS_COMPLETED, 'trade_no' => 'fixture-trade']);
        $order->refresh();
        app(NotificationOutboxService::class)->recordPayment($order);
    });
    ql_check(DB::transactionLevel() === 0, 'intent_committed_' . $sn);
    return $order;
}

function ns_outbox($order)
{
    return DB::table('notification_outbox')->where('order_id', $order->id)->where('channel', 'api-hook')->first();
}

function ns_snapshot($name)
{
    $tables = [];
    foreach (['jobs', 'failed_jobs', 'notification_outbox', 'orders'] as $table) $tables[$table] = DB::table($table)->orderBy('id')->get()->toArray();
    ql_json($GLOBALS['ql_run'] . '/sql-' . $name . '.json', ['time' => microtime(true), 'tables' => $tables]);
    ql_log('SqlSnapshot', ['name' => $name]);
    return $tables;
}

function ns_hashes($root, $source)
{
    $paths = ['deploy/supervisor/queue.conf', 'app/Jobs/DeliverNotification.php', 'app/Service/NotificationOutboxService.php',
        'tests/Integration/LinuxLifecycle/run.php', 'tests/Integration/LinuxLifecycle/process.php',
        'tests/Integration/QueueLifecycle/bootstrap.php', 'tests/Integration/QueueLifecycle/console.php',
        'tests/Compatibility/Laravel12/vendor/laravel/framework/src/Illuminate/Queue/Worker.php'];
    $hashes = [];
    foreach ($paths as $path) $hashes[$path] = hash_file('sha256', $root . '/' . $path);
    foreach (['supervisord.py', 'options.py', 'process.py'] as $path) $hashes[$source . '/supervisor/' . $path] = hash_file('sha256', $source . '/supervisor/' . $path);
    foreach (['/etc/passwd', '/etc/group'] as $path) $hashes[$path] = hash_file('sha256', $path);
    return $hashes;
}

$app = null;
$exit = 1;
$failure = null;
$before = [];
$runtime = [];
$timing = [];
$supervisorIdentity = null;
$workerIdentity = null;
$GLOBALS['ll_processes'] = [];
$GLOBALS['ns_owned'] = [];
try {
    $candidate = false;
    $source = null;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--candidate') $candidate = true;
        elseif (strpos($arg, '--supervisor-source=') === 0) $source = substr($arg, 20);
        else throw new RuntimeException('Unknown namespace fixture argument: ' . $arg);
    }
    if (!$candidate || PHP_OS_FAMILY !== 'Linux' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600
        || !extension_loaded('pcntl') || !extension_loaded('posix') || !extension_loaded('pdo_sqlite') || posix_geteuid() === 0) {
        throw new RuntimeException('Requires unprivileged Linux candidate PHP 8.5 with pcntl, posix and pdo_sqlite.');
    }
    if (!$source || $source[0] !== '/' || !is_dir($source . '/supervisor')) throw new RuntimeException('Requires --supervisor-source=ABS.');
    $source = ql_normalize(realpath($source));
    $root = ql_normalize(realpath(dirname(__DIR__, 3)));
    $before = ns_hashes($root, $source);
    $marker = bin2hex(random_bytes(12));
    $run = $root . '/tests/.runtime/queue-lifecycle/run-ns-' . gmdate('Ymd-His') . '-' . $marker;
    if (file_exists($run) || !mkdir($run, 0700, true)) throw new RuntimeException('Fresh runtime directory failed.');
    foreach (['cache', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/cache/locks',
        'storage/framework/sessions', 'storage/logs', 'storage/app'] as $dir) {
        if (!mkdir($run . '/' . $dir, 0700, true)) throw new RuntimeException('Runtime subdirectory failed.');
    }
    $ini = ql_normalize((string) php_ini_loaded_file());
    if ($ini === '' || $ini[0] !== '/' || !is_file($ini)) throw new RuntimeException('Requires an explicit Linux PHP INI.');
    $cfg = ['marker' => $marker, 'root' => $root, 'run' => $run, 'candidate' => true,
        'binary' => ql_normalize(realpath(PHP_BINARY)), 'ini' => $ini, 'key' => 'base64:' . base64_encode(random_bytes(32))];
    ql_json($run . '/run.json', $cfg);
    file_put_contents($run . '/marker', $marker, LOCK_EX);
    file_put_contents($run . '/passwd', "application:x:1000:1000:Fixture application:/app:/bin/sh\n", LOCK_EX);
    file_put_contents($run . '/group', "application:x:1000:\n", LOCK_EX);
    touch($run . '/queue.sqlite');
    ql_json($run . '/provider.json', ['mode' => '204']);
    $app = ql_boot($run, $marker, true, 'namespace-parent');
    ql_schema();
    ql_check(config('queue.connections.database.retry_after') === 90, 'database_retry_after_90');
    $parent = ns_identity(getmypid());
    $production = parse_ini_file($root . '/deploy/supervisor/queue.conf', true, INI_SCANNER_RAW)['program:dujiaoka-queue'];
    $expected = ['user' => 'application', 'numprocs' => '1', 'autostart' => 'true', 'autorestart' => 'true',
        'startsecs' => '5', 'startretries' => '10', 'stopasgroup' => 'true', 'killasgroup' => 'true', 'stopwaitsecs' => '90'];
    foreach ($expected as $key => $value) ql_check($production[$key] === $value, 'production_' . $key);
    ql_check(strpos($production['command'], '--sleep=3') !== false && strpos($production['command'], '--tries=2') !== false
        && strpos($production['command'], '--timeout=60') !== false, 'production_worker_options');
    $worker = ns_php_args(['queue:work', 'database', '--queue=fixture35', '--sleep=3', '--tries=2', '--timeout=60'], 'namespace-worker');
    foreach (array_merge($worker, [$source, $run]) as $value) {
        if (strpbrk($value, "%\r\n") !== false) throw new RuntimeException('Unsupported Supervisor interpolation in path.');
    }
    $isolated = $production;
    $isolated['command'] = implode(' ', array_map('escapeshellarg', $worker));
    $isolated['directory'] = $root;
    $isolated['stdout_logfile'] = $run . '/worker.stdout';
    $isolated['stopsignal'] = 'TERM';
    $conf = "[unix_http_server]\nfile=/tmp/control.sock\nchmod=0700\n"
        . "[supervisord]\nnodaemon=true\nloglevel=debug\nlogfile=" . $run . "/supervisord.log\nlogfile_maxbytes=0\n"
        . "pidfile=/tmp/supervisord.pid\nchildlogdir=" . $run . "\n"
        . "[rpcinterface:supervisor]\nsupervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface\n"
        . "[program:dujiaoka-queue]\n";
    foreach ($isolated as $key => $value) $conf .= $key . '=' . $value . "\n";
    file_put_contents($run . '/supervisord.conf', $conf, LOCK_EX);
    $runtime = ['php' => PHP_VERSION, 'binary' => $cfg['binary'], 'binary_sha256' => hash_file('sha256', $cfg['binary']),
        'ini' => $ini, 'ini_sha256' => hash_file('sha256', $ini), 'scanned_ini' => php_ini_scanned_files(),
        'host_parent' => $parent, 'host_uid' => posix_geteuid(), 'host_gid' => posix_getegid(),
        'source' => $source, 'config_path' => $run . '/supervisord.conf', 'config_sha256' => hash('sha256', $conf),
        'production_config_sha256' => $before['deploy/supervisor/queue.conf'], 'production_program' => $production,
        'isolated_program' => $isolated, 'worker_command' => $worker,
        'mounts' => 'Private recursive mount namespace; synthetic passwd/group read-only binds; private tmpfs /tmp mode0700; inner proc mount',
        'native_timeout_60s' => 'Retained, not exercised', 'docker_image_and_crontick' => 'NOT_RUN'];
    ql_json($run . '/namespace-runtime.json', $runtime);
    ll_spawn(['unshare', '--user', '--map-root-user', '--mount', '/bin/sh', __DIR__ . '/namespace.sh', 'outer', $run, $marker, $source], 'namespace-launcher');
    ll_until(function () use ($run) { return is_file($run . '/namespace-supervisor.json') && is_file($run . '/namespace-worker.json'); }, 'namespace identities', 20);
    $inside = json_decode(file_get_contents($run . '/namespace-supervisor.json'), true, 512, JSON_THROW_ON_ERROR);
    $workerInside = json_decode(file_get_contents($run . '/namespace-worker.json'), true, 512, JSON_THROW_ON_ERROR);
    $supervisorIdentity = ll_until(function () use ($run) {
        foreach (ns_track_descendants() as $row) {
            if (end($row['nspid']) === 1 && in_array('supervisor.supervisord', $row['argv'], true)
                && in_array($run . '/supervisord.conf', $row['argv'], true)) return $row;
        }
        return false;
    }, 'owned Supervisor HostNS to PID1 mapping', 5);
    $workerIdentity = ll_until(function () use ($workerInside, $supervisorIdentity, $cfg) {
        foreach (ns_track_descendants() as $row) {
            if (end($row['nspid']) === $workerInside['pid'] && $row['ppid'] === $supervisorIdentity['pid']
                && $row['exe'] === $cfg['binary'] && in_array('--marker=' . $cfg['marker'], $row['argv'], true)
                && in_array($cfg['ini'], $row['argv'], true) && in_array(__DIR__ . '/console.php', $row['argv'], true)) return $row;
        }
        return false;
    }, 'owned worker HostNS mapping', 5);
    ql_check($inside['marker'] === $marker && $inside['pid'] === 1 && $inside['uid'] === 1000 && $inside['gid'] === 1000
        && $inside['user'] === 'application' && $inside['group'] === 'application' && $inside['version'] === '4.3.0'
        && strpos($inside['source'], $source . '/supervisor/') === 0 && $inside['socket_directory_mode'] === 0700,
        'actual_application_Supervisor_PID1', $inside);
    ql_check($workerInside['marker'] === $marker && $workerInside['uid'] === 1000 && $workerInside['gid'] === 1000
        && $workerInside['user'] === 'application' && $workerInside['group'] === 'application'
        && $workerInside['binary'] === $cfg['binary'] && $workerInside['ini'] === $ini && $workerInside['php'] === PHP_VERSION,
        'actual_application_worker_same_php_ini', $workerInside);
    foreach (['user', 'mnt', 'pid'] as $name) {
        ql_check($supervisorIdentity['namespaces'][$name] !== $parent['namespaces'][$name]
            && $supervisorIdentity['namespaces'][$name] === $inside['namespaces'][$name]
            && $workerIdentity['namespaces'][$name] === $supervisorIdentity['namespaces'][$name]
            && $workerInside['namespaces'][$name] === $supervisorIdentity['namespaces'][$name], 'real_isolated_namespace_' . $name);
    }
    ql_check(count($supervisorIdentity['nspid']) > count($parent['nspid'])
        && $supervisorIdentity['pid'] !== 1 && $workerIdentity['pid'] !== $workerInside['pid'], 'host_and_inner_PID_are_distinct');
    ql_json($run . '/pid-mapping.json', ['parent' => $parent, 'launcher' => $GLOBALS['ll_processes']['namespace-launcher']['identity'],
        'supervisor' => $supervisorIdentity, 'worker' => $workerIdentity, 'supervisor_inside' => $inside, 'worker_inside' => $workerInside]);
    ll_until(function () use ($run) { return strpos((string) @file_get_contents($run . '/supervisord.log'), 'entered RUNNING state') !== false; }, 'Supervisor worker RUNNING', 15);
    $first = ns_intent('D-ns-first-' . $marker);
    $second = ns_intent('D-ns-next-' . $marker);
    ql_json($run . '/provider.json', ['mode' => 'pause', 'order' => $first->order_sn, 'gate' => 'D']);
    ll_spawn(ns_php_args(['notifications:dispatch'], 'namespace-publisher'), 'publisher');
    ql_check(ll_wait('publisher', 10) === 0, 'original_dispatcher_exit_0');
    $ready = ll_until(function () use ($run) {
        if (!is_file($run . '/D.ready.json')) return false;
        $ready = json_decode(file_get_contents($run . '/D.ready.json'), true);
        return is_array($ready) && isset($ready['pid'], $ready['outbox_id']) ? $ready : false;
    }, 'original provider gate ready', 10);
    $jobs = DB::table('jobs')->orderBy('id')->get();
    ql_check($ready['pid'] === $workerInside['pid'] && $ready['outbox_id'] === (int) ns_outbox($first)->id
        && count($jobs) === 2 && $jobs[0]->reserved_at !== null && (int) $jobs[0]->attempts === 1
        && $jobs[1]->reserved_at === null && (int) $jobs[1]->attempts === 0, 'original_task_reserved_next_pending', ['ready' => $ready]);
    $secondJobId = (int) $jobs[1]->id;
    $payload = json_decode($jobs[0]->payload, true, 512, JSON_THROW_ON_ERROR);
    ql_check($payload['displayName'] === App\Jobs\DeliverNotification::class && $payload['timeout'] === 60
        && $payload['maxTries'] === 1, 'original_DeliverNotification_payload_timeout60_tries1');
    ns_snapshot('before-TERM');
    $sent = ns_owned_signal($supervisorIdentity, SIGTERM);
    $termWall = $sent['wall'];
    $termMono = $sent['monotonic'];
    ql_log('HostSIGTERM', ['host_pid' => $supervisorIdentity['pid'], 'namespace_pid' => 1, 'sent_wall' => $termWall]);
    ll_until(function () use ($run, $workerInside) {
        $log = (string) @file_get_contents($run . '/supervisord.log');
        return strpos($log, 'received SIGTERM') !== false
            && preg_match('/killing[^\n]*\b' . $workerInside['pid'] . '\b[^\n]*signal SIGTERM/', $log);
    }, 'PID1 forwards actual SIGTERM to worker', 2);
    ll_until(function () use ($termMono) { return (hrtime(true) - $termMono) / 1e9 >= 10.5; }, '10.5 seconds since HostNS SIGTERM', 12);
    $held = (hrtime(true) - $termMono) / 1e9;
    $pending = DB::table('jobs')->where('id', $secondJobId)->first();
    $firstRow = ns_outbox($first);
    ql_check($held >= 10.5 && ll_same_process($supervisorIdentity) && ll_same_process($workerIdentity)
        && proc_get_status($GLOBALS['ll_processes']['namespace-launcher']['proc'])['running']
        && !$firstRow->delivered_at && $firstRow->reserved_until && (int) $firstRow->attempts === 1
        && count(ns_events('ProviderResponse', 'namespace-worker')) === 0
        && count(ns_events('JobProcessed', 'namespace-worker')) === 0
        && count(ns_events('ConsoleExit', 'namespace-worker')) === 0, 'TERM_drains_beyond_Docker_default_10s',
        ['held_seconds' => $held, 'supervisor_host_pid' => $supervisorIdentity['pid'], 'worker_host_pid' => $workerIdentity['pid']]);
    ql_check(DB::table('jobs')->count() === 2 && $pending && $pending->reserved_at === null && (int) $pending->attempts === 0
        && (int) ns_outbox($second)->attempts === 0 && !ns_outbox($second)->delivered_at, 'next_unreserved_at_10_5s');
    ns_snapshot('TERM-held-10s');
    $releaseWall = microtime(true);
    file_put_contents($run . '/D.release', 'release', LOCK_EX);
    ql_log('ProviderGateReleased', ['since_TERM_seconds' => (hrtime(true) - $termMono) / 1e9]);
    ql_check(ll_wait('namespace-launcher', 20) === 0, 'Supervisor_PID1_forwarded_exit_0');
    ll_until(function () use ($supervisorIdentity, $workerIdentity) { return !is_dir('/proc/' . $supervisorIdentity['pid']) && !is_dir('/proc/' . $workerIdentity['pid']); }, 'namespace processes disappear', 5);
    $elapsed = (hrtime(true) - $termMono) / 1e9;
    $firstRow = ns_outbox($first);
    $pending = DB::table('jobs')->where('id', $secondJobId)->first();
    $responses = ns_events('ProviderResponse', 'namespace-worker');
    $processed = ns_events('JobProcessed', 'namespace-worker');
    $consoleExit = ns_events('ConsoleExit', 'namespace-worker');
    ql_check($firstRow->delivered_at && !$firstRow->reserved_until && !$firstRow->reservation_token && !$firstRow->failed_at
        && (int) $firstRow->attempts === 1 && $firstRow->last_error === null && count($responses) === 1
        && $responses[0]['order_sn'] === $first->order_sn && $responses[0]['status'] === 204
        && $responses[0]['transaction_level'] === 0 && count($processed) === 1 && $processed[0]['transaction_level'] === 0,
        'original_task_204_txn0_delivered_once');
    ql_check(count($consoleExit) === 1 && $consoleExit[0]['exit'] === 0
        && count(ns_events('ConnectionClosed', 'namespace-worker')) === 1
        && strpos(file_get_contents($run . '/supervisord.log'), 'stopped: dujiaoka-queue (exit status 0)') !== false,
        'real_worker_exit0_and_DB_closed');
    ql_check(DB::table('jobs')->count() === 1 && $pending && $pending->reserved_at === null && (int) $pending->attempts === 0
        && (int) ns_outbox($second)->attempts === 0 && !ns_outbox($second)->delivered_at
        && count(ns_events('JobProcessing', 'namespace-worker')) === 1 && DB::table('failed_jobs')->count() === 0,
        'next_job_still_pending_after_PID1_exit');
    $readyEvents = ns_events('ProviderReady', 'namespace-worker');
    $timing = ['TERM_wall' => $termWall, 'held_seconds' => $held, 'release_wall' => $releaseWall,
        'provider_gate_seconds' => $releaseWall - $readyEvents[0]['time'], 'Supervisor_exit_after_TERM_seconds' => $elapsed,
        'ProviderResponse_after_TERM_seconds' => $responses[0]['time'] - $termWall,
        'worker_exit_after_TERM_seconds' => $consoleExit[0]['time'] - $termWall];
    ql_check($timing['provider_gate_seconds'] < 15 && $elapsed > 10 && $timing['worker_exit_after_TERM_seconds'] > 10,
        'actual_wall_clock_exceeds_10s_with_original_gate', $timing);
    ns_snapshot('after-PID1-exit');
    ql_log('ScenarioPassed', ['scenario' => 'namespace-PID1-SIGTERM-drain', 'timing' => $timing, 'next_job_id' => $secondJobId]);
    $exit = 0;
} catch (Throwable $e) {
    $failure = ['class' => get_class($e), 'message' => $e->getMessage()];
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    if (isset($GLOBALS['ql_run'])) ql_log('RunFailure', $failure);
} finally {
    $cleanupErrors = [];
    if (isset($GLOBALS['ql_run'])) {
        $run = $GLOBALS['ql_run'];
        if (is_file($run . '/D.ready.json')) file_put_contents($run . '/D.release', 'cleanup-release', LOCK_EX);
        if ($supervisorIdentity && ll_same_process($supervisorIdentity)) {
            try { ns_owned_signal($supervisorIdentity, SIGTERM); }
            catch (Throwable $e) { $cleanupErrors[] = $e->getMessage(); }
        }
        foreach (array_keys($GLOBALS['ll_processes']) as $label) {
            try { ll_cleanup_owned($label); }
            catch (Throwable $e) { $cleanupErrors[] = $e->getMessage(); }
        }
        foreach ($GLOBALS['ns_owned'] as $identity) {
            if (!ll_same_process($identity)) continue;
            // Previously proved descendants remain owned only while ticks and argv still match.
            posix_kill($identity['pid'], SIGTERM);
            try { ll_until(function () use ($identity) { return !ll_same_process($identity); }, 'owned descendant TERM cleanup', 3); }
            catch (Throwable $e) {
                if (ll_same_process($identity)) posix_kill($identity['pid'], SIGKILL);
                try { ll_until(function () use ($identity) { return !ll_same_process($identity); }, 'owned descendant KILL cleanup', 3); }
                catch (Throwable $e) { $cleanupErrors[] = $e->getMessage(); }
            }
        }
        $after = ns_hashes($root, $source);
        if ($before !== $after) { $exit = 1; $cleanupErrors[] = 'Protected source/config or HostNS account hashes changed.'; }
        $processes = [];
        foreach ($GLOBALS['ll_processes'] as $label => $child) {
            $processes[$label] = array_diff_key($child, ['proc' => true]);
            if (!$child['closed'] || ll_identity($child['pid'])) $cleanupErrors[] = 'Owned launcher/console not reaped: ' . $label;
        }
        $remaining = [];
        foreach ($GLOBALS['ns_owned'] as $identity) if (ll_same_process($identity)) $remaining[] = $identity;
        if ($remaining) $cleanupErrors[] = 'Owned namespace descendants remain.';
        if ($cleanupErrors) $exit = 1;
        $summary = ['status' => $exit === 0 ? 'PASS' : 'FAIL', 'exit' => $exit, 'failure' => $failure, 'marker' => $marker,
            'scenario' => 'namespace-PID1-SIGTERM-drain', 'runtime' => $runtime, 'timing' => $timing,
            'supervisor' => $supervisorIdentity, 'worker' => $workerIdentity, 'processes' => $processes,
            'owned_descendants' => array_values($GLOBALS['ns_owned']), 'remaining_owned' => $remaining, 'cleanup_errors' => $cleanupErrors,
            'provider_responses' => ns_events('ProviderResponse'), 'jobs_processing' => ns_events('JobProcessing'),
            'jobs_processed' => ns_events('JobProcessed'), 'jobs_failed' => ns_events('JobFailed'), 'config_evidence' => ns_events('Start'),
            'checks_passed' => count(ns_events('CheckPassed')), 'checks_failed' => ns_events('CheckFailed'),
            'protected_hashes_before' => $before, 'protected_hashes_after' => $after, 'protected_hashes_equal' => $before === $after,
            'docker_image_and_crontick' => 'NOT_RUN', 'native_timeout_60s' => 'NOT_RUN', 'pending_recovery_replay' => 'NOT_RUN'];
        if ($app) {
            try { $summary['final_sql'] = ns_snapshot('summary-final'); ql_disconnect($app); }
            catch (Throwable $e) { $exit = 1; $summary['final_sql_error'] = $e->getMessage(); }
        }
        $summary['status'] = $exit === 0 ? 'PASS' : 'FAIL';
        $summary['exit'] = $exit;
        ql_json($run . '/summary.json', $summary);
        fwrite(STDOUT, $summary['status'] . ' summary=' . $run . '/summary.json' . PHP_EOL);
    }
}
exit($exit);
