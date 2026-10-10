<?php
require __DIR__ . '/http-prepend.php';
require __DIR__ . '/queue-prepend.php';

$checks = []; $processes = []; $identities = []; $signalsSent = []; $snapshots = []; $jobDefinitions = [];
$events = []; $shutdowns = []; $filesSHA = []; $sourceIncludes = []; $packageGraph = []; $cleanup = [];
$failure = null; $interrupted = null; $ownsRun = false; $completed = false; $alarm = false; $seedObserver = null;
$cfg = ['run' => '/probe/queue.private', 'database' => '/app/storage/image-fixture.sqlite', 'contract_pid' => getmypid()];
$app = null; $db = null; $started = microtime(true); $rpcSerial = 0; $workerIdentities = [];

function miq_check($ok, $label)
{
    if (!$ok) throw new RuntimeException('Original image queue contract: ' . $label);
    $GLOBALS['checks'][] = $label;
}

function miq_tick()
{
    if ($GLOBALS['interrupted'] !== null) throw new RuntimeException('Owned queue contract interrupted: ' . $GLOBALS['interrupted']);
    if (microtime(true) - $GLOBALS['started'] >= 230) throw new RuntimeException('Owned queue work budget exhausted before cleanup.');
}

function miq_identity($pid)
{
    // A disappearing /proc entry is normal for an owned process, not a PHP application diagnostic.
    $stat = miq_proc_read('/proc/' . (int) $pid . '/stat');
    if ($stat === false) return null;
    $tail = preg_split('/\s+/', substr($stat, strrpos($stat, ')') + 2));
    $status = miq_proc_read('/proc/' . (int) $pid . '/status');
    if ($status === false) return null;
    preg_match('/^Uid:\s+(.*)$/m', $status, $uids); preg_match('/^Gid:\s+(.*)$/m', $status, $gids);
    $evidence = miq_process_identity_evidence($pid, $status); if (!$evidence) return null;
    return ['pid' => (int) $pid, 'ppid' => (int) $tail[1], 'pgrp' => (int) $tail[2], 'state' => $tail[0],
        'start_ticks' => $tail[19], 'uids' => array_map('intval', preg_split('/\s+/', trim($uids[1]))),
        'gids' => array_map('intval', preg_split('/\s+/', trim($gids[1]))),
        'exe' => miq_proc_read('/proc/' . (int) $pid . '/exe', true),
        'cmdline' => str_replace(chr(0), ' ', (string) miq_proc_read('/proc/' . (int) $pid . '/cmdline')),
        'raw_stat' => $stat, 'raw_status' => $status] + $evidence;
}

function miq_track($pid, $owner, $parent = null, $validate = true)
{
    $identity = miq_identity($pid);
    if (!$identity || $identity['state'] === 'Z') return null;
    if ($parent !== null && $identity['ppid'] !== $parent) throw new RuntimeException('Owned child parent mismatch: ' . $pid);
    $key = $identity['pid'] . ':' . $identity['start_ticks'];
    if (!isset($GLOBALS['identities'][$key])) {
        $GLOBALS['identities'][$key] = ['owner' => $owner, 'identity' => $identity, 'closed' => false, 'last' => $identity];
        miq_append($GLOBALS['cfg']['run'] . '/identities.jsonl', ['owner' => $owner, 'identity' => $identity, 'time' => microtime(true)]);
    }
    if ($validate) miq_assert_identity_profile($identity, $GLOBALS['cfg']['identity_profile']);
    return $identity;
}

function miq_import_boot_identities($validate = true)
{
    foreach (glob($GLOBALS['cfg']['run'] . '/boot-*.json') as $file) {
        $boot = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!$boot['ancestry']['reached_contract_pid']) throw new RuntimeException('Original boot ancestry did not reach owned contract PID.');
        $chain = $boot['ancestry']['identities']; if (!$chain) continue;
        $owner = null; $root = end($chain);
        foreach ($GLOBALS['processes'] as $label => $process) {
            if ($process['pid'] !== $root['pid'] || $root['ppid'] !== getmypid()) continue;
            if (!$process['identity']) $GLOBALS['processes'][$label]['identity'] = $root;
            if ($GLOBALS['processes'][$label]['identity']['start_ticks'] === $root['start_ticks']) $owner = $label;
        }
        if ($owner === null) throw new RuntimeException('Unmatched original boot process ancestry: ' . $file);
        foreach ($chain as $i => $identity) {
            if ($validate) miq_assert_identity_profile($identity, $GLOBALS['cfg']['identity_profile']);
            if ($i + 1 < count($chain) && $identity['ppid'] !== $chain[$i + 1]['pid']) throw new RuntimeException('Original boot ancestry link mismatch.');
            $key = $identity['pid'] . ':' . $identity['start_ticks'];
            if (!isset($GLOBALS['identities'][$key])) {
                $GLOBALS['identities'][$key] = ['owner' => $owner, 'identity' => $identity, 'closed' => false, 'last' => $identity,
                    'recorded_by' => $file];
                miq_append($GLOBALS['cfg']['run'] . '/identities.jsonl', ['owner' => $owner, 'identity' => $identity,
                    'recorded_by' => $file, 'time' => microtime(true)]);
            }
        }
    }
}

function miq_socket_closed()
{
    $path = $GLOBALS['cfg']['run'] . '/control.sock'; if (!file_exists($path)) return true;
    $previous = null;
    $previous = set_error_handler(function ($level, $message, $file, $line) use (&$previous, $path) {
        if ($level === E_WARNING && $file === __FILE__ && strpos($message, 'stream_socket_client(): Unable to connect to unix://' . $path) === 0
            && (strpos($message, 'Connection refused') !== false || strpos($message, 'No such file or directory') !== false)) return true;
        return $previous ? $previous($level, $message, $file, $line) : false;
    });
    try { $socket = stream_socket_client('unix://' . $path, $errno, $error, 0.2); } finally { restore_error_handler(); }
    miq_append($GLOBALS['cfg']['run'] . '/socket-closure.jsonl', ['socket' => $path, 'connected' => is_resource($socket),
        'errno' => $errno, 'error' => $error, 'time' => microtime(true)]);
    if (is_resource($socket)) { fclose($socket); return false; } return true;
}

function miq_monitor($validate = true)
{
    miq_import_boot_identities($validate);
    // Walk only already owned ancestry; no unrelated /proc or host process enumeration.
    foreach (array_keys($GLOBALS['identities']) as $key) {
        $entry =& $GLOBALS['identities'][$key]; $old = $entry['identity']; $now = miq_identity($old['pid']);
        if (!$now || $now['start_ticks'] !== $old['start_ticks'] || $now['state'] === 'Z') {
            $entry['closed'] = true; $entry['last'] = $now; continue;
        }
        $entry['last'] = $now;
        if ($validate) miq_assert_identity_profile($now, $GLOBALS['cfg']['identity_profile']);
        $children = miq_proc_read('/proc/' . $old['pid'] . '/task/' . $old['pid'] . '/children');
        foreach (preg_split('/\s+/', trim((string) $children)) as $pid) {
            if (ctype_digit($pid)) miq_track((int) $pid, $entry['owner'], $old['pid'], $validate);
        }
        unset($entry);
    }
}

function miq_spawn(array $args, $label, array $env)
{
    miq_tick(); miq_check(!isset($GLOBALS['processes'][$label]), 'unique_process_role_' . $label);
    $run = $GLOBALS['cfg']['run']; $env['IMAGE_QUEUE_ROLE'] = $label;
    if ($label === 'supervisord') $env['IMAGE_QUEUE_ROLE'] = 'supervisor-worker';
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $run . '/' . $label . '.stdout', 'w'],
        2 => ['file', $run . '/' . $label . '.stderr', 'w']];
    $handle = proc_open($args, $descriptors, $pipes, '/app', $env, ['bypass_shell' => true]);
    if (!is_resource($handle)) throw new RuntimeException('Owned original command spawn failed: ' . $label);
    $status = proc_get_status($handle);
    $GLOBALS['processes'][$label] = ['handle' => $handle, 'pid' => (int) $status['pid'], 'args' => $args,
        'role' => $env['IMAGE_QUEUE_ROLE'], 'started_at' => microtime(true), 'exit' => null, 'closed' => false,
        'signals' => [], 'identity' => null, 'stdout' => $run . '/' . $label . '.stdout', 'stderr' => $run . '/' . $label . '.stderr'];
    if (!$status['running']) $GLOBALS['processes'][$label]['exit'] = $status['exitcode'];
    else $GLOBALS['processes'][$label]['identity'] = miq_track($status['pid'], $label);
}

function miq_running($label)
{
    $p =& $GLOBALS['processes'][$label];
    if ($p['closed']) return false;
    $status = proc_get_status($p['handle']);
    if (!$status['running'] && $p['exit'] === null) $p['exit'] = $status['exitcode'];
    return $status['running'];
}

function miq_wait($label, $seconds, $interruptible = true)
{
    $deadline = microtime(true) + $seconds;
    if (!$interruptible && isset($GLOBALS['cleanupDeadline'])) $deadline = min($deadline, $GLOBALS['cleanupDeadline']);
    while (miq_running($label)) {
        if ($interruptible) miq_tick();
        miq_monitor($interruptible);
        if (microtime(true) >= $deadline) throw new RuntimeException('Owned original command timeout: ' . $label);
        usleep(20000);
    }
    $p =& $GLOBALS['processes'][$label]; $close = proc_close($p['handle']); unset($p['handle']); $p['closed'] = true;
    if ($p['exit'] === null || $p['exit'] < 0) $p['exit'] = $close;
    $p['finished_at'] = microtime(true); miq_monitor($interruptible); return $p['exit'];
}

