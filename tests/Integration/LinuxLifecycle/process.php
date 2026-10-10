<?php

function ll_until(callable $condition, $check, $seconds = 15)
{
    $deadline = microtime(true) + $seconds;
    do {
        clearstatcache();
        $value = $condition();
        if ($value) return $value;
        usleep(50000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Timed out: ' . $check);
}

function ll_identity($pid)
{
    $stat = @file_get_contents('/proc/' . $pid . '/stat');
    if ($stat === false) return null;
    $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
    return ['pid' => $pid, 'start_ticks' => $fields[19], 'ppid' => (int) $fields[1],
        'exe' => ql_normalize((string) @readlink('/proc/' . $pid . '/exe')),
        'argv' => explode("\0", rtrim((string) @file_get_contents('/proc/' . $pid . '/cmdline'), "\0"))];
}

function ll_spawn(array $args, $label, $env = null)
{
    $run = $GLOBALS['ql_run'];
    $pipes = [];
    $proc = proc_open($args, [0 => ['file', '/dev/null', 'r'],
        1 => ['file', $run . '/' . $label . '.stdout', 'a'],
        2 => ['file', $run . '/' . $label . '.stderr', 'a']], $pipes, $GLOBALS['ql_config']['root'], $env,
        ['bypass_shell' => true]);
    if (!is_resource($proc)) throw new RuntimeException('proc_open failed: ' . $label);
    $status = proc_get_status($proc);
    $GLOBALS['ll_processes'][$label] = ['proc' => $proc, 'pid' => $status['pid'], 'args' => $args,
        'identity' => ll_identity($status['pid']), 'exit' => null, 'closed' => false];
    ql_log('ProcessStarted', ['label' => $label, 'child_pid' => $status['pid'], 'args' => $args]);
    return $label;
}

function ll_wait($label, $seconds = 30)
{
    $child =& $GLOBALS['ll_processes'][$label];
    if ($child['closed']) return $child['exit'];
    return ll_until(function () use (&$child, $label) {
        $status = proc_get_status($child['proc']);
        if ($status['running']) return false;
        $code = $status['exitcode'];
        $close = proc_close($child['proc']);
        if ($code < 0 && $close >= 0) $code = $close;
        $child['exit'] = $code;
        $child['signaled'] = $status['signaled'];
        $child['term_signal'] = $status['termsig'];
        $child['closed'] = true;
        ql_log('ProcessExit', ['label' => $label, 'child_pid' => $child['pid'], 'exit' => $code,
            'signaled' => $status['signaled'], 'term_signal' => $status['termsig']]);
        return ['code' => $code];
    }, 'process exit ' . $label, $seconds)['code'];
}

function ll_same_process(array $identity)
{
    $current = ll_identity($identity['pid']);
    return $current && $current['start_ticks'] === $identity['start_ticks'] && $current['argv'] === $identity['argv'];
}

function ll_cleanup_owned($label)
{
    $child =& $GLOBALS['ll_processes'][$label];
    if ($child['closed']) return;
    // The open proc handle owns this child even if its pre-exec argv snapshot changed.
    if (proc_get_status($child['proc'])['running']) proc_terminate($child['proc'], SIGTERM);
    try {
        ll_wait($label, 5);
    } catch (Throwable $timeout) {
        if (proc_get_status($child['proc'])['running'] && !proc_terminate($child['proc'], SIGKILL)) {
            throw new RuntimeException('Owned child SIGKILL failed: ' . $label);
        }
        ll_wait($label, 5);
    }
}
