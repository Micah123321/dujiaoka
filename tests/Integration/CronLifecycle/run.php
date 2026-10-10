<?php

use App\Models\Order;
use App\Service\NotificationOutboxService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

function cl_put($path, $text)
{
    if (file_put_contents($path, $text, LOCK_EX) === false) throw new RuntimeException('Write failed: ' . basename($path));
}

function cl_events($event = null)
{
    $file = $GLOBALS['ql_run'] . '/events.jsonl';
    if (!is_file($file)) return [];
    $fp = fopen($file, 'r');
    if (!$fp || !flock($fp, LOCK_SH)) throw new RuntimeException('Event lock failed.');
    try { $text = stream_get_contents($fp); } finally { flock($fp, LOCK_UN); fclose($fp); }
    $rows = [];
    foreach (explode("\n", trim($text)) as $line) {
        if ($line === '') continue;
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if ($event === null || $row['event'] === $event) $rows[] = $row;
    }
    return $rows;
}

function cl_children($pid)
{
    $text = trim((string) @file_get_contents('/proc/' . $pid . '/task/' . $pid . '/children'));
    return $text === '' ? [] : array_map('intval', preg_split('/\s+/', $text));
}

function cl_descendant($pid, $launcher)
{
    for ($depth = 0; $depth < 12 && $pid > 1; ++$depth) {
        $identity = ll_identity($pid);
        if (!$identity) return false;
        if ($identity['ppid'] === $launcher) return true;
        $pid = $identity['ppid'];
    }
    return false;
}

function cl_owned_cron()
{
    if (!isset($GLOBALS['ll_processes']['namespace-cron'])) return null;
    $launcher = $GLOBALS['ll_processes']['namespace-cron']['pid'];
    $queue = cl_children($launcher);
    for ($i = 0; $i < count($queue) && $i < 32; ++$i) {
        $pid = $queue[$i];
        $p = ll_identity($pid);
        if (!$p) continue;
        $queue = array_merge($queue, cl_children($pid));
        $status = (string) @file_get_contents('/proc/' . $pid . '/status');
        $root = @readlink('/proc/' . $pid . '/root');
        $pidns = @readlink('/proc/' . $pid . '/ns/pid');
        if ($root === $GLOBALS['cl_rootfs'] && $p['argv'] === ['/usr/sbin/cron', '-f', '-L', '15']
            && $pidns && $pidns !== readlink('/proc/self/ns/pid') && cl_descendant($pid, $launcher)
            && preg_match('/^NSpid:\s+(?:\d+\s+)*1\s*$/m', $status)) {
            return array_merge($p, ['root' => $root, 'pid_namespace' => $pidns, 'launcher' => $launcher,
                'uid_map' => file_get_contents('/proc/' . $pid . '/uid_map'),
                'gid_map' => file_get_contents('/proc/' . $pid . '/gid_map'),
                'setgroups' => trim(file_get_contents('/proc/' . $pid . '/setgroups'))]);
        }
    }
    return null;
}

function cl_stop_cron()
{
    $owned = $GLOBALS['cl_owned'] ?? cl_owned_cron();
    if (!$owned) return;
    $current = cl_owned_cron();
    if (!$current) {
        if (!ll_same_process($owned)) return;
        throw new RuntimeException('Owned cron survived with changed ownership evidence.');
    }
    if ($current['pid'] !== $owned['pid'] || $current['start_ticks'] !== $owned['start_ticks']
        || $current['pid_namespace'] !== $owned['pid_namespace'] || $current['root'] !== $owned['root']) {
        throw new RuntimeException('Cron identity changed before signal.');
    }
    // Debian cron PID1 can ignore TERM and clear PDEATHSIG after credential changes.
    ql_check(posix_kill($owned['pid'], SIGKILL), 'signal_only_verified_owned_cron_pid1', ['identity' => $owned]);
    ll_until(function () use ($owned) {
        $stat = @file_get_contents('/proc/' . $owned['pid'] . '/stat');
        if ($stat === false) return true;
        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        return $fields[0] === 'Z' || !ll_same_process($owned);
    }, 'owned cron KILL bounded', 5);
    ql_log('CronStopped', ['identity' => $owned, 'signal' => SIGKILL]);
}

