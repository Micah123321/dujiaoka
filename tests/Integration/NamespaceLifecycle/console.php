<?php

// Observe identity only; the original console boots and executes the real worker.
if (in_array('--role=namespace-worker', $argv, true)) {
    $run = null;
    $marker = null;
    foreach ($argv as $arg) {
        if (strpos($arg, '--run=') === 0) $run = substr($arg, 6);
        if (strpos($arg, '--marker=') === 0) $marker = substr($arg, 9);
    }
    $root = realpath(dirname(__DIR__, 3));
    $base = realpath($root . '/tests/.runtime/queue-lifecycle');
    $actual = $run === null ? false : realpath($run);
    if (!$actual || !$base || dirname($actual) !== $base || !preg_match('/^run-ns-[a-zA-Z0-9-]+$/D', basename($actual))
        || !$marker || !hash_equals(trim(file_get_contents($actual . '/marker')), $marker)) {
        throw new RuntimeException('Namespace console requires an owned fixture.');
    }
    $record = ['marker' => $marker, 'pid' => getmypid(), 'uid' => posix_geteuid(), 'gid' => posix_getegid(),
        'user' => posix_getpwuid(posix_geteuid())['name'], 'group' => posix_getgrgid(posix_getegid())['name'],
        'binary' => realpath(PHP_BINARY), 'ini' => php_ini_loaded_file(), 'php' => PHP_VERSION,
        'uid_map' => file_get_contents('/proc/self/uid_map'), 'gid_map' => file_get_contents('/proc/self/gid_map'),
        'status' => file_get_contents('/proc/self/status'), 'namespaces' => []];
    foreach (['user', 'mnt', 'pid'] as $name) $record['namespaces'][$name] = readlink('/proc/self/ns/' . $name);
    if ($record['uid'] !== 1000 || $record['gid'] !== 1000 || $record['user'] !== 'application' || $record['group'] !== 'application') {
        throw new RuntimeException('Unexpected application identity.');
    }
    if (file_put_contents($actual . '/namespace-worker.json', json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Namespace identity write failed.');
    }
}
require dirname(__DIR__) . '/QueueLifecycle/console.php';