function miq_command(array $args, $label, array $env, $seconds = 25)
{
    miq_spawn($args, $label, $env); $exit = miq_wait($label, $seconds);
    miq_check($exit === 0, 'original_command_exit0_' . $label); return file_get_contents($GLOBALS['processes'][$label]['stdout']);
}

function miq_artisan(array $arguments, $label, $seconds = 25)
{
    return miq_command(array_merge(['/usr/local/bin/php', '-d', 'auto_prepend_file=/probe/queue-prepend.php',
        '/app/artisan'], $arguments, ['--no-interaction']), $label, $GLOBALS['env'], $seconds);
}

function miq_until($predicate, $label, $seconds = 15)
{
    $deadline = microtime(true) + $seconds;
    do {
        miq_tick(); miq_monitor(); $value = $predicate(); if ($value) return $value;
        if (microtime(true) >= $deadline) throw new RuntimeException('Owned queue observation timeout: ' . $label);
        usleep(50000);
    } while (true);
}

function miq_signal_handle($label, $signal)
{
    $p =& $GLOBALS['processes'][$label]; if (!miq_running($label)) return false;
    $now = miq_identity($p['pid']);
    if (!$now || !$p['identity'] || $now['start_ticks'] !== $p['identity']['start_ticks']) throw new RuntimeException('Owned handle identity changed: ' . $label);
    if (!proc_terminate($p['handle'], $signal)) throw new RuntimeException('Owned process signal failed: ' . $label);
    $record = ['role' => $label, 'pid' => $now['pid'], 'start_ticks' => $now['start_ticks'], 'signal' => $signal,
        'mechanism' => 'proc_terminate owned open handle', 'time' => microtime(true)];
    $p['signals'][] = $record; $GLOBALS['signalsSent'][] = $record; return true;
}

function miq_cleanup_identities()
{
    // A profile failure must still allow exact-PID/start_ticks emergency cleanup.
    miq_monitor(false);
    foreach (array_reverse(array_keys($GLOBALS['identities'])) as $key) {
        $entry = $GLOBALS['identities'][$key]; $old = $entry['identity'];
        if (miq_identity_gone($old)) continue;
        $GLOBALS['cleanup'][] = 'Emergency owned identity cleanup: ' . $key;
        foreach ([SIGTERM, SIGKILL] as $signal) {
            $now = miq_identity($old['pid']);
            if (!$now || $now['start_ticks'] !== $old['start_ticks'] || $now['state'] === 'Z') break;
            if (!posix_kill($old['pid'], $signal)) throw new RuntimeException('Owned identity signal failed: ' . $key);
            $GLOBALS['signalsSent'][] = ['role' => $entry['owner'], 'pid' => $old['pid'], 'start_ticks' => $old['start_ticks'],
                'signal' => $signal, 'mechanism' => 'owned descendant PID with exact start_ticks', 'time' => microtime(true)];
            $deadline = min(microtime(true) + 1, $GLOBALS['cleanupDeadline']);
            while (!miq_identity_gone($old) && microtime(true) < $deadline) usleep(20000);
        }
    }
    miq_monitor(false);
}