function cl_snapshot($name)
{
    $tables = [];
    foreach (['jobs', 'failed_jobs', 'notification_outbox', 'orders', 'coupons'] as $table) {
        $tables[$table] = array_map(function ($r) { return (array) $r; }, DB::table($table)->orderBy('id')->get()->all());
    }
    ql_json($GLOBALS['ql_run'] . '/sql-' . $name . '.json', ['wall_time' => microtime(true), 'tables' => $tables]);
    return $tables;
}

function cl_hashes(array $paths)
{
    $out = [];
    foreach ($paths as $path) {
        if (!is_file($path)) throw new RuntimeException('Missing protected file: ' . $path);
        $out[$path] = hash_file('sha256', $path);
    }
    return $out;
}

$app = null;
$exit = 1;
$failure = null;
$phase = 'prepare';
$started = microtime(true);
$cleanup = [];
$before = $manifestBefore = [];
$final = $ticks = [];
$GLOBALS['ll_processes'] = [];
try {
    if (array_slice($argv, 1) !== ['--candidate'] || PHP_OS_FAMILY !== 'Linux' || PHP_VERSION !== '8.5.11'
        || !extension_loaded('pcntl') || !extension_loaded('posix') || !extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('Requires --candidate and existing Linux PHP8.5.11/pcntl/posix/pdo_sqlite.');
    }
    if (posix_getuid() !== 1000 || posix_getgid() !== 1000) throw new RuntimeException('Expected unprivileged Host UID/GID1000.');
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGALRM] as $signal) pcntl_signal($signal, function ($received) {
        throw new RuntimeException('Bounded run interrupted by signal ' . $received);
    });
    pcntl_alarm(225);
    $root = str_replace('\\', '/', realpath(dirname(__DIR__, 3)));
    $marker = bin2hex(random_bytes(12));
    $run = $root . '/tests/.runtime/cron-lifecycle/run-' . gmdate('Ymd-His') . '-' . $marker;
    if (file_exists($run) || !mkdir($run, 0700, true)) throw new RuntimeException('Fresh run directory failed.');
    foreach (['cache', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/cache/locks',
        'storage/framework/sessions', 'storage/logs', 'storage/app'] as $dir) mkdir($run . '/' . $dir, 0700, true);
    $cfg = ['root' => $root, 'run' => $run, 'marker' => $marker, 'candidate' => true,
        'binary' => realpath(PHP_BINARY), 'ini' => (string) php_ini_loaded_file(),
        'key' => 'base64:' . base64_encode(random_bytes(32))];
    $GLOBALS['cl_rootfs'] = '/tmp/dujiaoka-cron41.' . $marker;
    // Only the run boundary and fixed source root change in the copied shared bootstrap.
    $source = file_get_contents(dirname(__DIR__) . '/QueueLifecycle/bootstrap.php');
    $replacements = [
        '$root = ql_normalize(realpath(dirname(__DIR__, 3)));' => '$root = ' . var_export($root, true) . ';',
        "'/tests/.runtime/queue-lifecycle'" => "'/tests/.runtime/cron-lifecycle'",
        "'tests/.runtime/queue-lifecycle/'" => "'tests/.runtime/cron-lifecycle/'",
    ];
    $adapted = $source;
    foreach ($replacements as $old => $new) {
        if (substr_count($adapted, $old) !== 1) throw new RuntimeException('Shared bootstrap anchor changed.');
        $adapted = str_replace($old, $new, $adapted);
    }
    cl_put($run . '/bootstrap.php', $adapted);
    cl_put($run . '/console.php', file_get_contents(dirname(__DIR__) . '/QueueLifecycle/console.php'));
    require $run . '/bootstrap.php';
    require dirname(__DIR__) . '/LinuxLifecycle/process.php';
    $GLOBALS['ql_run'] = $run;
    $GLOBALS['ql_config'] = $cfg;
    $GLOBALS['ql_role'] = 'parent';
    ql_json($run . '/run.json', $cfg);
    ql_json($run . '/bootstrap-adaptation.json', ['replacements' => $replacements,
        'source_sha256' => hash('sha256', $source), 'copy_sha256' => hash('sha256', $adapted),
        'console_byte_identical' => hash_file('sha256', $run . '/console.php') === hash_file('sha256', dirname(__DIR__) . '/QueueLifecycle/console.php')]);
    cl_put($run . '/marker', $marker . "\n");
    touch($run . '/queue.sqlite');
    ql_json($run . '/provider.json', ['mode' => '204']);
    $protected = ['/etc/passwd', '/etc/group', '/usr/sbin/cron'];
    foreach (['deploy/cron/dujiaoka', 'deploy/supervisor/queue.conf', 'config/app.php', 'config/database.php',
        'config/queue.php', 'bootstrap/app.php', 'tests/Integration/QueueLifecycle/bootstrap.php',
        'tests/Integration/QueueLifecycle/console.php', 'tests/Integration/QueueLifecycle/run.php',
        'tests/Integration/LinuxLifecycle/process.php', 'tests/Integration/CronLifecycle/run.php',
        'tests/Integration/CronLifecycle/namespace.sh', 'tests/Integration/CronLifecycle/inner.sh'] as $path) $protected[] = $root . '/' . $path;
    $before = cl_hashes($protected);
    $app = ql_boot($run, $marker, true, 'parent');
    $parent = cl_events('Start')[0];
    $manifestBefore = array_column($parent['sources'], 'sha256', 'path');
    ql_check(count($manifestBefore) === 14, 'production_class_manifest_14');
    ql_schema();
    ql_check(!Carbon::hasTestNow(), 'unfrozen_application_clock');
    ql_check(date_default_timezone_get() === config('app.timezone'), 'same_application_clock_timezone');
    $now = Carbon::now();
    DB::table('coupons')->insert(['id' => 1, 'ret' => 0]);
    $expiredId = DB::table('orders')->insertGetId(['order_sn' => 'C-expired', 'goods_id' => 1, 'coupon_id' => 1,
        'created_at' => $now->copy()->subMinutes(10), 'updated_at' => $now]);
    $futureId = DB::table('orders')->insertGetId(['order_sn' => 'C-future', 'goods_id' => 1,
        'created_at' => $now->copy()->addMinutes(10), 'updated_at' => $now]);
    $intentId = DB::table('orders')->insertGetId(['order_sn' => 'C-cron', 'goods_id' => 1,
        'info' => 'Synthetic cron delivery', 'created_at' => $now, 'updated_at' => $now]);
    DB::transaction(function () use ($intentId) {
        DB::table('orders')->where('id', $intentId)->update(['status' => Order::STATUS_COMPLETED, 'trade_no' => 'fixture-trade']);
        app(NotificationOutboxService::class)->recordPayment(Order::query()->findOrFail($intentId));
    });
    ql_check(DB::transactionLevel() === 0 && DB::table('jobs')->count() === 0
        && DB::table('notification_outbox')->count() === 2, 'transaction_committed_two_intents_zero_jobs');
    ql_check(DB::table('notification_outbox')->where('channel', 'payment-mail')->value('payload') !== null, 'encrypted_mail_intent');
    cl_snapshot('seed');
    cl_put($run . '/production-cron.copy', file_get_contents($root . '/deploy/cron/dujiaoka'));
    cl_put($run . '/passwd', "root:x:0:0:Synthetic namespace root:/app:/bin/sh\napplication:x:0:0:Synthetic cron application:/app:/bin/sh\n");
    cl_put($run . '/group', "root:x:0:\napplication:x:0:\n");
    cl_put($run . '/nsswitch.conf', "passwd: files\ngroup: files\nshadow: files\nhosts: files\n");
    cl_put($run . '/pam-cron', "account required pam_permit.so\nsession required pam_permit.so\n");
    $libraries = dirname(dirname($cfg['binary'])) . '/lib/x86_64-linux-gnu';
    $wrapper = "#!/bin/sh\nset -eu\nexport LD_LIBRARY_PATH=" . escapeshellarg($libraries)
        . "\nexport PHP_INI_SCAN_DIR=\nexec " . escapeshellarg($cfg['binary']) . ' -c ' . escapeshellarg($cfg['ini']) . ' "$@"' . "\n";
    cl_put($run . '/php-wrapper', $wrapper);
    $shim = '<?php' . "\n" . '$run = ' . var_export($run, true) . ';' . "\n" . <<<'SHIM'
