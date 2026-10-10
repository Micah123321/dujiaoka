<?php

require __DIR__ . '/bootstrap.php';

use App\Jobs\DeliverNotification;
use App\Jobs\OrderExpired;
use App\Models\Order;
use App\Service\NotificationOutboxService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;

function ql_start(array $command, $label)
{
    $cfg = $GLOBALS['ql_config'];
    $args = [PHP_BINARY];
    if ($cfg['ini'] !== '') array_push($args, '-c', $cfg['ini']);
    array_push($args, __DIR__ . '/console.php', '--run=' . $cfg['run'], '--marker=' . $cfg['marker']);
    if ($cfg['candidate']) $args[] = '--candidate';
    $args[] = '--role=' . $label;
    $args = array_merge($args, $command);
    $pipes = [];
    $descriptors = [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['file', $cfg['run'] . '/' . $label . '.stdout', 'a'],
        2 => ['file', $cfg['run'] . '/' . $label . '.stderr', 'a']];
    $proc = proc_open($args, $descriptors, $pipes, $cfg['root'], null, ['bypass_shell' => true]);
    if (!is_resource($proc)) throw new RuntimeException('Fixture proc_open failed.');
    $status = proc_get_status($proc);
    $GLOBALS['ql_processes'][$label] = ['proc' => $proc, 'pid' => $status['pid'], 'args' => $args,
        'exit' => null, 'closed' => false, 'terminated' => false, 'signaled' => false, 'term_signal' => 0];
    ql_log('ProcessStarted', ['label' => $label, 'child_pid' => $status['pid'], 'args' => $args]);
    return $label;
}

