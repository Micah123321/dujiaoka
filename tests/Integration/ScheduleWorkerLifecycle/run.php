<?php
require dirname(__DIR__) . '/QueueLifecycle/bootstrap.php';
require dirname(__DIR__) . '/LinuxLifecycle/process.php';
require __DIR__ . '/process.php';

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

function sw_events($event, $role = null)
{
    $rows = [];
    $fp = fopen($GLOBALS['ql_run'] . '/events.jsonl', 'r');
    if (!$fp || !flock($fp, LOCK_SH)) throw new RuntimeException('Event read lock failed.');
    try { $text = stream_get_contents($fp); } finally { flock($fp, LOCK_UN); fclose($fp); }
    foreach (explode("\n", trim($text)) as $line) {
        if ($line === '') continue;
        $r = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if ($r['event'] === $event && ($role === null || $r['role'] === $role)) $rows[] = $r;
    }
    return $rows;
}
function sw_order($sn, array $extra)
{
    $id = DB::table('orders')->insertGetId(array_merge(['order_sn' => $sn, 'goods_id' => 1,
        'info' => 'Synthetic fixture delivery', 'created_at' => Carbon::now()->subMinutes(10)->toDateTimeString(),
        'updated_at' => Carbon::now()->toDateTimeString()], $extra));
    return Order::query()->findOrFail($id);
}
function sw_snapshot()
{
    return ['jobs' => DB::table('jobs')->orderBy('id')->get()->toArray(),
        'orders' => DB::table('orders')->orderBy('id')->get()->toArray(),
        'outbox' => DB::table('notification_outbox')->orderBy('id')->get()->toArray(),
        'coupon' => DB::table('coupons')->where('id', 1)->first()];
}
function sw_args($command, $role)
{
    $cfg = $GLOBALS['ql_config'];
    return array_merge([$cfg['binary'], '-c', $cfg['ini'], __DIR__ . '/console.php',
        '--candidate', '--run=' . $cfg['run'], '--marker=' . $cfg['marker'], '--role=' . $role], $command);
}
$app = null;
$exit = 1;
$failure = null;
$cleanupErrors = [];
$GLOBALS['ll_processes'] = [];
$GLOBALS['sw_owned_children'] = [];
$GLOBALS['sw_groups'] = [];
$interrupted = null;
pcntl_async_signals(true);
foreach ([SIGTERM, SIGINT, SIGALRM] as $signal) pcntl_signal($signal, function ($signal) use (&$interrupted) { $interrupted = $signal; });
pcntl_alarm(270);
$summary = [];
try {
    if (PHP_OS_FAMILY !== 'Linux' || !in_array('--candidate', $argv, true) || posix_geteuid() !== 1000) {
        throw new RuntimeException('Linux regular UID1000 candidate runtime required.');
    }
    $root = ql_normalize(realpath(dirname(__DIR__, 3)));
    $marker = bin2hex(random_bytes(12));
    $run = $root . '/tests/.runtime/queue-lifecycle/run-minute-' . gmdate('Ymd-His') . '-' . $marker;
    if (!mkdir($run, 0777, true)) throw new RuntimeException('Fresh run required.');
    foreach (['cache', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/cache/locks',
        'storage/framework/sessions', 'storage/logs', 'storage/app'] as $d) mkdir($run . '/' . $d, 0777, true);
    $cfg = ['marker' => $marker, 'root' => $root, 'run' => $run, 'candidate' => true,
        'binary' => ql_normalize(realpath(PHP_BINARY)), 'ini' => ql_normalize(php_ini_loaded_file()),
        'key' => 'base64:' . base64_encode(random_bytes(32))];
    ql_json($run . '/run.json', $cfg);
    file_put_contents($run . '/marker', $marker);
    touch($run . '/queue.sqlite');
    ql_json($run . '/provider.json', ['mode' => '204']);
    $app = ql_boot($run, $marker, true, 'minute-parent');
    ql_schema();
    ql_check(!Carbon::hasTestNow(), 'no_frozen_clock');
    DB::table('coupons')->insert(['id' => 1, 'ret' => 0]);
    $expired = sw_order('A-minute-expired', ['coupon_id' => 1]);
    $future = sw_order('A-minute-future', ['created_at' => Carbon::now()->addMinutes(10)->toDateTimeString()]);
    $paid = sw_order('C-minute', ['created_at' => Carbon::now()->toDateTimeString()]);
    DB::transaction(function () use ($paid) {
        DB::table('orders')->where('id', $paid->id)->update(['status' => Order::STATUS_COMPLETED, 'trade_no' => 'fixture-trade']);
        $paid->refresh();
        app(App\Service\NotificationOutboxService::class)->recordPayment($paid);
    });
    ql_check(DB::transactionLevel() === 0 && DB::table('jobs')->count() === 0
        && DB::table('notification_outbox')->count() === 2, 'committed_unpublished_intents');
    $shim = '<?php' . PHP_EOL . '$argv = array_merge([' . var_export(__DIR__ . '/console.php', true) . ', ' 
        . var_export('--candidate', true) . ', ' . var_export('--run=' . $run, true) . ', ' 
        . var_export('--marker=' . $marker, true) . ', ' . var_export('--role=minute-tick', true) 
        . '], array_slice($argv, 1));' . PHP_EOL . 'require ' . var_export(__DIR__ . '/console.php', true) . ';' . PHP_EOL;
    file_put_contents($run . '/artisan', $shim);
    $started = microtime(true);
    $signalProbe = in_array('--signal-probe', $argv, true);
    sw_spawn(sw_args(['schedule:work', '--no-interaction', '--run-output-file=' . $run . '/minute-output.log'], 'minute-daemon'), 'minute-daemon');
    if ($signalProbe) {
        ll_until(function () {
            sw_track_children();
            $pid = $GLOBALS['ll_processes']['minute-daemon']['pid'];
            $ready = sw_events('NativeSignalReady', 'minute-daemon');
            if (!$ready || $ready[0]['pid'] !== $pid) return false;
            $status = (string) @file_get_contents('/proc/' . $pid . '/status');
            return preg_match('/^SigCgt:\s+([a-f0-9]+)$/m', $status, $m) && (hexdec($m[1]) & (1 << (SIGTERM - 1)));
        }, 'native TERM handler registered', 20);
        ql_check(proc_terminate($GLOBALS['ll_processes']['minute-daemon']['proc'], SIGTERM), 'signal_probe_owned_term');
        ql_check(sw_wait('minute-daemon', 30) === 0, 'signal_probe_original_exit0');
        ql_check(count(sw_events('ConsoleExit', 'minute-daemon')) === 1, 'signal_probe_original_console_return');
        $summary = ['status' => 'PASS', 'scope' => 'Original Laravel signal registrar with testing availability override', 'elapsed' => microtime(true) - $started];
    } else {
    ll_until(function () use (&$interrupted) {
        sw_track_children();
        if ($interrupted !== null) throw new RuntimeException('Fixture interrupted by signal ' . $interrupted);
        if (!proc_get_status($GLOBALS['ll_processes']['minute-daemon']['proc'])['running']) throw new RuntimeException('Minute daemon exited early.');
        return count(sw_events('ConsoleExit', 'minute-tick')) >= 1;
    }, 'first real minute', 110);
    $first = sw_snapshot();
    ql_json($run . '/first-minute.json', $first);
    ql_check(count($first['jobs']) === 2 && (int) $first['coupon']->ret === 1, 'first_minute_jobs2_coupon1');
    ql_check((int) DB::table('orders')->where('id', $expired->id)->value('status') === Order::STATUS_EXPIRED
        && (int) DB::table('orders')->where('id', $future->id)->value('status') === Order::STATUS_WAIT_PAY, 'first_expired_future_preserved');
    foreach ($first['jobs'] as $j) ql_check((int) $j->attempts === 0 && $j->reserved_at === null, 'first_jobs_unreserved_attempt0');
    foreach ($first['outbox'] as $o) {
        ql_check($o->reservation_token && $o->reserved_until && (int) $o->attempts === 0 && !$o->delivered_at, 'first_original_intent_claim');
        ql_check(Carbon::parse($o->reserved_until)->getTimestamp() - Carbon::parse($o->updated_at)->getTimestamp() === 300, 'original_publication_lease_300_seconds');
    }
    ll_until(function () use (&$interrupted) {
        sw_track_children();
        if ($interrupted !== null) throw new RuntimeException('Fixture interrupted by signal ' . $interrupted);
        if (!proc_get_status($GLOBALS['ll_processes']['minute-daemon']['proc'])['running']) throw new RuntimeException('Minute daemon exited before second tick.');
        return count(sw_events('ConsoleExit', 'minute-tick')) >= 2;
    }, 'second real minute', 90);
    $second = sw_snapshot();
    ql_json($run . '/second-minute.json', $second);
    ql_check($first == $second, 'second_minute_exact_sql_state_unchanged');
    $ticks = sw_events('RealMinuteEntry', 'minute-tick');
    ql_check(count($ticks) === 2 && $ticks[1]['wall_minute'] - $ticks[0]['wall_minute'] === 1
        && $ticks[1]['time'] - $ticks[0]['time'] > 58 && $ticks[1]['time'] - $ticks[0]['time'] < 62, 'two_consecutive_wall_minutes');
    ql_check(count(sw_events('ScheduledResult', 'minute-tick')) === 4
        && count(sw_events('ConsoleExit', 'schedule-event')) === 4, 'four_original_event_children_completed');
    ql_check(count(sw_events('NativeSignalReady', 'minute-daemon')) === 1, 'native_original_TERM_callback_observed');
    ql_check(proc_terminate($GLOBALS['ll_processes']['minute-daemon']['proc'], SIGTERM), 'owned_daemon_term');
    ql_check(sw_wait('minute-daemon', 5) === 0, 'native_minute_daemon_signal_exit0');
    sw_spawn(sw_args(['queue:work', 'database', '--queue=fixture35', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=60'], 'minute-worker'), 'minute-worker');
    ql_check(sw_wait('minute-worker', 30) === 0, 'native_worker_exit0');
    ql_check(DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === 0, 'queue_empty_failed0');
    ql_check(DB::table('notification_outbox')->orderBy('id')->pluck('id')->map(function ($id) { return (int) $id; })->all()
        === array_map(function ($o) { return (int) $o->id; }, $first['outbox']), 'same_two_original_outbox_ids_retained');
    foreach (DB::table('notification_outbox')->get() as $o) {
        ql_check($o->delivered_at && !$o->failed_at && !$o->reservation_token && !$o->reserved_until
            && (int) $o->attempts === 1 && $o->last_error === null, 'original_outbox_delivered_once');
        if ($o->channel === 'payment-mail') ql_check($o->payload === null, 'mail_payload_cleared');
    }
    ql_check(count(sw_events('ProviderResponse')) === 1 && sw_events('ProviderResponse')[0]['status'] === 204
        && count(sw_events('MailAccepted')) === 1 && count(sw_events('JobProcessed')) === 2, 'exact_provider_mail_and_job_counts');
    foreach (sw_events('JobProcessing') as $e) ql_check($e['transaction_level'] === 0 && $e['attempts'] === 1, 'jobs_after_commit_attempt1');
    $summary = ['status' => 'PASS', 'scope' => 'Original Laravel13 schedule:work real minute loop; system cron and container image excluded',
        'ticks' => $ticks, 'tick_delta' => $ticks[1]['time'] - $ticks[0]['time'], 'elapsed' => microtime(true) - $started,
        'first' => $first, 'second' => $second, 'final' => sw_snapshot()];
    }
} catch (Throwable $e) {
    $failure = ['class' => get_class($e), 'message' => $e->getMessage()];
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    pcntl_alarm(0);
    sw_track_children();
    foreach ($GLOBALS['ll_processes'] as $child) if (!$child['closed'] && proc_get_status($child['proc'])['running']) proc_terminate($child['proc'], SIGTERM);
    try { sw_cleanup_children(); } catch (Throwable $e) { $cleanupErrors[] = $e->getMessage(); }
    foreach (array_keys($GLOBALS['ll_processes']) as $label) try { ll_cleanup_owned($label); } catch (Throwable $e) { $cleanupErrors[] = $e->getMessage(); }
    if ($app) ql_disconnect($app);
    if (isset($GLOBALS['ql_run'])) {
        $summary['status'] = $failure || $cleanupErrors ? 'FAIL' : 'PASS';
        $summary['failure'] = $failure;
        $summary['cleanup_errors'] = $cleanupErrors;
        $summary['checks'] = count(sw_events('CheckPassed'));
        $summary['owned_descendants'] = array_values($GLOBALS['sw_owned_children']);
        $summary['owned_sessions'] = array_values($GLOBALS['sw_groups']);
        $summary['remaining_owned'] = array_values(array_filter($GLOBALS['sw_owned_children'], 'sw_child_alive'));
        $summary['handles_remaining'] = count(array_filter($GLOBALS['ll_processes'], function ($p) { return !$p['closed']; }));
        if ($summary['remaining_owned'] || $summary['handles_remaining']) $summary['status'] = 'FAIL';
        $summary['processes'] = array_map(function ($c) { unset($c['proc']); return $c; }, $GLOBALS['ll_processes']);
        ql_json($GLOBALS['ql_run'] . '/summary.json', $summary);
        echo json_encode(['status' => $summary['status'], 'checks' => $summary['checks'], 'run' => $GLOBALS['ql_run']], JSON_THROW_ON_ERROR), PHP_EOL;
        $exit = $summary['status'] === 'PASS' ? 0 : 1;
    }
}
exit($exit);