$record = ['event' => 'CronEntry', 'wall_time' => microtime(true), 'uid' => posix_getuid(), 'euid' => posix_geteuid(),
    'gid' => posix_getgid(), 'egid' => posix_getegid(), 'pid' => getmypid(), 'logname' => getenv('LOGNAME'),
    'home' => getenv('HOME'), 'cwd' => getcwd(), 'argv' => $argv, 'root' => readlink('/proc/self/root'),
    'uid_map' => file_get_contents('/proc/self/uid_map'), 'gid_map' => file_get_contents('/proc/self/gid_map'),
    'setgroups' => trim(file_get_contents('/proc/self/setgroups')), 'pid_namespace' => readlink('/proc/self/ns/pid')];
file_put_contents($run . '/cron-entry.jsonl', json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);
if (array_slice($argv, 1) !== ['schedule:run', '--no-interaction']) throw new RuntimeException('Unexpected cron argv.');
$cfg = json_decode(file_get_contents($run . '/run.json'), true, 512, JSON_THROW_ON_ERROR);
$argv = [$run . '/console.php', '--candidate', '--run=' . $run, '--marker=' . $cfg['marker'], '--role=cron-tick',
    'schedule:run', '--no-interaction'];
require $run . '/console.php';
SHIM;
    cl_put($run . '/artisan', $shim . "\n");
    $proof = '<?php' . "\n" . '$run = ' . var_export($run, true) . ';' . "\n" . <<<'PROOF'
