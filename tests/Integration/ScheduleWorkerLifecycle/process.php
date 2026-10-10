<?php

function sw_spawn(array $args, $label)
{
    ll_spawn(array_merge(['/usr/bin/setsid'], $args), $label);
}

function sw_parse_stat($stat)
{
    // A process can disappear after opening its stat file and yield an incomplete record.
    if (!is_string($stat) || !preg_match('/^[0-9]+ [(]/', $stat)) return null;
    $end = strrpos($stat, ')');
    if ($end === false) return null;
    $f = preg_split('/\s+/', trim(substr($stat, $end + 1)));
    if (count($f) < 20 || !preg_match('/^[A-Za-z]$/D', $f[0])) return null;
    foreach ([2, 3, 19] as $index) if (!preg_match('/^[0-9]+$/D', $f[$index])) return null;
    return ['state' => $f[0], 'group' => (int) $f[2], 'session' => (int) $f[3], 'start_ticks' => $f[19]];
}

function sw_stat($pid)
{
    return sw_parse_stat(@file_get_contents('/proc/' . $pid . '/stat'));
}

function sw_track_children()
{
    foreach ($GLOBALS['ll_processes'] as $child) {
        $stat = sw_stat($child['pid']);
        if ($stat && $stat['start_ticks'] === $child['identity']['start_ticks']
            && $stat['group'] === $child['pid'] && $stat['session'] === $child['pid']) {
            $GLOBALS['sw_groups'][$child['pid']] = ['leader' => $child['pid'], 'start_ticks' => $stat['start_ticks']];
        }
    }
    if (!$GLOBALS['sw_groups']) return;
    // Read only stat metadata until it matches a session created by our open child handle.
    foreach (glob('/proc/[0-9]*/stat') as $path) {
        $pid = (int) basename(dirname($path));
        $stat = sw_stat($pid);
        if (!$stat || $stat['state'] === 'Z' || !isset($GLOBALS['sw_groups'][$stat['session']]) || $stat['group'] !== $stat['session']) continue;
        $p = ll_identity($pid);
        if (!$p || $p['start_ticks'] !== $stat['start_ticks']) continue;
        $p['pid_namespace'] = @readlink('/proc/' . $pid . '/ns/pid');
        // A short-lived descendant can exit between stat and namespace reads.
        $current = sw_stat($pid);
        if (!$current || $current['state'] === 'Z' || $current['start_ticks'] !== $p['start_ticks']) continue;
        $p['group'] = $stat['group'];
        $p['session'] = $stat['session'];
        if ($p['pid_namespace'] !== readlink('/proc/self/ns/pid')) throw new RuntimeException('Owned session changed PID namespace.');
        $status = @file_get_contents('/proc/' . $pid . '/status');
        if ($status === false) continue;
        if (!preg_match('/^Uid:\s+1000\s+1000\s+1000\s+1000$/m', $status)) throw new RuntimeException('Owned session changed UID.');
        $GLOBALS['sw_owned_children'][$pid] = $p;
    }
}

function sw_child_alive(array $p)
{
    $stat = sw_stat($p['pid']);
    return $stat && $stat['start_ticks'] === $p['start_ticks'] && $stat['state'] !== 'Z';
}

function sw_signal_child(array $p, $signal)
{
    if (!sw_child_alive($p)) return;
    $stat = sw_stat($p['pid']);
    if (!$stat || $stat['start_ticks'] !== $p['start_ticks'] || $stat['state'] === 'Z') return;
    if ($stat['group'] !== $p['group'] || $stat['session'] !== $p['session']
        || !isset($GLOBALS['sw_groups'][$p['session']])
        || @readlink('/proc/' . $p['pid'] . '/ns/pid') !== $p['pid_namespace']) {
        throw new RuntimeException('Owned session identity changed before signal.');
    }
    if (!posix_kill($p['pid'], $signal)) throw new RuntimeException('Owned descendant signal failed.');
}

function sw_wait($label, $seconds = 30)
{
    if ($GLOBALS['ll_processes'][$label]['closed']) return $GLOBALS['ll_processes'][$label]['exit'];
    return ll_until(function () use ($label) {
        sw_track_children();
        if (proc_get_status($GLOBALS['ll_processes'][$label]['proc'])['running']) return false;
        return ['exit' => ll_wait($label, 1)];
    }, 'tracked process exit ' . $label, $seconds)['exit'];
}

function sw_cleanup_children()
{
    $sent = [];
    $until = microtime(true) + 5;
    do {
        sw_track_children();
        $alive = array_filter($GLOBALS['sw_owned_children'], 'sw_child_alive');
        foreach ($alive as $p) {
            $key = $p['pid'] . ':' . $p['start_ticks'];
            if (!isset($sent[$key])) { sw_signal_child($p, SIGTERM); $sent[$key] = true; }
        }
        if (!$alive) break;
        usleep(50000);
    } while (microtime(true) < $until);
    $until = microtime(true) + 5;
    $quiet = false;
    do {
        sw_track_children();
        $alive = array_filter($GLOBALS['sw_owned_children'], 'sw_child_alive');
        foreach ($alive as $p) sw_signal_child($p, SIGKILL);
        if (!$alive && $quiet) return;
        $quiet = !$alive;
        usleep(50000);
    } while (microtime(true) < $until);
    throw new RuntimeException('Owned session still contains live processes after cleanup.');
}
