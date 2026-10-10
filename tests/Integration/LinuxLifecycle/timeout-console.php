<?php

require dirname(__DIR__) . '/QueueLifecycle/bootstrap.php';

use App\Jobs\DeliverNotification;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerStopReason;
use Illuminate\Support\Facades\DB;

$app = null;
$exit = 1;
try {
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--candidate') $options['candidate'] = true;
        elseif (preg_match('/^--(run|marker|role)=(.+)$/D', $arg, $m)) $options[$m[1]] = $m[2];
        else throw new RuntimeException('Unknown timeout console argument.');
    }
    if (empty($options['candidate']) || !isset($options['run'], $options['marker'], $options['role'])
        || $options['role'] !== 'timeout-worker' || PHP_OS_FAMILY !== 'Linux'
        || PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600
        || !extension_loaded('pcntl') || !extension_loaded('posix')) {
        throw new RuntimeException('Requires an owned Linux PHP 8.5 candidate timeout worker.');
    }
    $app = ql_boot($options['run'], $options['marker'], true, $options['role']);
    $kill = (new ReflectionClass(Worker::class))->getProperty('killCallback');
    ql_check(Worker::$killOnTimeout && Worker::$timedOutExitCode === null && $kill->getValue() === null,
        'native_worker_timeout_and_kill_defaults');
    $app['events']->listen(JobProcessing::class, function ($event) {
        $GLOBALS['lt_processing_at'] = microtime(true);
        ql_check($event->job->resolveName() === DeliverNotification::class && $event->job->timeout() === 60
            && $event->job->maxTries() === 1 && pcntl_async_signals(), 'original_job_native_alarm');
        ql_log('TimeoutProcessing', ['job_id' => $event->job->getJobId(), 'timeout' => $event->job->timeout(),
            'tries' => $event->job->maxTries(), 'processing_at' => $GLOBALS['lt_processing_at'],
            'pcntl_async_signals' => pcntl_async_signals(), 'transaction_level' => DB::transactionLevel()]);
    });
    $app['events']->listen(JobTimedOut::class, function ($event) {
        ql_log('JobTimedOut', ['job_id' => $event->job->getJobId(), 'timeout' => $event->timeout,
            'job_timeout' => $event->job->timeout(), 'attempts' => $event->job->attempts(),
            'failed' => $event->job->hasFailed(), 'deleted' => $event->job->isDeleted(),
            'transaction_level' => DB::transactionLevel(), 'pdo_in_transaction' => DB::connection()->getPdo()->inTransaction(),
            'processing_at' => $GLOBALS['lt_processing_at'] ?? null]);
    });
    $app['events']->listen(WorkerStopping::class, function ($event) {
        ql_log('WorkerStopping', ['status' => $event->status, 'reason' => $event->reason ? $event->reason->value : null,
            'reasonTimedOut' => $event->reason === WorkerStopReason::TimedOut,
            'cli_timeout' => $event->workerOptions->timeout, 'cli_tries' => $event->workerOptions->maxTries,
            'transaction_level' => DB::transactionLevel()]);
    });
    // Replace only the in-memory provider; Worker owns SIGALRM and its native kill path.
    $app->instance(GuzzleHttp\Client::class, new GuzzleHttp\Client(['handler' => GuzzleHttp\HandlerStack::create(function ($request) {
        $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $row = DB::table('notification_outbox')->first();
        $order = $row ? DB::table('orders')->where('id', $row->order_id)->first() : null;
        ql_check($request->getMethod() === 'POST' && (string) $request->getUri() === 'https://fixture.invalid/hook'
            && $order && $payload['order_sn'] === $order->order_sn, 'timeout_synthetic_provider_only');
        ql_check($row && $row->reservation_token && $row->reserved_until && !$row->delivered_at
            && (int) $row->attempts === 1 && DB::transactionLevel() === 0
            && !DB::connection()->getPdo()->inTransaction(), 'timeout_provider_claimed_committed_lease');
        $ready = ['pid' => getmypid(), 'job_id' => $GLOBALS['ql_current_job_id'], 'order_sn' => $order->order_sn,
            'outbox_id' => (int) $row->id, 'attempts' => (int) $row->attempts, 'reservation_token' => $row->reservation_token,
            'provider_at' => microtime(true), 'processing_at' => $GLOBALS['lt_processing_at'],
            'timeout' => 60, 'guard_seconds' => 75, 'transaction_level' => DB::transactionLevel()];
        ql_log('ProviderReady', $ready);
        ql_json($GLOBALS['ql_run'] . '/timeout.ready.json', $ready);
        $deadline = $ready['provider_at'] + 75;
        while (microtime(true) < $deadline) usleep(100000);
        throw new RuntimeException('Provider 75-second guard reached without native Worker timeout.');
    })]));
    $command = ['queue:work', 'database', '--queue=fixture35', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=1'];
    ql_log('TimeoutWorkerCommand', ['command' => $command, 'pcntl' => phpversion('pcntl'), 'posix' => phpversion('posix')]);
    $input = new Symfony\Component\Console\Input\ArgvInput(array_merge([__FILE__], $command));
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $exit = (int) $kernel->handle($input, new Symfony\Component\Console\Output\ConsoleOutput());
    $kernel->terminate($input, $exit);
    ql_log('ConsoleExit', ['command' => 'queue:work', 'exit' => $exit]);
} catch (Throwable $e) {
    $exit = 1;
    if (isset($GLOBALS['ql_run'])) ql_log('ConsoleFailure', ['class' => get_class($e), 'message' => $e->getMessage()]);
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    if ($app) ql_disconnect($app);
}
exit($exit);
