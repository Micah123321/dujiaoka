<?php

// Observation only: original artisan supplies the application, kernel and exception handling.
function miq_json($file, array $value)
{
    $bytes = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    $pending = $file . '.pending-' . getmypid();
    if (file_put_contents($pending, $bytes, LOCK_EX) !== strlen($bytes) || !rename($pending, $file)) throw new RuntimeException('Queue evidence write failed: ' . $file);
}

function miq_append($file, array $value)
{
    $bytes = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($file, $bytes, FILE_APPEND | LOCK_EX) !== strlen($bytes)) throw new RuntimeException('Queue observation write failed: ' . $file);
}

function miq_allowed_identity_profile($value)
{
    $allowed = [
        'root' => ['name' => 'root', 'uid' => 0, 'gid' => 0, 'uid_map' => '0:1000:1', 'gid_map' => '0:1000:1', 'user' => 'root', 'capability_mode' => 'namespace'],
        'application' => ['name' => 'application', 'uid' => 1000, 'gid' => 1000, 'uid_map' => '1000:1000:1', 'gid_map' => '1000:1000:1', 'user' => 'application', 'capability_mode' => 'empty'],
    ];
    if (!is_array($value) || !isset($value['name']) || !is_string($value['name']) || !isset($allowed[$value['name']])) {
        throw new RuntimeException('Queue identity profile is outside the exact allowed enum.');
    }
    $expected = $allowed[$value['name']]; $sorted = $value; ksort($sorted); $keys = $expected; ksort($keys);
    if ($sorted !== $keys) throw new RuntimeException('Queue identity profile fields or types changed.');
    return $expected;
}

function miq_identity_profile_source(?array $cfg = null)
{
    $file = '/probe/queue-identity.json';
    if (!is_file($file) || is_link($file) || realpath($file) !== $file) throw new RuntimeException('Fixed queue identity seed missing or linked.');
    $bytes = file_get_contents($file); $decoded = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
    if (!$decoded instanceof stdClass) throw new RuntimeException('Queue identity seed must be an exact object.');
    preg_match_all('/"(?:[^"\x5c]|\x5c.)*"\s*:/', $bytes, $members);
    if (count($members[0]) !== 7 || count(get_object_vars($decoded)) !== 7) {
        throw new RuntimeException('Queue identity seed has duplicate or additional fields.');
    }
    $profile = miq_allowed_identity_profile(get_object_vars($decoded)); $sha = hash('sha256', $bytes);
    if ($cfg !== null && (miq_allowed_identity_profile($cfg['identity_profile'] ?? null) !== $profile
        || !isset($cfg['identity_profile_source_sha256']) || $cfg['identity_profile_source_sha256'] !== $sha)) {
        throw new RuntimeException('Queue identity profile source bytes changed.');
    }
    return ['profile' => $profile, 'sha256' => $sha];
}

function miq_process_identity_evidence($pid, $status)
{
    $rawUidMap = miq_proc_read('/proc/' . (int) $pid . '/uid_map');
    $rawGidMap = miq_proc_read('/proc/' . (int) $pid . '/gid_map');
    if ($rawUidMap === false || $rawGidMap === false) return null;
    if (preg_match('/^Uid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*$/m', $status, $uids) !== 1
        || preg_match('/^Gid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*$/m', $status, $gids) !== 1) {
        throw new RuntimeException('Owned queue process status lacks exact UID/GID fields.');
    }
    $user = posix_getpwuid((int) $uids[2]); $group = posix_getgrgid((int) $gids[2]);
    return ['uids' => array_map('intval', array_slice($uids, 1)), 'gids' => array_map('intval', array_slice($gids, 1)),
        'raw_status' => $status, 'raw_uid_map' => $rawUidMap, 'raw_gid_map' => $rawGidMap,
        'uid_map' => preg_replace('/\s+/', ':', trim($rawUidMap)), 'gid_map' => preg_replace('/\s+/', ':', trim($rawGidMap)),
        'user' => $user === false ? null : array_intersect_key($user, array_flip(['name', 'uid', 'gid'])),
        'group' => $group === false ? null : array_intersect_key($group, array_flip(['name', 'gid']))];
}

