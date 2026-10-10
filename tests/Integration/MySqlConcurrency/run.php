<?php
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/receipt.php';
require __DIR__ . '/legacy-paypal.php';

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Carmis;
use App\Models\Coupon;

function fixtureSchema($db): void
{
    $schema = $db->schema();
    foreach (['paypal_webhook_events', 'paypal_reconciliations', 'paypal_orders', 'stripe_webhook_events', 'stripe_reconciliations', 'stripe_payment_intents', 'payment_quotes', 'pays', 'notification_outbox', 'orders', 'carmis', 'goods', 'coupons', 'queue_probe', 'send_probe'] as $table) {
        $schema->dropIfExists($table);
    }
    $schema->create('goods', function (Blueprint $t) {
        $t->engine = 'InnoDB'; $t->increments('id');
        $t->string('gd_name')->default('Fixture'); $t->integer('in_stock')->default(10);
        $t->integer('sales_volume')->default(0); $t->timestamps(); $t->softDeletes();
    });
    $schema->create('orders', function (Blueprint $t) {
        $t->engine = 'InnoDB'; $t->increments('id'); $t->string('order_sn')->unique();
        $t->integer('goods_id'); $t->integer('type')->default(Order::AUTOMATIC_DELIVERY);
        $t->integer('status')->default(Order::STATUS_WAIT_PAY); $t->integer('buy_amount')->default(1);
        $t->decimal('actual_price', 10, 2)->default(10); $t->string('trade_no')->default('');
        $t->string('title')->default('Fixture'); $t->string('email')->nullable(); $t->text('info')->nullable();
        $t->integer('pay_id')->default(1);
        $t->integer('coupon_id')->nullable(); $t->integer('coupon_ret_back')->default(Order::COUPON_BACK_WAIT);
        $t->timestamps(); $t->softDeletes();
    });
    $schema->create('carmis', function (Blueprint $t) {
        $t->engine = 'InnoDB'; $t->increments('id'); $t->integer('goods_id')->index();
        $t->integer('status')->default(Carmis::STATUS_UNSOLD); $t->integer('is_loop')->default(0);
        $t->string('carmi'); $t->timestamps(); $t->softDeletes();
    });
    $schema->create('coupons', function (Blueprint $t) {
        $t->engine = 'InnoDB'; $t->increments('id'); $t->string('coupon')->unique();
        $t->integer('ret')->default(0); $t->integer('is_open')->default(Coupon::STATUS_OPEN);
        $t->integer('is_use')->default(Coupon::STATUS_USE); $t->timestamps(); $t->softDeletes();
    });
    $schema->create('queue_probe', function (Blueprint $t) {
        $t->engine = 'InnoDB'; $t->increments('id'); $t->unsignedBigInteger('outbox_id'); $t->string('token', 32);
    });
    $schema->create('send_probe', function (Blueprint $t) {
        $t->engine = 'InnoDB'; $t->increments('id'); $t->string('kind');
    });
    require_once dirname(__DIR__, 3) . '/database/migrations/2026_01_01_000001_create_notification_outbox_table.php';
    require_once dirname(__DIR__, 3) . '/database/migrations/2026_01_01_000002_add_payload_to_notification_outbox.php';
    (new CreateNotificationOutboxTable())->up();
    (new AddPayloadToNotificationOutbox())->up();
    fixtureCheck($schema->hasColumn('notification_outbox', 'payload'), 'forward-migration-payload');
    $schema->create('pays', function (Blueprint $t) {
        $t->engine = 'InnoDB'; $t->increments('id'); $t->string('pay_handleroute'); $t->string('pay_check')->default('fixture');
        $t->string('merchant_id')->default('pk_test_fixture'); $t->string('merchant_pem')->default('sk_test_fixture');
        $t->string('merchant_key')->default('whsec_fixture');
        $t->integer('is_open')->default(1); $t->timestamps(); $t->softDeletes();
    });
    DB::table('pays')->insert(['id' => 1, 'pay_handleroute' => '/pay/paypal']);
    require_once dirname(__DIR__, 3) . '/database/migrations/2026_01_01_000003_create_payment_quotes_table.php';
    (new CreatePaymentQuotesTable())->up();
    fixtureCheck($schema->hasColumn('payment_quotes', 'usd_amount'), 'forward-migration-quotes');
    DB::table('pays')->insert(['id' => 2, 'pay_handleroute' => '/pay/stripe']);
    require_once dirname(__DIR__, 3) . '/database/migrations/2026_01_01_000004_create_stripe_payment_intents_table.php';
    (new CreateStripePaymentIntentsTable())->up();
    fixtureCheck($schema->hasColumn('stripe_payment_intents', 'provider_id'), 'forward-migration-intents');
    require_once dirname(__DIR__, 3) . '/database/migrations/2026_01_01_000005_create_stripe_reconciliation_tables.php';
    (new CreateStripeReconciliationTables())->up();
    fixtureCheck($schema->hasColumn('stripe_reconciliations', 'lease_token') && $schema->hasColumn('stripe_webhook_events', 'processed_at'), 'forward-migration-reconciliation');
    require_once dirname(__DIR__, 3) . '/database/migrations/2026_01_01_000006_create_paypal_orders_table.php';
    (new CreatePaypalOrdersTable())->up();
    fixtureCheck($schema->hasColumn('paypal_orders', 'capture_key'), 'forward-migration-paypal-orders');
    DB::table('pays')->insert(['id' => 3, 'pay_handleroute' => 'pay/paypal', 'merchant_id' => 'M123456789012',
        'merchant_key' => 'fixtureClient', 'merchant_pem' => 'fixtureSecret']);
    require_once dirname(__DIR__, 3) . '/database/migrations/2026_01_01_000007_create_paypal_reconciliation_tables.php';
    (new CreatePaypalReconciliationTables())->up();
    fixtureCheck($schema->hasColumn('paypal_webhook_events', 'resource_id') && $schema->hasColumn('paypal_reconciliations', 'lease_token'), 'forward-migration-paypal-reconciliation');
    echo "PASS forward-migrations\n";
}

