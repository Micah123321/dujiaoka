<?php

require dirname(__DIR__) . '/QueueLifecycle/bootstrap.php';

use App\Jobs\DeliverNotification;
use App\Models\Order;
use App\Service\NotificationOutboxService;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\DB;

function lt_events($event = null, $pid = null)
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
        if (($event === null || $row['event'] === $event) && ($pid === null || $row['pid'] === $pid)) $rows[] = $row;
    }
    return $rows;
}

function lt_until(callable $condition, $seconds, $name)
{
    $deadline = microtime(true) + min(90, max(0, $seconds));
    do {
        clearstatcache();
        if ($value = $condition()) return $value;
        usleep(50000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Timed out: ' . $name);
}

function lt_identity($pid)
{
    $stat = @file_get_contents('/proc/' . $pid . '/stat');
    if ($stat === false) return null;
    $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
    if (count($fields) < 20) return null;
    return ['pid' => $pid, 'start_ticks' => $fields[19], 'ppid' => (int) $fields[1],
        'exe' => ql_normalize((string) @readlink('/proc/' . $pid . '/exe')),
        'argv' => explode("\0", rtrim((string) @file_get_contents('/proc/' . $pid . '/cmdline'), "\0"))];
}

function lt_owned(array $child)
{
    $identity = lt_identity($child['pid']);
    $cfg = $GLOBALS['ql_config'];
    return $identity && $identity['ppid'] === getmypid() && $identity['exe'] === $cfg['binary']
        && $identity['argv'] === $child['args'] && in_array('--marker=' . $cfg['marker'], $identity['argv'], true)
        && in_array('--run=' . $cfg['run'], $identity['argv'], true)
        && (!$child['identity'] || $child['identity']['start_ticks'] === $identity['start_ticks']) ? $identity : false;
}

function lt_spawn($label, array $command = [], $timeout = false)
{
    $cfg = $GLOBALS['ql_config'];
    $script = $timeout ? __DIR__ . '/timeout-console.php' : dirname(__DIR__) . '/QueueLifecycle/console.php';
    $args = array_merge([$cfg['binary'], '-c', $cfg['ini'], $script, '--candidate', '--run=' . $cfg['run'],
        '--marker=' . $cfg['marker'], '--role=' . $label], $command);
    $pipes = [];
    $proc = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $cfg['run'] . '/' . $label . '.stdout', 'a'],
        2 => ['file', $cfg['run'] . '/' . $label . '.stderr', 'a']], $pipes, $cfg['root'], null, ['bypass_shell' => true]);
    if (!is_resource($proc)) throw new RuntimeException('proc_open failed: ' . $label);
    $status = proc_get_status($proc);
    $GLOBALS['lt_processes'][$label] = ['proc' => $proc, 'pid' => $status['pid'], 'args' => $args, 'identity' => null,
        'started_at' => microtime(true), 'ended_at' => null, 'closed' => false, 'exit' => null,
        'status' => $status, 'cleanup_signals' => []];
    $child =& $GLOBALS['lt_processes'][$label];
    $child['identity'] = lt_until(function () use (&$child) { return lt_owned($child); }, 2, 'owned child identity ' . $label);
    ql_log('ProcessStarted', ['label' => $label, 'child_pid' => $child['pid'], 'args' => $args, 'identity' => $child['identity']]);
}

function lt_wait($label, $seconds = 30)
{
    $child =& $GLOBALS['lt_processes'][$label];
    if ($child['closed']) return $child['exit'];
    lt_until(function () use (&$child, $label) {
        if ($child['status']['running']) $child['status'] = proc_get_status($child['proc']);
        if ($child['status']['running']) return false;
        $child['exit'] = $child['status']['exitcode'];
        $child['proc_close'] = proc_close($child['proc']);
        if ($child['exit'] < 0 && $child['proc_close'] >= 0) $child['exit'] = $child['proc_close'];
        $child['closed'] = true;
        $child['ended_at'] = microtime(true);
        ql_log('ProcessExit', ['label' => $label, 'child_pid' => $child['pid'], 'exit' => $child['exit'],
            'signaled' => $child['status']['signaled'], 'termsig' => $child['status']['termsig'], 'status' => $child['status']]);
        return true;
    }, $seconds, 'process exit ' . $label);
    return $child['exit'];
}

function lt_console($label, array $command)
{
    lt_spawn($label, $command);
    ql_check(lt_wait($label) === 0, 'console_exit_' . $label);
}

