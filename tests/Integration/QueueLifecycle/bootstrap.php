<?php

// This harness never loads the shop environment or uses its queue/cache paths.
function ql_normalize($path)
{
    return str_replace('\\', '/', $path);
}

function ql_json($path, array $data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Fixture JSON write failed: ' . basename($path));
    }
}

function ql_log($event, array $data = [])
{
    $run = $GLOBALS['ql_run'];
    $record = array_merge(['event' => $event, 'pid' => getmypid(), 'time' => microtime(true),
        'role' => $GLOBALS['ql_role'], 'marker' => $GLOBALS['ql_config']['marker']], $data);
    if (file_put_contents($run . '/events.jsonl', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Fixture event write failed.');
    }
}

function ql_check($condition, $name, array $evidence = [])
{
    ql_log($condition ? 'CheckPassed' : 'CheckFailed', ['check' => $name, 'evidence' => $evidence]);
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $name);
    }
}

function ql_disconnect($app)
{
    if (empty($GLOBALS['ql_db_closed']) && $app->resolved('db')) {
        $app['db']->disconnect();
        $GLOBALS['ql_db_closed'] = true;
        ql_log('ConnectionClosed');
    }
}

function ql_boot($run, $marker, $candidate, $role)
{
    $root = ql_normalize(realpath(dirname(__DIR__, 3)));
    $actualRun = realpath($run);
    $base = realpath($root . '/tests/.runtime/queue-lifecycle');
    if ($actualRun === false || $base === false || ql_normalize(dirname($actualRun)) !== ql_normalize($base)
        || !preg_match('/^run-[a-zA-Z0-9-]+$/D', basename($actualRun))) {
        throw new RuntimeException('Invalid fixture runtime directory.');
    }
    $run = ql_normalize($actualRun);
    if (!is_file($run . '/marker') || !hash_equals(trim(file_get_contents($run . '/marker')), $marker)) {
        throw new RuntimeException('Fixture marker mismatch.');
    }
    $cfg = json_decode(file_get_contents($run . '/run.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!hash_equals($cfg['marker'], $marker) || (bool) $cfg['candidate'] !== (bool) $candidate
        || $cfg['root'] !== $root || $cfg['run'] !== $run) {
        throw new RuntimeException('Fixture manifest mismatch.');
    }
    $GLOBALS['ql_run'] = $run;
    $GLOBALS['ql_config'] = $cfg;
    $GLOBALS['ql_role'] = $role;
    ql_check(ql_normalize(realpath(PHP_BINARY)) === $cfg['binary'], 'same_php_binary');
    ql_check(ql_normalize((string) php_ini_loaded_file()) === $cfg['ini'], 'same_loaded_ini');
    $vendor = $candidate ? $root . '/tests/Compatibility/Laravel12/vendor' : $root . '/tests/Compatibility/Laravel6/vendor';
    $relative = 'tests/.runtime/queue-lifecycle/' . basename($run);
    $env = [
        'APP_BASE_PATH' => $root, 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
        'APP_NAME' => 'QueueLifecycleFixture', 'APP_KEY' => $cfg['key'], 'APP_URL' => 'http://fixture.invalid',
        'LARAVEL_STORAGE_PATH' => $run . '/storage', 'COMPOSER_VENDOR_DIR' => $vendor,
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $run . '/queue.sqlite', 'DATABASE_URL' => '',
        'DB_FOREIGN_KEYS' => 'true', 'DB_HOST' => '', 'DB_PORT' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => '',
        'CACHE_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'CACHE_PREFIX' => 'queue-lifecycle-' . $marker,
        'SCHEDULE_CACHE_DRIVER' => 'file', 'SCHEDULE_CACHE_STORE' => 'file',
        'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database', 'QUEUE_FAILED_DRIVER' => $candidate ? 'database-uuids' : 'database',
        'MAIL_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'MAIL_FROM_ADDRESS' => 'sender@example.test',
        'MAIL_HOST' => '', 'MAIL_PORT' => '', 'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '', 'MAIL_ENCRYPTION' => '',
        'LOG_CHANNEL' => 'single', 'API_HOOK_OUTBOX' => 'true', 'PAYMENT_MAIL_OUTBOX' => 'true', 'MANUAL_STATUS_MAIL_OUTBOX' => 'false',
        'PAYMENT_QUOTE_FREEZE' => 'false', 'STRIPE_PAYMENT_INTENTS' => 'false', 'STRIPE_RECONCILIATION' => 'false',
        'PAYPAL_ORDERS_V2' => 'false', 'PAYPAL_RECONCILIATION' => 'false', 'PAYPAL_WEBHOOK_IDS' => '{}',
        'PAYPAL_ORDERS_MODE' => 'sandbox', 'DUJIAO_ADMIN_LANGUAGE' => 'en', 'BROADCAST_DRIVER' => 'log',
        'AWS_ACCESS_KEY_ID' => '', 'AWS_SECRET_ACCESS_KEY' => '', 'AWS_DEFAULT_REGION' => '',
        'REDIS_PASSWORD' => '', 'MEMCACHED_USERNAME' => '', 'MEMCACHED_PASSWORD' => '',
    ];
    foreach (['CONFIG', 'ROUTES', 'PACKAGES', 'SERVICES', 'EVENTS'] as $cache) {
        $env['APP_' . $cache . '_CACHE'] = $relative . '/cache/' . strtolower($cache) . '.php';
    }
    foreach ($env as $key => $value) {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    ql_check($candidate ? PHP_VERSION_ID >= 80400 : (PHP_MAJOR_VERSION === 7 && PHP_MINOR_VERSION === 4), 'supported_fixture_php');
    error_reporting(E_ALL);
    require $vendor . '/autoload.php';
    $app = require $root . '/bootstrap/app.php';
    $GLOBALS['ql_db_closed'] = false;
    // Worker::stop exits the interpreter directly, so shutdown also closes its connection.
    register_shutdown_function(function () use ($app) { ql_disconnect($app); });
    $app->useEnvironmentPath($run)->loadEnvironmentFrom('.env.fixture-not-present');
    $app->useStoragePath($run . '/storage');
    $app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\RegisterProviders::class, function ($app) use ($run) {
        $app['config']->set([
            'queue.connections.database.connection' => 'sqlite', 'queue.connections.database.queue' => 'fixture35',
            'queue.connections.database.retry_after' => 90, 'queue.failed.database' => 'sqlite',
            'cache.stores.file.path' => $run . '/storage/framework/cache/data',
            'cache.stores.file.lock_path' => $run . '/storage/framework/cache/locks',
            'view.compiled' => $run . '/storage/framework/views',
            'mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array'],
        ]);
    });
    $app->instance('path.vendor', $vendor);
    $manifest = $app->make(Illuminate\Foundation\PackageManifest::class);
    $manifest->vendorPath = $vendor;
    $manifest->build();
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    foreach ($env as $key => $value) {
        ql_check((string) getenv($key) === $value, 'isolated_env_' . $key);
    }
    ql_check($app->environmentPath() === $run && $app->environmentFile() === '.env.fixture-not-present'
        && !file_exists($app->environmentFilePath()), 'absent_run_env');
    ql_check(config('dujiaoka.manual_status_mail_outbox') === false, 'manual_status_mail_fixture_disabled');
    ql_check($app->environment() === 'testing' && config('app.key') === $cfg['key'], 'synthetic_app_config');
    ql_check(ql_normalize($app->storagePath()) === $run . '/storage', 'isolated_storage');
    foreach (['Config', 'Routes', 'Packages', 'Services', 'Events'] as $cache) {
        $method = 'getCached' . $cache . 'Path';
        ql_check(ql_normalize($app->$method()) === $run . '/cache/' . strtolower($cache) . '.php', 'isolated_cache_' . $cache);
    }
    ql_check(config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === $run . '/queue.sqlite'
        && empty(config('database.connections.sqlite.url')), 'isolated_database_config');
    $db = $app['db']->connection();
    $db->statement('PRAGMA busy_timeout = 5000');
    $databases = $db->select('PRAGMA database_list');
    ql_check(ql_normalize($databases[0]->file) === $run . '/queue.sqlite', 'actual_sqlite_file');
    ql_check(config('cache.default') === 'file' && config('cache.stores.file.path') === $run . '/storage/framework/cache/data', 'isolated_filecache');
    ql_check($app['cache']->store()->getStore() instanceof Illuminate\Cache\FileStore, 'actual_filecache');
    ql_check(config('queue.default') === 'database' && config('queue.connections.database.queue') === 'fixture35', 'isolated_queue');
    ql_check($app['queue']->connection() instanceof Illuminate\Queue\DatabaseQueue, 'actual_database_queue');
    ql_check($kernel instanceof App\Console\Kernel, 'actual_app_kernel');
    ql_check($app->version() === ($candidate ? '13.35.0' : '6.20.42'), 'expected_framework_version');
    $sources = [];
    foreach ([App\Console\Kernel::class, App\Console\Commands\ExpireOrders::class, App\Console\Commands\DispatchNotifications::class,
        App\Jobs\OrderExpired::class, App\Jobs\DeliverNotification::class, App\Jobs\ApiHook::class, App\Jobs\MailSend::class,
        App\Service\OrderService::class, App\Service\NotificationOutboxService::class, App\Service\PaymentMailService::class,
        Illuminate\Queue\Worker::class, Illuminate\Queue\Console\WorkCommand::class, Illuminate\Bus\Dispatcher::class,
        Illuminate\Queue\DatabaseQueue::class] as $class) {
        $source = ql_normalize((new ReflectionClass($class))->getFileName());
        $prefix = strpos($class, 'App\\') === 0 ? $root . '/app/' : $vendor . '/laravel/framework/src/';
        ql_check(strpos($source, $prefix) === 0, 'actual_source_' . $class);
        $sources[$class] = ['path' => $source, 'sha256' => hash_file('sha256', $source)];
    }
    $app['cache']->forever('system-setting', ['driver' => 'array', 'from_address' => 'sender@example.test',
        'from_name' => 'Fixture', 'manage_email' => 'manager@example.test', 'text_logo' => 'Fixture', 'order_expire_time' => 5]);
    ql_observe($app);
    ql_log('Start', ['php' => PHP_VERSION, 'binary' => ql_normalize(PHP_BINARY), 'ini' => $cfg['ini'],
        'framework' => $app->version(), 'database' => $run . '/queue.sqlite', 'cache' => config('cache.stores.file.path'),
        'env_path' => $app->environmentFilePath(), 'storage' => $app->storagePath(), 'sources' => $sources]);
    return $app;
}