function fixtureSeed(int $goods, array $sns, bool $coupon = false): void
{
    DB::table('goods')->insert(['id' => $goods]);
    DB::table('carmis')->insert(['goods_id' => $goods, 'carmi' => 'synthetic-card-' . $goods]);
    if ($coupon) { DB::table('coupons')->insert(['id' => $goods, 'coupon' => 'synthetic-coupon-' . $goods]); }
    foreach ($sns as $sn) {
        DB::table('orders')->insert(['order_sn' => $sn, 'goods_id' => $goods,
            'coupon_id' => $coupon ? $goods : null, 'created_at' => date('Y-m-d H:i:s', time() - 3600)]);
    }
}

function fixtureObserver(): PDO
{
    // Only the coordinator uses the new local instance's admin account to observe lock waits.
    $pdo = new PDO('mysql:host=127.0.0.1;port=13316;dbname=dujiaoka_concurrency_fixture', 'root', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    fixtureCheck($pdo->query('SELECT value FROM fixture_marker')->fetchColumn() === 'dujiaoka-isolated-concurrency-v1', 'observer-marker');
    $actual = strtolower(str_replace('\\', '/', $pdo->query('SELECT @@datadir')->fetchColumn()));
    $expected = strtolower(str_replace('\\', '/', dirname(__DIR__, 2) . '/.runtime/mysql-fixture-data/'));
    fixtureCheck($actual === $expected, 'observer-datadir');
    return $pdo;
}

function fixtureRace(string $root, string $name, array $tasks, string $table, array $ids, ?callable $beforeRelease = null, bool $afterProviderRead = false): array
{
    $dir = $root . '/' . $name;
    fixtureCheck(mkdir($dir, 0700), 'race-directory');
    $children = [];
    $results = [];
    try {
        if (!$afterProviderRead) {
            DB::beginTransaction();
            DB::table($table)->whereIn('id', $ids)->lockForUpdate()->get();
        }
        foreach ($tasks as $i => $task) {
            $path = $dir . '/' . $i;
            file_put_contents($path . '.task', json_encode($task));
            // PHP 7.4 array commands avoid shells; file descriptors avoid pipe deadlocks.
            $command = [PHP_BINARY];
            if (php_ini_loaded_file()) { $command[] = '-c'; $command[] = php_ini_loaded_file(); }
            $command = array_merge($command, [__DIR__ . '/worker.php', '--enable-isolated-mysql', $dir, (string) $i]);
            if (in_array('--candidate', $GLOBALS['argv'], true)) $command[] = '--candidate';
            $p = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['file', $path . '.stdout', 'w'], 2 => ['file', $path . '.stderr', 'w']], $pipes, __DIR__, null, ['bypass_shell' => true]);
            fixtureCheck(is_resource($p), 'worker-start');
            $children[$i] = $p;
        }
        fixtureWait(function () use ($children, $dir) {
            foreach ($children as $i => $p) {
                fixtureCheck(proc_get_status($p)['running'], 'worker-exited-before-ready');
                if (!is_file($dir . '/' . $i . '.ready')) { return false; }
            }
            return true;
        }, 'ready-timeout');
        touch($dir . '/gate');
        fixtureWait(function () use ($children, $dir) {
            foreach ($children as $i => $p) {
                if (!is_file($dir . '/' . $i . '.entered')) { return false; }
            }
            return true;
        }, 'entry-timeout', 5);
        if ($afterProviderRead) {
            // Let real quote lookup finish, then hold the order before verified GETs reach reservation/fulfillment.
            fixtureWait(function () use ($dir, $children) {
                return count(glob($dir . '/*.provider-read')) === count($children);
            }, 'provider-read-barrier', 10);
            DB::beginTransaction();
            DB::table($table)->whereIn('id', $ids)->lockForUpdate()->get();
            touch($dir . '/provider-read-gate');
        }
        $connectionIds = [];
        foreach ($children as $i => $p) {
            $ready = json_decode(file_get_contents($dir . '/' . $i . '.ready'), true);
            fixtureCheck(is_array($ready) && $ready['connection_id'] > 0, 'connection-id-evidence');
            $connectionIds[] = (int) $ready['connection_id'];
        }
        $observer = fixtureObserver();
        $waitQuery = $observer->prepare("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT' AND trx_mysql_thread_id IN (" . implode(',', $connectionIds) . ')');
        try {
            fixtureWait(function () use ($waitQuery, $children) {
                $waitQuery->execute();
                return (int) $waitQuery->fetchColumn() === count($children);
            // INNODB_TRX needs more than 100ms without reads before its snapshot refreshes.
            }, 'innodb-lock-wait-not-observed', 5, 150000);
        } catch (RuntimeException $exception) {
            $states = $observer->query('SELECT trx_mysql_thread_id, trx_state, trx_operation_state FROM information_schema.innodb_trx'
                . ' WHERE trx_mysql_thread_id IN (' . implode(',', $connectionIds) . ')')->fetchAll(PDO::FETCH_ASSOC);
            $threads = $observer->query('SELECT ID, COMMAND, STATE FROM information_schema.processlist'
                . ' WHERE ID IN (' . implode(',', $connectionIds) . ')')->fetchAll(PDO::FETCH_ASSOC);
            file_put_contents($dir . '/lock-diagnostics.json', json_encode(['transactions' => $states, 'threads' => $threads]));
            throw $exception;
        }
        // Every independent connection is now confirmed waiting inside InnoDB.
        $until = microtime(true) + 0.5;
        do {
            foreach ($children as $i => $p) {
                fixtureCheck(proc_get_status($p)['running'] && !is_file($dir . '/' . $i . '.result'), 'lock-barrier-not-blocking');
            }
            usleep(20000);
        } while (microtime(true) < $until);
        $waitQuery->execute();
        $finalWaits = (int) $waitQuery->fetchColumn();
        fixtureCheck($finalWaits === count($children), 'innodb-lock-waits-not-persistent');
        file_put_contents($dir . '/lock-observed.json', json_encode([
            'workers' => count($children), 'held_seconds' => 0.5,
            'mysql_connection_ids' => $connectionIds, 'innodb_lock_waits' => count($children),
            'waits_after_held_interval' => $finalWaits, 'observer_poll_micros' => 150000,
        ]));
        if ($beforeRelease !== null) $beforeRelease();
        DB::commit();
        fixtureWait(function () use ($children, $dir) {
            foreach ($children as $i => $p) {
                if (!is_file($dir . '/' . $i . '.result')) { return false; }
            }
            return true;
        }, 'result-timeout', 20);
        $exits = [];
        fixtureWait(function () use ($children, &$exits) {
            foreach ($children as $i => $p) {
                if (array_key_exists($i, $exits)) { continue; }
                $status = proc_get_status($p);
                if (!$status['running']) { $exits[$i] = $status['exitcode']; }
            }
            return count($exits) === count($children);
        }, 'worker-exit-timeout', 3);
        foreach ($children as $i => $p) {
            fixtureCheck($exits[$i] === 0, 'worker-nonzero-exit-' . $name . '-' . $i);
            $result = json_decode(file_get_contents($dir . '/' . $i . '.result'), true);
            fixtureCheck(is_array($result) && $result['category'] !== 'error', 'worker-error-' . $name . '-' . $i);
            $results[] = $result;
        }
        echo 'PASS contention ' . $name . ' workers=' . count($children) . PHP_EOL;
        return $results;
    } finally {
        if (DB::transactionLevel() > 0) { DB::rollBack(); }
        foreach ($children as $p) {
            $deadline = microtime(true) + 1;
            while (proc_get_status($p)['running'] && microtime(true) < $deadline) { usleep(20000); }
            if (proc_get_status($p)['running']) { proc_terminate($p, 9); }
            proc_close($p);
        }
    }
}

