<?php

// Validate the real Compose model without loading a shop environment or engine.
$root = dirname(__DIR__, 3);
$options = getopt('', ['compose-bin:', 'file:', 'worker-config:', 'candidate', 'legacy']);
$binary = isset($options['compose-bin']) ? realpath($options['compose-bin']) : false;
$source = isset($options['file']) ? realpath($options['file']) : $root . '/docker-compose.yml';
$workerSource = isset($options['worker-config']) ? realpath($options['worker-config']) : $root . '/deploy/supervisor/queue.conf';
if (!$binary || !$source || !$workerSource || (isset($options['candidate']) && isset($options['legacy']))) {
    fwrite(STDERR, "Usage: php run.php --compose-bin=PATH [--candidate | --legacy] [--file=PATH] [--worker-config=PATH]\n");
    exit(2);
}
$run = $root . '/tests/.runtime/deployment-contract/run-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(8));
mkdir($run . '/docker-config', 0700, true);
file_put_contents($run . '/empty.env', '');
$before = hash_file('sha256', $source);
$env = [];
foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP'] as $name) {
    if (getenv($name) !== false) $env[$name] = getenv($name);
}
$env['DOCKER_CONFIG'] = $run . '/docker-config';
$env['USERPROFILE'] = $run;
$env['COMPOSE_DISABLE_ENV_FILE'] = '1';
$env['DOCKER_HOST'] = 'tcp://127.0.0.1:1';
$command = [$binary, '--env-file', $run . '/empty.env', '--project-directory', $root, '--project-name', 'dujiaoka-contract', '-f', $source, 'config', '--no-env-resolution', '--no-interpolate', '--format', 'json'];
$report = ['status' => 'FAIL', 'command' => $command, 'source' => $source, 'source_sha256' => $before, 'worker_config' => $workerSource, 'worker_config_sha256' => hash_file('sha256', $workerSource), 'binary_sha256' => hash_file('sha256', $binary), 'checks' => [], 'no_engine_started' => true, 'shop_env_loaded' => false];
$process = null;
$check = function ($id, $passed, $actual = null) use (&$report) {
    $report['checks'][] = ['id' => $id, 'passed' => (bool) $passed, 'actual' => $actual];
};
try {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $run . '/model.json', 'w'], 2 => ['file', $run . '/compose.stderr', 'w']], $pipes, $root, $env);
    if (!is_resource($process)) throw new RuntimeException('Compose process did not start.');
    fclose($pipes[0]);
    $deadline = microtime(true) + 30;
    do {
        $status = proc_get_status($process);
        if (!$status['running']) break;
        usleep(50000);
    } while (microtime(true) < $deadline);
    if ($status['running']) throw new RuntimeException('Compose model exceeded bounded wait.');
    $exit = $status['exitcode'];
    proc_close($process);
    $process = null;
    if ($exit !== 0) throw new RuntimeException('Compose model failed with exit ' . $exit . '.');
    $model = json_decode(file_get_contents($run . '/model.json'), true, 512, JSON_THROW_ON_ERROR);
    $web = $model['services']['web'];
    $signal = $web['stop_signal'] ?? null;
    $duration = $web['stop_grace_period'] ?? '';
    $seconds = 0;
    if (is_string($duration) && preg_match('/^(?:[0-9]+(?:\.[0-9]+)?(?:h|m|s))+$/', $duration)) {
        preg_match_all('/([0-9]+(?:\.[0-9]+)?)(h|m|s)/', $duration, $units, PREG_SET_ORDER);
        foreach ($units as $unit) $seconds += (float) $unit[1] * ['h' => 3600, 'm' => 60, 's' => 1][$unit[2]];
    }
    $supervisor = file_get_contents($workerSource);
    if (!preg_match('/^stopwaitsecs=([0-9]+)\s*$/m', $supervisor, $wait) || !preg_match('/--timeout=([0-9]+)(?:\s|$)/', $supervisor, $timeout)) throw new RuntimeException('Worker lifecycle options absent.');
    $vendor = $root . (isset($options['legacy']) ? '/tests/Compatibility/Laravel6/vendor'
        : (isset($options['candidate']) ? '/tests/Compatibility/Laravel12/vendor' : '/vendor'));
    require $vendor . '/autoload.php';
    $report['runtime'] = ['php' => PHP_VERSION, 'framework' => Illuminate\Foundation\Application::VERSION, 'vendor' => $vendor];
    $job = (new ReflectionClass(App\Jobs\DeliverNotification::class))->getDefaultProperties();
    $check('explicit_supervisor_sigterm', $signal === 'SIGTERM', $signal);
    $check('worker_and_job_have_bounded_deadlines', (int) $timeout[1] > 0 && $job['timeout'] > 0, ['worker' => (int) $timeout[1], 'job' => $job['timeout']]);
    $check('supervisor_wait_outlives_actual_deadlines', (int) $wait[1] > max((int) $timeout[1], $job['timeout']), (int) $wait[1]);
    $check('container_grace_outlives_supervisor', $seconds > (int) $wait[1], ['model' => $duration, 'seconds' => $seconds]);
    $check('source_unchanged_during_validation', hash_file('sha256', $source) === $before && hash_file('sha256', $workerSource) === $report['worker_config_sha256']);
    $report['effective'] = ['signal' => $signal, 'container_seconds' => $seconds, 'supervisor_seconds' => (int) $wait[1], 'worker_seconds' => (int) $timeout[1], 'job_seconds' => $job['timeout']];
    $report['status'] = array_filter($report['checks'], function ($item) { return !$item['passed']; }) ? 'FAIL' : 'PASS';
} catch (Throwable $error) {
    $report['error'] = ['class' => get_class($error), 'message' => $error->getMessage()];
} finally {
    if (is_resource($process)) {
        // Close only after the owned process stopped; never block indefinitely.
        foreach ([15, 9] as $signal) {
            if (!proc_get_status($process)['running']) break;
            proc_terminate($process, $signal);
            $deadline = microtime(true) + 5;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) break;
                usleep(50000);
            } while (microtime(true) < $deadline);
        }
        if (!proc_get_status($process)['running']) proc_close($process);
        else $report['cleanup_error'] = 'Owned Compose process still running after bounded cleanup.';
    }
    file_put_contents($run . '/summary.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
echo $report['status'] . ' summary=' . $run . '/summary.json', PHP_EOL;
exit($report['status'] === 'PASS' && !isset($report['cleanup_error']) ? 0 : 1);