function miq_assert_identity_profile(array $identity, array $profile)
{
    $profile = miq_allowed_identity_profile($profile);
    foreach (['Uid' => 'uid', 'Gid' => 'gid'] as $field => $key) {
        $numbers = implode('[ \t]+', array_fill(0, 4, (string) $profile[$key]));
        if (preg_match_all('/^' . $field . ':[ \t]+' . $numbers . '[ \t]*$/m', $identity['raw_status'], $matches) !== 1
            || preg_match_all('/^' . $field . ':/m', $identity['raw_status'], $matches) !== 1) {
            throw new RuntimeException('Raw queue process status differs from exact profile ' . $field . '.');
        }
    }
    if (isset($identity['raw_self_status']) && $identity['raw_self_status'] !== $identity['raw_status']) {
        throw new RuntimeException('Raw queue self status changed in recorded identity.');
    }
    if ($identity['uids'] !== array_fill(0, 4, $profile['uid']) || $identity['gids'] !== array_fill(0, 4, $profile['gid'])
        || $identity['uid_map'] !== $profile['uid_map'] || $identity['gid_map'] !== $profile['gid_map']
        || preg_replace('/\s+/', ':', trim($identity['raw_uid_map'])) !== $profile['uid_map']
        || preg_replace('/\s+/', ':', trim($identity['raw_gid_map'])) !== $profile['gid_map']
        || ($identity['user']['name'] ?? null) !== $profile['user'] || ($identity['user']['uid'] ?? null) !== $profile['uid']
        || ($identity['user']['gid'] ?? null) !== $profile['gid'] || ($identity['group']['name'] ?? null) !== $profile['user']
        || ($identity['group']['gid'] ?? null) !== $profile['gid']) throw new RuntimeException('Actual queue process identity differs from exact profile.');
    if ($profile['capability_mode'] === 'empty') {
        foreach (['CapEff', 'CapPrm', 'CapInh', 'CapAmb', 'CapBnd'] as $field) {
            if (preg_match_all('/^' . $field . ':[ \t]+0+[ \t]*$/m', $identity['raw_status'], $matches) !== 1
                || preg_match_all('/^' . $field . ':/m', $identity['raw_status'], $matches) !== 1) {
                throw new RuntimeException('Application queue process has nonempty or missing ' . $field . '.');
            }
        }
        if (preg_match_all('/^NoNewPrivs:[ \t]+1[ \t]*$/m', $identity['raw_status'], $matches) !== 1
            || preg_match_all('/^NoNewPrivs:/m', $identity['raw_status'], $matches) !== 1) {
            throw new RuntimeException('Application queue process requires actual NoNewPrivs=1.');
        }
    }
}

function miq_self_identity(array $cfg)
{
    $source = miq_identity_profile_source($cfg); $profile = $source['profile'];
    $status = file_get_contents('/proc/self/status'); $identity = miq_process_identity_evidence(getmypid(), $status);
    if (!$identity || posix_geteuid() !== $profile['uid'] || posix_getegid() !== $profile['gid']) {
        throw new RuntimeException('Actual queue POSIX identity differs from exact profile.');
    }
    miq_assert_identity_profile($identity, $profile); $identity['raw_self_status'] = $status;
    $identity['identity_profile'] = $profile; $identity['identity_profile_source_sha256'] = $source['sha256'];
    return $identity;
}

function miq_environment(array $cfg)
{
    // Reuse the original fixture environment builder without entering its FPM-only branch.
    $env = mih_environment(['marker' => $cfg['nonce'], 'url' => 'http://127.0.0.1', 'bcrypt_rounds' => $cfg['bcrypt_rounds']]);
    unset($env['IMAGE_HTTP_RUN']); putenv('IMAGE_HTTP_RUN'); unset($_ENV['IMAGE_HTTP_RUN'], $_SERVER['IMAGE_HTTP_RUN']);
    $env = array_merge($env, [
        'HOME' => $cfg['run'], 'IMAGE_QUEUE_RUN' => $cfg['run'], 'IMAGE_QUEUE_NONCE' => $cfg['nonce'],
        'IMAGE_QUEUE_ROLE' => 'contract-seed', 'QUEUE_CONNECTION' => 'database', 'QUEUE_FAILED_DRIVER' => 'database-uuids',
        'DB_DATABASE' => $cfg['database'], 'QUEUE_NAME' => 'default', 'PYTHONDONTWRITEBYTECODE' => '1',
        'MAIL_MAILER' => 'array', 'MAIL_DRIVER' => 'array',
        'MANUAL_STATUS_MAIL_OUTBOX' => 'false', 'LOG_CHANNEL' => 'single', 'LOG_DEPRECATIONS_CHANNEL' => 'single',
        // A separate scan directory observes schedule:run's original PHP subprocess too.
        // No image INI is edited; the original scanned directories stay first, in their original order.
        'PHP_INI_SCAN_DIR' => $cfg['ini_scan'],
    ]);
    foreach ($env as $name => $value) { putenv($name . '=' . $value); $_ENV[$name] = $value; $_SERVER[$name] = $value; }
    return $env;
}

