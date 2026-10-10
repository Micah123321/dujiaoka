<?php

require dirname(__DIR__) . '/QueueLifecycle/bootstrap.php';
require __DIR__ . '/process.php';

use App\Models\Order;
use App\Service\NotificationOutboxService;
use Illuminate\Support\Facades\DB;

function ll_events($event = null, $pid = null, $sn = null)
{
    $rows = [];
    $handle = fopen($GLOBALS['ql_run'] . '/events.jsonl', 'r');
    if (!$handle || !flock($handle, LOCK_SH)) throw new RuntimeException('Event read lock failed.');
    $text = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    foreach (explode("\n", trim($text)) as $line) {
        if ($line === '') continue;
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (($event === null || $row['event'] === $event) && ($pid === null || $row['pid'] === $pid)
            && ($sn === null || ($row['order_sn'] ?? null) === $sn)) $rows[] = $row;
    }
    return $rows;
}

function ll_php_args(array $command, $role)
{
    $cfg = $GLOBALS['ql_config'];
    return array_merge([$cfg['binary'], '-c', $cfg['ini'], dirname(__DIR__) . '/QueueLifecycle/console.php',
        '--candidate', '--run=' . $cfg['run'], '--marker=' . $cfg['marker'], '--role=' . $role], $command);
}

function ll_console(array $command, $role)
{
    ll_spawn(ll_php_args($command, $role), $role);
    ql_check(ll_wait($role) === 0, 'console_exit_' . $role);
}

function ll_ctl(array $command, $wait = true, array $allowed = [0])
{
    $label = 'ctl-' . (++$GLOBALS['ll_ctl_sequence']);
    ll_spawn(array_merge(['python3', '-m', 'supervisor.supervisorctl', '-c', $GLOBALS['ll_conf']], $command),
        $label, $GLOBALS['ll_python_env']);
    if (!$wait) return $label;
    $code = ll_wait($label);
    $out = trim(file_get_contents($GLOBALS['ql_run'] . '/' . $label . '.stdout'));
    ql_check(in_array($code, $allowed, true), 'supervisorctl_' . implode('_', $command), ['exit' => $code, 'output' => $out]);
    return $out;
}

function ll_status()
{
    // supervisorctl uses LSB exit 3 for STARTING, STOPPING and STOPPED.
    $text = ll_ctl(['status', 'dujiaoka-queue'], true, [0, 3]);
    ql_log('SupervisorStatus', ['output' => $text]);
    return $text;
}

function ll_worker($pid)
{
    $cfg = $GLOBALS['ql_config'];
    $identity = ll_identity($pid);
    ql_check($identity && $identity['exe'] === $cfg['binary']
        && in_array('--marker=' . $cfg['marker'], $identity['argv'], true)
        && in_array('--run=' . $cfg['run'], $identity['argv'], true)
        && in_array($cfg['ini'], $identity['argv'], true)
        && in_array(dirname(__DIR__) . '/QueueLifecycle/console.php', $identity['argv'], true)
        && $identity['ppid'] === $GLOBALS['ll_processes']['supervisord']['pid'], 'actual_owned_worker', ['identity' => $identity]);
    $GLOBALS['ll_workers'][$pid] = $identity;
    return $pid;
}

function ll_running_worker($previous = null)
{
    $status = ll_until(function () use ($previous) {
        $text = ll_status();
        if (preg_match('/\\bRUNNING\\s+pid (\\d+),/', $text, $m) && (int) $m[1] !== $previous) return (int) $m[1];
        return false;
    }, 'Supervisor RUNNING new worker', 20);
    return ll_worker($status);
}

function ll_outbox($order, $channel = 'api-hook')
{
    return DB::table('notification_outbox')->where('order_id', $order->id)->where('channel', $channel)->first();
}