function ql_observe($app)
{
    foreach (['JobProcessing', 'JobProcessed', 'JobFailed'] as $eventName) {
        $app['events']->listen('Illuminate\\Queue\\Events\\' . $eventName, function ($event) use ($eventName) {
            if ($eventName === 'JobProcessing') $GLOBALS['ql_current_job_id'] = (int) $event->job->getJobId();
            ql_log($eventName, ['job_id' => $event->job->getJobId(), 'job' => $event->job->resolveName(),
                'attempts' => $event->job->attempts(), 'transaction_level' => Illuminate\Support\Facades\DB::transactionLevel(),
                'exception_class' => isset($event->exception) ? get_class($event->exception) : null]);
            if ($eventName !== 'JobProcessing') unset($GLOBALS['ql_current_job_id']);
        });
    }
    $app['events']->listen(Illuminate\Mail\Events\MessageSending::class, function () {
        ql_check((cache('system-setting')['driver'] ?? '') === 'array' && config('mail.driver') === 'array', 'mail_array_transport');
        ql_check(Illuminate\Support\Facades\DB::transactionLevel() === 0, 'mail_after_commit');
        $mailer = app('mailer');
        $transport = method_exists($mailer, 'getSymfonyTransport') ? $mailer->getSymfonyTransport() : $mailer->getSwiftMailer()->getTransport();
        if ($transport instanceof App\Mail\TrackedTransport) {
            $inner = Closure::bind(function () { return $this->transport; }, $transport, App\Mail\TrackedTransport::class);
            $transport = $inner();
        }
        ql_check($transport instanceof Illuminate\Mail\Transport\ArrayTransport, 'actual_array_mail_transport');
    });
    $app['events']->listen(Illuminate\Mail\Events\MessageSent::class, function ($event) {
        $subject = $event->message->getSubject();
        // Only a synthetic fixture order identifier is retained, never body or recipient data.
        if (!preg_match('/^Fixture (B|C|D|E)[a-zA-Z0-9-]*$/D', $subject)) {
            throw new RuntimeException('Unexpected fixture mail metadata.');
        }
        $sn = substr($subject, 8);
        $orderId = Illuminate\Support\Facades\DB::table('orders')->where('order_sn', $sn)->value('id');
        $row = Illuminate\Support\Facades\DB::table('notification_outbox')->where('channel', 'payment-mail')->where('order_id', $orderId)->first();
        ql_check($row && $row->reservation_token && $row->reserved_until && !$row->delivered_at, 'mail_claimed_lease');
        ql_log('MailAccepted', ['order_sn' => $sn, 'outbox_id' => (int) $row->id, 'attempt' => (int) $row->attempts,
            'transaction_level' => Illuminate\Support\Facades\DB::transactionLevel(), 'transport' => 'array']);
    });
    $app->instance(GuzzleHttp\Client::class, new GuzzleHttp\Client(['handler' => GuzzleHttp\HandlerStack::create(function ($request) {
        $payload = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $sn = $payload['order_sn'];
        ql_check($request->getMethod() === 'POST' && (string) $request->getUri() === 'https://fixture.invalid/hook', 'fixture_provider_only');
        ql_check(Illuminate\Support\Facades\DB::transactionLevel() === 0, 'provider_after_commit');
        $order = Illuminate\Support\Facades\DB::table('orders')->where('order_sn', $sn)->first();
        $row = Illuminate\Support\Facades\DB::table('notification_outbox')->where('order_id', $order->id)->where('channel', 'api-hook')->first();
        ql_check($row && $row->reservation_token && $row->reserved_until && (int) $row->attempts > 0, 'provider_claimed_lease');
        $control = json_decode(file_get_contents($GLOBALS['ql_run'] . '/provider.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($control['order'] ?? null) === $sn && ($control['mode'] ?? '') === 'pause') {
            $gate = $control['gate'];
            if (!in_array($gate, ['D', 'E'], true)) throw new RuntimeException('Unknown provider gate.');
            $ready = ['pid' => getmypid(), 'outbox_id' => (int) $row->id, 'attempts' => (int) $row->attempts];
            ql_log('ProviderReady', $ready);
            ql_json($GLOBALS['ql_run'] . '/' . $gate . '.ready.json', $ready);
            $deadline = microtime(true) + 15;
            while (!is_file($GLOBALS['ql_run'] . '/' . $gate . '.release') && microtime(true) < $deadline) usleep(50000);
            if (!is_file($GLOBALS['ql_run'] . '/' . $gate . '.release')) throw new RuntimeException('Provider gate timed out.');
        }
        $status = (($control['order'] ?? null) === $sn && ($control['mode'] ?? '') === '503') ? 503 : 204;
        ql_log('ProviderResponse', ['order_sn' => $sn, 'outbox_id' => (int) $row->id, 'status' => $status,
            'job_id' => $GLOBALS['ql_current_job_id'] ?? null,
            'attempt' => (int) $row->attempts, 'transaction_level' => Illuminate\Support\Facades\DB::transactionLevel()]);
        $response = new GuzzleHttp\Psr7\Response($status);
        return class_exists(GuzzleHttp\Promise\Create::class)
            ? GuzzleHttp\Promise\Create::promiseFor($response)
            : GuzzleHttp\Promise\promise_for($response);
    })]));
}

function ql_schema()
{
    $schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
    $schema->create('jobs', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->bigIncrements('id'); $t->string('queue')->index(); $t->longText('payload');
        $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable();
        $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
    });
    $schema->create('failed_jobs', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->bigIncrements('id');
        if ($GLOBALS['ql_config']['candidate']) $t->string('uuid')->unique();
        $t->text('connection'); $t->text('queue'); $t->longText('payload'); $t->longText('exception');
        $t->timestamp('failed_at')->useCurrent();
    });
    require_once $GLOBALS['ql_config']['root'] . '/database/migrations/2026_01_01_000001_create_notification_outbox_table.php';
    require_once $GLOBALS['ql_config']['root'] . '/database/migrations/2026_01_01_000002_add_payload_to_notification_outbox.php';
    (new CreateNotificationOutboxTable())->up();
    (new AddPayloadToNotificationOutbox())->up();
    $schema->create('goods', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id'); $t->string('gd_name'); $t->string('api_hook');
        $t->integer('in_stock')->default(10); $t->integer('sales_volume')->default(0); $t->timestamps(); $t->softDeletes();
    });
    $schema->create('orders', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id'); $t->string('order_sn')->unique(); $t->integer('goods_id');
        $t->integer('type')->default(App\Models\Order::AUTOMATIC_DELIVERY);
        $t->integer('status')->default(App\Models\Order::STATUS_WAIT_PAY);
        $t->integer('buy_amount')->default(1); $t->decimal('actual_price', 10, 2)->default(10);
        $t->string('trade_no')->default(''); $t->string('title')->default('Fixture product');
        $t->string('email')->default('buyer@example.test'); $t->text('info')->nullable();
        $t->integer('coupon_id')->nullable(); $t->integer('coupon_ret_back')->default(0); $t->timestamps(); $t->softDeletes();
    });
    $schema->create('coupons', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id'); $t->integer('ret')->default(0); $t->timestamps(); $t->softDeletes();
    });
    $schema->create('coupons_goods', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->integer('goods_id'); $t->integer('coupons_id');
    });
    $schema->create('carmis', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id'); $t->integer('goods_id'); $t->integer('status')->default(1);
        $t->integer('is_loop')->default(0); $t->text('carmi'); $t->timestamps(); $t->softDeletes();
    });
    $schema->create('emailtpls', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->increments('id'); $t->string('tpl_token'); $t->string('tpl_name'); $t->text('tpl_content'); $t->timestamps(); $t->softDeletes();
    });
    Illuminate\Support\Facades\DB::table('goods')->insert(['id' => 1, 'gd_name' => 'Fixture product', 'api_hook' => 'https://fixture.invalid/hook']);
    foreach (['card_send_user_email', 'manual_send_manage_mail'] as $token) {
        Illuminate\Support\Facades\DB::table('emailtpls')->insert(['tpl_token' => $token, 'tpl_name' => 'Fixture {order_id}',
            'tpl_content' => '<p>{product_name}: {ord_info}; {order_id}</p>']);
    }
}