$lock = null;
try {
    $db = fixtureBoot($argv);
    $evidence = dirname(__DIR__, 2) . '/.runtime/mysql-concurrency';
    if (!is_dir($evidence)) { fixtureCheck(mkdir($evidence, 0700, true), 'evidence-directory'); }
    $lock = fopen($evidence . '/.coordinator.lock', 'c');
    fixtureCheck($lock && flock($lock, LOCK_EX | LOCK_NB), 'coordinator-already-running');
    $root = $evidence . '/run-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
    fixtureCheck(mkdir($root, 0700), 'run-directory');
    fixtureSchema($db);

    fixtureSeed(1, ['same']);
    $id = (int) DB::table('orders')->where('order_sn', 'same')->value('id');
    fixtureRace($root, 'same-order', array_fill(0, 4, ['action' => 'pay', 'sn' => 'same', 'outbox' => true]), 'orders', [$id]);
    fixtureCheck((int) DB::table('orders')->where('id', $id)->value('status') === Order::STATUS_COMPLETED, 'same-order-status');
    fixtureCheck((int) DB::table('goods')->where('id', 1)->value('sales_volume') === 1, 'same-order-sales');
    fixtureCheck(DB::table('carmis')->where('goods_id', 1)->where('status', Carmis::STATUS_SOLD)->count() === 1, 'same-order-card');
    fixtureCheck(DB::table('notification_outbox')->count() === 1 && DB::table('queue_probe')->count() === 1, 'same-payment-single-intent');

    fixtureSeed(2, ['card-a', 'card-b']);
    $cardId = (int) DB::table('carmis')->where('goods_id', 2)->value('id');
    fixtureRace($root, 'one-card', [['action' => 'pay', 'sn' => 'card-a'], ['action' => 'pay', 'sn' => 'card-b']], 'carmis', [$cardId]);
    $statuses = DB::table('orders')->where('goods_id', 2)->pluck('status')->map(function ($s) { return (int) $s; })->sort()->values()->all();
    fixtureCheck($statuses === [Order::STATUS_COMPLETED, Order::STATUS_ABNORMAL], 'one-card-statuses');
    $card = DB::table('carmis')->where('id', $cardId)->first();
    fixtureCheck((int) $card->status === Carmis::STATUS_SOLD && DB::table('orders')->where('goods_id', 2)->where('info', $card->carmi)->count() === 1, 'one-card-no-duplicate');

    // Multiple races plus a deterministic expired legacy case cover either winning schedule.
    for ($g = 3; $g <= 5; ++$g) {
        $sn = 'expiry-' . $g;
        fixtureSeed($g, [$sn], true);
        $id = (int) DB::table('orders')->where('order_sn', $sn)->value('id');
        fixtureRace($root, $sn, [['action' => 'pay', 'sn' => $sn], ['action' => 'expire', 'sn' => $sn], ['action' => 'expire', 'sn' => $sn], ['action' => 'return', 'sn' => $sn]], 'orders', [$id]);
        $order = DB::table('orders')->where('id', $id)->first();
        $paid = (int) $order->status === Order::STATUS_COMPLETED;
        fixtureCheck($paid || (int) $order->status === Order::STATUS_ABNORMAL, 'expiry-final-state');
        fixtureCheck((int) DB::table('coupons')->where('id', $g)->value('ret') === ($paid ? 0 : 1), 'coupon-return-once');
        fixtureCheck((int) $order->coupon_ret_back === ($paid ? Order::COUPON_BACK_WAIT : Order::COUPON_BACK_OK), 'coupon-return-marker');
        fixtureCheck((int) DB::table('carmis')->where('goods_id', $g)->value('status') === ($paid ? Carmis::STATUS_SOLD : Carmis::STATUS_UNSOLD), 'late-payment-no-card');
        fixtureCheck((int) DB::table('goods')->where('id', $g)->value('sales_volume') === ($paid ? 1 : 0), 'expiry-sales');
    }
    fixtureSeed(6, ['legacy-late'], true);
    DB::table('orders')->where('order_sn', 'legacy-late')->update(['status' => Order::STATUS_EXPIRED]);
    $id = (int) DB::table('orders')->where('order_sn', 'legacy-late')->value('id');
    fixtureRace($root, 'late-coupon', [['action' => 'pay', 'sn' => 'legacy-late'], ['action' => 'return', 'sn' => 'legacy-late'], ['action' => 'return', 'sn' => 'legacy-late']], 'orders', [$id]);
    fixtureCheck((int) DB::table('coupons')->where('id', 6)->value('ret') === 1, 'late-coupon-once');
    fixtureCheck((int) DB::table('orders')->where('id', $id)->value('status') === Order::STATUS_ABNORMAL, 'late-abnormal');
    fixtureCheck((int) DB::table('carmis')->where('goods_id', 6)->value('status') === Carmis::STATUS_UNSOLD, 'late-card-untouched');

    config(['dujiaoka.api_hook_outbox' => true]);
    // Simulate the one captured payment job being lost, then race compensation publishers.
    DB::table('queue_probe')->delete();
    DB::table('notification_outbox')->update(['reservation_token' => null, 'reserved_until' => null]);
    $outboxId = (int) DB::table('notification_outbox')->value('id');
    $published = fixtureRace($root, 'publishers', array_fill(0, 4, ['action' => 'publish']), 'notification_outbox', [$outboxId]);
    fixtureCheck(DB::table('queue_probe')->count() === 1, 'single-publication');
    fixtureCheck(array_sum(array_map(function ($r) { return $r['counts']['published']; }, $published)) === 1, 'publisher-results');
    fixtureCheck(array_sum(array_map(function ($r) { return $r['counts']['failed']; }, $published)) === 0, 'publisher-failures');
    $job = DB::table('queue_probe')->first();
    fixtureRace($root, 'deliveries', array_fill(0, 4, ['action' => 'deliver', 'outbox_id' => (int) $job->outbox_id, 'token' => $job->token]), 'notification_outbox', [$outboxId]);
    $outbox = DB::table('notification_outbox')->where('id', $outboxId)->first();
    fixtureCheck(DB::table('send_probe')->count() === 1 && $outbox->delivered_at !== null && $outbox->failed_at === null && (int) $outbox->attempts === 1, 'single-delivery');
    fixtureSeed(7, ['quote-race']);
    $quoteOrderId = (int) DB::table('orders')->where('order_sn', 'quote-race')->value('id');
    $quotes = fixtureRace($root, 'quotes', [
        ['action' => 'quote', 'sn' => 'quote-race', 'denominator' => '7'],
        ['action' => 'quote', 'sn' => 'quote-race', 'denominator' => '5'],
    ], 'orders', [$quoteOrderId]);
    $frozen = DB::table('payment_quotes')->where('order_id', $quoteOrderId)->first();
    fixtureCheck(DB::table('payment_quotes')->count() === 1 && $frozen !== null, 'quote-single-snapshot');
    fixtureCheck(count(glob($root . '/quotes/*.fetched')) === 2, 'quote-both-live-rates-fetched');
    fixtureCheck($quotes[0]['usd'] === $frozen->usd_amount && $quotes[1]['usd'] === $frozen->usd_amount
        && in_array($frozen->usd_amount, ['1.43', '2.00'], true), 'quote-both-workers-return-winner');
    fixtureSeed(8, ['intent-race']);
    DB::table('orders')->where('order_sn', 'intent-race')->update(['pay_id' => 2]);
    $intentOrderId = (int) DB::table('orders')->where('order_sn', 'intent-race')->value('id');
    $intents = fixtureRace($root, 'intents', array_fill(0, 2, ['action' => 'intent', 'sn' => 'intent-race']), 'orders', [$intentOrderId]);
    $binding = DB::table('stripe_payment_intents')->first();
    fixtureCheck(DB::table('stripe_payment_intents')->count() === 1 && $binding !== null, 'intent-single-binding');
    fixtureCheck($intents[0]['provider_id'] === $binding->provider_id && $intents[1]['provider_id'] === $binding->provider_id, 'intent-same-provider');
    $requests = array_map(function ($file) { return json_decode(file_get_contents($file), true); }, glob($root . '/intents/*.requested'));
    fixtureCheck(count($requests) === 2 && $requests[0] === $requests[1] && $requests[0]['key'] === $binding->reference, 'intent-same-creation-parameters');
    $inbox = fixtureRace($root, 'stripe-inbox', array_fill(0, 2, ['action' => 'inbox', 'provider_id' => $binding->provider_id]), 'pays', [2]);
    fixtureCheck(DB::table('stripe_webhook_events')->count() === 1 && array_sum(array_column($inbox, 'inserted')) === 1, 'stripe-inbox-single-receipt');
    DB::table('stripe_webhook_events')->update(['available_at' => date('Y-m-d H:i:s', time() + 3600)]);
    $reconcileId = DB::table('stripe_reconciliations')->insertGetId(['intent_id' => $binding->id, 'available_at' => date('Y-m-d H:i:s')]);
    $reconciled = fixtureRace($root, 'stripe-reconcile', array_fill(0, 2, ['action' => 'reconcile']), 'stripe_reconciliations', [$reconcileId]);
    fixtureCheck(array_sum(array_column(array_column($reconciled, 'counts'), 'completed')) === 1, 'stripe-one-reconciliation');
    fixtureCheck(count(glob($root . '/stripe-reconcile/*.retrieved')) === 1, 'stripe-one-provider-read');
    fixtureCheck((int) DB::table('goods')->where('id', 8)->value('sales_volume') === 1
        && (int) DB::table('orders')->where('id', $intentOrderId)->value('status') === Order::STATUS_COMPLETED
        && DB::table('stripe_reconciliations')->where('id', $reconcileId)->value('finished_at') !== null, 'stripe-one-delivery');
    fixtureSeed(9, ['paypal-race']);
    DB::table('orders')->where('order_sn', 'paypal-race')->update(['pay_id' => 3]);
    $paypalOrderId = (int) DB::table('orders')->where('order_sn', 'paypal-race')->value('id');
    $paypalCreated = fixtureRace($root, 'paypal-create', array_fill(0, 2, ['action' => 'paypal-create', 'sn' => 'paypal-race']), 'orders', [$paypalOrderId]);
    $paypal = DB::table('paypal_orders')->first();
    $paypalRequests = array_map(function ($file) { return json_decode(file_get_contents($file), true); }, glob($root . '/paypal-create/*.requested'));
    fixtureCheck(DB::table('paypal_orders')->count() === 1 && $paypalRequests[0] === $paypalRequests[1]
        && $paypalRequests[0]['key'] === $paypal->create_key, 'paypal-single-binding-and-create-request');
    $selection = fixtureRace($root, 'payment-selection', [['action' => 'select-payment', 'sn' => 'paypal-race', 'pay_id' => 2],
        ['action' => 'select-payment', 'sn' => 'paypal-race', 'pay_id' => 3]], 'orders', [$paypalOrderId]);
    fixtureCheck(count(array_filter($selection, function ($r) { return $r['category'] === 'locked'; })) === 1
        && (int) DB::table('orders')->where('id', $paypalOrderId)->value('pay_id') === 3, 'paypal-stripe-selection-exclusion');
    $paypalCapture = fixtureRace($root, 'paypal-capture', array_fill(0, 2, ['action' => 'paypal-capture', 'sn' => 'paypal-race']), 'orders', [$paypalOrderId]);
    $captures = array_map(function ($file) { return json_decode(file_get_contents($file), true); }, glob($root . '/paypal-capture/*.captured'));
    fixtureCheck(count($captures) === 1 && $captures[0]['key'] === $paypal->capture_key && $paypal->capture_key !== $paypal->create_key, 'paypal-one-capture-distinct-key');
    fixtureCheck((int) DB::table('goods')->where('id', 9)->value('sales_volume') === 1
        && (int) DB::table('orders')->where('id', $paypalOrderId)->value('status') === Order::STATUS_COMPLETED
        && DB::table('orders')->where('id', $paypalOrderId)->value('trade_no') === '3C679366HH908993F', 'paypal-one-actual-delivery');
    fixtureSeed(10, ['parallel-stripe']); fixtureSeed(11, ['parallel-paypal']);
    DB::table('orders')->where('order_sn', 'parallel-stripe')->update(['pay_id' => 2]);
    DB::table('orders')->where('order_sn', 'parallel-paypal')->update(['pay_id' => 3]);
    $parallelIds = DB::table('orders')->whereIn('order_sn', ['parallel-stripe', 'parallel-paypal'])->pluck('id')->all();
    $parallel = fixtureRace($root, 'parallel-bindings', [['action' => 'parallel-binding', 'sn' => 'parallel-stripe', 'pay_id' => 2],
        ['action' => 'parallel-binding', 'sn' => 'parallel-paypal', 'pay_id' => 3]], 'orders', $parallelIds);
    fixtureCheck(count(array_filter($parallel, function ($r) { return $r['category'] === 'parallel-binding'; })) === 2, 'independent-provider-bindings');
    fixtureSeed(12, ['paypal-reconciled']);
    $paypalReconcileOrder = (int) DB::table('orders')->where('order_sn', 'paypal-reconciled')->value('id');
    DB::table('orders')->where('id', $paypalReconcileOrder)->update(['pay_id' => 3]);
    $paypalCredentials = ['client_id' => 'fixtureClient', 'secret' => 'fixtureSecret', 'merchant_id' => 'M123456789012', 'mode' => 'sandbox'];
    $paypalReconcileBinding = DB::table('paypal_orders')->insertGetId(['order_id' => $paypalReconcileOrder, 'pay_id' => 3,
        'cny_amount' => '10.00', 'usd_amount' => '2.00', 'reference' => bin2hex(random_bytes(16)),
        'create_key' => bin2hex(random_bytes(16)), 'capture_key' => bin2hex(random_bytes(16)),
        'account_fingerprint' => hash('sha256', json_encode($paypalCredentials)), 'provider_id' => '6O190127TN364715T',
        'return_url' => 'https://fixture.test/return', 'cancel_url' => 'https://fixture.test/cancel',
        'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    $paypalInbox = fixtureRace($root, 'paypal-inbox', array_fill(0, 2, ['action' => 'paypal-inbox', 'sn' => 'paypal-reconciled']), 'pays', [3]);
    fixtureCheck(DB::table('paypal_webhook_events')->count() === 1 && array_sum(array_column($paypalInbox, 'inserted')) === 1, 'paypal-inbox-one-receipt');
    DB::table('paypal_webhook_events')->update(['available_at' => date('Y-m-d H:i:s', time() + 3600)]);
    $paypalReconcileState = DB::table('paypal_reconciliations')->insertGetId(['order_binding_id' => $paypalReconcileBinding, 'available_at' => date('Y-m-d H:i:s')]);
    $paypalReconciled = fixtureRace($root, 'paypal-reconcile', array_fill(0, 2, ['action' => 'paypal-reconcile', 'sn' => 'paypal-reconciled', 'binding_id' => $paypalReconcileBinding]), 'paypal_reconciliations', [$paypalReconcileState]);
    fixtureCheck(count(glob($root . '/paypal-reconcile/*.retrieved')) === 1, 'paypal-reconcile-one-order-get');
    fixtureCheck(count(array_filter($paypalReconciled, function ($r) { return $r['outcome'] === 'completed'; })) === 1, 'paypal-reconcile-one-completion');
    fixtureCheck((int) DB::table('goods')->where('id', 12)->value('sales_volume') === 1
        && (int) DB::table('orders')->where('id', $paypalReconcileOrder)->value('status') === Order::STATUS_COMPLETED
        && DB::table('orders')->where('id', $paypalReconcileOrder)->value('trade_no') === '4C679366HH908993F'
        && DB::table('paypal_reconciliations')->where('id', $paypalReconcileState)->value('finished_at') !== null, 'paypal-reconcile-one-actual-delivery');
    $receiptEvidence = fixtureReceiptRaces($root);
    $legacyEvidence = fixtureLegacyPaypalRaces($root);
    $server = DB::selectOne('SELECT VERSION() AS version, @@transaction_isolation AS isolation');
    $engines = DB::select("SELECT DISTINCT ENGINE AS engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'mc\\_%'");
    fixtureCheck(count($engines) === 1 && $engines[0]->engine === 'InnoDB', 'fixture-engines');
    $evidence = [
        'mysql' => $server->version, 'isolation' => $server->isolation,
        'php' => PHP_VERSION, 'framework' => Illuminate\Foundation\Application::VERSION,
        'dependency_tree' => in_array('--candidate', $argv, true) ? 'candidate' : 'root', 'receipt_guard' => $receiptEvidence, 'legacy_paypal' => $legacyEvidence['legacy_paypal'],
        'contention_groups' => count(glob($root . '/*/lock-observed.json')),
        'worker_processes' => count(glob($root . '/*/*.result')),
        'same_order_sales' => (int) DB::table('goods')->where('id', 1)->value('sales_volume'),
        'single_payment_intent' => DB::table('notification_outbox')->count(),
        'compensated_publications' => DB::table('queue_probe')->count(),
        'mock_http_sends' => DB::table('send_probe')->count(),
        'delivered_attempts' => (int) $outbox->attempts,
        'migration_payload_column' => true, 'migration_payment_quotes' => true,
        'frozen_quote_rows' => 1, 'frozen_usd_amount' => $frozen->usd_amount,
        'intent_rows' => 1, 'intent_same_creation_key_and_parameters' => true, 'migration_stripe_payment_intents' => true,
        'stripe_webhook_rows' => 1, 'stripe_reconcile_reads' => 1, 'stripe_reconcile_sales' => 1, 'migration_stripe_reconciliation' => true,
        'paypal_webhook_rows' => 1, 'paypal_reconcile_gets' => 1, 'paypal_reconcile_sales' => 1, 'migration_paypal_reconciliation' => true,
        'paypal_order_rows' => 1, 'paypal_one_capture' => true, 'paypal_sales' => 1, 'migration_paypal_orders' => true,
        'cross_gateway_exclusion' => true, 'independent_provider_bindings' => true, 'all_fixture_engines' => 'InnoDB',
    ];
    file_put_contents($root . '/summary.json', json_encode($evidence, JSON_PRETTY_PRINT));
    echo "PASS all assertions; worker evidence: " . basename($root) . PHP_EOL;
} catch (Throwable $e) {
    // Only harness-generated labels are printable; database exception messages are private.
    $label = get_class($e) === RuntimeException::class ? $e->getMessage() : get_class($e);
    fwrite(STDERR, 'FAIL ' . $label . ' at ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL);
    exit(1);
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}
