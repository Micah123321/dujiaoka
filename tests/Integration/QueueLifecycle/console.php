<?php

require __DIR__ . '/bootstrap.php';
$app = null;
$exit = 1;
try {
    $options = ['candidate' => false, 'role' => 'console'];
    $command = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!$command && $arg === '--candidate') $options['candidate'] = true;
        elseif (!$command && preg_match('/^--(run|marker|role)=(.+)$/D', $arg, $m)) $options[$m[1]] = $m[2];
        else $command[] = $arg;
    }
    if (!isset($options['run'], $options['marker']) || !$command
        || !in_array($command[0], ['orders:expire', 'notifications:dispatch', 'queue:work', 'queue:restart', 'schedule:run'], true)) {
        throw new RuntimeException('Fixture console requires an owned run and an allowed command.');
    }
    $app = ql_boot($options['run'], $options['marker'], $options['candidate'], $options['role']);
    $schedule = $command[0] === 'schedule:run' ? ql_schedule($app) : null;
    $input = new Symfony\Component\Console\Input\ArgvInput(array_merge([__FILE__], $command));
    $output = new Symfony\Component\Console\Output\ConsoleOutput();
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $exit = (int) $kernel->handle($input, $output);
    $kernel->terminate($input, $exit);
    if ($schedule) {
        foreach ($schedule->events() as $event) {
            ql_check($event->exitCode === 0, 'scheduled_process_exit', ['command' => $event->command, 'exit' => $event->exitCode]);
            ql_check(!$event->mutex->exists($event), 'actual_schedule_mutex_removed');
            foreach ($GLOBALS['ql_original_mutexes'] as $originalMutex) {
                ql_check(!$app['cache']->has($originalMutex), 'original_schedule_mutex_clear');
            }
            ql_log('ScheduledResult', ['command' => $event->command, 'exit' => $event->exitCode, 'mutex' => $event->mutexName()]);
        }
    }
    ql_log('ConsoleExit', ['command' => $command[0], 'exit' => $exit]);
} catch (Throwable $e) {
    if (isset($GLOBALS['ql_run'])) ql_log('ConsoleFailure', ['class' => get_class($e), 'message' => $e->getMessage()]);
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
    $exit = 1;
} finally {
    if ($app) ql_disconnect($app);
}
exit($exit);