$root = posix_getpwnam('root');
$application = posix_getpwnam('application');
$allow = trim(file_get_contents('/proc/self/setgroups')) === 'allow';
$initgroups = $allow && posix_initgroups('application', 0);
$data = ['wall_time' => microtime(true), 'uid' => posix_getuid(), 'gid' => posix_getgid(),
    'root' => $root, 'application' => $application, 'setgroups' => trim(file_get_contents('/proc/self/setgroups')),
    'initgroups' => $initgroups, 'uid_map' => file_get_contents('/proc/self/uid_map'),
    'gid_map' => file_get_contents('/proc/self/gid_map'), 'pid_namespace' => readlink('/proc/self/ns/pid'),
    'cron_sha256' => hash_file('sha256', '/etc/cron.d/dujiaoka'), 'cron_mode' => fileperms('/etc/cron.d/dujiaoka') & 0777,
    'mountinfo' => file_get_contents('/proc/self/mountinfo')];
file_put_contents($run . '/namespace-proof.json', json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
if (!$allow || !$initgroups || $root['uid'] !== 0 || $application['uid'] !== 0 || posix_getuid() !== 0 || posix_getgid() !== 0) exit(72);
PROOF;
    cl_put($run . '/namespace-proof.php', $proof . "\n");
    ql_json($run . '/evidence-before.json', ['host_and_protected_hashes' => $before, 'production_manifest_14' => $manifestBefore,
        'runtime_sha256' => cl_hashes([$cfg['binary'], $cfg['ini'], $run . '/bootstrap.php', $run . '/console.php',
            $run . '/artisan', $run . '/namespace-proof.php', $run . '/production-cron.copy']),
        'host_identity' => ['uid' => posix_getuid(), 'gid' => posix_getgid(), 'pid' => getmypid()],
        'scope' => 'Synthetic root=application UID0 maps only Host UID/GID1000; no stock root-to-UID1000 claim.']);
    ql_check(hash_file('sha256', $run . '/production-cron.copy') === $before[$root . '/deploy/cron/dujiaoka'], 'original_cron_bytes_copied');
    $phase = 'namespace_mapping';
    ll_spawn(['/usr/bin/unshare', '--user', '--map-root-user', '--mount', '/bin/sh', __DIR__ . '/namespace.sh',
        $root, $run, $GLOBALS['cl_rootfs']], 'namespace-cron');
    ll_until(function () use ($run) {
        if (is_file($run . '/namespace-proof.json')) return true;
        if (!proc_get_status($GLOBALS['ll_processes']['namespace-cron']['proc'])['running']) {
            throw new RuntimeException('Namespace precondition failed: ' . trim(file_get_contents($run . '/namespace-cron.stderr')));
        }
        return false;
    }, 'allow and mapping proof before waiting for ticks', 12);
    $ns = json_decode(file_get_contents($run . '/namespace-proof.json'), true, 512, JSON_THROW_ON_ERROR);
    ql_check($ns['setgroups'] === 'allow' && $ns['initgroups'] && $ns['uid'] === 0 && $ns['gid'] === 0, 'actual_allow_initgroups_uid_gid');
    ql_check(preg_match('/^\s*0\s+0\s+1\s*$/D', $ns['uid_map']) && preg_match('/^\s*0\s+0\s+1\s*$/D', $ns['gid_map']), 'inner_single_root_mapping');
    foreach (['uid-map', 'gid-map'] as $kind) ql_check(preg_match('/^\s*0\s+1000\s+1\s*$/D', file_get_contents($run . '/outer.' . $kind)), 'outer_only_host1000_' . $kind);
    ql_check($ns['cron_mode'] === 0644 && $ns['cron_sha256'] === hash_file('sha256', $run . '/production-cron.copy'), 'daemon_original_cron_bytes_mode');
    $GLOBALS['cl_owned'] = ll_until(function () { return cl_owned_cron(); }, 'verified owned cron PID1', 5);
    ql_check($GLOBALS['cl_owned']['setgroups'] === 'allow', 'owned_cron_allow');
    ql_log('CronOwned', ['identity' => $GLOBALS['cl_owned']]);
    $phase = 'wall_clock_ticks';
    $tickDeadline = microtime(true) + 150;
    $first = null;
    for ($tick = 1; $tick <= 2; ++$tick) {
        ll_until(function () use ($tick, $tickDeadline) {
            if (count(array_filter(cl_events('ConsoleExit'), function ($r) { return $r['role'] === 'cron-tick' && $r['command'] === 'schedule:run' && $r['exit'] === 0; })) >= $tick) return true;
            if (microtime(true) >= $tickDeadline) throw new RuntimeException('150-second wall-clock tick limit reached.');
            if (!cl_owned_cron()) throw new RuntimeException('Owned cron exited before two ticks.');
            return false;
        }, 'actual cron minute tick ' . $tick, max(1, $tickDeadline - microtime(true)));
        $snapshot = cl_snapshot('tick' . $tick);
        ql_check(count($snapshot['jobs']) === 2 && count($snapshot['notification_outbox']) === 2, 'two_pending_jobs_tick' . $tick);
        ql_check(count(cl_events('JobProcessing')) === 0 && count(cl_events('ProviderResponse')) === 0 && count(cl_events('MailAccepted')) === 0, 'no_worker_or_effects_between_ticks_' . $tick);
        ql_check((int) Order::query()->findOrFail($expiredId)->status === Order::STATUS_EXPIRED
            && (int) Order::query()->findOrFail($expiredId)->coupon_ret_back === 1
            && (int) DB::table('coupons')->value('ret') === 1, 'expire_and_return_coupon_once_tick' . $tick);
        ql_check((int) Order::query()->findOrFail($futureId)->status === Order::STATUS_WAIT_PAY, 'future_untouched_tick' . $tick);
        foreach ($snapshot['jobs'] as $job) ql_check((int) $job['attempts'] === 0 && $job['reserved_at'] === null && $job['queue'] === 'fixture35', 'unconsumed_original_job_tick' . $tick);
        foreach ($snapshot['notification_outbox'] as $row) ql_check($row['reservation_token'] && $row['reserved_until'] && !$row['delivered_at']
            && (int) $row['attempts'] === 0 && Carbon::parse($row['updated_at'])->diffInSeconds(Carbon::parse($row['reserved_until'])) === 300.0, 'unconsumed_300s_lease_tick' . $tick);
        if ($tick === 1) $first = $snapshot;
        else ql_check($first === $snapshot, 'second_tick_preserves_job_id_payload_token_lease_and_orders');
    }
    cl_stop_cron();
    ll_wait('namespace-cron', 5);
    $entryLines = file($run . '/cron-entry.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $ticks = array_map(function ($line) { return json_decode($line, true, 512, JSON_THROW_ON_ERROR); }, $entryLines);
    ql_check(count($ticks) === 2, 'exactly_two_real_cron_entries');
    $spacing = $ticks[1]['wall_time'] - $ticks[0]['wall_time'];
    ql_check((int) floor($ticks[1]['wall_time'] / 60) === (int) floor($ticks[0]['wall_time'] / 60) + 1
        && $spacing >= 58 && $spacing <= 62, 'different_consecutive_wall_minutes_about_60s', ['seconds' => $spacing]);
    foreach ($ticks as $entry) ql_check($entry['uid'] === 0 && $entry['gid'] === 0 && $entry['logname'] === 'application'
        && $entry['cwd'] === '/app' && $entry['root'] === '/' && $entry['argv'] === ['artisan', 'schedule:run', '--no-interaction']
        && $entry['pid_namespace'] === $GLOBALS['cl_owned']['pid_namespace'], 'original_cron_execution_identity');
    $starts = array_values(array_filter(cl_events('Start'), function ($r) { return $r['role'] === 'schedule-event'; }));
    ql_check(count($starts) === 4 && count(array_unique(array_column($starts, 'pid'))) === 4, 'four_original_command_children');
    foreach (['orders:expire', 'notifications:dispatch'] as $command) ql_check(count(array_filter(cl_events('ConsoleExit'), function ($r) use ($command) { return $r['command'] === $command && $r['exit'] === 0; })) === 2, 'two_command_starts_and_exits_' . $command);
    ql_check(count(cl_events('ScheduledResult')) === 4 && count(cl_events('ScheduleRewrite')) === 4, 'four_real_event_results');
    foreach (['actual_schedule_mutex_held_before_child', 'actual_schedule_mutex_removed'] as $check) ql_check(count(array_filter(cl_events('CheckPassed'), function ($r) use ($check) { return $r['check'] === $check; })) === 4, 'four_' . $check);
    $phase = 'worker';
    ll_spawn([$cfg['binary'], '-c', $cfg['ini'], $run . '/console.php', '--candidate', '--run=' . $run,
        '--marker=' . $marker, '--role=cron-worker', 'queue:work', 'database', '--queue=fixture35', '--stop-when-empty',
        '--sleep=0', '--tries=1', '--timeout=60'], 'cron-worker');
    ql_check(ll_wait('cron-worker', 20) === 0, 'real_queue_worker_exit_zero');
    ql_check(DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === 0, 'queue_and_failed_empty');
    foreach (DB::table('notification_outbox')->get() as $row) ql_check($row->delivered_at && !$row->failed_at
        && (int) $row->attempts === 1 && $row->reservation_token === null && $row->reserved_until === null
        && $row->last_error === null && ($row->channel !== 'payment-mail' || $row->payload === null), 'delivered_once_clear_lease_error_' . $row->channel);
    ql_check(count(cl_events('ProviderResponse')) === 1 && cl_events('ProviderResponse')[0]['status'] === 204
        && cl_events('ProviderResponse')[0]['transaction_level'] === 0, 'provider_once_204_txn0');
    ql_check(count(cl_events('MailAccepted')) === 1 && cl_events('MailAccepted')[0]['transport'] === 'array'
        && cl_events('MailAccepted')[0]['transaction_level'] === 0, 'mail_once_array_txn0');
    ql_check(count(cl_events('JobProcessed')) === 2 && count(cl_events('JobFailed')) === 0, 'two_real_jobs_processed_once');
    $closed = array_column(cl_events('ConnectionClosed'), 'pid');
    foreach (cl_events('Start') as $start) if ($start['role'] !== 'parent') ql_check(in_array($start['pid'], $closed, true), 'child_database_connection_closed_' . $start['pid']);
    $final = cl_snapshot('final');
    $phase = 'verified';
    $exit = 0;
} catch (Throwable $error) {
    $failure = ['phase' => $phase, 'class' => get_class($error), 'message' => $error->getMessage()];
    if (function_exists('ql_log')) ql_log('RunFailure', $failure);
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
} finally {
    if (function_exists('ll_cleanup_owned')) {
        try { cl_stop_cron(); } catch (Throwable $error) { $cleanup[] = $error->getMessage(); }
        foreach (array_keys($GLOBALS['ll_processes']) as $label) {
            try { ll_cleanup_owned($label); } catch (Throwable $error) { $cleanup[] = $label . ': ' . $error->getMessage(); }
        }
    }
    if (function_exists('ql_json') && isset($GLOBALS['ql_run'])) {
        $after = cl_hashes(array_keys($before));
        $manifestAfter = cl_hashes(array_keys($manifestBefore));
        if ($before !== $after || $manifestBefore !== $manifestAfter) $cleanup[] = 'Protected/production hashes changed.';
        if ($app) ql_disconnect($app);
        $processes = [];
        $remaining = 0;
        foreach ($GLOBALS['ll_processes'] as $label => $child) {
            $processes[$label] = array_diff_key($child, ['proc' => true]);
            if (!$child['closed']) ++$remaining;
        }
        if ($remaining || cl_owned_cron()) $cleanup[] = 'Owned handles/processes remain.';
        if ($cleanup) $exit = 1;
        $mapping = [];
        foreach (['outer.uid-map', 'outer.gid-map', 'outer.setgroups', 'outer.user-ns'] as $file) $mapping[$file] = is_file($run . '/' . $file) ? trim(file_get_contents($run . '/' . $file)) : null;
        $summary = ['status' => $exit === 0 ? 'PASS' : ($phase === 'namespace_mapping' ? 'BLOCKED' : 'FAIL'),
            'exit' => $exit, 'failure' => $failure, 'cleanup_errors' => $cleanup, 'elapsed_seconds' => microtime(true) - $started,
            'checkedcount' => count(cl_events('CheckPassed')), 'php' => PHP_VERSION, 'framework' => $app ? $app->version() : null,
            'run' => $run, 'marker' => $marker, 'parent_pid' => getmypid(), 'owned_cron' => $GLOBALS['cl_owned'] ?? null,
            'processes' => $processes, 'handles_remaining' => $remaining, 'mapping_evidence' => $mapping,
            'cron_entries' => $ticks, 'observed_schedule_ticks' => count(array_filter(cl_events('ConsoleExit'), function ($r) { return $r['role'] === 'cron-tick'; })),
            'provider_responses' => cl_events('ProviderResponse'), 'mail_accepted' => cl_events('MailAccepted'), 'final' => $final,
            'host_and_protected_hashes_before' => $before, 'host_and_protected_hashes_after' => $after,
            'production_manifest_14_before' => $manifestBefore, 'production_manifest_14_after' => $manifestAfter,
            'scope' => 'Private synthetic passwd root/application UID0; same HostUID/GID1000 only. No stock root-to-UID1000, R40 identity, production schema or real provider receipt claim.',
            'verification_limit' => $exit === 0 ? null : 'Two real cron ticks and worker acceptance were not completed.'];
        ql_json($run . '/summary.json', $summary);
        fwrite(STDOUT, $summary['status'] . ' checks=' . $summary['checkedcount'] . ' seconds=' . round($summary['elapsed_seconds'], 3)
            . ' handles_remaining=' . $remaining . ' summary=' . $run . '/summary.json' . PHP_EOL);
    }
    if (function_exists('pcntl_alarm')) pcntl_alarm(0);
}
exit($exit);