function ql_wait($label, $seconds = 30)
{
    $child =& $GLOBALS['ql_processes'][$label];
    $deadline = microtime(true) + min(30, $seconds);
    do {
        $status = proc_get_status($child['proc']);
        if (!$status['running']) {
            $child['exit'] = $status['exitcode'];
            $child['signaled'] = $status['signaled'];
            $child['term_signal'] = $status['termsig'];
            $close = proc_close($child['proc']);
            if ($child['exit'] < 0 && $close >= 0) $child['exit'] = $close;
            $child['closed'] = true;
            ql_log('ProcessExit', ['label' => $label, 'child_pid' => $child['pid'], 'exit' => $child['exit'],
                'terminated' => $child['terminated'], 'signaled' => $child['signaled'], 'term_signal' => $child['term_signal']]);
            return $child['exit'];
        }
        usleep(50000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Fixture child timed out: ' . $label);
}

function ql_command(array $command, $label)
{
    ql_start($command, $label);
    ql_check(ql_wait($label) === 0, 'process_exit_' . $label);
}

function ql_drain($label)
{
    ql_command(['queue:work', 'database', '--queue=fixture35', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=60'], $label);
    ql_check(DB::table('jobs')->count() === 0, 'queue_drained_' . $label);
}

function ql_worker_start($label)
{
    return ql_start(['queue:work', 'database', '--queue=fixture35', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=60'], $label);
}

function ql_ready($gate, $label)
{
    $path = $GLOBALS['ql_run'] . '/' . $gate . '.ready.json';
    $deadline = microtime(true) + 15;
    while (!is_file($path) && microtime(true) < $deadline) {
        if (!proc_get_status($GLOBALS['ql_processes'][$label]['proc'])['running']) {
            throw new RuntimeException('Worker exited before provider ready: ' . $label);
        }
        usleep(50000);
    }
    ql_check(is_file($path), $gate . '_provider_ready_within_15s');
    $ready = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    ql_check($ready['pid'] === $GLOBALS['ql_processes'][$label]['pid'], $gate . '_ready_owned_child_pid');
    ql_check(proc_get_status($GLOBALS['ql_processes'][$label]['proc'])['running'], $gate . '_worker_running_at_ready');
    return $ready;
}

function ql_events($event = null, $sn = null)
{
    $rows = [];
    foreach (file($GLOBALS['ql_run'] . '/events.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        if (($event === null || $row['event'] === $event) && ($sn === null || ($row['order_sn'] ?? null) === $sn)) $rows[] = $row;
    }
    return $rows;
}

function ql_order($sn, array $values = [])
{
    $defaults = ['order_sn' => $sn, 'goods_id' => 1, 'info' => 'Synthetic fixture delivery',
        'created_at' => date('Y-m-d H:i:s', time() - 600), 'updated_at' => date('Y-m-d H:i:s')];
    $id = DB::table('orders')->insertGetId(array_merge($defaults, $values));
    return Order::query()->findOrFail($id);
}

function ql_intent($sn, $mail = true)
{
    $order = ql_order($sn, ['created_at' => date('Y-m-d H:i:s')]);
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
    ql_check(DB::transactionLevel() === 0, 'committed_intent_' . $sn);
    return $order;
}

function ql_outbox($order, $channel = 'api-hook')
{
    return DB::table('notification_outbox')->where('order_id', $order->id)->where('channel', $channel)->first();
}

function ql_done($order, $mail = true, $attempts = 1)
{
    $api = ql_outbox($order);
    ql_check($api && $api->delivered_at && !$api->failed_at && !$api->reserved_until && !$api->reservation_token
        && (int) $api->attempts === $attempts && $api->last_error === null, 'api_final_' . $order->order_sn);
    if ($mail) {
        $row = ql_outbox($order, 'payment-mail');
        ql_check($row && $row->delivered_at && !$row->failed_at && !$row->reserved_until && !$row->reservation_token
            && $row->payload === null && (int) $row->attempts === 1 && $row->last_error === null, 'mail_final_' . $order->order_sn);
        ql_check(count(ql_events('MailAccepted', $order->order_sn)) === 1, 'mail_once_' . $order->order_sn);
    }
}

function ql_scenario($id, callable $body)
{
    $providerBefore = count(ql_events('ProviderResponse'));
    $mailBefore = count(ql_events('MailAccepted'));
    ql_log('ScenarioStart', ['scenario' => $id]);
    $body();
    $expected = ['A' => [0, 0, 0], 'B' => [3, 2, 4], 'C' => [1, 1, 6], 'D' => [2, 0, 8], 'E' => [1, 0, 9]][$id];
    ql_check(count(ql_events('ProviderResponse')) - $providerBefore === $expected[0]
        && count(ql_events('MailAccepted')) - $mailBefore === $expected[1], $id . '_exact_external_effect_counts');
    ql_check(DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === ($id === 'E' ? 1 : 0)
        && DB::table('notification_outbox')->count() === $expected[2]
        && DB::table('notification_outbox')->whereNull('delivered_at')->count() === 0, $id . '_exact_final_database_state');
    $GLOBALS['ql_scenarios'][] = $id;
    ql_log('ScenarioPassed', ['scenario' => $id, 'jobs' => DB::table('jobs')->count(),
        'failed_jobs' => DB::table('failed_jobs')->count(), 'outbox_rows' => DB::table('notification_outbox')->count(),
        'outbox_pending' => DB::table('notification_outbox')->whereNull('delivered_at')->count(),
        'provider_responses' => count(ql_events('ProviderResponse')), 'mail_accepted' => count(ql_events('MailAccepted'))]);
}

$app = null;
$exit = 1;
$failure = null;
$GLOBALS['ql_processes'] = [];
$GLOBALS['ql_scenarios'] = [];
try {
    $candidate = in_array('--candidate', array_slice($argv, 1), true);
    foreach (array_slice($argv, 1) as $arg) {
        if (!in_array($arg, ['--candidate', '--through=C'], true)) throw new RuntimeException('Unknown fixture run argument.');
    }
    $root = ql_normalize(realpath(dirname(__DIR__, 3)));
    $marker = bin2hex(random_bytes(12));
    $run = $root . '/tests/.runtime/queue-lifecycle/run-' . gmdate('Ymd-His') . '-' . $marker;
    if (file_exists($run) || !mkdir($run, 0777, true)) throw new RuntimeException('Fresh fixture runtime creation failed.');
    foreach (['cache', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/cache/locks',
        'storage/framework/sessions', 'storage/logs', 'storage/app'] as $dir) {
        if (!mkdir($run . '/' . $dir, 0777, true)) throw new RuntimeException('Fixture directory creation failed.');
    }
    $cfg = ['marker' => $marker, 'root' => $root, 'run' => $run, 'candidate' => $candidate,
        'binary' => ql_normalize(realpath(PHP_BINARY)), 'ini' => ql_normalize((string) php_ini_loaded_file()),
        'key' => 'base64:' . base64_encode(random_bytes(32))];
    ql_json($run . '/run.json', $cfg);
    file_put_contents($run . '/marker', $marker, LOCK_EX);
    touch($run . '/queue.sqlite');
    ql_json($run . '/provider.json', ['mode' => '204']);
    $app = ql_boot($run, $marker, $candidate, 'parent');
    ql_schema();
    ql_check($app->make(Dispatcher::class) instanceof Illuminate\Bus\Dispatcher, 'actual_bus_dispatcher');
    ql_scenario('A', function () {
        DB::table('coupons')->insert(['id' => 1, 'ret' => 0]);
        $expired = ql_order('A-expired', ['coupon_id' => 1]);
        $future = ql_order('A-future-deadline');
        foreach ([new OrderExpired($expired->order_sn, time() - 1), new OrderExpired($expired->order_sn, time() - 1),
            new OrderExpired($future->order_sn, time() + 3600)] as $job) app(Dispatcher::class)->dispatch($job);
        ql_check(DB::table('jobs')->count() === 3, 'A_serialized_jobs_persisted');
        foreach (DB::table('jobs')->get() as $row) {
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            ql_check($row->queue === 'fixture35' && $payload['displayName'] === OrderExpired::class
                && strpos($payload['data']['command'], 'OrderExpired') !== false, 'A_real_serialized_payload');
        }
        ql_drain('A-worker');
        ql_check((int) $expired->fresh()->status === Order::STATUS_EXPIRED && (int) $expired->fresh()->coupon_ret_back === 1, 'A_expired_coupon_marked');
        ql_check((int) DB::table('coupons')->where('id', 1)->value('ret') === 1, 'A_coupon_returned_once');
        ql_check((int) $future->fresh()->status === Order::STATUS_WAIT_PAY, 'A_future_deadline_preserved');
        // Retain the old created_at proof above, but prevent this fixture from becoming a later scheduler orphan.
        DB::table('orders')->where('id', $future->id)->update(['created_at' => date('Y-m-d H:i:s', time() + 3600)]);
        ql_check(DB::table('failed_jobs')->count() === 0, 'A_no_failed_jobs');
        ql_check(DB::table('notification_outbox')->count() === 0, 'A_no_outbox_intents');
        ql_check(count(ql_events('ProviderResponse')) === 0 && count(ql_events('MailAccepted')) === 0, 'A_no_notification_effects');
    });
    ql_scenario('B', function () {
        $order = ql_intent('B-success');
        ql_check(DB::table('jobs')->count() === 0 && DB::table('notification_outbox')->count() === 2, 'B_transaction_only_intents');
        ql_check(ql_outbox($order, 'payment-mail')->payload !== null, 'B_encrypted_mail_snapshot');
        ql_command(['notifications:dispatch'], 'B-publisher');
        ql_check(DB::table('jobs')->count() === 2 && !ql_outbox($order)->delivered_at
            && !ql_outbox($order, 'payment-mail')->delivered_at && (int) ql_outbox($order)->attempts === 0, 'B_publisher_only_queued');
        ql_check(count(ql_events('ProviderResponse')) === 0 && count(ql_events('MailAccepted')) === 0, 'B_publisher_no_external_effect');
        foreach (DB::table('jobs')->get() as $job) {
            DB::table('jobs')->insert(['queue' => $job->queue, 'payload' => $job->payload, 'attempts' => 0,
                'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        }
        ql_drain('B-worker');
        ql_done($order);
        ql_check(count(ql_events('ProviderResponse', $order->order_sn)) === 1, 'B_duplicate_api_once');
        $retry = ql_intent('B-retry');
        ql_json($GLOBALS['ql_run'] . '/provider.json', ['mode' => '503', 'order' => $retry->order_sn]);
        ql_command(['notifications:dispatch'], 'B-retry-publisher');
        ql_drain('B-retry-worker');
        $row = ql_outbox($retry);
        ql_check(!$row->delivered_at && !$row->failed_at && !$row->reservation_token && !$row->reserved_until
            && (int) $row->attempts === 1 && strtotime($row->available_at) > time() && $row->last_error === 'Delivery failed: RuntimeException', 'B_503_outbox_backoff_queue_ack');
        ql_check(ql_outbox($retry, 'payment-mail')->delivered_at && ql_outbox($retry, 'payment-mail')->payload === null, 'B_mail_independent_of_503');
        ql_command(['notifications:dispatch'], 'B-before-due-publisher');
        ql_check(DB::table('jobs')->count() === 0, 'B_backoff_not_published_early');
        DB::table('notification_outbox')->where('id', $row->id)->update(['available_at' => date('Y-m-d H:i:s', time() - 1)]);
        ql_log('FixtureClockElapsed', ['scenario' => 'B', 'columns' => ['notification_outbox.available_at']]);
        ql_json($GLOBALS['ql_run'] . '/provider.json', ['mode' => '204']);
        ql_command(['notifications:dispatch'], 'B-recovered-publisher');
        ql_check(DB::table('jobs')->count() === 1, 'B_only_retry_republished');
        ql_drain('B-recovered-worker');
        ql_done($retry, true, 2);
        $responses = ql_events('ProviderResponse', $retry->order_sn);
        ql_check(array_column($responses, 'status') === [503, 204], 'B_retry_provider_statuses');
        ql_check(DB::table('failed_jobs')->count() === 0, 'B_no_queue_failures');
    });
    ql_scenario('C', function () {
        DB::table('coupons')->insert(['id' => 2, 'ret' => 0]);
        $orphan = ql_order('C-orphan', ['coupon_id' => 2]);
        $future = ql_order('C-future', ['created_at' => date('Y-m-d H:i:s', time() + 3600)]);
        $paid = ql_order('C-paid', ['status' => Order::STATUS_COMPLETED]);
        $intent = ql_intent('C-outbox');
        $jobsBefore = count(ql_events('JobProcessed'));
        $effectsBefore = count(ql_events('ProviderResponse')) + count(ql_events('MailAccepted'));
        ql_command(['schedule:run'], 'C-tick1');
        ql_check((int) $orphan->fresh()->status === Order::STATUS_EXPIRED && (int) DB::table('coupons')->where('id', 2)->value('ret') === 1,
            'C_orphan_expired_coupon_returned');
        ql_check((int) $future->fresh()->status === Order::STATUS_WAIT_PAY && (int) $paid->fresh()->status === Order::STATUS_COMPLETED,
            'C_future_and_paid_preserved');
        ql_check(DB::table('jobs')->count() === 2 && !ql_outbox($intent)->delivered_at, 'C_orphan_outbox_queued_only');
        ql_check(count(ql_events('ProviderResponse')) + count(ql_events('MailAccepted')) === $effectsBefore, 'C_scheduler_no_delivery');
        ql_drain('C-worker');
        ql_done($intent);
        ql_command(['schedule:run'], 'C-tick2');
        ql_check(DB::table('jobs')->count() === 0 && (int) DB::table('coupons')->where('id', 2)->value('ret') === 1,
            'C_second_tick_no_duplicate');
        ql_check(count(ql_events('JobProcessed')) === $jobsBefore + 2 && count(ql_events('ProviderResponse', $intent->order_sn)) === 1,
            'C_worker_effects_once');
        ql_check(count(ql_events('ScheduleRewrite')) === 4 && count(ql_events('ScheduledResult')) === 4, 'C_two_ticks_both_original_events');
        $children = array_values(array_filter(ql_events('Start'), function ($row) { return $row['role'] === 'schedule-event'; }));
        ql_check(count($children) === 4 && count(array_unique(array_column($children, 'pid'))) === 4, 'C_actual_event_child_pids');
        foreach ($children as $child) ql_check($child['database'] === $GLOBALS['ql_run'] . '/queue.sqlite'
            && $child['php'] === PHP_VERSION && $child['binary'] === $GLOBALS['ql_config']['binary'] && $child['ini'] === $GLOBALS['ql_config']['ini'], 'C_same_run_event_config');
        ql_check(DB::table('failed_jobs')->count() === 0, 'C_no_failed_jobs');
    });
    if (!in_array('--through=C', $argv, true)) {
        ql_scenario('D', function () {
            $first = ql_intent('D-first', false);
            $second = ql_intent('D-second', false);
            ql_command(['notifications:dispatch'], 'D-publisher');
            ql_check(DB::table('jobs')->count() === 2, 'D_two_jobs_queued');
            ql_check(app('cache')->get('illuminate:queue:restart') === null, 'D_initial_restart_absent');
            ql_json($GLOBALS['ql_run'] . '/provider.json', ['mode' => 'pause', 'order' => $first->order_sn, 'gate' => 'D']);
            ql_worker_start('D-old-worker');
            $ready = ql_ready('D', 'D-old-worker');
            ql_check($ready['outbox_id'] === (int) ql_outbox($first)->id && (int) ql_outbox($first)->attempts === 1
                && !ql_outbox($first)->delivered_at && ql_outbox($first)->reserved_until !== null, 'D_job_claimed_before_restart');
            ql_check(DB::table('jobs')->whereNotNull('reserved_at')->count() === 1
                && DB::table('jobs')->whereNull('reserved_at')->count() === 1, 'D_first_reserved_second_pending');
            ql_command(['queue:restart'], 'D-restart-console');
            ql_check(is_int(app('cache')->get('illuminate:queue:restart')), 'D_real_filecache_restart_timestamp');
            ql_check(proc_get_status($GLOBALS['ql_processes']['D-old-worker']['proc'])['running']
                && !ql_outbox($first)->delivered_at, 'D_restart_waits_for_current_job');
            file_put_contents($GLOBALS['ql_run'] . '/D.release', 'release', LOCK_EX);
            ql_check(ql_wait('D-old-worker', 15) === 0, 'D_old_worker_clean_restart_exit');
            ql_done($first, false);
            ql_check(DB::table('jobs')->count() === 1 && DB::table('jobs')->whereNull('reserved_at')->count() === 1
                && (int) ql_outbox($second)->attempts === 0 && !ql_outbox($second)->delivered_at, 'D_second_job_left_for_new_worker');
            ql_check(count(ql_events('ProviderResponse', $first->order_sn)) === 1
                && count(ql_events('ProviderResponse', $second->order_sn)) === 0, 'D_old_worker_one_external_effect');
            ql_json($GLOBALS['ql_run'] . '/provider.json', ['mode' => '204']);
            ql_drain('D-new-worker');
            ql_done($second, false);
            ql_check(count(ql_events('ProviderResponse', $second->order_sn)) === 1, 'D_new_worker_second_effect_once');
            ql_check(DB::table('failed_jobs')->count() === 0, 'D_no_queue_failures');
        });
        ql_scenario('E', function () {
            $order = ql_intent('E-lost', false);
            ql_command(['notifications:dispatch'], 'E-publisher');
            $originalJob = DB::table('jobs')->first();
            $oldToken = ql_outbox($order)->reservation_token;
            // A second unreserved serialization retains the old token; the lost reserved job keeps its real attempt count.
            $copyId = DB::table('jobs')->insertGetId(['queue' => $originalJob->queue, 'payload' => $originalJob->payload,
                'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
            ql_json($GLOBALS['ql_run'] . '/provider.json', ['mode' => 'pause', 'order' => $order->order_sn, 'gate' => 'E']);
            ql_worker_start('E-lost-worker');
            $ready = ql_ready('E', 'E-lost-worker');
            $claimed = ql_outbox($order);
            ql_check($ready['outbox_id'] === (int) $claimed->id && (int) $claimed->attempts === 1
                && $claimed->reservation_token !== $oldToken && $claimed->reserved_until && !$claimed->delivered_at, 'E_rotated_claim_before_loss');
            $reserved = DB::table('jobs')->where('id', $originalJob->id)->first();
            ql_check($reserved->reserved_at !== null && (int) $reserved->attempts === 1
                && DB::table('jobs')->where('id', $copyId)->value('reserved_at') === null, 'E_actual_queue_reservation');
            $child =& $GLOBALS['ql_processes']['E-lost-worker'];
            // Linux SIGTERM drains the running job; this scenario requires abrupt loss.
            $lossSignal = PHP_OS_FAMILY === 'Windows' ? 15 : 9;
            ql_check(proc_terminate($child['proc'], $lossSignal), 'E_terminate_only_owned_worker', ['signal' => $lossSignal]);
            $child['terminated'] = true;
            $code = ql_wait('E-lost-worker', 15);
            ql_check(PHP_OS_FAMILY === 'Windows' ? $code !== 0 : ($child['signaled'] && $child['term_signal'] === 9),
                'E_lost_worker_nonzero_termination', ['exit' => $code, 'signaled' => $child['signaled'], 'term_signal' => $child['term_signal']]);
            unset($child);
            $left = ql_outbox($order);
            ql_check(DB::table('jobs')->count() === 2 && DB::table('jobs')->whereNotNull('reserved_at')->count() === 1
                && $left->reservation_token === $claimed->reservation_token && $left->reserved_until
                && !$left->delivered_at && (int) $left->attempts === 1, 'E_loss_preserves_queue_and_outbox_lease');
            ql_check(count(ql_events('ProviderResponse', $order->order_sn)) === 0, 'E_loss_before_external_acceptance');
            ql_command(['notifications:dispatch'], 'E-unexpired-publisher');
            ql_check(DB::table('jobs')->count() === 2, 'E_unexpired_lease_not_republished');
            DB::table('notification_outbox')->where('id', $left->id)->update([
                'reserved_until' => date('Y-m-d H:i:s', time() - 1), 'available_at' => date('Y-m-d H:i:s', time() - 1)]);
            DB::table('jobs')->where('id', $originalJob->id)->update(['reserved_at' => time() - 91]);
            ql_log('FixtureClockElapsed', ['scenario' => 'E', 'columns' => ['notification_outbox.reserved_until',
                'notification_outbox.available_at', 'jobs.reserved_at']]);
            ql_json($GLOBALS['ql_run'] . '/provider.json', ['mode' => '204']);
            ql_command(['notifications:dispatch'], 'E-recovery-publisher');
            $republished = ql_outbox($order);
            ql_check(DB::table('jobs')->count() === 3 && $republished->reservation_token !== $oldToken
                && $republished->reservation_token === $claimed->reservation_token && (int) $republished->attempts === 1, 'E_new_publication_token_preserves_attempt');
            $newJobId = (int) DB::table('jobs')->max('id');
            ql_check($newJobId !== (int) $originalJob->id && $newJobId !== (int) $copyId, 'E_distinct_republished_job_id');
            ql_drain('E-recovery-worker');
            ql_done($order, false, 2);
            $responses = ql_events('ProviderResponse', $order->order_sn);
            ql_check(count($responses) === 1, 'E_recovered_external_acceptance_once');
            ql_check($responses[0]['job_id'] === $newJobId, 'E_only_new_job_produces_recovered_response');
            $processed = array_values(array_filter(ql_events('JobProcessed'), function ($r) use ($copyId) {
                return (int) $r['job_id'] === (int) $copyId;
            }));
            ql_check(count($processed) === 1 && $processed[0]['job'] === DeliverNotification::class
                && (int) $processed[0]['attempts'] === 1, 'E_old_token_copy_processed_noop');
            $failed = DB::table('failed_jobs')->first();
            ql_check(DB::table('failed_jobs')->count() === 1 && $failed->connection === 'database' && $failed->queue === 'fixture35'
                && strpos($failed->exception, 'MaxAttemptsExceededException') !== false, 'E_lost_reserved_job_attempt_limit_recorded');
            $failedPayload = json_decode($failed->payload, true, 512, JSON_THROW_ON_ERROR);
            ql_check($failedPayload['displayName'] === DeliverNotification::class
                && $failedPayload['data']['command'] === json_decode($originalJob->payload, true)['data']['command'], 'E_failed_record_is_original_lost_serialization');
            $failedEvents = ql_events('JobFailed');
            ql_check(count($failedEvents) === 1 && (int) $failedEvents[0]['job_id'] === (int) $originalJob->id
                && $failedEvents[0]['exception_class'] === Illuminate\Queue\MaxAttemptsExceededException::class, 'E_actual_failed_job_event');
        });
    }
    $expectedScenarios = in_array('--through=C', $argv, true) ? ['A', 'B', 'C'] : ['A', 'B', 'C', 'D', 'E'];
    ql_check($GLOBALS['ql_scenarios'] === $expectedScenarios, 'all_requested_scenarios_passed');
    ql_check(DB::table('jobs')->count() === 0, 'final_queue_empty');
    ql_check(DB::table('failed_jobs')->count() === (count($expectedScenarios) === 5 ? 1 : 0), 'final_expected_failed_jobs');
    ql_check(DB::table('notification_outbox')->whereNull('delivered_at')->count() === 0
        && DB::table('notification_outbox')->whereNotNull('failed_at')->count() === 0, 'final_all_outbox_delivered');
    ql_check(DB::table('notification_outbox')->where('channel', 'payment-mail')->whereNotNull('payload')->count() === 0, 'final_mail_snapshots_cleared');
    foreach ($GLOBALS['ql_processes'] as $label => $child) {
        $lost = $child['terminated'] && (PHP_OS_FAMILY === 'Windows' ? $child['exit'] > 0
            : ($child['signaled'] && $child['term_signal'] === 9));
        ql_check($child['closed'] && ($label === 'E-lost-worker' ? $lost : $child['exit'] === 0),
            'final_process_code_' . $label, ['exit' => $child['exit'], 'pid' => $child['pid']]);
    }
    $closedPids = array_column(ql_events('ConnectionClosed'), 'pid');
    foreach (ql_events('Start') as $start) {
        if ($start['role'] !== 'parent' && $start['role'] !== 'E-lost-worker') {
            ql_check(in_array($start['pid'], $closedPids, true), 'child_connection_closed_' . $start['role']);
        }
    }
    foreach (ql_events('JobProcessing') as $event) ql_check($event['transaction_level'] === 0, 'worker_processing_after_db_commit');
    $exit = 0;
} catch (Throwable $e) {
    $failure = ['class' => get_class($e), 'message' => $e->getMessage()];
    if (isset($GLOBALS['ql_run'])) ql_log('RunFailure', $failure);
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    foreach ($GLOBALS['ql_processes'] as $label => &$child) {
        if (!$child['closed'] && is_resource($child['proc'])) {
            $status = proc_get_status($child['proc']);
            if ($status['running']) {
                proc_terminate($child['proc']);
                $child['terminated'] = true;
            }
            try {
                ql_wait($label, 5);
            } catch (Throwable $cleanup) {
                if (proc_get_status($child['proc'])['running']) proc_terminate($child['proc'], 9);
                try {
                    ql_wait($label, 5);
                } catch (Throwable $stuck) {
                    $exit = 1;
                    $failure = $failure ?: ['class' => get_class($stuck), 'message' => $stuck->getMessage()];
                    ql_log('CleanupFailure', ['label' => $label, 'message' => $stuck->getMessage()]);
                }
            }
        }
    }
    unset($child);
    if (isset($GLOBALS['ql_run'])) {
        $events = ql_events();
        $starts = array_values(array_filter($events, function ($r) { return $r['event'] === 'Start'; }));
        $checks = array_values(array_filter($events, function ($r) { return $r['event'] === 'CheckPassed'; }));
        $processes = [];
        foreach ($GLOBALS['ql_processes'] as $label => $p) $processes[$label] = array_diff_key($p, ['proc' => true]);
        $summary = ['status' => $exit === 0 ? 'PASS' : 'FAIL', 'exit' => $exit, 'failure' => $failure,
            'marker' => $GLOBALS['ql_config']['marker'], 'candidate' => $GLOBALS['ql_config']['candidate'],
            'php' => PHP_VERSION, 'framework' => $app ? $app->version() : null, 'checkedcount' => count($checks),
            'scenarioids' => $GLOBALS['ql_scenarios'], 'parentPID' => getmypid(),
            'childPIDs' => array_values(array_unique(array_column(array_filter($starts, function ($row) { return $row['role'] !== 'parent'; }), 'pid'))),
            'configevidence' => $starts, 'processes' => $processes, 'scenarioevidence' => ql_events('ScenarioPassed'),
            'providerresponses' => ql_events('ProviderResponse'), 'mailaccepted' => ql_events('MailAccepted')];
        if ($app && $app->bound('db')) {
            try {
                $summary['final'] = ['jobs' => DB::table('jobs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count(),
                    'outbox' => DB::table('notification_outbox')->get(['id', 'channel', 'attempts', 'delivered_at', 'failed_at'])->toArray()];
            } catch (Throwable $ignored) {
                $summary['final'] = ['schema_incomplete' => true];
            }
            ql_disconnect($app);
        }
        ql_json($GLOBALS['ql_run'] . '/summary.json', $summary);
        fwrite(STDOUT, $summary['status'] . ' scenarios=' . implode(',', $summary['scenarioids']) . ' checks=' . $summary['checkedcount']
            . ' summary=' . $GLOBALS['ql_run'] . '/summary.json' . PHP_EOL);
    }
}
exit($exit);