function miq_snapshot($label)
{
    $db = $GLOBALS['db']; $db->beginTransaction(); $result = ['time' => microtime(true), 'tables' => [], 'objects' => []];
    try {
        $result['objects'] = $db->query("SELECT type,name,tbl_name,sql FROM sqlite_master ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($db->query("SELECT name,sql FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $table) {
            $quoted = '"' . str_replace('"', '""', $table['name']) . '"'; $rows = $db->query('SELECT * FROM ' . $quoted)->fetchAll(PDO::FETCH_ASSOC);
            usort($rows, function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
            $result['tables'][$table['name']] = ['schema' => $table['sql'], 'data' => $rows,
                'schema_sha256' => hash('sha256', $table['sql']), 'rows_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
        }
        $db->rollBack();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    miq_json($GLOBALS['cfg']['run'] . '/sql-' . $label . '.json', $result); $GLOBALS['snapshots'][$label] = $result; return $result;
}

function miq_row(array $snapshot, $table, $column, $value)
{
    $rows = array_values(array_filter($snapshot['tables'][$table]['data'], function ($row) use ($column, $value) { return (string) $row[$column] === (string) $value; }));
    miq_check(count($rows) === 1, 'exact_' . $table . '_' . $column . '_' . $value); return $rows[0];
}

function miq_exact_transition(array $before, array $after, array $changes, array $volatile, $label)
{
    miq_check(array_keys($before['tables']) === array_keys($after['tables']), 'same_table_set_' . $label);
    foreach ($before['tables'] as $table => $old) {
        miq_check($old['schema'] === $after['tables'][$table]['schema'], 'same_schema_' . $label . '_' . $table);
        if (in_array($table, $volatile, true)) continue;
        $expected = $old['data'];
        foreach ($expected as &$row) {
            foreach ($changes[$table] ?? [] as $id => $fields) {
                if ((int) ($row['id'] ?? 0) === (int) $id) foreach ($fields as $field => $value) $row[$field] = $value;
            }
        }
        unset($row); usort($expected, function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
        miq_check($expected === $after['tables'][$table]['data'], 'exact_sql_change_' . $label . '_' . $table);
    }
}

function miq_expiry_changes(array $before, array $after, $case)
{
    $ids = $GLOBALS['cfg']['seed_ids'][$case]; $result = [];
    foreach (['orders' => $ids['order'], 'coupons' => $ids['coupon']] as $table => $id) {
        $row = miq_row($after, $table, 'id', $id);
        $stamp = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $row['updated_at'], new DateTimeZone($GLOBALS['cfg']['timezone']));
        miq_check($stamp && $stamp->format('Y-m-d H:i:s') === $row['updated_at']
            && $stamp->getTimestamp() >= (int) floor($before['time']) - 1 && $stamp->getTimestamp() <= (int) ceil($after['time']),
            'actual_expiry_updated_at_within_real_stage_' . $case . '_' . $table);
        $fields = $table === 'orders' ? ['status' => -1, 'coupon_ret_back' => 1] : ['ret' => 1];
        $fields['updated_at'] = $row['updated_at']; $result[$table][$id] = $fields;
    }
    return $result;
}

function miq_lines($file)
{
    if (!is_file($file)) return [];
    $handle = fopen($file, 'rb'); if (!$handle) throw new RuntimeException('Observation read failed: ' . $file);
    try {
        if (!flock($handle, LOCK_SH)) throw new RuntimeException('Observation read lock failed: ' . $file);
        $bytes = stream_get_contents($handle); flock($handle, LOCK_UN);
    } finally { fclose($handle); }
    return array_map(function ($line) { return json_decode($line, true, 512, JSON_THROW_ON_ERROR); },
        array_values(array_filter(explode("\n", $bytes), 'strlen')));
}

function miq_events()
{
    $result = [];
    foreach (glob($GLOBALS['cfg']['run'] . '/events-*.jsonl') as $file) $result = array_merge($result, miq_lines($file));
    usort($result, function ($a, $b) { return $a['time'] <=> $b['time']; }); return $result;
}

function miq_for_uuid($uuid, $type)
{
    return array_values(array_filter(miq_events(), function ($e) use ($uuid, $type) {
        return $e['payload_uuid'] === $uuid && $e['event'] === 'Illuminate\Queue\Events\\' . $type;
    }));
}

function miq_push($case, $queue, $deadline, $delay = null, $label = null)
{
    $sn = $GLOBALS['cfg']['orders'][$case]; $job = new App\Jobs\OrderExpired($sn, $deadline);
    $connection = $GLOBALS['app']['queue']->connection('database');
    $id = $delay === null ? $connection->push($job, '', $queue) : $connection->later($delay, $job, '', $queue);
    $s = $GLOBALS['db']->prepare('SELECT * FROM jobs WHERE id=?'); $s->execute([$id]); $row = $s->fetch(PDO::FETCH_ASSOC);
    $payload = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR); $key = $label ?: $case;
    miq_check($row['queue'] === $queue && (int) $row['attempts'] === 0 && $row['reserved_at'] === null
        && $payload['displayName'] === 'App\Jobs\OrderExpired' && $payload['maxTries'] === 3 && $payload['timeout'] === 20
        && strpos($payload['data']['command'], $sn) !== false && strpos($payload['data']['command'], 'i:' . $deadline . ';') !== false,
        'native_original_job_payload_' . $key);
    miq_check(preg_match('/^[0-9a-f-]{36}$/D', $payload['uuid']) === 1, 'native_payload_uuid_' . $key);
    $GLOBALS['jobDefinitions'][$key] = ['case' => $case, 'sn' => $sn, 'deadline' => $deadline, 'row' => $row,
        'uuid' => $payload['uuid'], 'payload' => $payload]; return $GLOBALS['jobDefinitions'][$key];
}

function miq_sql_control($label, $sql, array $parameters = [])
{
    miq_snapshot($label . '-before'); $s = $GLOBALS['db']->prepare($sql); $s->execute($parameters);
    miq_append($GLOBALS['cfg']['run'] . '/sql-controls.jsonl', ['label' => $label, 'sql' => $sql,
        'parameters' => $parameters, 'row_count' => $s->rowCount(), 'time' => microtime(true)]);
    return miq_snapshot($label . '-after');
}

function miq_hash_tree($root, array &$result)
{
    if (is_link($root)) {
        $result[$root] = ['sha256' => is_file($root) ? hash_file('sha256', $root) : null, 'link' => readlink($root), 'realpath' => realpath($root)]; return;
    }
    if (is_dir($root)) { foreach (scandir($root) as $name) if ($name !== '.' && $name !== '..') miq_hash_tree($root . '/' . $name, $result); return; }
    miq_check(is_file($root) && is_readable($root), 'readable_protected_input_' . $root);
    $result[$root] = ['sha256' => hash_file('sha256', $root), 'link' => null, 'realpath' => realpath($root)];
}

function miq_app_files($path, array &$result, $verify = false)
{
    foreach (scandir($path) as $name) {
        if ($name === '.' || $name === '..') continue; $file = $path . '/' . $name;
        if ($file === '/app/.env' || $file === '/app/storage/image-fixture.sqlite' || $file === '/app/storage/image-fixture.sqlite-journal') continue;
        miq_check(!is_link($file), 'original_app_no_links_' . $file);
        if (is_dir($file)) { miq_app_files($file, $result, $verify); continue; }
        if ($verify && !isset($GLOBALS['appOriginal'][$file]) && (strpos($file, '/app/bootstrap/cache/') === 0
            || strpos($file, '/app/storage/') === 0)) continue;
        $result[$file] = ['sha256' => hash_file('sha256', $file), 'link' => null, 'realpath' => realpath($file)];
    }
}

function miq_rpc_info()
{
    $cfg = $GLOBALS['cfg']; miq_check(is_file($cfg['run'] . '/supervisord.pid'), 'owned_supervisor_pidfile');
    miq_check((int) trim(file_get_contents($cfg['run'] . '/supervisord.pid')) === $GLOBALS['processes']['supervisord']['pid'], 'socket_owns_actual_supervisor');
    $socket = stream_socket_client('unix://' . $cfg['run'] . '/control.sock', $errno, $error, 2);
    if (!$socket) throw new RuntimeException('Owned XMLRPC socket failed: ' . $error);
    stream_set_timeout($socket, 2);
    $body = '<?xml version="1.0"?><methodCall><methodName>supervisor.getProcessInfo</methodName><params><param><value><string>dujiaoka-queue</string></value></param></params></methodCall>';
    $request = "POST /RPC2 HTTP/1.0\r\nHost: localhost\r\nContent-Type: text/xml\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body;
    if (fwrite($socket, $request) !== strlen($request)) throw new RuntimeException('Owned XMLRPC request incomplete.');
    $raw = stream_get_contents($socket); $meta = stream_get_meta_data($socket); fclose($socket);
    miq_check(!$meta['timed_out'] && preg_match('#^HTTP/1\.[01] 200 #', $raw) && strpos($raw, '<fault>') === false, 'original_xmlrpc_getProcessInfo');
    $fields = [];
    preg_match_all('#<member>\s*<name>([^<]+)</name>\s*<value>\s*<(int|i4|string|boolean)>(.*?)</\2>\s*</value>\s*</member>#s', $raw, $members, PREG_SET_ORDER);
    foreach ($members as $member) $fields[$member[1]] = $member[2] === 'string' ? html_entity_decode($member[3], ENT_QUOTES | ENT_XML1) : (int) $member[3];
    miq_check(isset($fields['pid'], $fields['statename']) && ($fields['group'] ?? '') === 'dujiaoka-queue', 'native_xmlrpc_process_fields');
    $serial = ++$GLOBALS['rpcSerial']; file_put_contents($cfg['run'] . '/rpc-' . $serial . '.http', $raw);
    miq_append($cfg['run'] . '/rpc.jsonl', ['sequence' => $serial, 'socket' => $cfg['run'] . '/control.sock',
        'method' => 'supervisor.getProcessInfo', 'fields' => $fields, 'time' => microtime(true)]); return $fields;
}

function miq_ctl($action, $label)
{
    return miq_command(['/usr/bin/supervisorctl', '-c', $GLOBALS['cfg']['run'] . '/supervisord.conf', $action,
        'dujiaoka-queue'], $label, $GLOBALS['env'], 20);
}

function miq_supervisor_worker($different = null)
{
    return miq_until(function () use ($different) {
        if (!miq_running('supervisord')) throw new RuntimeException('Original supervisord exited early.');
        if (!file_exists($GLOBALS['cfg']['run'] . '/control.sock') || !is_file($GLOBALS['cfg']['run'] . '/supervisord.pid')) return false;
        $info = miq_rpc_info(); if ($info['statename'] !== 'RUNNING' || $info['pid'] <= 0 || $info['pid'] === $different) return false;
        $id = miq_track($info['pid'], 'supervisord', $GLOBALS['processes']['supervisord']['pid']);
        if (!$id || $id['exe'] !== '/usr/local/bin/php' || strpos($id['cmdline'], '/app/artisan queue:work --sleep=3 --tries=2 --timeout=60') === false) return false;
        $profile = miq_allowed_identity_profile($GLOBALS['cfg']['identity_profile']);
        miq_assert_identity_profile($id, $profile);
        miq_check($id['pgrp'] === $id['pid'] && $id['uids'] === array_fill(0, 4, $profile['uid'])
            && $id['gids'] === array_fill(0, 4, $profile['gid']), 'native_supervisor_worker_group_uid' . $profile['uid'] . '_' . $id['pid']);
        $GLOBALS['workerIdentities'][$id['pid'] . ':' . $id['start_ticks']] = $id; return $id;
    }, 'actual original RUNNING Supervisor worker', 18);
}

function miq_identity_gone(array $old)
{
    $now = miq_identity($old['pid']); return !$now || $now['start_ticks'] !== $old['start_ticks'] || $now['state'] === 'Z';
}

function miq_validate_runtime(array $x, $cached, $label)
{
    miq_check($x['app_type'] === 'Illuminate\Foundation\Application' && $x['framework'] === '13.35.0'
        && $x['base'] === '/app' && $x['storage'] === '/app/storage' && $x['environment'] === 'production' && $x['debug'] === false
        && $x['kernel_type'] === 'App\Console\Kernel' && $x['app_source'] === '/app/app/Console/Kernel.php'
        && $x['vendor_source'] === '/app/vendor/laravel/framework/src/Illuminate/Foundation/Application.php'
        && $x['db_default'] === 'sqlite' && $x['db'] === $GLOBALS['cfg']['database'] && $x['pdo_driver'] === 'sqlite'
        && $x['cache'] === 'file' && $x['cache_store'] === 'Illuminate\Cache\FileStore'
        && $x['queue'] === 'database' && $x['queue_connection'] === 'Illuminate\Queue\DatabaseQueue'
        && $x['failed_driver'] === 'database-uuids' && $x['failer'] === 'Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider'
        && $x['mail'] === 'array' && (!$cached || $x['config_cached']), 'actual_original_runtime_' . $label);
    foreach ($x['provider_flags'] as $flag => $value) miq_check($value === false, 'provider_disabled_' . $label . '_' . $flag);
}

try {
    // Fixed external seed data is validated before touching /app or creating private state.
    $identitySeed = miq_identity_profile_source(); $profile = $identitySeed['profile'];
    $cfg['identity_profile'] = $profile; $cfg['identity_profile_source_sha256'] = $identitySeed['sha256'];
    $contractIdentity = miq_self_identity($cfg); $uidMap = $contractIdentity['uid_map']; $gidMap = $contractIdentity['gid_map'];
    miq_check($contractIdentity['identity_profile'] === $profile
        && $contractIdentity['identity_profile_source_sha256'] === $identitySeed['sha256'],
        'exact_allowed_identity_profile_and_actual_process_' . $profile['name']);
    miq_check(realpath('/probe') === '/probe' && realpath('/app') === '/app' && !is_dir('/app/tests'), 'fixed_original_app_without_tests');
    miq_check(is_file('/probe/queue.namespace.started') && !is_link('/probe/queue.namespace.started'), 'outer_queue_namespace_marker');
    foreach (['/probe/queue.private', '/probe/queue-contract.json'] as $file) miq_check(!file_exists($file) && !is_link($file), 'fresh_queue_path_' . $file);
    miq_check(mkdir($cfg['run'], 0700), 'fresh_private_queue_root'); $ownsRun = true;
    $cfg['nonce'] = bin2hex(random_bytes(16)); $cfg['outer_marker'] = trim(file_get_contents('/probe/queue.namespace.started'));
    miq_check($cfg['outer_marker'] !== '' && $cfg['outer_marker'] !== $cfg['nonce'], 'distinct_outer_marker_inner_nonce');
    file_put_contents($cfg['run'] . '/selfnonce', $cfg['nonce']); file_put_contents($cfg['run'] . '/queue.started', $cfg['nonce']);
    miq_check((fileperms($cfg['run']) & 0777) === 0700 && fileowner($cfg['run']) === $profile['uid']
        && filegroup($cfg['run']) === $profile['gid'], 'owned_private_root_mode0700');
    miq_check(PHP_OS_FAMILY === 'Linux' && PHP_VERSION === '8.5.11' && PHP_BINARY === '/usr/local/bin/php'
        && PHP_SAPI === 'cli' && posix_geteuid() === $profile['uid'] && posix_getegid() === $profile['gid'],
        $profile['name'] === 'root' ? 'original_php8511_namespace_root0' : 'original_php8511_application1000');
    miq_check($uidMap === $profile['uid_map'] && $gidMap === $profile['gid_map'],
        $profile['name'] === 'root' ? 'single_root0_outer1000_uid_gid_map' : 'single_application1000_outer1000_uid_gid_map');
    foreach (['pdo_sqlite', 'pcntl', 'posix'] as $extension) miq_check(extension_loaded($extension), 'original_extension_' . $extension);
    pcntl_async_signals(true); foreach ([SIGINT, SIGTERM, SIGALRM] as $signal) pcntl_signal($signal, function ($signal) { $GLOBALS['interrupted'] = $signal; });
    pcntl_alarm(240); $alarm = true;
    foreach (['/usr/bin/supervisord', '/usr/bin/supervisorctl', '/usr/local/bin/php', '/app/artisan', '/app/deploy/supervisor/queue.conf',
        '/app/deploy/cron/dujiaoka', '/probe/queue-contract.php', '/probe/queue-prepend.php', '/probe/http-prepend.php',
        '/probe/seed/tests/Fixtures/AdminData.php', '/probe/seed/database/sql/install.sql', '/app/database/sql/install.sql',
        '/probe/queue-identity.json', '/etc/passwd', '/etc/group'] as $file) miq_hash_tree($file, $filesSHA);
    miq_check($filesSHA['/probe/queue-identity.json']['sha256'] === $cfg['identity_profile_source_sha256'], 'exact_identity_profile_source_sha256');
    miq_check($filesSHA['/probe/seed/database/sql/install.sql']['sha256'] === $filesSHA['/app/database/sql/install.sql']['sha256'], 'original_schema_copy_same_bytes');
    miq_check(is_file('/app/.env') && !is_link('/app/.env') && filesize('/app/.env') === 0, 'staged_empty_environment_without_reading_credentials');
    miq_check(is_file($cfg['database']) && !is_link($cfg['database']), 'native_staged_sqlite_file');
    foreach (['storage/logs', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'bootstrap/cache'] as $dir) {
        miq_check(realpath('/app/' . $dir) === '/app/' . $dir, 'original_runtime_directory_' . $dir);
        miq_check(array_values(array_diff(scandir('/app/' . $dir), ['.', '..', '.gitignore'])) === [], 'fresh_runtime_directory_' . $dir);
    }
    $appOriginal = []; miq_app_files('/app', $appOriginal); $filesSHA += $appOriginal;
    $appCount = count(array_filter(array_keys($appOriginal), function ($f) { return strpos($f, '/app/vendor/') !== 0; }));
    miq_check($appCount === 1273, 'original_current1273_source_inputs');
    miq_hash_tree('/usr/local/etc/php', $filesSHA);
    miq_hash_tree('/usr/bin/python3', $filesSHA);
    $supervisorRoots = glob('/usr/lib/python*/site-packages/supervisor');
    $supervisorRoots = array_merge($supervisorRoots, glob('/usr/local/lib/python*/site-packages/supervisor'),
        glob('/usr/lib/python*/dist-packages/supervisor'), glob('/usr/local/lib/python*/dist-packages/supervisor'));
    miq_check(count($supervisorRoots) === 1, 'original_single_supervisor_python_graph');
    foreach ($supervisorRoots as $directory) miq_hash_tree($directory, $filesSHA);
    $installed = json_decode(file_get_contents('/app/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $lock = json_decode(file_get_contents('/app/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    miq_check($installed['dev'] === false && count($installed['packages']) === 100 && count($lock['packages']) === 100, 'original_fixed100_no_dev');
    $packages = []; foreach ($installed['packages'] as $p) $packages[$p['name']] = $p;
    foreach ($lock['packages'] as $p) {
        miq_check(isset($packages[$p['name']]) && $packages[$p['name']]['version'] === $p['version']
            && ($packages[$p['name']]['source']['reference'] ?? null) === ($p['source']['reference'] ?? null), 'fixed_package_reference_' . $p['name']);
        $packageGraph[$p['name']] = ['version' => $p['version'], 'reference' => $p['source']['reference'] ?? null];
    }
    foreach ($lock['packages-dev'] as $p) miq_check(!isset($packages[$p['name']]), 'dev_package_absent_' . $p['name']);
    $cfg['original_ini'] = ['loaded' => php_ini_loaded_file(), 'scanned' => php_ini_scanned_files()];
    $scanDirs = []; foreach (preg_split('/,\s*/', trim((string) php_ini_scanned_files())) as $file) if ($file !== '') $scanDirs[dirname(trim($file))] = true;
    miq_check((bool) $scanDirs, 'original_scanned_ini_directories');
    mkdir($cfg['run'] . '/php.d', 0700); $cfg['ini_scan'] = implode(':', array_keys($scanDirs)) . ':' . $cfg['run'] . '/php.d';
    file_put_contents($cfg['run'] . '/php.d/queue-observer.ini', "auto_prepend_file=/probe/queue-prepend.php\nerror_reporting=32767\nlog_errors=On\nerror_log=/probe/queue.private/php-engine.log\n");
    $bcrypt = password_get_info(password_hash('fixture-cost-probe', PASSWORD_BCRYPT)); $cfg['bcrypt_rounds'] = $bcrypt['options']['cost'];
    $cfg['orders'] = []; foreach (['expired', 'future', 'paid', 'fault'] as $case) $cfg['orders'][$case] = strtoupper(substr(hash('sha256', $cfg['nonce'] . ':' . $case), 0, 16));
    miq_check(count(array_unique($cfg['orders'])) === 4, 'four_unique_owned_order_sns');
    miq_json($cfg['run'] . '/runtime.json', $cfg); $env = miq_environment($cfg);
    miq_observe($cfg, 'contract-seed'); $loader = require '/app/vendor/autoload.php'; miq_rearm_watch(); $app = require '/app/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); miq_validate_runtime(miq_runtime_identity($app), false, 'seed');
    $db = new PDO('sqlite:' . $cfg['database']); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->exec('PRAGMA busy_timeout=5000');
    miq_check($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN) === [], 'original_fresh_empty_sqlite');
    require '/probe/seed/tests/Fixtures/AdminData.php'; Tests\Fixtures\AdminData::create(false);
    Illuminate\Support\Facades\Schema::create('jobs', function ($t) {
        $t->bigIncrements('id'); $t->string('queue')->index(); $t->longText('payload'); $t->unsignedTinyInteger('attempts');
        $t->unsignedInteger('reserved_at')->nullable(); $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
    });
    Illuminate\Support\Facades\Schema::create('failed_jobs', function ($t) {
        $t->bigIncrements('id'); $t->string('uuid')->unique(); $t->text('connection'); $t->text('queue');
        $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at')->useCurrent();
    });
    Illuminate\Support\Facades\Cache::forever('system-setting', ['title' => 'Original Queue Fixture', 'template' => 'modern', 'language' => 'zh_CN',
        'order_expire_time' => 5, 'driver' => 'array', 'is_open_img_code' => 0, 'is_open_geetest' => 0, 'is_open_server_jiang' => 0,
        'is_open_telegram_push' => 0, 'is_open_bark_push' => 0, 'is_open_qywxbot_push' => 0]);
    $schemaBytes = file_get_contents('/probe/seed/database/sql/install.sql'); $quote = chr(96);
    preg_match_all('/CREATE TABLE ' . $quote . '([^' . $quote . ']+)' . $quote . ' \((.*?)\n\) ENGINE=/s', $schemaBytes, $schemaTables, PREG_SET_ORDER);
    $expectedTables = ['fixture_marker','jobs']; foreach ($schemaTables as $t) $expectedTables[] = $t[1]; sort($expectedTables);
    $actualTables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    miq_check($actualTables === $expectedTables, 'exact_original_helper_business_schema_plus_native_queue_tables');
    $initial = miq_snapshot('helper-initial');
    miq_check(count($initial['tables']['orders']['data']) === 2 && count($initial['tables']['coupons']['data']) === 1
        && !$initial['tables']['jobs']['data'] && !$initial['tables']['failed_jobs']['data'], 'original_helper_createfalse_baseline');
    $cfg['fault_message'] = 'Owned queue coupon rollback ' . $cfg['nonce']; $cfg['seed_ids'] = []; $cfg['timezone'] = config('app.timezone'); $timezone = new DateTimeZone($cfg['timezone']);
    $past = (new DateTimeImmutable('@' . (time() - 900)))->setTimezone($timezone)->format('Y-m-d H:i:s');
    $now = (new DateTimeImmutable('@' . time()))->setTimezone($timezone)->format('Y-m-d H:i:s');
    $baseOrder = miq_row($initial, 'orders', 'id', 1); $baseCoupon = miq_row($initial, 'coupons', 'id', 1);
    foreach ($cfg['orders'] as $case => $sn) {
        $coupon = $baseCoupon; unset($coupon['id']); $coupon['coupon'] = 'QUEUE-' . $sn; $coupon['ret'] = 0;
        $coupon['created_at'] = $now; $coupon['updated_at'] = $now;
        $couponId = Illuminate\Support\Facades\DB::table('coupons')->insertGetId($coupon);
        Illuminate\Support\Facades\DB::table('coupons_goods')->insert(['goods_id' => 1, 'coupons_id' => $couponId]);
        $order = $baseOrder; unset($order['id']); $order['order_sn'] = $sn; $order['coupon_id'] = $couponId; $order['coupon_ret_back'] = 0;
        $order['status'] = $case === 'paid' ? 4 : 1; $order['created_at'] = $case === 'future' ? $now : $past;
        $order['updated_at'] = $now; $order['coupon_discount_price'] = '1.00'; $order['actual_price'] = '8.00';
        $order['email'] = $case . '@queue.example.test'; $order['info'] = $case === 'paid' ? 'OWNED-ALREADY-PAID' : '';
        $orderId = Illuminate\Support\Facades\DB::table('orders')->insertGetId($order);
        $cfg['seed_ids'][$case] = ['order' => $orderId, 'coupon' => $couponId];
    }
    // Make the helper's unpaid sample ineligible; all tested deadlines belong to the four new rows.
    miq_sql_control('helper-order-future', 'UPDATE orders SET created_at=? WHERE id=1', [$now]);
    $cfg['past_deadline'] = time() - 60; $cfg['future_deadline'] = time() + 3600;
    miq_json($cfg['run'] . '/runtime.json', $cfg); miq_json($cfg['run'] . '/seed.json', ['helper' => '/probe/seed/tests/Fixtures/AdminData.php',
        'schema' => '/probe/seed/database/sql/install.sql', 'create_argument' => false, 'cases' => $cfg['seed_ids'],
        'orders' => $cfg['orders'], 'past' => $past, 'now' => $now, 'queue_tables' => ['jobs', 'failed_jobs'], 'initial' => $initial]);
    $seeded = miq_snapshot('four-orders-seeded');
    $app['db']->disconnect(); miq_artisan(['config:cache'], 'config-cache', 40);
    miq_check(is_file('/app/bootstrap/cache/config.php'), 'original_configcache_produced_real_file');
    $normal = miq_push('expired', 'owned-normal', $cfg['past_deadline']); miq_snapshot('normal-enqueued');
    miq_artisan(['queue:work', 'database', '--queue=owned-normal', '--once', '--sleep=0', '--tries=2', '--timeout=60'], 'worker-normal');
    $normalAfter = miq_snapshot('normal-consumed'); $id = $cfg['seed_ids']['expired'];
    miq_exact_transition($seeded, $normalAfter, miq_expiry_changes($seeded, $normalAfter, 'expired'), ['jobs','sqlite_sequence'], 'normal-once');
    miq_check(!$normalAfter['tables']['jobs']['data'] && !$normalAfter['tables']['failed_jobs']['data'], 'normal_native_job_deleted_no_failure');
    $duplicate = miq_push('expired', 'owned-normal', $cfg['past_deadline'], null, 'duplicate'); miq_snapshot('duplicate-enqueued');
    miq_artisan(['queue:work', 'database', '--queue=owned-normal', '--stop-when-empty', '--sleep=0', '--tries=2', '--timeout=60'], 'worker-duplicate');
    $duplicateAfter = miq_snapshot('duplicate-consumed'); miq_exact_transition($normalAfter, $duplicateAfter, [], ['sqlite_sequence'], 'duplicate-noeffects');
    $paid = miq_push('paid', 'owned-paid', $cfg['past_deadline']); miq_snapshot('paid-enqueued');
    miq_artisan(['queue:work', 'database', '--queue=owned-paid', '--once', '--sleep=0', '--tries=2', '--timeout=60'], 'worker-paid');
    $paidAfter = miq_snapshot('paid-consumed'); miq_exact_transition($duplicateAfter, $paidAfter, [], ['sqlite_sequence'], 'paid-noeffects');
    $futureDate = new DateTimeImmutable('@' . $cfg['future_deadline']);
    $future = miq_push('future', 'owned-future', $cfg['future_deadline'], $futureDate); $futureBefore = miq_snapshot('future-enqueued');
    miq_check((int) $future['row']['available_at'] === $cfg['future_deadline'], 'future_native_available_at_deadline');
    miq_artisan(['queue:work', 'database', '--queue=owned-future', '--once', '--sleep=0', '--tries=2', '--timeout=60'], 'worker-future');
    $futureAfter = miq_snapshot('future-unreserved'); miq_exact_transition($futureBefore, $futureAfter, [], [], 'future-complete-sql-unchanged');
    miq_check(!miq_for_uuid($future['uuid'], 'JobProcessing'), 'future_never_processed_or_reserved');
    $couponId = $cfg['seed_ids']['fault']['coupon'];
    $trigger = "CREATE TRIGGER owned_queue_coupon_fault AFTER UPDATE OF ret ON coupons WHEN NEW.id=" . (int) $couponId
        . " AND NEW.ret>OLD.ret BEGIN SELECT RAISE(ABORT, '" . $cfg['fault_message'] . "'); END";
    miq_sql_control('install-coupon-fault', $trigger);
    $fault = miq_push('fault', 'default', $cfg['past_deadline']); $faultBefore = miq_snapshot('fault-enqueued');
    // Preserve all program bytes except the private log/PHP prefix and root-only user substitution.
    $originalProgram = file_get_contents('/app/deploy/supervisor/queue.conf');
    miq_check(substr_count($originalProgram, 'user=application') === 1 && substr_count($originalProgram, 'stdout_logfile=/dev/stdout') === 1
        && substr_count($originalProgram, 'command=php /app/artisan') === 1, 'exact_original_deployment_program_anchors');
    $programFrom = ['stdout_logfile=/dev/stdout', 'command=php /app/artisan'];
    $programTo = ['stdout_logfile=' . $cfg['run'] . '/worker.log', 'command=php -d auto_prepend_file=/probe/queue-prepend.php /app/artisan'];
    $programSubstitutions = ['stdout_logfile -> private', 'PHP observer prefix'];
    if ($profile['name'] === 'root') {
        array_unshift($programFrom, 'user=application'); array_unshift($programTo, 'user=root');
        array_unshift($programSubstitutions, 'user=application -> root');
    }
    $program = str_replace($programFrom, $programTo, $originalProgram);
    miq_check(str_replace($programTo, $programFrom, $program) === $originalProgram
        && substr_count($program, 'user=' . $profile['user']) === 1,
        $profile['name'] === 'root' ? 'only_three_program_substitutions' : 'only_two_program_substitutions_application_user_unchanged');
    file_put_contents($cfg['run'] . '/queue.conf', $program);
    $run = $cfg['run']; $supervisorUser = $profile['user'];
    $supervisorConfig = "[unix_http_server]\nfile=$run/control.sock\nchmod=0700\n[supervisord]\nnodaemon=true\nuser=$supervisorUser\nlogfile=$run/supervisord.log\nlogfile_maxbytes=0\npidfile=$run/supervisord.pid\nchildlogdir=$run\ndirectory=/app\n[rpcinterface:supervisor]\nsupervisor.rpcinterface_factory=supervisor.rpcinterface:make_main_rpcinterface\n[supervisorctl]\nserverurl=unix://$run/control.sock\n[include]\nfiles=$run/queue.conf\n";
    file_put_contents($run . '/supervisord.conf', $supervisorConfig);
    miq_json($run . '/deployment-program.json', ['original' => '/app/deploy/supervisor/queue.conf', 'original_bytes' => $originalProgram,
        'isolated_bytes' => $program, 'substitutions' => $programSubstitutions, 'identity_profile' => $profile,
        'environment' => ['IMAGE_QUEUE_ROLE' => 'supervisor-worker', 'PYTHONDONTWRITEBYTECODE' => '1'],
        'cron_source' => file_get_contents('/app/deploy/cron/dujiaoka'),
        'claim' => 'Supervisor database queue and original scheduler under exact profile ' . $profile['name']]);
    miq_spawn(['/usr/bin/supervisord', '-n', '-c', $run . '/supervisord.conf'], 'supervisord', $env);
    $firstWorker = miq_supervisor_worker(); miq_ctl('status', 'supervisor-status-initial');
    miq_until(function () use ($db, $fault) {
        $s = $db->prepare('SELECT * FROM failed_jobs WHERE uuid=?'); $s->execute([$fault['uuid']]); $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 && count(miq_for_uuid($fault['uuid'], 'JobExceptionOccurred')) === 3;
    }, 'actual three attempts and native UUID failure', 20);
    $failed = miq_snapshot('fault-failed'); miq_exact_transition($faultBefore, $failed, [], ['jobs','failed_jobs','sqlite_sequence'], 'three-fault-rollback');
    miq_check($failed['tables']['jobs']['data'] === $futureAfter['tables']['jobs']['data'] && count($failed['tables']['failed_jobs']['data']) === 1,
        'only_fault_job_deleted_future_payload_unchanged');
    $failedRow = miq_row($failed, 'failed_jobs', 'uuid', $fault['uuid']);
    miq_check($failedRow['connection'] === 'database' && $failedRow['queue'] === 'default' && $failedRow['payload'] === $fault['row']['payload']
        && strpos($failedRow['exception'], $cfg['fault_message']) !== false, 'original_native_uuid_failure_exact_payload');
    $attemptEvents = miq_for_uuid($fault['uuid'], 'JobProcessing'); $exceptionEvents = miq_for_uuid($fault['uuid'], 'JobExceptionOccurred');
    miq_check(array_column($attemptEvents, 'attempts') === [1,2,3] && array_column($exceptionEvents, 'attempts') === [1,2,3]
        && count(miq_for_uuid($fault['uuid'], 'JobFailed')) === 1 && !miq_for_uuid($fault['uuid'], 'JobProcessed'), 'job_tries3_overrides_original_command_tries2');
    foreach ($exceptionEvents as $e) {
        miq_check($e['exception']['class'] === 'Illuminate\Database\QueryException' && strpos($e['exception']['message'], $cfg['fault_message']) !== false,
            'actual_expected_database_exception_attempt_' . $e['attempts']);
        foreach ($faultBefore['tables'] as $table => $data) {
            if (in_array($table, ['jobs','failed_jobs','sqlite_sequence'], true)) continue;
            miq_check($e['sql'][$table]['data'] === $data['data'], 'committed_rollback_after_attempt_' . $e['attempts'] . '_' . $table);
        }
    }
    miq_ctl('stop', 'supervisor-stop-before-retry');
    miq_until(function () use ($firstWorker) { return miq_identity_gone($firstWorker); }, 'first worker stopped', 5);
    miq_check(miq_rpc_info()['statename'] === 'STOPPED', 'actual_supervisor_stop_before_retry');
    miq_sql_control('remove-coupon-fault', 'DROP TRIGGER owned_queue_coupon_fault');
    $retryBefore = miq_snapshot('retry-before'); miq_artisan(['queue:retry', $fault['uuid']], 'queue-retry-original-uuid');
    $retryQueued = miq_snapshot('retry-enqueued');
    miq_exact_transition($retryBefore, $retryQueued, [], ['jobs','failed_jobs','sqlite_sequence'], 'native-retry-only-queue-changes');
    miq_check(!$retryQueued['tables']['failed_jobs']['data'] && count($retryQueued['tables']['jobs']['data']) === 2, 'native_retry_forgets_one_uuid_failure');
    $retryRows = array_values(array_filter($retryQueued['tables']['jobs']['data'], function ($row) { return $row['queue'] === 'default'; }));
    miq_check(count($retryRows) === 1 && (int) $retryRows[0]['attempts'] === 0
        && $retryRows[0]['reserved_at'] === null && (int) $retryRows[0]['available_at'] <= time(), 'native_uuid_retry_resets_database_attempts');
    $retryPayload = json_decode($retryRows[0]['payload'], true, 512, JSON_THROW_ON_ERROR); $expectedRetry = $fault['payload'];
    if (array_key_exists('attempts', $retryPayload)) $expectedRetry['attempts'] = 0;
    miq_check($retryPayload === $expectedRetry, 'native_uuid_retry_preserves_command_uuid_except_native_attempt_reset');
    miq_json($run . '/retry-payload.json', ['original' => $fault['payload'], 'retry' => $retryPayload, 'original_row' => $fault['row'], 'retry_row' => $retryRows[0]]);
    miq_ctl('start', 'supervisor-start-retry'); $secondWorker = miq_supervisor_worker($firstWorker['pid']);
    miq_until(function () use ($fault) { return count(miq_for_uuid($fault['uuid'], 'JobProcessed')) === 1; }, 'Supervisor consumes retried original job', 15);
    $retried = miq_snapshot('retry-consumed'); $id = $cfg['seed_ids']['fault'];
    miq_exact_transition($retryQueued, $retried, miq_expiry_changes($retryQueued, $retried, 'fault'), ['jobs'], 'retry-atomic-single-return');
    miq_check($retried['tables']['jobs']['data'] === $futureAfter['tables']['jobs']['data'] && !$retried['tables']['failed_jobs']['data'], 'retry_future_unchanged_failure_cleared');
    miq_check(array_column(miq_for_uuid($fault['uuid'], 'JobProcessing'), 'attempts') === [1,2,3,1]
        && miq_for_uuid($fault['uuid'], 'JobProcessed')[0]['attempts'] === 1, 'original_retry_attempt1_same_uuid');
    // queue:restart is the original cache signal; autorestart=true must produce a new real PHP daemon.
    miq_artisan(['queue:restart'], 'queue-restart-native'); $thirdWorker = miq_supervisor_worker($secondWorker['pid']);
    miq_until(function () use ($secondWorker) { return miq_identity_gone($secondWorker); }, 'native restart old worker gone', 5);
    miq_ctl('status', 'supervisor-status-autorestarted');
    miq_ctl('stop', 'supervisor-stop-restarted'); miq_until(function () use ($thirdWorker) { return miq_identity_gone($thirdWorker); }, 'stopped restarted worker gone', 5);
    miq_ctl('start', 'supervisor-start-final'); $fourthWorker = miq_supervisor_worker($thirdWorker['pid']);
    miq_ctl('status', 'supervisor-status-final');
    miq_check(count($workerIdentities) === 4, 'four_distinct_supervisor_worker_identities_stop_start_autorestart');
    miq_exact_transition($retried, miq_snapshot('after-supervisor-controls'), [], [], 'supervisor-controls-no-sql-effects');
    // Separate scheduler epoch: explicitly re-arm only the owned normal order/coupon, never its old job payload.
    $id = $cfg['seed_ids']['expired'];
    $db->beginTransaction();
    $s = $db->prepare('UPDATE orders SET status=1,coupon_ret_back=0 WHERE id=? AND order_sn=? AND status=-1 AND coupon_ret_back=1');
    $s->execute([$id['order'], $cfg['orders']['expired']]); miq_check($s->rowCount() === 1, 'owned_scheduler_epoch_rearm_order');
    $s = $db->prepare('UPDATE coupons SET ret=0 WHERE id=? AND ret=1'); $s->execute([$id['coupon']]); miq_check($s->rowCount() === 1, 'owned_scheduler_epoch_rearm_coupon'); $db->commit();
    miq_append($run . '/sql-controls.jsonl', ['label' => 'scheduler-epoch-rearm', 'order' => $id['order'], 'coupon' => $id['coupon'],
        'changes' => ['orders.status' => 1, 'orders.coupon_ret_back' => 0, 'coupons.ret' => 0], 'time' => microtime(true)]);
    $scheduleBefore = miq_snapshot('scheduler-before');
    miq_exact_transition($retried, $scheduleBefore, ['orders' => [$id['order'] => ['status' => 1, 'coupon_ret_back' => 0]],
        'coupons' => [$id['coupon'] => ['ret' => 0]]], [], 'explicit-owned-scheduler-epoch');
    miq_artisan(['schedule:run'], 'scheduler-tick-one', 30); $scheduleAfter = miq_snapshot('scheduler-after-one');
    miq_exact_transition($scheduleBefore, $scheduleAfter, miq_expiry_changes($scheduleBefore, $scheduleAfter, 'expired'), [], 'original-scheduler-single-expiry-return');
    miq_artisan(['schedule:run'], 'scheduler-tick-two', 30); $scheduleTwice = miq_snapshot('scheduler-after-two');
    miq_exact_transition($scheduleAfter, $scheduleTwice, [], [], 'original-scheduler-repeat-idempotent');
    miq_check(miq_row($scheduleTwice, 'orders', 'order_sn', $cfg['orders']['future']) === miq_row($seeded, 'orders', 'order_sn', $cfg['orders']['future'])
        && miq_row($scheduleTwice, 'orders', 'order_sn', $cfg['orders']['paid']) === miq_row($seeded, 'orders', 'order_sn', $cfg['orders']['paid']),
        'actual_scheduler_keeps_future_and_paid_exact_rows');
    miq_check(miq_signal_handle('supervisord', SIGTERM), 'owned_supervisor_master_sigterm');
    miq_check(miq_wait('supervisord', 18) === 0, 'original_supervisor_graceful_master_exit0');
    foreach ($workerIdentities as $id) {
        miq_until(function () use ($id) { return miq_identity_gone($id); }, 'owned supervisor worker identity disappeared', 3);
        miq_check(!posix_kill(-$id['pgrp'], 0), 'owned_supervisor_worker_process_group_gone_' . $id['pid']);
    }
    miq_check(miq_socket_closed(), 'original_supervisor_unix_listener_closed');
    $final = miq_snapshot('final'); miq_exact_transition($scheduleTwice, $final, [], [], 'teardown-no-sql-effects');
    $seedObserver = miq_shutdown_record();
    foreach (glob($run . '/shutdown-*.json') as $file) {
        $record = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); $shutdowns[] = $record;
        miq_check($record['nonce'] === $cfg['nonce'] && $record['php'] === '8.5.11' && $record['binary'] === '/usr/local/bin/php'
            && $record['sapi'] === 'cli' && $record['uid'] === $profile['uid'] && $record['gid'] === $profile['gid']
            && $record['argc'] === count($record['argv']), 'original_shutdown_process_identity_' . basename($file));
        miq_check($record['identity_profile'] === $profile
            && $record['process_identity']['identity_profile'] === $profile
            && $record['process_identity']['identity_profile_source_sha256'] === $cfg['identity_profile_source_sha256']
            && $record['observer']['process_identity']['identity_profile_source_sha256'] === $cfg['identity_profile_source_sha256'],
            'exact_shutdown_profile_source_' . basename($file));
        miq_assert_identity_profile($record['process_identity'], $profile);
        miq_assert_identity_profile($record['observer']['process_identity'], $profile);
        miq_check(!$record['inspection_error'] && !$record['outside'] && $record['loaders'] === ['/app/vendor']
            && $record['loader_types'] === ['Composer\Autoload\ClassLoader'] && !$record['loader_paths_outside'] && !$record['dev_loaded']
            && !$record['dev_mapped'] && $record['installed_dev'] === false, 'sole_original_vendor_shutdown_' . basename($file));
        miq_check($record['observer']['installed'] && $record['observer']['bound_before_vendor'] && !$record['observer']['issues']
            && !$record['observer']['observation_errors'] && $record['last_php_error'] === null, 'strict_no_php_issues_' . basename($file));
        miq_validate_runtime($record['runtime'], !in_array($record['role'], ['contract-seed','config-cache'], true), $record['role'] . '-' . $record['pid']);
        miq_check($record['current_app_observed'], 'actual_current_app_observed_' . basename($file));
        miq_check(count($record['observer']['applications']) === ($record['role'] === 'config-cache' ? 2 : 1), 'exact_actual_bootstrap_app_count_' . basename($file));
        miq_check($record['fixture_loaded'] === ($record['role'] === 'contract-seed'), 'fixture_only_in_seed_' . basename($file));
        $scanned = array_values(array_filter(array_map('trim', preg_split('/,\s*/', (string) $record['ini_scanned'])), 'strlen'));
        $expectedScanned = array_values(array_filter(array_map('trim', preg_split('/,\s*/', (string) $cfg['original_ini']['scanned'])), 'strlen'));
        if ($record['role'] !== 'contract-seed') $expectedScanned[] = $run . '/php.d/queue-observer.ini';
        miq_check($record['ini_loaded'] === $cfg['original_ini']['loaded'] && $scanned === $expectedScanned,
            'original_ini_plus_private_observer_exact_scan_order_' . basename($file));
        foreach ($record['sourceIncludes'] as $file => $sha) {
            miq_check(!isset($sourceIncludes[$file]) || $sourceIncludes[$file] === $sha, 'stable_included_source_' . $file); $sourceIncludes[$file] = $sha;
        }
    }
    miq_check(count($shutdowns) === 16, 'exact16_original_php_shutdown_roles');
    miq_check(count($processes) === 17, 'exact17_owned_original_commands');
    foreach ($processes as $label => $p) {
        if ($p['args'][0] !== '/usr/local/bin/php') continue;
        $found = array_values(array_filter($shutdowns, function ($r) use ($p) { return $r['pid'] === $p['pid'] && $r['role'] === $p['role']; }));
        miq_check(count($found) === 1 && $found[0]['argv'][0] === '/app/artisan', 'one_shutdown_per_owned_artisan_' . $label);
    }
    foreach ($workerIdentities as $id) {
        $found = array_values(array_filter($shutdowns, function ($r) use ($id) { return $r['pid'] === $id['pid'] && $r['role'] === 'supervisor-worker'; }));
        miq_check(count($found) === 1 && $found[0]['argv'][1] === 'queue:work', 'one_shutdown_per_supervisor_restart_pid_' . $id['pid']);
    }
    foreach (['scheduler-tick-one' => 1, 'scheduler-tick-two' => 0] as $role => $expiredCount) {
        $children = array_values(array_filter($shutdowns, function ($r) use ($role) { return $r['role'] === $role . '-orders-expire'; }));
        miq_check(count($children) === 1 && $children[0]['argv'][1] === 'orders:expire' && $children[0]['pid'] !== $processes[$role]['pid'], 'original_scheduler_real_orders_expire_child_' . $role);
        $owned = array_filter($identities, function ($e) use ($children, $role) { return $e['owner'] === $role && $e['identity']['pid'] === $children[0]['pid']; });
        miq_check(count($owned) === 1, 'actual_scheduler_child_proc_ancestry_' . $role);
        miq_check(strpos(file_get_contents($run . '/' . $role . '.stdout'), 'orders:expire') !== false, 'scheduler_reports_actual_original_command_' . $role);
        miq_check((bool) $children[0]['runtime']['config_cached'], 'cached_scheduler_child_' . $role);
    }
    $events = miq_events(); miq_check(count($events) === 15, 'exact15_native_queue_events_no_extra_processing');
    foreach ($events as $e) {
        miq_check($e['nonce'] === $cfg['nonce'] && $e['pid'] === $e['kernel_pid'] && $e['connection'] === 'database'
            && count($e['order_sns']) === 1 && $e['payload']['displayName'] === 'App\Jobs\OrderExpired', 'native_event_identity_' . $e['pid'] . '_' . $e['event']);
        miq_validate_runtime($e['runtime'], true, 'event-' . $e['pid']);
        $proof = array_values(array_filter($shutdowns, function ($r) use ($e) { return $r['pid'] === $e['pid'] && $r['role'] === $e['role']; }));
        miq_check(count($proof) === 1 && $proof[0]['observer']['boot_identity']['app_id'] === $e['runtime']['app_id'], 'event_same_actual_hot_app_and_pid_' . $e['pid']);
        if ($e['role'] === 'supervisor-worker') miq_check($e['ppid'] === $processes['supervisord']['pid'], 'event_real_supervisor_parent_' . $e['pid']);
    }
    foreach ([$normal, $duplicate, $paid] as $job) {
        miq_check(count(miq_for_uuid($job['uuid'], 'JobProcessing')) === 1 && count(miq_for_uuid($job['uuid'], 'JobProcessed')) === 1
            && !miq_for_uuid($job['uuid'], 'JobExceptionOccurred') && !miq_for_uuid($job['uuid'], 'JobFailed'), 'native_success_event_pair_' . $job['case'] . '_' . $job['uuid']);
    }
    $scheduleEvidence = [];
    foreach (glob($run . '/schedule-*.jsonl') as $file) $scheduleEvidence = array_merge($scheduleEvidence, miq_lines($file));
    miq_check(count($scheduleEvidence) === 4, 'two_real_scheduler_task_start_finish_pairs');
    foreach (['scheduler-tick-one', 'scheduler-tick-two'] as $role) {
        $pair = array_values(array_filter($scheduleEvidence, function ($e) use ($role) { return $e['role'] === $role; }));
        miq_check(count($pair) === 2 && $pair[0]['event'] === 'Illuminate\Console\Events\ScheduledTaskStarting'
            && $pair[1]['event'] === 'Illuminate\Console\Events\ScheduledTaskFinished' && $pair[1]['task_exit_code'] === 0,
            'actual_scheduler_task_exit0_' . $role);
        foreach ($pair as $e) miq_check($e['pid'] === $processes[$role]['pid'] && strpos($e['command'], 'orders:expire') !== false
            && $e['expression'] === '* * * * *' && $e['without_overlapping'] === true
            && $e['mutex_type'] === 'Illuminate\Console\Scheduling\CacheEventMutex' && $e['mutex_file_store'] === 'Illuminate\Cache\FileStore',
            'actual_original_kernel_schedule_file_mutex_' . $role);
        miq_check($pair[0]['mutex_name'] === $pair[1]['mutex_name'] && $pair[1]['mutex_present'] === false, 'scheduler_file_mutex_released_' . $role);
    }
    miq_json($run . '/schedule-evidence.json', $scheduleEvidence);
    $finalSequences = $seeded['tables']['sqlite_sequence']['data'];
    $finalSequences[] = ['name' => 'jobs', 'seq' => (int) $retryRows[0]['id']]; $finalSequences[] = ['name' => 'failed_jobs', 'seq' => 1];
    usort($finalSequences, function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
    miq_check($finalSequences === $final['tables']['sqlite_sequence']['data'], 'exact_native_sequences_business_unchanged_jobs_and_single_failure');
    $applicationLog = '/app/storage/logs/laravel.log';
    miq_check(is_file($applicationLog), 'expected_original_exception_reporting_log');
    $rawApplicationLog = file_get_contents($applicationLog); $headers = [];
    preg_match_all('/^\[[^\]\r\n]+\] [A-Za-z0-9_-]+\.([A-Z]+): ([^\r\n]*)/m', $rawApplicationLog, $headers, PREG_SET_ORDER);
    miq_check(count($headers) === 3, 'exact_three_original_job_exception_reports');
    foreach ($headers as $header) miq_check($header[1] === 'ERROR' && strpos($header[2], $cfg['fault_message']) !== false, 'only_expected_job_database_exception_report');
    miq_json($run . '/expected-database-exceptions.json', ['uuid' => $fault['uuid'], 'attempts' => $exceptionEvents,
        'job_failed' => miq_for_uuid($fault['uuid'], 'JobFailed'), 'application_log' => $applicationLog,
        'application_log_sha256' => hash('sha256', $rawApplicationLog), 'application_log_bytes' => $rawApplicationLog]);
    $supervisorLog = file_get_contents($run . '/supervisord.log');
    miq_check(strpos($supervisorLog, 'exited: dujiaoka-queue (exit status 0; expected)') !== false
        && strpos($supervisorLog, 'stopped: dujiaoka-queue (exit status 0)') !== false, 'native_supervisor_zero_exit_restart_and_stop_logs');
    $engineLog = $run . '/php-engine.log'; miq_check(!is_file($engineLog) || filesize($engineLog) === 0, 'no_php_engine_issue_log');
    foreach (glob($run . '/*.stderr') as $file) miq_check(!preg_match('/(?:^|[\r\n])(?:PHP )?(?:Fatal error|Warning|Notice|Deprecated|Parse error):/', file_get_contents($file)), 'no_raw_php_issue_' . basename($file));
    // Expected job QueryExceptions are separate evidence, never blanket-whitelisted PHP diagnostics.
    $cfg['expected_job_failure'] = ['uuid' => $fault['uuid'], 'message' => $cfg['fault_message'], 'attempts' => [1,2,3], 'job_failed_events' => 1];
    $appAfter = []; miq_app_files('/app', $appAfter, true); miq_check($appAfter === $appOriginal, 'all_original1273_app_and100_vendor_files_unchanged');
    foreach ($filesSHA as $file => $proof) miq_check(is_file($file) && hash_file('sha256', $file) === $proof['sha256']
        && ($proof['link'] === null ? !is_link($file) : readlink($file) === $proof['link']), 'protected_original_hash_' . $file);
    foreach ($sourceIncludes as $file => $sha) miq_check(hash_file('sha256', $file) === $sha, 'loaded_original_source_hash_' . $file);
    miq_monitor(); foreach ($identities as $key => $entry) miq_check($entry['closed'], 'owned_identity_closed_' . $key);
    miq_check($interrupted === null && !$GLOBALS['miq_observer']['issues'] && error_get_last() === null, 'parent_no_php_issue_or_interrupt');
    miq_check(microtime(true) - $started < 230, 'inner_work_completed_within230s_before_cleanup');
    $completed = true;
} catch (Throwable $e) {
    $failure = ['class' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    $cleanupDeadline = min($started + 239, microtime(true) + 12);
    if ($alarm) { pcntl_alarm(0); foreach ([SIGINT, SIGTERM, SIGALRM] as $signal) pcntl_signal($signal, SIG_IGN); }
    // Close owned handles only. Any emergency TERM/KILL is a failing outcome with exact signal evidence.
    foreach (array_reverse(array_keys($processes)) as $label) {
        if ($processes[$label]['closed']) continue;
        try {
            if (miq_running($label)) {
                $cleanup[] = 'Emergency cleanup required: ' . $label; miq_signal_handle($label, SIGTERM);
                try { miq_wait($label, 6, false); } catch (Throwable $e) { miq_signal_handle($label, SIGKILL); miq_wait($label, 3, false); }
            } else miq_wait($label, 1, false);
        } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    }
    if ($ownsRun) try { miq_cleanup_identities(); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    foreach (array_keys($processes) as $label) if (!$processes[$label]['closed'] && !miq_running($label)) {
        try { miq_wait($label, 0.1, false); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    }
    if ($db && $db->inTransaction()) $db->rollBack();
    if ($ownsRun) {
        try {
            miq_monitor(false);
            foreach ($identities as $key => $entry) if (!$entry['closed']) $cleanup[] = 'Owned identity still active: ' . $key;
            foreach ($workerIdentities as $id) if (posix_kill(-$id['pgrp'], 0)) $cleanup[] = 'Owned worker process group still present: ' . $id['pgrp'];
            if (!miq_socket_closed()) $cleanup[] = 'Owned Supervisor control socket still listening';
            if ($app && isset($GLOBALS['miq_observer'])) {
                $seedObserver = miq_shutdown_record();
                if ($seedObserver['inspection_error'] || !$seedObserver['runtime'] || !$seedObserver['current_app_observed']
                    || $seedObserver['observer']['issues'] || $seedObserver['observer']['observation_errors']
                    || $seedObserver['last_php_error'] !== null) $cleanup[] = 'Incomplete terminal seed observation';
            }
            if ($db) {
                miq_snapshot('cleanup-final');
                if ($completed) miq_check(count($snapshots) === 27, 'exact27_complete_sql_snapshots_including_cleanup');
            }
            $events = miq_events();
            $shutdowns = []; foreach (glob($cfg['run'] . '/shutdown-*.json') as $file) $shutdowns[] = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $sourceMismatches = [];
            foreach ($filesSHA as $file => $proof) if (!is_file($file) || hash_file('sha256', $file) !== $proof['sha256']) $sourceMismatches[] = $file;
            if ($sourceMismatches) $cleanup[] = 'Original source changed during run/cleanup: ' . implode(', ', $sourceMismatches);
        } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    }
    if ($app) try { $app['db']->disconnect(); } catch (Throwable $e) { $cleanup[] = $e->getMessage(); }
    $status = $completed && !$failure && !$cleanup && $interrupted === null && microtime(true) - $started < 240 ? 'PASS' : 'FAIL';
    if (microtime(true) - $started >= 240 && !$failure) $failure = ['class' => 'RuntimeException', 'message' => 'Inner240s budget exceeded'];
    $logs = [];
    if ($status === 'PASS' && file_put_contents($cfg['run'] . '/queue.completed', $cfg['nonce'], LOCK_EX) !== strlen($cfg['nonce'])) {
        $status = 'FAIL'; $failure = ['class' => 'RuntimeException', 'message' => 'Queue completion marker write failed'];
    }
    $collect = function ($directory) use (&$collect, &$logs) {
        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') continue; $file = $directory . '/' . $name;
            if (is_link($file)) throw new RuntimeException('Private queue evidence link');
            if (is_dir($file)) $collect($file);
            elseif (is_file($file)) $logs[$file] = ['bytes' => filesize($file), 'sha256' => hash_file('sha256', $file)];
        }
    };
    if ($ownsRun) $collect($cfg['run']);
    $result = ['status' => $status, 'completed' => $status === 'PASS', 'nonce' => $cfg['nonce'] ?? null,
        'outer_marker' => $cfg['outer_marker'] ?? null, 'started_marker' => $cfg['run'] . '/queue.started',
        'completion_marker' => $cfg['run'] . '/queue.completed', 'failure' => $failure, 'cleanup_errors' => $cleanup,
        'interrupted' => $interrupted, 'elapsed' => microtime(true) - $started, 'php' => PHP_VERSION, 'binary' => PHP_BINARY,
        'uid' => function_exists('posix_geteuid') ? posix_geteuid() : null, 'gid' => function_exists('posix_getegid') ? posix_getegid() : null,
        'uid_map' => $uidMap ?? null, 'gid_map' => $gidMap ?? null, 'process_identity' => $contractIdentity ?? null,
        'runtime' => $cfg, 'checks' => count($checks), 'check_names' => $checks,
        'processes' => array_map(function ($p) { unset($p['handle']); return $p; }, $processes), 'identities' => $identities,
        'worker_identities' => $workerIdentities, 'signals' => $signalsSent, 'jobs' => $jobDefinitions,
        'snapshots' => $snapshots, 'events' => $events, 'shutdowns' => $shutdowns, 'seed_observer' => $seedObserver,
        'source_hashes' => $filesSHA, 'source_includes' => $sourceIncludes, 'source_mismatches' => $sourceMismatches ?? [],
        'fixed100_graph' => $packageGraph, 'logs' => $logs,
        'scope' => 'Original PHP8.5.11 image /app current1273 + fixed100 no-dev vendor, unchanged original artisan/App/INI/Supervisor inputs. '
            . 'Exact externally seeded root0->outside1000 or application1000->outside1000 namespace; actual database queue/Supervisor/original schedule:run under that profile. '
            . 'Application requires empty effective/permitted/inheritable/ambient/bounding capabilities and NoNewPrivs=1 for every owned live identity. '
            . 'Four owned synthetic orders, native UUID failer, isolated program with root-only user substitution plus private log/PHP prefix, original scheduler file mutex. '
            . 'Private additive observer INI preserves original scan order and instruments the original scheduled PHP child. '
            . 'Explicit owned normal-row rearm separates worker and scheduler epochs; no frozen time or direct job/service invocation. '
            . 'Outer staging, parent timeout300s and independent fresh replay are provided by caller. No Engine/stock-user/syscron claim.'];
    try {
        if ($ownsRun) {
            miq_json('/probe/queue-contract.json', $result);
            if ($status === 'PASS' && trim(file_get_contents($cfg['run'] . '/queue.completed')) !== $cfg['nonce']) throw new RuntimeException('Queue completion marker lost.');
        }
    } catch (Throwable $e) {
        $status = 'FAIL'; $result['status'] = 'FAIL'; $result['completed'] = false; $result['failure'] = ['class' => get_class($e), 'message' => $e->getMessage()];
        if ($ownsRun) { if (is_file($cfg['run'] . '/queue.completed')) unlink($cfg['run'] . '/queue.completed'); miq_json('/probe/queue-contract.json', $result); }
    }
    echo json_encode(['status' => $status, 'completed' => $status === 'PASS', 'nonce' => $cfg['nonce'] ?? null,
        'checks' => count($checks), 'output' => '/probe/queue-contract.json'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
}
exit($status === 'PASS' ? 0 : 1);