function ll_intent($sn, $mail = false)
{
    $id = DB::table('orders')->insertGetId(['order_sn' => $sn, 'goods_id' => 1, 'info' => 'Synthetic Linux fixture',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    $order = Order::query()->findOrFail($id);
    $old = config('dujiaoka.payment_mail_outbox');
    config(['dujiaoka.payment_mail_outbox' => $mail]);
    try {
        DB::transaction(function () use ($order) {
            DB::table('orders')->where('id', $order->id)->update(['status' => Order::STATUS_COMPLETED, 'trade_no' => 'fixture-trade']);
            $order->refresh();
            app(NotificationOutboxService::class)->recordPayment($order);
        });
    } finally {
        config(['dujiaoka.payment_mail_outbox' => $old]);
    }
    ql_check(DB::transactionLevel() === 0, 'intent_committed_' . $sn);
    return $order;
}

function ll_done($order, $mail = false)
{
    ll_until(function () use ($order, $mail) {
        $api = ll_outbox($order);
        $mailRow = $mail ? ll_outbox($order, 'payment-mail') : null;
        return $api && $api->delivered_at && (!$mail || ($mailRow && $mailRow->delivered_at))
            && DB::table('jobs')->count() === 0;
    }, 'delivered ' . $order->order_sn);
    foreach ($mail ? ['api-hook', 'payment-mail'] : ['api-hook'] as $channel) {
        $row = ll_outbox($order, $channel);
        ql_check($row->delivered_at && !$row->failed_at && !$row->reserved_until && !$row->reservation_token
            && (int) $row->attempts === 1 && $row->last_error === null && (!$mail || $channel !== 'payment-mail' || $row->payload === null),
            'delivered_once_' . $channel . '_' . $order->order_sn);
    }
    ql_check(count(ll_events('ProviderResponse', null, $order->order_sn)) === 1, 'provider_once_' . $order->order_sn);
    if ($mail) ql_check(count(ll_events('MailAccepted', null, $order->order_sn)) === 1, 'array_mail_once_' . $order->order_sn);
}

function ll_snapshot($name)
{
    $tables = [];
    foreach (['jobs', 'failed_jobs', 'notification_outbox', 'orders'] as $table) {
        $tables[$table] = DB::table($table)->orderBy('id')->get()->toArray();
    }
    ql_json($GLOBALS['ql_run'] . '/sql-' . $name . '.json', ['time' => microtime(true), 'tables' => $tables]);
    ql_log('SqlSnapshot', ['name' => $name, 'path' => $GLOBALS['ql_run'] . '/sql-' . $name . '.json']);
    return $tables;
}

function ll_hashes($root)
{
    $hashes = [];
    foreach (['deploy/supervisor/queue.conf', 'app/Jobs/DeliverNotification.php',
        'tests/Integration/LinuxLifecycle/run.php', 'tests/Integration/LinuxLifecycle/process.php',
        'tests/Integration/QueueLifecycle/bootstrap.php', 'tests/Integration/QueueLifecycle/console.php',
        'tests/Integration/QueueLifecycle/run.php', 'tests/Compatibility/Laravel12/vendor/laravel/framework/src/Illuminate/Queue/Worker.php'] as $path) {
        $hashes[$path] = hash_file('sha256', $root . '/' . $path);
    }
    return $hashes;
}

$app = null;
$exit = 1;
$failure = null;
$before = [];
$scenarios = [];
$GLOBALS['ll_processes'] = [];
$GLOBALS['ll_workers'] = [];
$GLOBALS['ll_ctl_sequence'] = 0;
$socketDir = null;
try {
    $source = null;
    $candidate = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--candidate') $candidate = true;
        elseif (strpos($arg, '--supervisor-source=') === 0) $source = substr($arg, 20);
        else throw new RuntimeException('Unknown Linux fixture argument: ' . $arg);
    }
    if (!$candidate || PHP_OS_FAMILY !== 'Linux' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600
        || !extension_loaded('pcntl') || !extension_loaded('posix') || !extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('Requires Linux candidate PHP 8.5 with pcntl, posix and pdo_sqlite.');
    }
    if (!$source || $source[0] !== '/' || !is_dir($source . '/supervisor')) throw new RuntimeException('Requires --supervisor-source=ABS.');
    $source = ql_normalize(realpath($source));
    $root = ql_normalize(realpath(dirname(__DIR__, 3)));
    $before = ll_hashes($root);
    $marker = bin2hex(random_bytes(12));
    $run = $root . '/tests/.runtime/queue-lifecycle/run-linux-' . gmdate('Ymd-His') . '-' . $marker;
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
    touch($run . '/queue.sqlite');
    ql_json($run . '/provider.json', ['mode' => '204']);
    $app = ql_boot($run, $marker, true, 'linux-parent');
    ql_schema();
    ql_check(config('queue.connections.database.retry_after') === 90, 'database_retry_after_90');
    ql_check(posix_geteuid() !== 0, 'unprivileged_user_level_supervisor', ['uid' => posix_geteuid(), 'gid' => posix_getegid()]);
    $pythonEnv = getenv();
    $pythonEnv['PYTHONPATH'] = $source;
    $pythonEnv['PYTHONDONTWRITEBYTECODE'] = '1';
    $GLOBALS['ll_python_env'] = $pythonEnv;
    ll_spawn(['python3', '-c', 'import json,os,sys,supervisor,supervisor.options; print(json.dumps({"version":supervisor.options.VERSION,"source":os.path.realpath(supervisor.__file__),"python":sys.executable}))'],
        'supervisor-version', $pythonEnv);
    ql_check(ll_wait('supervisor-version') === 0, 'supervisor_version_command');
    $supervisor = json_decode(trim(file_get_contents($run . '/supervisor-version.stdout')), true, 512, JSON_THROW_ON_ERROR);
    ql_check($supervisor['version'] === '4.3.0' && strpos($supervisor['source'], $source . '/supervisor/') === 0,
        'actual_supervisor_4_3_source', $supervisor);
    $socketDir = '/tmp/dujiaoka-linux-' . $marker;
    if (file_exists($socketDir) || !mkdir($socketDir, 0700) || !chmod($socketDir, 0700)) throw new RuntimeException('Fresh private socket directory failed.');
    clearstatcache(true, $socketDir);
    ql_check((fileperms($socketDir) & 0777) === 0700, 'socket_directory_0700');
    $GLOBALS['ll_conf'] = $run . '/supervisord.conf';
    $worker = ll_php_args(['queue:work', 'database', '--queue=fixture35', '--sleep=3', '--tries=2', '--timeout=60'], 'linux-worker');
    foreach (array_merge($worker, [$source, $run]) as $value) {
        if (strpbrk($value, "%\r\n") !== false) throw new RuntimeException('Unsupported Supervisor interpolation in path.');
    }
    $production = parse_ini_file($root . '/deploy/supervisor/queue.conf', true, INI_SCANNER_RAW)['program:dujiaoka-queue'];
    $expected = ['autorestart' => 'true', 'startsecs' => '5', 'startretries' => '10', 'stopasgroup' => 'true',
        'killasgroup' => 'true', 'stopwaitsecs' => '90'];
    foreach ($expected as $key => $value) ql_check($production[$key] === $value, 'production_' . $key);
    ql_check(strpos($production['command'], '--tries=2') !== false && strpos($production['command'], '--timeout=60') !== false,
        'production_worker_tries_timeout');
    $conf = "[unix_http_server]\nfile=" . $socketDir . "/control.sock\nchmod=0700\n"
        . "[supervisord]\nnodaemon=true\nloglevel=debug\nlogfile=" . $run . "/supervisord.log\nlogfile_maxbytes=0\n"
        . "pidfile=" . $socketDir . "/supervisord.pid\nchildlogdir=" . $run . "\n"
        . "[rpcinterface:supervisor]\nsupervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface\n"
        . "[supervisorctl]\nserverurl=unix://" . $socketDir . "/control.sock\n"
        . "[program:dujiaoka-queue]\ncommand=" . implode(' ', array_map('escapeshellarg', $worker)) . "\ndirectory=" . $root
        . "\nnumprocs=1\nautostart=true\nautorestart=true\nstartsecs=5\nstartretries=10\nstopsignal=TERM\n"
        . "stopasgroup=true\nkillasgroup=true\nstopwaitsecs=90\nredirect_stderr=true\nstdout_logfile=" . $run
        . "/worker.stdout\nstdout_logfile_maxbytes=0\n";
    file_put_contents($GLOBALS['ll_conf'], $conf, LOCK_EX);
    ql_json($run . '/linux-runtime.json', ['php' => PHP_VERSION, 'binary' => $cfg['binary'], 'ini' => $ini,
        'ini_sha256' => hash_file('sha256', $ini), 'uid' => posix_geteuid(), 'gid' => posix_getegid(),
        'supervisor' => $supervisor, 'source' => $source,
        'socket_dir' => $socketDir, 'worker_command' => $worker, 'config' => $conf,
        'production_hashes_before' => $before, 'native_timeout' => 'not requested: shared provider gate is 15 seconds']);
    ll_spawn(['python3', '-m', 'supervisor.supervisord', '-n', '-c', $GLOBALS['ll_conf']], 'supervisord', $pythonEnv);
    ll_until(function () use ($socketDir) { return file_exists($socketDir . '/control.sock'); }, 'Supervisor private socket');
    $firstPid = ll_running_worker();
    ql_log('ScenarioStart', ['scenario' => 'SIGTERM-stop', 'worker_pid' => $firstPid]);
    $first = ll_intent('D-linux-first-' . $marker);
    $second = ll_intent('D-linux-second-' . $marker);
    ql_json($run . '/provider.json', ['mode' => 'pause', 'order' => $first->order_sn, 'gate' => 'D']);
    ll_console(['notifications:dispatch'], 'linux-publish-first');
    $ready = ll_until(function () use ($run) {
        if (!is_file($run . '/D.ready.json')) return false;
        $ready = json_decode(file_get_contents($run . '/D.ready.json'), true);
        return is_array($ready) && isset($ready['pid'], $ready['outbox_id']) ? $ready : false;
    }, 'paused D provider');
    $jobs = DB::table('jobs')->orderBy('id')->get();
    ql_check($ready['pid'] === $firstPid && $ready['outbox_id'] === (int) ll_outbox($first)->id
        && count($jobs) === 2 && $jobs[0]->reserved_at !== null && (int) $jobs[0]->attempts === 1
        && $jobs[1]->reserved_at === null && (int) $jobs[1]->attempts === 0, 'D_current_reserved_next_pending', ['ready' => $ready]);
    $secondJobId = (int) $jobs[1]->id;
    ll_snapshot('D-paused');
    $stopLabel = ll_ctl(['stop', 'dujiaoka-queue'], false);
    ll_until(function () use ($run, $firstPid) {
        return preg_match('/killing[^\n]*\\b' . $firstPid . '\\b[^\n]*signal SIGTERM/', (string) @file_get_contents($run . '/supervisord.log'));
    }, 'Supervisor sends native SIGTERM', 5);
    ql_check(strpos(ll_status(), 'STOPPING') !== false && ll_same_process($GLOBALS['ll_workers'][$firstPid])
        && !ll_outbox($first)->delivered_at && ll_outbox($first)->reserved_until && (int) ll_outbox($first)->attempts === 1
        && count(ll_events('ProviderResponse', null, $first->order_sn)) === 0
        && count(ll_events('ConsoleExit', $firstPid)) === 0, 'SIGTERM_waits_for_inflight_provider');
    ll_snapshot('D-SIGTERM-pending');
    file_put_contents($run . '/D.release', 'release', LOCK_EX);
    ql_check(ll_wait($stopLabel) === 0, 'supervisor_stop_exit_0');
    ll_until(function () use ($firstPid) { return !is_dir('/proc/' . $firstPid); }, 'stopped worker PID disappears');
    ql_check(strpos(ll_status(), 'STOPPED') !== false, 'supervisor_STOPPED');
    $console = ll_events('ConsoleExit', $firstPid);
    ql_check(count($console) === 1 && $console[0]['exit'] === 0, 'SIGTERM_worker_actual_exit_0');
    $row = ll_outbox($first);
    $pending = DB::table('jobs')->where('id', $secondJobId)->first();
    ql_check($row->delivered_at && !$row->reserved_until && !$row->reservation_token && !$row->failed_at
        && (int) $row->attempts === 1 && $row->last_error === null
        && count(ll_events('ProviderResponse', null, $first->order_sn)) === 1, 'D_first_finished_once');
    ql_check(DB::table('jobs')->count() === 1 && $pending && $pending->reserved_at === null && (int) $pending->attempts === 0
        && (int) ll_outbox($second)->attempts === 0 && !ll_outbox($second)->delivered_at
        && count(ll_events('ProviderResponse', null, $second->order_sn)) === 0, 'D_second_never_reserved_after_stop');
    ll_snapshot('D-stopped');
    $scenarios[] = 'SIGTERM-stop';
    ql_log('ScenarioPassed', ['scenario' => 'SIGTERM-stop', 'worker_pid' => $firstPid, 'next_job_id' => $secondJobId]);

    ql_log('ScenarioStart', ['scenario' => 'restart-autorestart']);
    ql_json($run . '/provider.json', ['mode' => '204']);
    ll_ctl(['start', 'dujiaoka-queue']);
    $secondPid = ll_running_worker($firstPid);
    ll_done($second);
    ll_snapshot('restart-next-job');
    ql_check(count(ll_events('ProviderResponse', $secondPid, $second->order_sn)) === 1, 'started_worker_consumed_next_once');
    ql_check(app('cache')->get('illuminate:queue:restart') === null, 'restart_timestamp_initially_absent');
    ll_console(['queue:restart'], 'linux-queue-restart');
    ql_check(is_int(app('cache')->get('illuminate:queue:restart')), 'real_queue_restart_filecache_timestamp');
    ll_until(function () use ($secondPid) { return !is_dir('/proc/' . $secondPid); }, 'queue restart old PID disappears');
    $console = ll_events('ConsoleExit', $secondPid);
    ql_check(count($console) === 1 && $console[0]['exit'] === 0, 'queue_restart_worker_actual_exit_0');
    $thirdPid = ll_running_worker($secondPid);
    ql_check($thirdPid !== $firstPid && $thirdPid !== $secondPid, 'autorestart_distinct_new_PID');
    $third = ll_intent('E-linux-autorestart-' . $marker, true);
    ll_console(['notifications:dispatch'], 'linux-publish-autorestart');
    ll_done($third, true);
    ql_check(count(ll_events('ProviderResponse', $thirdPid, $third->order_sn)) === 1
        && count(ll_events('MailAccepted', $thirdPid, $third->order_sn)) === 1, 'autorestarted_worker_consumes_provider_and_arraymail');
    ll_snapshot('autorestart-consumed');
    ql_check(DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === 0
        && DB::table('notification_outbox')->whereNull('delivered_at')->count() === 0, 'all_synthetic_deliveries_complete');
    ll_ctl(['shutdown']);
    ql_check(ll_wait('supervisord', 20) === 0, 'supervisord_actual_shutdown_exit_0');
    foreach (array_keys($GLOBALS['ll_workers']) as $pid) {
        ll_until(function () use ($pid) { return !is_dir('/proc/' . $pid); }, 'all owned worker PIDs disappear');
        ql_check(count(ll_events('ConnectionClosed', $pid)) === 1, 'worker_closed_DB_' . $pid);
    }
    $console = ll_events('ConsoleExit', $thirdPid);
    ql_check(count($console) === 1 && $console[0]['exit'] === 0, 'shutdown_worker_actual_exit_0');
    foreach ($GLOBALS['ll_processes'] as $label => $child) {
        ql_check($child['closed'] && $child['exit'] === 0 || strpos($label, 'ctl-') === 0 && $child['closed'] && $child['exit'] === 3,
            'owned_process_reaped_' . $label, ['pid' => $child['pid'], 'exit' => $child['exit']]);
        clearstatcache();
        ql_check(!is_dir('/proc/' . $child['pid']), 'owned_PID_disappeared_' . $label);
    }
    ll_snapshot('shutdown-final');
    $scenarios[] = 'restart-autorestart';
    ql_log('ScenarioPassed', ['scenario' => 'restart-autorestart', 'worker_pids' => [$secondPid, $thirdPid]]);
    $exit = 0;
} catch (Throwable $e) {
    $failure = ['class' => get_class($e), 'message' => $e->getMessage()];
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    if (isset($GLOBALS['ql_run'])) ql_log('RunFailure', $failure);
} finally {
    $cleanupErrors = [];
    if (isset($GLOBALS['ql_run'])) {
        $run = $GLOBALS['ql_run'];
        // Release only this run's gate before asking its Supervisor to stop.
        if (is_file($run . '/D.ready.json')) file_put_contents($run . '/D.release', 'cleanup-release', LOCK_EX);
        if (isset($GLOBALS['ll_processes']['supervisord']) && !$GLOBALS['ll_processes']['supervisord']['closed']) {
            try {
                if ($socketDir && file_exists($socketDir . '/control.sock')) ll_ctl(['shutdown']);
                ll_wait('supervisord', 20);
            } catch (Throwable $e) { $cleanupErrors[] = $e->getMessage(); }
        }
        foreach ($GLOBALS['ll_workers'] as $identity) {
            if (ll_same_process($identity)) {
                posix_kill($identity['pid'], SIGTERM);
                try { ll_until(function () use ($identity) { return !ll_same_process($identity); }, 'owned worker cleanup', 5); }
                catch (Throwable $e) {
                    if (ll_same_process($identity)) posix_kill($identity['pid'], SIGKILL);
                    $cleanupErrors[] = $e->getMessage();
                }
            }
        }
        foreach ($GLOBALS['ll_processes'] as $label => &$child) {
            if ($child['closed']) continue;
            try { ll_cleanup_owned($label); }
            catch (Throwable $e) { $cleanupErrors[] = $e->getMessage(); }
        }
        unset($child);
        $after = ll_hashes($GLOBALS['ql_config']['root']);
        if ($before !== $after) { $exit = 1; $cleanupErrors[] = 'Protected production/fixture/Worker hashes changed.'; }
        if ($cleanupErrors) $exit = 1;
        $processes = [];
        foreach ($GLOBALS['ll_processes'] as $label => $child) $processes[$label] = array_diff_key($child, ['proc' => true]);
        $summary = ['status' => $exit === 0 ? 'PASS' : 'FAIL', 'exit' => $exit, 'failure' => $failure,
            'cleanup_errors' => $cleanupErrors, 'scenarios' => $scenarios, 'marker' => $GLOBALS['ql_config']['marker'],
            'php' => PHP_VERSION, 'binary' => $GLOBALS['ql_config']['binary'], 'ini' => $GLOBALS['ql_config']['ini'],
            'supervisor' => isset($supervisor) ? $supervisor : null, 'config_path' => isset($GLOBALS['ll_conf']) ? $GLOBALS['ll_conf'] : null,
            'workers' => array_values($GLOBALS['ll_workers']), 'processes' => $processes,
            'config_evidence' => ll_events('Start'), 'provider_responses' => ll_events('ProviderResponse'), 'mail_accepted' => ll_events('MailAccepted'),
            'jobs_processing' => ll_events('JobProcessing'), 'jobs_processed' => ll_events('JobProcessed'), 'jobs_failed' => ll_events('JobFailed'),
            'checks_passed' => count(ll_events('CheckPassed')), 'checks_failed' => ll_events('CheckFailed'),
            'protected_hashes_before' => $before, 'protected_hashes_after' => $after, 'protected_hashes_equal' => $before === $after,
            'native_timeout_60s' => 'NOT_RUN', 'docker_cron' => 'NOT_RUN'];
        if ($app) {
            try { $summary['final_sql'] = ll_snapshot('summary-final'); }
            catch (Throwable $e) {
                $exit = 1;
                $summary['status'] = 'FAIL';
                $summary['exit'] = 1;
                $summary['final_sql_error'] = $e->getMessage();
            }
            ql_disconnect($app);
        }
        ql_json($run . '/summary.json', $summary);
        fwrite(STDOUT, $summary['status'] . ' scenarios=' . implode(',', $scenarios) . ' summary=' . $run . '/summary.json' . PHP_EOL);
        if ($socketDir && is_dir($socketDir)) {
            foreach (['control.sock', 'supervisord.pid'] as $name) {
                if (file_exists($socketDir . '/' . $name)) unlink($socketDir . '/' . $name);
            }
            rmdir($socketDir);
        }
    }
}
exit($exit);