function ql_schedule($app)
{
    $schedule = $app->make(Illuminate\Console\Scheduling\Schedule::class);
    ql_check(count($schedule->events()) === 2, 'exact_production_schedule_events');
    foreach ($schedule->events() as $event) {
        if (!preg_match('/(?:^|\\s)(orders:expire|notifications:dispatch)$/D', $event->command, $match)) {
            throw new RuntimeException('Unknown scheduled event; no process spawned.');
        }
        $name = $match[1];
        ql_check($event->expression === '* * * * *' && $event->withoutOverlapping && !$event->runInBackground,
            'production_schedule_' . $name);
        $original = $event->command;
        $originalMutex = $event->mutexName();
        $GLOBALS['ql_original_mutexes'][$name] = $originalMutex;
        $cfg = $GLOBALS['ql_config'];
        $args = [$cfg['binary']];
        if ($cfg['ini'] !== '') array_push($args, '-c', $cfg['ini']);
        array_push($args, __DIR__ . '/console.php', '--run=' . $cfg['run'], '--marker=' . $cfg['marker']);
        if ($cfg['candidate']) $args[] = '--candidate';
        $args[] = '--role=schedule-event';
        $args[] = $name;
        // Event's real foreground Process is retained, including cron and mutex callbacks.
        $process = new Symfony\Component\Process\Process($args);
        $event->command = $process->getCommandLine();
        $event->before(function () use ($event) {
            ql_check($event->mutex->exists($event), 'actual_schedule_mutex_held_before_child');
        });
        $event->appendOutputTo($cfg['run'] . '/scheduled-' . str_replace(':', '-', $name) . '.stdout');
        ql_log('ScheduleRewrite', ['command' => $name, 'original' => $original, 'original_mutex' => $originalMutex,
            'actual' => $event->command, 'mutex' => $event->mutexName(), 'expression' => $event->expression]);
        ql_check(!$app['cache']->has($originalMutex) && !$app['cache']->has($event->mutexName()), 'schedule_mutex_initially_clear_' . $name);
    }
    return $schedule;
}