function miq_proc_read($path, $link = false)
{
    if (!preg_match('#^/proc/[0-9]+/(?:stat|status|uid_map|gid_map|cmdline|exe|task/[0-9]+/children)$#D', $path)) throw new RuntimeException('Expected owned /proc path.');
    $previous = null;
    $previous = set_error_handler(function ($level, $message, $file, $line) use (&$previous, $path, $link) {
        if ($level === E_WARNING && $file === __FILE__ && strpos($message, 'No such file or directory') !== false
            && ($link ? strpos($message, 'readlink():') === 0 : strpos($message, 'file_get_contents(' . $path . '):') === 0)) return true;
        return $previous ? $previous($level, $message, $file, $line) : false;
    });
    try { return $link ? readlink($path) : file_get_contents($path); } finally { restore_error_handler(); }
}

function miq_boot_ancestry(array $cfg)
{
    $result = []; $pid = getmypid();
    for ($i = 0; $i < 12 && $pid > 0 && $pid !== $cfg['contract_pid']; $i++) {
        $stat = miq_proc_read('/proc/' . $pid . '/stat'); if ($stat === false) break;
        $tail = preg_split('/\s+/', substr($stat, strrpos($stat, ')') + 2));
        $status = miq_proc_read('/proc/' . $pid . '/status'); if ($status === false) break;
        preg_match('/^Uid:\s+(.*)$/m', $status, $uids); preg_match('/^Gid:\s+(.*)$/m', $status, $gids);
        $evidence = miq_process_identity_evidence($pid, $status); if (!$evidence) break;
        miq_assert_identity_profile($evidence, $cfg['identity_profile']);
        $result[] = ['pid' => $pid, 'ppid' => (int) $tail[1], 'pgrp' => (int) $tail[2], 'state' => $tail[0],
            'start_ticks' => $tail[19], 'uids' => array_map('intval', preg_split('/\s+/', trim($uids[1]))),
            'gids' => array_map('intval', preg_split('/\s+/', trim($gids[1]))), 'raw_stat' => $stat, 'raw_status' => $status,
            'exe' => miq_proc_read('/proc/' . $pid . '/exe', true),
            'cmdline' => str_replace(chr(0), ' ', (string) miq_proc_read('/proc/' . $pid . '/cmdline'))] + $evidence;
        $pid = (int) $tail[1];
    }
    return ['identities' => $result, 'reached_contract_pid' => $pid === $cfg['contract_pid']];
}

function miq_schedule_event($event)
{
    $state =& $GLOBALS['miq_observer'];
    try {
        $task = $event->task; $mutex = (new ReflectionObject($task))->getProperty('mutex')->getValue($task);
        $record = ['event' => get_class($event), 'role' => $state['role'], 'nonce' => $state['cfg']['nonce'],
            'pid' => getmypid(), 'time' => microtime(true), 'command' => $task->command, 'expression' => $task->expression,
            'without_overlapping' => $task->withoutOverlapping, 'mutex_type' => get_class($mutex),
            'mutex_name' => $task->mutexName(), 'mutex_file_store' => get_class(app('cache')->store()->getStore()),
            'mutex_present' => app('cache')->store()->has($task->mutexName()), 'task_exit_code' => $task->exitCode,
            'runtime' => miq_runtime_identity(Illuminate\Container\Container::getInstance())];
        if (isset($event->exception)) $record['exception'] = ['class' => get_class($event->exception), 'message' => $event->exception->getMessage()];
        miq_append($state['cfg']['run'] . '/schedule-' . $state['role'] . '-' . getmypid() . '.jsonl', $record);
    } catch (Throwable $e) { $state['observation_errors'][] = ['class' => get_class($e), 'message' => $e->getMessage()]; }
}

