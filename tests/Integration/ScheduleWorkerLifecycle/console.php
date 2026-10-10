<?php
require dirname(__DIR__) . '/QueueLifecycle/bootstrap.php';
$app = null;
$exit = 1;
try {
    $options = ['candidate' => false, 'role' => 'minute-console'];
    $command = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!$command && $arg === '--candidate') $options['candidate'] = true;
        elseif (!$command && preg_match('/^--(run|marker|role)=(.+)$/D', $arg, $m)) $options[$m[1]] = $m[2];
        else $command[] = $arg;
    }
    if (!$options['candidate'] || !isset($options['run'], $options['marker']) || !$command
        || !in_array($command[0], ['schedule:work', 'schedule:run', 'queue:work'], true)) {
        throw new RuntimeException('Owned candidate minute console required.');
    }
    if ($command[0] === 'schedule:work') define('ARTISAN_BINARY', $options['run'] . '/artisan');
    $app = ql_boot($options['run'], $options['marker'], true, $options['role']);
    ql_check(!Illuminate\Support\Carbon::hasTestNow(), 'real_application_clock');
    ql_check(posix_geteuid() === 1000 && posix_getegid() === 1000, 'regular_host_uid_gid_1000');
    if ($command[0] === 'schedule:work') {
        // Keep testing isolation while exercising the original console signal registrar.
        Illuminate\Console\Signals::resolveAvailabilityUsing(function () use ($app) {
            return $app->runningInConsole() && extension_loaded('pcntl');
        });
        ql_log('SignalAvailabilityOverride', ['reason' => 'ArtisanServiceProvider disables traps in testing',
            'console' => $app->runningInConsole(), 'pcntl' => extension_loaded('pcntl'), 'environment' => $app->environment()]);
        putenv('PHPRC=' . $GLOBALS['ql_config']['ini']);
        $_ENV['PHPRC'] = $_SERVER['PHPRC'] = $GLOBALS['ql_config']['ini'];
        putenv('PHP_INI_SCAN_DIR=');
        $_ENV['PHP_INI_SCAN_DIR'] = $_SERVER['PHP_INI_SCAN_DIR'] = '';
        $source = (new ReflectionClass(Illuminate\Console\Scheduling\ScheduleWorkCommand::class))->getFileName();
        ql_log('MinuteDaemonSource', ['path' => $source, 'sha256' => hash_file('sha256', $source), 'artisan' => ARTISAN_BINARY]);
        // Observe the registered native callback; do not install a TERM handler here.
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, function () {
            $handler = pcntl_signal_get_handler(SIGTERM);
            $ready = false;
            if (is_array($handler) && $handler[0] instanceof Symfony\Component\Console\SignalRegistry\SignalRegistry) {
                $property = new ReflectionProperty(get_class($handler[0]), 'signalHandlers');
                $callbacks = $property->getValue($handler[0]);
                foreach ($callbacks[SIGTERM] ?? [] as $callback) {
                    if ($callback instanceof Closure) {
                        $scope = (new ReflectionFunction($callback))->getClosureScopeClass();
                        if ($scope && $scope->getName() === Illuminate\Console\Scheduling\ScheduleWorkCommand::class) $ready = true;
                    }
                }
            }
            if ($ready) ql_log('NativeSignalReady', ['signal' => SIGTERM, 'registrar' => get_class($handler[0])]);
            else pcntl_alarm(1);
        });
        pcntl_alarm(1);
    }
    $schedule = $command[0] === 'schedule:run' ? ql_schedule($app) : null;
    if ($schedule) ql_log('RealMinuteEntry', ['uid' => posix_geteuid(), 'gid' => posix_getegid(),
        'ppid' => posix_getppid(), 'argv' => $argv, 'wall_minute' => (int) floor(microtime(true) / 60)]);
    $input = new Symfony\Component\Console\Input\ArgvInput(array_merge([__FILE__], $command));
    $output = new Symfony\Component\Console\Output\ConsoleOutput();
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $exit = (int) $kernel->handle($input, $output);
    $kernel->terminate($input, $exit);
    if ($schedule) foreach ($schedule->events() as $event) {
        ql_check($event->exitCode === 0, 'real_minute_event_exit');
        ql_check(!$event->mutex->exists($event), 'real_minute_event_mutex_removed');
        ql_log('ScheduledResult', ['command' => $event->command, 'exit' => $event->exitCode, 'mutex' => $event->mutexName()]);
    }
    ql_log('ConsoleExit', ['command' => $command[0], 'exit' => $exit]);
} catch (Throwable $e) {
    $exit = 1;
    if (isset($GLOBALS['ql_run'])) ql_log('ConsoleFailure', ['class' => get_class($e), 'message' => $e->getMessage()]);
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
} finally {
    if ($app) ql_disconnect($app);
}
exit($exit);