function lt_payload($row, $outboxId, $token)
{
    $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
    ql_check($payload['displayName'] === DeliverNotification::class && $payload['data']['commandName'] === DeliverNotification::class
        && $payload['timeout'] === 60 && $payload['maxTries'] === 1, 'original_payload_properties');
    $job = unserialize($payload['data']['command'], ['allowed_classes' => [DeliverNotification::class]]);
    ql_check($job instanceof DeliverNotification && $job->timeout === 60 && $job->tries === 1
        && $job->outboxId === $outboxId && $job->reservationToken === $token, 'serialized_job_token_and_id');
    return $payload;
}

function lt_snapshot($name)
{
    $tables = [];
    foreach (['jobs', 'failed_jobs', 'notification_outbox', 'orders'] as $table) $tables[$table] = DB::table($table)->orderBy('id')->get()->toArray();
    $snapshot = ['time' => microtime(true), 'tables' => $tables, 'transaction_level' => DB::transactionLevel(),
        'pdo_in_transaction' => DB::connection()->getPdo()->inTransaction(), 'databases' => DB::select('PRAGMA database_list'),
        'journal_mode' => DB::select('PRAGMA journal_mode'), 'schema' => DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'table' ORDER BY name")];
    ql_json($GLOBALS['ql_run'] . '/sql-' . $name . '.json', $snapshot);
    ql_log('SqlSnapshot', ['name' => $name, 'transaction_level' => $snapshot['transaction_level']]);
    return $snapshot;
}

function lt_hashes($root)
{
    $paths = ['config/app.php', 'config/database.php', 'config/queue.php', 'bootstrap/app.php',
        'app/Jobs/DeliverNotification.php', 'app/Service/NotificationOutboxService.php', 'app/Console/Commands/DispatchNotifications.php',
        'tests/Integration/QueueLifecycle/bootstrap.php', 'tests/Integration/QueueLifecycle/console.php',
        'tests/Integration/QueueLifecycle/run.php', 'tests/Integration/LinuxLifecycle/run.php',
        'tests/Integration/LinuxLifecycle/timeout.php', 'tests/Integration/LinuxLifecycle/timeout-console.php'];
    foreach (['Worker.php', 'WorkerStopReason.php', 'Events/JobTimedOut.php', 'Events/WorkerStopping.php',
        'Events/JobProcessing.php', 'Events/JobFailed.php'] as $file) {
        $paths[] = 'tests/Compatibility/Laravel12/vendor/laravel/framework/src/Illuminate/Queue/' . $file;
    }
    $hashes = [];
    foreach ($paths as $path) $hashes[$path] = hash_file('sha256', $root . '/' . $path);
    return $hashes;
}

$app = null;
$exit = 1;
$failure = null;
$native = false;
$recovered = false;
$times = [];
$before = [];
$runtime = [];
$GLOBALS['lt_processes'] = [];
try {
    if (array_slice($argv, 1) !== ['--candidate'] || PHP_OS_FAMILY !== 'Linux' || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600
        || !extension_loaded('pcntl') || !extension_loaded('posix') || !extension_loaded('pdo_sqlite') || posix_geteuid() === 0) {
        throw new RuntimeException('Requires --candidate and unprivileged Linux PHP 8.5 with pcntl, posix and pdo_sqlite.');
    }
    $root = ql_normalize(realpath(dirname(__DIR__, 3)));
    $ini = ql_normalize((string) php_ini_loaded_file());
    if ($ini === '' || $ini[0] !== '/' || !is_file($ini) || getenv('PHP_INI_SCAN_DIR') !== '' || php_ini_scanned_files()) {
        throw new RuntimeException('Requires explicit Linux PHP INI and empty PHP_INI_SCAN_DIR.');
    }
    $before = lt_hashes($root);
    $marker = bin2hex(random_bytes(12));
    $run = $root . '/tests/.runtime/queue-lifecycle/run-timeout-' . gmdate('Ymd-His') . '-' . $marker;
    if (file_exists($run) || !mkdir($run, 0700, true)) throw new RuntimeException('Fresh timeout runtime directory failed.');
    foreach (['cache', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/cache/locks',
        'storage/framework/sessions', 'storage/logs', 'storage/app'] as $dir) {
        if (!mkdir($run . '/' . $dir, 0700, true)) throw new RuntimeException('Runtime subdirectory failed.');
    }
    $cfg = ['marker' => $marker, 'root' => $root, 'run' => $run, 'candidate' => true,
        'binary' => ql_normalize(realpath(PHP_BINARY)), 'ini' => $ini, 'key' => 'base64:' . base64_encode(random_bytes(32))];
    ql_json($run . '/run.json', $cfg);
    file_put_contents($run . '/marker', $marker, LOCK_EX);
    touch($run . '/queue.sqlite');
    ql_json($run . '/provider.json', ['mode' => '204']);
    $app = ql_boot($run, $marker, true, 'timeout-parent');
    ql_schema();
    $runtime = ['php' => PHP_VERSION, 'binary' => $cfg['binary'], 'binary_sha256' => hash_file('sha256', $cfg['binary']),
        'ini' => $ini, 'ini_sha256' => hash_file('sha256', $ini), 'scanned_ini' => php_ini_scanned_files(),
        'pcntl' => phpversion('pcntl'), 'posix' => phpversion('posix'), 'uid' => posix_geteuid(), 'gid' => posix_getegid(),
        'retry_after' => config('queue.connections.database.retry_after'), 'job_timeout' => 60, 'cli_timeout' => 1,
        'kill_implementation' => 'Worker::kill dispatches WorkerStopping then posix_kill(getmypid(), SIGKILL); exit(status) fallback'];
    ql_json($run . '/timeout-runtime.json', $runtime);
    $sn = 'T-timeout-' . $marker;
    $id = DB::table('orders')->insertGetId(['order_sn' => $sn, 'goods_id' => 1, 'info' => 'Synthetic timeout fixture',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    config(['dujiaoka.payment_mail_outbox' => false]);
    DB::transaction(function () use ($id) {
        DB::table('orders')->where('id', $id)->update(['status' => Order::STATUS_COMPLETED, 'trade_no' => 'fixture-trade']);
        app(NotificationOutboxService::class)->recordPayment(Order::query()->findOrFail($id));
    });
    ql_check(DB::transactionLevel() === 0 && DB::table('notification_outbox')->count() === 1, 'one_committed_api_intent');
    lt_console('timeout-publish', ['notifications:dispatch']);
    $outbox = DB::table('notification_outbox')->first();
    $original = DB::table('jobs')->first();
    ql_check($original && DB::table('jobs')->count() === 1 && (int) $original->attempts === 0, 'one_original_queued_job');
    $payload = lt_payload($original, (int) $outbox->id, $outbox->reservation_token);
    lt_snapshot('published');
    lt_spawn('timeout-worker', [], true);
    $workerPid = $GLOBALS['lt_processes']['timeout-worker']['pid'];
    $ready = lt_until(function () use ($workerPid) {
        $events = lt_events('ProviderReady', $workerPid);
        return $events ? $events[0] : false;
    }, 10, 'native provider ready');
    $claimed = DB::table('notification_outbox')->first();
    $reserved = DB::table('jobs')->first();
    ql_check($ready['pid'] === $workerPid && lt_owned($GLOBALS['lt_processes']['timeout-worker'])
        && (int) $ready['job_id'] === (int) $original->id && $ready['outbox_id'] === (int) $outbox->id
        && $ready['reservation_token'] === $claimed->reservation_token && $claimed->reservation_token !== $outbox->reservation_token
        && (int) $claimed->attempts === 1 && $reserved && $reserved->reserved_at && (int) $reserved->attempts === 1,
        'owned_worker_blocked_with_rotated_execution_token');
    lt_snapshot('provider-blocked');
    lt_wait('timeout-worker', max(0, 90 - (microtime(true) - $GLOBALS['lt_processes']['timeout-worker']['started_at'])));
    $processing = lt_events('JobProcessing', $workerPid);
    $timed = lt_events('JobTimedOut', $workerPid);
    $stopping = lt_events('WorkerStopping', $workerPid);
    $failedEvents = lt_events('JobFailed', $workerPid);
    ql_check(count($processing) === 1 && count($timed) === 1 && count($stopping) === 1 && count($failedEvents) === 1,
        'exact_real_timeout_events');
    $times = ['job_processing' => $processing[0]['time'], 'provider_ready' => $ready['provider_at'],
        'job_failed' => $failedEvents[0]['time'], 'job_timed_out' => $timed[0]['time'], 'worker_stopping' => $stopping[0]['time'],
        'processing_to_timeout_seconds' => $timed[0]['time'] - $processing[0]['time']];
    $child = $GLOBALS['lt_processes']['timeout-worker'];
    ql_check($times['processing_to_timeout_seconds'] >= 59 && $times['processing_to_timeout_seconds'] <= 65
        && $times['provider_ready'] >= $times['job_processing'] && $times['provider_ready'] < $times['job_timed_out']
        && $timed[0]['timeout'] === 60 && $timed[0]['job_timeout'] === 60 && $timed[0]['failed'] && $timed[0]['deleted']
        && (int) $timed[0]['job_id'] === (int) $original->id && $timed[0]['attempts'] === 1
        && $stopping[0]['reasonTimedOut'] && $stopping[0]['reason'] === 'timed_out'
        && $stopping[0]['status'] === Worker::EXIT_ERROR && (int) $stopping[0]['cli_timeout'] === 1 && (int) $stopping[0]['cli_tries'] === 1
        && $child['status']['signaled'] && $child['status']['termsig'] === SIGKILL && !$child['cleanup_signals']
        && !lt_events('ConsoleExit', $workerPid) && !lt_events('JobProcessed', $workerPid), 'real_60s_alarm_native_worker_self_kill', $times);
    $failed = DB::table('failed_jobs')->first();
    $lease = DB::table('notification_outbox')->first();
    ql_check(DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === 1 && $failed
        && $failed->payload === $original->payload && $failed->connection === 'database' && $failed->queue === 'fixture35'
        && strpos($failed->exception, TimeoutExceededException::class) !== false
        && $failedEvents[0]['exception_class'] === TimeoutExceededException::class
        && (int) $failedEvents[0]['job_id'] === (int) $original->id
        && !$lease->delivered_at && !$lease->failed_at && $lease->reserved_until && $lease->last_error === null
        && $lease->reservation_token === $ready['reservation_token'] && (int) $lease->attempts === 1
        && !lt_events('ProviderResponse') && !lt_events('MailAccepted'), 'failed_queue_preserves_uncompleted_outbox_lease');
    foreach (array_merge($processing, $timed, $stopping, $failedEvents, lt_events('ProviderReady')) as $event) {
        ql_check($event['transaction_level'] === 0, 'timeout_event_outside_transaction_' . $event['event']);
    }
    ql_check(!$timed[0]['pdo_in_transaction'] && DB::transactionLevel() === 0
        && !DB::connection()->getPdo()->inTransaction(), 'timeout_connections_have_no_transaction');
    lt_payload($failed, (int) $lease->id, $outbox->reservation_token);
    file_put_contents($run . '/failed-command.serialized', $payload['data']['command'], LOCK_EX);
    lt_snapshot('timed-out');
    $native = true;
    $failedRow = (array) $failed;
    // Expire only this synthetic execution lease; the original dispatcher republishes it.
    ql_check(DB::table('notification_outbox')->where('id', $lease->id)->where('reservation_token', $lease->reservation_token)
        ->whereNull('delivered_at')->update(['reserved_until' => date('Y-m-d H:i:s', time() - 1)]) === 1, 'only_fixture_lease_expired');
    lt_console('timeout-republish', ['notifications:dispatch']);
    $recovery = DB::table('jobs')->first();
    $newLease = DB::table('notification_outbox')->first();
    ql_check($recovery && DB::table('jobs')->count() === 1 && (int) $recovery->id !== (int) $original->id
        && (int) $recovery->attempts === 0 && (int) $newLease->attempts === 1
        && $newLease->reservation_token === $lease->reservation_token, 'distinct_recovery_job_reuses_execution_lease_token');
    $recoveryPayload = lt_payload($recovery, (int) $lease->id, $lease->reservation_token);
    ql_check($recoveryPayload['uuid'] !== $payload['uuid'], 'distinct_recovery_payload_uuid');
    lt_snapshot('republished');
    lt_console('timeout-recovery-worker', ['queue:work', 'database', '--queue=fixture35', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=1']);
    $final = DB::table('notification_outbox')->first();
    $responses = lt_events('ProviderResponse');
    $recoveryPid = $GLOBALS['lt_processes']['timeout-recovery-worker']['pid'];
    $recoveryProcessing = lt_events('JobProcessing', $recoveryPid);
    $recoveryProcessed = lt_events('JobProcessed', $recoveryPid);
    ql_check(DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === 1
        && (array) DB::table('failed_jobs')->first() === $failedRow && DB::table('notification_outbox')->count() === 1
        && $final->delivered_at && !$final->failed_at && !$final->reserved_until && !$final->reservation_token
        && $final->last_error === null && (int) $final->attempts === 2 && count($responses) === 1
        && $responses[0]['status'] === 204 && $responses[0]['attempt'] === 2 && $responses[0]['pid'] === $recoveryPid
        && $responses[0]['transaction_level'] === 0 && (int) $responses[0]['job_id'] === (int) $recovery->id
        && count($recoveryProcessing) === 1 && count($recoveryProcessed) === 1
        && (int) $recoveryProcessed[0]['job_id'] === (int) $recovery->id && !lt_events('MailAccepted'), 'recovery_once_attempt_2_keeps_original_failed_row');
    $recovered = true;
    $exit = 0;
} catch (Throwable $e) {
    $exit = 1;
    $failure = ['class' => get_class($e), 'message' => $e->getMessage()];
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    if (isset($GLOBALS['ql_run'])) ql_log('RunFailure', $failure);
} finally {
    if (isset($GLOBALS['ql_run'])) {
        $cleanupErrors = [];
        foreach (array_keys($GLOBALS['lt_processes']) as $label) {
            $child =& $GLOBALS['lt_processes'][$label];
            if (!$child['closed']) {
                try {
                    if ($child['status']['running']) $child['status'] = proc_get_status($child['proc']);
                    $status = $child['status'];
                    if ($status['running']) {
                        if (!lt_owned($child)) throw new RuntimeException('Cleanup ownership mismatch: ' . $label);
                        $child['cleanup_signals'][] = SIGTERM;
                        ql_log('CleanupSignal', ['label' => $label, 'child_pid' => $child['pid'], 'signal' => SIGTERM]);
                        proc_terminate($child['proc'], SIGTERM);
                    }
                    try { lt_wait($label, 3); }
                    catch (Throwable $e) {
                        if (!lt_owned($child)) throw $e;
                        $child['cleanup_signals'][] = SIGKILL;
                        ql_log('CleanupSignal', ['label' => $label, 'child_pid' => $child['pid'], 'signal' => SIGKILL]);
                        proc_terminate($child['proc'], SIGKILL);
                        lt_wait($label, 3);
                    }
                } catch (Throwable $e) { $cleanupErrors[] = $e->getMessage(); }
            }
            ql_log('CleanupProcess', ['label' => $label, 'child_pid' => $child['pid'], 'closed' => $child['closed'],
                'cleanup_signals' => $child['cleanup_signals'], 'status' => $child['status']]);
            if (!$child['closed']) $cleanupErrors[] = 'Unreaped owned handle: ' . $label;
        }
        unset($child);
        $after = lt_hashes($GLOBALS['ql_config']['root']);
        if ($before !== $after) $cleanupErrors[] = 'Protected configuration/source hashes changed.';
        $processes = [];
        foreach ($GLOBALS['lt_processes'] as $label => $child) $processes[$label] = array_diff_key($child, ['proc' => true]);
        $finalSql = null;
        if ($app) {
            try {
                $finalSql = lt_snapshot('final');
                if ($finalSql['transaction_level'] !== 0 || $finalSql['pdo_in_transaction']) $cleanupErrors[] = 'Final DB transaction remains open.';
            } catch (Throwable $e) { $cleanupErrors[] = $e->getMessage(); }
            ql_disconnect($app);
        }
        if ($cleanupErrors || !$native || !$recovered || lt_events('CheckFailed')) $exit = 1;
        $summary = ['status' => $exit === 0 ? 'PASS' : 'FAIL', 'exit' => $exit, 'failure' => $failure,
            'native_timeout_60s' => $native ? 'PASS' : 'NOT_PROVEN', 'recovery' => $recovered, 'runtime' => $runtime,
            'marker' => $GLOBALS['ql_config']['marker'], 'times' => $times, 'processes' => $processes,
            'cleanup_errors' => $cleanupErrors, 'protected_hashes_before' => $before, 'protected_hashes_after' => $after,
            'protected_hashes_equal' => $before === $after, 'config_evidence' => lt_events('Start'),
            'job_processing' => lt_events('JobProcessing'), 'job_failed' => lt_events('JobFailed'),
            'job_timed_out' => lt_events('JobTimedOut'), 'worker_stopping' => lt_events('WorkerStopping'),
            'provider_responses' => lt_events('ProviderResponse'), 'checks_failed' => lt_events('CheckFailed'), 'final_sql' => $finalSql,
            'original_job_id' => isset($original) ? (int) $original->id : null, 'recovery_job_id' => isset($recovery) ? (int) $recovery->id : null,
            'failed_serialization_sha256' => isset($payload) ? hash('sha256', $payload['data']['command']) : null];
        ql_json($GLOBALS['ql_run'] . '/summary.json', $summary);
        fwrite(STDOUT, $summary['status'] . ' native_timeout_60s=' . $summary['native_timeout_60s']
            . ' summary=' . $GLOBALS['ql_run'] . '/summary.json' . PHP_EOL);
    }
}
exit($exit);