function miq_runtime_identity($app)
{
    $config = $app->make('config');
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    return [
        'app_id' => spl_object_id($app), 'app_type' => get_class($app), 'framework' => $app->version(),
        'base' => $app->basePath(), 'storage' => $app->storagePath(), 'environment' => $app->environment(),
        'debug' => $config->get('app.debug'), 'timezone' => $config->get('app.timezone'),
        'kernel_type' => get_class($kernel), 'app_source' => (new ReflectionClass($kernel))->getFileName(),
        'vendor_source' => (new ReflectionClass($app))->getFileName(),
        'config_cached' => $app->configurationIsCached(), 'routes_cached' => $app->routesAreCached(),
        'db_default' => $config->get('database.default'), 'db' => $config->get('database.connections.sqlite.database'),
        'pdo_driver' => $app['db']->connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME),
        'cache' => $config->get('cache.default'), 'cache_store' => get_class($app['cache']->store()->getStore()),
        'cache_path' => $config->get('cache.stores.file.path'), 'mail' => $config->get('mail.default'),
        'queue' => $config->get('queue.default'), 'queue_connection' => get_class($app['queue']->connection()),
        'failed_driver' => $config->get('queue.failed.driver'), 'failer' => get_class($app->make('queue.failer')),
        'provider_flags' => array_intersect_key($config->get('dujiaoka', []), array_flip([
            'api_hook_outbox', 'payment_mail_outbox', 'manual_status_mail_outbox', 'payment_quote_freeze',
            'stripe_payment_intents', 'stripe_reconciliation', 'paypal_orders_v2', 'paypal_reconciliation',
        ])),
    ];
}

function miq_job_event($event)
{
    $state =& $GLOBALS['miq_observer'];
    try {
        $job = $event->job; $payload = $job->payload(); $sns = [];
        foreach ($state['cfg']['orders'] ?? [] as $case => $sn) {
            if (strpos($payload['data']['command'] ?? '', $sn) !== false) $sns[$case] = $sn;
        }
        $app = Illuminate\Container\Container::getInstance();
        $entry = [
            'event' => get_class($event), 'nonce' => $state['cfg']['nonce'], 'role' => $state['role'],
            'pid' => getmypid(), 'ppid' => posix_getppid(), 'kernel_pid' => getmypid(), 'time' => microtime(true),
            'connection' => $event->connectionName, 'queue' => $job->getQueue(), 'job_id' => $job->getJobId(),
            'attempts' => $job->attempts(), 'payload_uuid' => $payload['uuid'] ?? null,
            'payload_sha256' => hash('sha256', $job->getRawBody()), 'payload' => $payload,
            'order_sns' => $sns, 'runtime' => miq_runtime_identity($app), 'exception' => null,
        ];
        if (isset($event->exception)) {
            $entry['exception'] = ['class' => get_class($event->exception), 'message' => $event->exception->getMessage(),
                'file' => $event->exception->getFile(), 'line' => $event->exception->getLine()];
        }
        // Read-only, complete committed SQLite state: the throwing transaction has rolled back at these events.
        $pdo = new PDO('sqlite:' . $state['cfg']['database']); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA query_only=ON'); $pdo->beginTransaction(); $tables = [];
        foreach ($pdo->query("SELECT name,sql FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $table) {
            $quoted = '"' . str_replace('"', '""', $table['name']) . '"';
            $rows = $pdo->query('SELECT * FROM ' . $quoted)->fetchAll(PDO::FETCH_ASSOC);
            usort($rows, function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
            $tables[$table['name']] = ['schema' => $table['sql'], 'data' => $rows];
        }
        $entry['sql'] = $tables; $pdo->rollBack();
        miq_append($state['cfg']['run'] . '/events-' . $state['role'] . '-' . getmypid() . '.jsonl', $entry);
        $state['event_count']++;
    } catch (Throwable $e) {
        // An observation failure is evidence of FAIL, never a replacement for the original job exception.
        $state['observation_errors'][] = ['class' => get_class($e), 'message' => $e->getMessage()];
    }
}

function miq_attach_observer($app)
{
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\HandleExceptions::class, function ($app) {
        $state =& $GLOBALS['miq_observer']; $state['installed'] = true; $id = spl_object_id($app);
        $state['applications'][] = ['id' => $id, 'type' => get_class($app), 'base' => $app->basePath(),
            'bootstrapper' => 'Illuminate\Foundation\Bootstrap\HandleExceptions'];
        $previous = null;
        $previous = set_error_handler(function ($level, $message, $file, $line) use (&$previous, $id) {
            if ($level & error_reporting()) $GLOBALS['miq_observer']['issues'][] = compact('level', 'message', 'file', 'line', 'id');
            return $previous ? $previous($level, $message, $file, $line) : false;
        });
        $app->booted(function ($app) {
            try {
                $state =& $GLOBALS['miq_observer']; $state['boot_identity'] = miq_runtime_identity($app);
                foreach ([Illuminate\Queue\Events\JobProcessing::class, Illuminate\Queue\Events\JobProcessed::class,
                    Illuminate\Queue\Events\JobExceptionOccurred::class, Illuminate\Queue\Events\JobFailed::class] as $event) {
                    $app['events']->listen($event, function ($event) { miq_job_event($event); });
                }
                foreach ([Illuminate\Console\Events\ScheduledTaskStarting::class, Illuminate\Console\Events\ScheduledTaskFinished::class,
                    Illuminate\Console\Events\ScheduledTaskFailed::class] as $event) $app['events']->listen($event, function ($event) { miq_schedule_event($event); });
                $identity = ['event' => 'OriginalQueueBoot', 'nonce' => $state['cfg']['nonce'], 'role' => $state['role'],
                    'pid' => getmypid(), 'ppid' => posix_getppid(), 'time' => microtime(true), 'runtime' => $state['boot_identity'],
                    'argv' => $_SERVER['argv'] ?? [], 'ancestry' => miq_boot_ancestry($state['cfg'])];
                miq_json($state['cfg']['run'] . '/boot-' . $state['role'] . '-' . getmypid() . '.json', $identity);
            } catch (Throwable $e) { $GLOBALS['miq_observer']['observation_errors'][] = ['class' => get_class($e), 'message' => $e->getMessage()]; }
        });
    });

    // The reviewed native config method is inherited except for observing its fresh App.
    $app->extend(Illuminate\Foundation\Console\ConfigCacheCommand::class,function($command,$app){
        $reflection=new ReflectionMethod(Illuminate\Foundation\Console\ConfigCacheCommand::class,'getFreshConfiguration');
        $lines=file($reflection->getFileName(),FILE_IGNORE_NEW_LINES);$actual=implode("\n",array_slice($lines,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
        $expected="    protected function getFreshConfiguration()\n    {\n        \$app = require \$this->laravel->bootstrapPath('app.php');\n\n        \$app->useStoragePath(\$this->laravel->storagePath());\n\n        \$app->make(ConsoleKernelContract::class)->bootstrap();\n\n        return \$app['config']->all();\n    }";
        if(str_replace(chr(13),'',$actual)!==$expected)throw new RuntimeException('Original fresh config bootstrap changed; observation adapter must be reviewed.');
        // Attach observation to the fresh App; inherited native serialization stays unchanged.
        return new class($app->make('files')) extends Illuminate\Foundation\Console\ConfigCacheCommand {
            protected function getFreshConfiguration()
            {
                $app=require $this->laravel->bootstrapPath('app.php');
                $app->useStoragePath($this->laravel->storagePath());
                miq_attach_observer($app);
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                return $app['config']->all();
            }
        };
    });
}

function miq_observe(array $cfg, $role)
{
    error_reporting(E_ALL);
    $GLOBALS['miq_observer'] = [
        'cfg' => $cfg, 'role' => $role, 'installed' => false, 'issues' => [], 'applications' => [],
        'bound_before_vendor' => !class_exists('Composer\Autoload\ClassLoader', false),
        'event_count' => 0, 'observation_errors' => [], 'boot_identity' => null,
        'process_identity' => miq_self_identity($cfg),
    ];
    $watch = null;
    $watch = function ($class) use (&$watch) {
        if ($class !== 'App\Console\Kernel') return;
        $app = Illuminate\Container\Container::getInstance();
        miq_attach_observer($app);
        spl_autoload_unregister($watch);
    };
    $GLOBALS['miq_watch'] = $watch; spl_autoload_register($watch, true, true);
}

function miq_rearm_watch()
{
    spl_autoload_unregister($GLOBALS['miq_watch']); spl_autoload_register($GLOBALS['miq_watch'], true, true);
}

function miq_shutdown_record()
{
    $state = $GLOBALS['miq_observer']; $last = error_get_last();
    $record = [
        'event' => 'OriginalQueueShutdown', 'nonce' => $state['cfg']['nonce'], 'role' => $state['role'],
        'pid' => getmypid(), 'ppid' => posix_getppid(), 'php' => PHP_VERSION, 'binary' => PHP_BINARY, 'sapi' => PHP_SAPI,
        'uid' => posix_geteuid(), 'gid' => posix_getegid(), 'argc' => $_SERVER['argc'] ?? null, 'argv' => $_SERVER['argv'] ?? [],
        'identity_profile' => $state['cfg']['identity_profile'], 'process_identity' => miq_self_identity($state['cfg']),
        'time' => microtime(true), 'last_php_error' => $last, 'observer' => array_diff_key($state, ['cfg' => true]),
        'runtime' => null, 'inspection_error' => null, 'includes' => [], 'sourceIncludes' => [], 'outside' => [],
        'loaders' => [], 'loader_types' => [], 'loader_paths_outside' => [], 'dev_loaded' => [], 'dev_mapped' => [],
        'fixture_loaded' => class_exists('Tests\Fixtures\AdminData', false), 'ini_loaded' => php_ini_loaded_file(), 'ini_scanned' => php_ini_scanned_files(),
    ];
    try {
        foreach (get_included_files() as $file) {
            $file = realpath($file) ?: $file; $record['includes'][] = $file; $record['sourceIncludes'][$file] = hash_file('sha256', $file);
            if (strpos($file, '/app/') !== 0 && !in_array($file, ['/probe/queue-contract.php', '/probe/queue-prepend.php',
                '/probe/http-prepend.php', '/probe/seed/tests/Fixtures/AdminData.php'], true)) $record['outside'][] = $file;
        }
        foreach (['Tests\TestCase', 'PHPUnit\Framework\TestCase', 'Mockery', 'Faker\Factory'] as $class) {
            if (class_exists($class, false)) $record['dev_loaded'][] = $class;
        }
        foreach (Composer\Autoload\ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            $record['loaders'][] = $vendor; $record['loader_types'][] = get_class($loader); $paths = array_values($loader->getClassMap());
            foreach ($loader->getPrefixesPsr4() as $prefix => $dirs) {
                if (strpos($prefix, 'Tests\\') === 0) $record['dev_mapped'][] = $prefix;
                $paths = array_merge($paths, $dirs);
            }
            foreach ($loader->getPrefixes() as $dirs) $paths = array_merge($paths, $dirs);
            foreach ($paths as $path) if (strpos(realpath($path) ?: '', '/app/') !== 0) $record['loader_paths_outside'][] = $path;
            if ($loader->getFallbackDirsPsr4() || $loader->getFallbackDirs() || $loader->getUseIncludePath()) $record['loader_paths_outside'][] = 'fallback/include-path';
        }
        $app = Illuminate\Container\Container::getInstance(); $record['runtime'] = miq_runtime_identity($app);
        $record['installed_dev'] = json_decode(file_get_contents('/app/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR)['dev'];
        $record['current_app_observed'] = in_array(spl_object_id($app), array_column($state['applications'], 'id'), true);
    } catch (Throwable $e) { $record['inspection_error'] = ['class' => get_class($e), 'message' => $e->getMessage()]; }
    miq_json($state['cfg']['run'] . '/shutdown-' . $state['role'] . '-' . getmypid() . '.json', $record);
    return $record;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === '/app/artisan') {
    $run = '/probe/queue.private';
    $cfg = json_decode(file_get_contents($run . '/runtime.json'), true, 512, JSON_THROW_ON_ERROR);
    $profile = miq_allowed_identity_profile($cfg['identity_profile'] ?? null);
    $role = getenv('IMAGE_QUEUE_ROLE');
    if (!preg_match('/^[a-z][a-z0-9-]{0,79}$/D', (string) $role) || getenv('IMAGE_QUEUE_RUN') !== $run
        || getenv('IMAGE_QUEUE_NONCE') !== $cfg['nonce'] || !hash_equals(trim(file_get_contents($run . '/selfnonce')), $cfg['nonce'])
        || $cfg['run'] !== $run || PHP_VERSION !== '8.5.11' || PHP_BINARY !== '/usr/local/bin/php'
        || posix_geteuid() !== $profile['uid'] || posix_getegid() !== $profile['gid']) throw new RuntimeException('Fresh original queue CLI observation mismatch.');
    miq_self_identity($cfg);
    if (($_SERVER['argv'][1] ?? '') === 'orders:expire') $role .= '-orders-expire';
    require_once '/probe/http-prepend.php';
    miq_observe($cfg, $role); register_shutdown_function('miq_shutdown_record');
    // Only load the untouched Composer loader early, then restore the watch before original artisan boots.
    require_once '/app/vendor/autoload.php'; miq_rearm_watch();
}
