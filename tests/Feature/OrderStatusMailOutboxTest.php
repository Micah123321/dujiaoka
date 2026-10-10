<?php

namespace Tests\Feature;

use App\Events\OrderUpdated;
use App\Jobs\DeliverNotification;
use App\Models\Order;
use App\Service\GoodsService;
use App\Service\NotificationOutboxService;
use App\Service\OrderProcessService;
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderStatusMailOutboxTest extends TestCase
{
    use \Tests\Concerns\InteractsWithMailRuntime;

    const MIGRATION = 'database/migrations/2026_01_01_000008_add_event_key_to_notification_outbox.php';
    const CHANNEL = 'order-status-mail';
    private $outbox;
    private $bus;
    private $messages = [];
    private $levels = [];
    private $jobs = [];
    private $cancel = false;
    private $sendFailure = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
        config(['dujiaoka.api_hook_outbox' => false, 'dujiaoka.payment_mail_outbox' => false,
            'dujiaoka.manual_status_mail_outbox' => true]);
        require_once base_path(NotificationOutboxTest::MIGRATION);
        require_once base_path(PaymentMailOutboxTest::PAYLOAD_MIGRATION);
        $this->oldSchema();
        // The before run must exercise old production code against the intended SQL schema.
        Schema::table('notification_outbox', function (Blueprint $t) {
            $t->string('event_key', 64)->default('');
            $t->unique(['order_id', 'channel', 'event_key']);
            $t->dropUnique(['order_id', 'channel']);
        });
        Schema::create('goods', function (Blueprint $t) {
            $t->increments('id'); $t->string('gd_name'); $t->integer('in_stock')->default(5);
            $t->integer('sales_volume')->default(0); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->increments('id'); $t->string('order_sn')->unique(); $t->integer('goods_id');
            $t->integer('type')->default(Order::MANUAL_PROCESSING);
            $t->integer('status')->default(Order::STATUS_WAIT_PAY);
            $t->integer('buy_amount')->default(1); $t->decimal('actual_price', 10, 2)->default(10);
            $t->string('trade_no')->default(''); $t->string('title')->default('Frozen title');
            $t->string('email')->default('buyer@example.test'); $t->text('info')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('emailtpls', function (Blueprint $t) {
            $t->increments('id'); $t->string('tpl_token'); $t->string('tpl_name'); $t->text('tpl_content');
            $t->timestamps(); $t->softDeletes();
        });
        DB::table('goods')->insert(['id' => 1, 'gd_name' => 'Frozen product']);
        DB::table('orders')->insert(['order_sn' => 'STATUS', 'goods_id' => 1,
            'info' => 'PRIVATE-INFO-A' . PHP_EOL . 'PRIVATE-INFO-B', 'created_at' => Carbon::now()]);
        foreach (['pending_order', 'completed_order', 'failed_order', 'manual_send_manage_mail'] as $token) {
            $this->template($token);
        }
        Cache::forever('system-setting', ['driver' => 'array', 'from_address' => 'sender@example.test',
            'from_name' => 'Fixture', 'manage_email' => 'manager@example.test', 'text_logo' => 'Frozen shop']);
        $goods = \Mockery::mock(GoodsService::class)->makePartial();
        $goods->shouldReceive('detail')->andReturn((object) ['id' => 1, 'gd_name' => 'Frozen product', 'api_hook' => '']);
        $this->app->instance('Service\GoodsService', $goods);
        $this->app['events']->listen(\Illuminate\Mail\Events\MessageSending::class, function () {
            if ((cache('system-setting')['driver'] ?? '') !== 'array') {
                throw new \LogicException('Fixture requires the isolated array mail transport.');
            }
            $this->levels[] = DB::transactionLevel();
            if ($this->sendFailure) { throw $this->transportException(); }
            return $this->cancel ? false : null;
        });
        $this->app['events']->listen(\Illuminate\Mail\Events\MessageSent::class, function ($event) {
            $this->messages[] = $event->message;
        });
        $this->outbox = app(NotificationOutboxService::class);
        $this->bus = app(Dispatcher::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function oldSchema(): void
    {
        Schema::dropIfExists('notification_outbox');
        (new \CreateNotificationOutboxTable())->up();
        (new \AddPayloadToNotificationOutbox())->up();
    }

    private function template(string $token): void
    {
        DB::table('emailtpls')->insert(['tpl_token' => $token, 'tpl_name' => $token . ' {order_id}',
            'tpl_content' => '{ord_title}|{ord_info}|{ord_price}|{created_at}|{webname}|{weburl}']);
    }

    private function change(int $status): Order
    {
        $order = Order::first();
        $order->status = $status;
        $order->save();
        return $order;
    }

    private function rows()
    {
        return DB::table('notification_outbox')->where('channel', self::CHANNEL)->orderBy('id')->get();
    }

    private function row()
    {
        return $this->rows()->first();
    }

    private function captureJobs(): void
    {
        $bus = \Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(function ($job) { $this->jobs[] = $job; return count($this->jobs); });
        $this->app->instance(Dispatcher::class, $bus);
    }

    public function testSameInstanceUpdatingPreservesBothCommittedTransitions(): void
    {
        $once = false;
        Order::updating(function ($order) use (&$once) {
            if (!$once) {
                $once = true;
                $order->status = 4;
                $order->save();
                $order->status = 5;
            }
        });
        $saved = $this->change(4);
        $this->assertSame(5, (int) DB::table('orders')->value('status'));
        $this->assertCount(2, $this->rows());
        $templates = $this->rows()->map(function ($row) {
            return json_decode(Crypt::decryptString($row->payload), true)['template'];
        })->all();
        sort($templates);
        $this->assertSame(['completed_order', 'failed_order'], $templates);
        $this->assertFalse($saved->isStatusMailCaptureActive());
        $this->assertCount(0, $this->messages);
    }

    public function testSameInstanceUpdatedPreservesDeferredOuterListener(): void
    {
        $once = false;
        $this->app['events']->forget(OrderUpdated::class);
        $this->app['events']->listen(OrderUpdated::class, function ($event) use (&$once) {
            if (!$once) {
                $once = true;
                $event->order->status = 5;
                $event->order->save();
            }
        });
        $this->app['events']->listen(OrderUpdated::class, function ($event) {
            (new \App\Listeners\OrderUpdated())->handle($event);
        });
        $saved = $this->change(4);
        $this->assertSame(5, (int) DB::table('orders')->value('status'));
        $this->assertCount(2, $this->rows());
        $templates = $this->rows()->map(function ($row) {
            return json_decode(Crypt::decryptString($row->payload), true)['template'];
        })->all();
        sort($templates);
        $this->assertSame(['completed_order', 'failed_order'], $templates);
        $this->assertFalse($saved->isStatusMailCaptureActive());
        $this->assertCount(0, $this->messages);
    }

    public function testRolledBackEventCannotBorrowLaterSaveWindowOnSameModel(): void
    {
        $captured = null;
        $this->app['events']->listen(OrderUpdated::class, function ($event) use (&$captured) {
            if ($captured === null) $captured = $event;
            else $this->outbox->recordOrderStatus($captured);
        });
        DB::beginTransaction();
        $order = $this->change(4);
        DB::rollBack();
        $this->assertCount(0, $this->rows());
        $order->status = 5;
        $order->save();
        $this->assertSame(5, (int) DB::table('orders')->value('status'));
        $this->assertCount(1, $this->rows());
        $recipe = json_decode(Crypt::decryptString($this->row()->payload), true);
        $this->assertSame('failed_order', $recipe['template']);
        $this->assertNotSame($captured->eventKey, $this->row()->event_key);
        $this->assertCount(0, $this->messages);
    }

    public function testBeforeRollbackNeverSendsMail(): void
    {
        DB::beginTransaction();
        try { $this->change(Order::STATUS_COMPLETED); } finally { DB::rollBack(); }
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
        $this->assertCount(0, $this->messages);
        $this->assertCount(0, $this->rows());
    }

    public function testBeforeNonStatusEditNeverSendsMail(): void
    {
        DB::table('orders')->update(['status' => Order::STATUS_PENDING]);
        Order::first()->forceFill(['info' => 'Edited info', 'title' => 'Edited title'])->save();
        $this->assertCount(0, $this->messages);
        $this->assertCount(0, $this->rows());
    }

    public function testBeforeStatusUpdatePersistsIntentWithoutEarlyMail(): void
    {
        $this->change(Order::STATUS_PENDING);
        $this->assertCount(1, $this->rows());
        $this->assertCount(0, $this->messages);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testSameDifferentAndRepeatedTransitionsHaveDistinctKeys(): void
    {
        foreach ([2, 2, 4, 4, 5, 4] as $status) { $this->change($status); }
        $rows = $this->rows();
        $this->assertCount(4, $rows);
        $this->assertCount(4, array_unique($rows->pluck('event_key')->all()));
        $this->assertSame(['pending_order', 'completed_order', 'failed_order', 'completed_order'],
            $rows->map(function ($row) { return json_decode(Crypt::decryptString($row->payload), true)['template']; })->all());
        foreach ($rows as $row) { $this->assertSame(64, strlen($row->event_key)); }
        $this->assertCount(0, $this->messages);
    }

    public function testTwoStaleViewsConvergingToSameStatusCreateOnlyOneIntent(): void
    {
        $a = Order::first(); $b = Order::first();
        $a->status = 4; $a->save();
        $b->status = 4; $b->save();
        $this->assertCount(1, $this->rows());
        $b->status = 5; $b->save();
        $a->status = 5; $a->save();
        $this->assertCount(2, $this->rows());
    }

    public function testStaleSnapshotUsesPersistedBuyerTitleInfoAndType(): void
    {
        $order = Order::first();
        DB::table('orders')->update(['email' => 'current@example.test', 'title' => 'Current title', 'info' => 'Current info']);
        $order->status = 4; $order->save();
        $recipe = json_decode(Crypt::decryptString($this->row()->payload), true);
        $this->assertSame('current@example.test', $recipe['to']);
        $this->assertSame('Current title', $recipe['data']['ord_title']);
        $this->assertSame('Current info', $recipe['data']['ord_info']);
        $order = Order::first();
        DB::table('orders')->update(['type' => Order::AUTOMATIC_DELIVERY]);
        $order->status = 5; $order->save();
        $this->assertCount(1, $this->rows());
    }

    public function testStaleNonStatusEditDoesNotRevertOrNotifyStoredStatus(): void
    {
        $stale = Order::first();
        $this->change(4);
        $stale->title = 'Title only'; $stale->save();
        $this->assertSame(4, (int) Order::first()->status);
        $this->assertCount(1, $this->rows());
    }

    public function testCapturedEventIsStableAndRepeatedDispatchIsIdempotent(): void
    {
        $captured = null;
        $this->app['events']->listen(OrderUpdated::class, function ($event) use (&$captured) { $captured = $event; });
        $order = $this->change(4);
        $this->assertSame(['from' => 1, 'to' => 4], $captured->statusTransition);
        $key = $captured->eventKey;
        $snapshot = $captured->snapshot;
        DB::table('orders')->update(['email' => 'changed@example.test', 'info' => 'Changed info']);
        $order->status = 5;
        $order->info = 'Later model info';
        event($captured); event($captured);
        $this->assertSame($key, $captured->eventKey);
        $this->assertSame($snapshot, $captured->snapshot);
        $this->assertCount(1, $this->rows());
        $this->assertSame($key, $this->row()->event_key);
        $this->assertSame($snapshot, json_decode(Crypt::decryptString($this->row()->payload), true));
    }

    public function testAuditRollbackEventReplayOutsideCaptureWindow(): void
    {
        $captured = null;
        $this->app['events']->listen(OrderUpdated::class, function ($event) use (&$captured) { $captured = $event; });
        DB::beginTransaction();
        try { $this->change(4); } finally { DB::rollBack(); }
        $this->assertSame(['from' => 1, 'to' => 4], $captured->statusTransition);
        $this->assertSame(1, (int) Order::first()->status);
        $this->assertCount(0, $this->rows());
        event($captured);
        $counts = [$this->rows()->count()];
        DB::beginTransaction();
        try { event($captured); $counts[] = $this->rows()->count(); } finally { DB::rollBack(); }
        $this->assertSame([0, 0], $counts);
        $this->assertCount(0, $this->messages);
    }

    public function testAuditUpdatingNestedSameStatusRecordsOnlyStoredTransition(): void
    {
        $once = false;
        Order::updating(function () use (&$once) {
            if (!$once) { $once = true; $this->change(4); }
        });
        $this->change(4);
        $this->assertSame(4, (int) Order::first()->status);
        $this->assertCount(1, $this->rows());
        $this->outbox->publishDue();
        $this->assertCount(1, $this->messages);
        $this->assertSame([0], $this->levels);
    }

    public function testAuditCaptureIsClosedBeforeSqlAndAfterSave(): void
    {
        $events = [];
        Order::updating(function ($order) use (&$events) { $events[] = new OrderUpdated($order); });
        Order::saved(function ($order) use (&$events) { $events[] = new OrderUpdated($order); });
        $order = $this->change(4);
        $events[] = new OrderUpdated($order);
        DB::transaction(function () use ($order, &$events) { $events[] = new OrderUpdated($order); });
        foreach ($events as $event) { $this->assertNull($event->statusTransition); event($event); }
        $this->assertCount(1, $this->rows());
    }

    public function testAuditMissingRowAndUnsavedModelHaveNoStatusCapture(): void
    {
        $new = (new Order())->forceFill(['order_sn' => 'UNSAVED', 'goods_id' => 1, 'status' => 4]);
        DB::transaction(function () use ($new) {
            $event = new OrderUpdated($new);
            $this->assertNull($event->statusTransition);
            event($event);
        });
        $order = Order::first();
        DB::table('orders')->delete();
        $captured = null;
        $this->app['events']->listen(OrderUpdated::class, function ($event) use (&$captured) { $captured = $event; });
        $order->status = 4; $order->save();
        $this->assertInstanceOf(OrderUpdated::class, $captured);
        $this->assertNull($captured->statusTransition);
        $this->assertSame(0, DB::table('orders')->count());
        $this->assertCount(0, $this->rows());
        $this->assertCount(0, $this->messages);
    }

    public function testAuditUpdatingNestedDifferentStatusesUseEachStoredBaseline(): void
    {
        $once = false;
        $captured = [];
        $this->app['events']->listen(OrderUpdated::class, function ($event) use (&$captured) {
            if ($event->statusTransition !== null) { $captured[] = $event; }
        });
        Order::updating(function () use (&$once) {
            if (!$once) {
                $once = true;
                $inner = $this->change(2);
                $inner->info = 'Later nested model info';
            }
        });
        $this->change(4);
        $this->assertSame([['from' => 1, 'to' => 2], ['from' => 2, 'to' => 4]],
            array_map(function ($event) { return $event->statusTransition; }, $captured));
        $this->assertCount(2, $this->rows());
        $this->assertSame('PRIVATE-INFO-A<br/>PRIVATE-INFO-B',
            json_decode(Crypt::decryptString($this->rows()[0]->payload), true)['data']['ord_info']);
        $this->outbox->publishDue();
        $this->assertSame(['pending_order STATUS', 'completed_order STATUS'],
            array_map(function ($message) { return $message->getSubject(); }, $this->messages));
        $this->assertSame([0, 0], $this->levels);
    }

    public function testAuditNestedUpdatingFailureRollsBackInnerIntent(): void
    {
        $once = false;
        $captured = null;
        $this->app['events']->listen(OrderUpdated::class, function ($event) use (&$captured) { $captured = $event; });
        Order::updating(function () use (&$once) {
            if (!$once) {
                $once = true;
                $this->change(2);
                throw new \RuntimeException('Nested updating failure');
            }
        });
        try { $this->change(4); $this->fail('Nested updating failure accepted'); }
        catch (\RuntimeException $e) { $this->assertSame('Nested updating failure', $e->getMessage()); }
        $this->assertSame(1, (int) Order::first()->status);
        event($captured);
        $this->assertCount(0, $this->rows());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertCount(0, $this->messages);
    }

    public function testAuditNestedUpdatedFailureRollsBackOuterSqlAndAllIntents(): void
    {
        $once = false;
        $captured = [];
        Order::updating(function () use (&$once) {
            if (!$once) { $once = true; $this->change(2); }
        });
        $this->app['events']->listen(OrderUpdated::class, function ($event) use (&$captured) {
            $captured[] = $event;
            if ($event->statusTransition['to'] === 4) {
                throw new \RuntimeException('Outer updated failure');
            }
        });
        try { $this->change(4); $this->fail('Outer updated failure accepted'); }
        catch (\RuntimeException $e) { $this->assertSame('Outer updated failure', $e->getMessage()); }
        $this->assertCount(2, $captured);
        $this->assertSame(1, (int) Order::first()->status);
        $this->assertCount(0, $this->rows());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertCount(0, $this->messages);
    }

    public function testAuditUpdatingVetoPreservesFalseAndOrderState(): void
    {
        Order::updating(function () { return false; });
        $order = Order::first();
        $order->status = 4;
        $this->assertFalse($order->save());
        $this->assertSame(1, (int) Order::first()->status);
        $this->assertCount(0, $this->rows());
        $this->assertNull((new OrderUpdated($order))->statusTransition);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testAutomaticAndUnrelatedStatusesDoNotRecord(): void
    {
        foreach ([3, 6, -1, 1] as $status) { $this->change($status); }
        DB::table('orders')->update(['type' => Order::AUTOMATIC_DELIVERY]);
        foreach ([2, 4, 5] as $status) { $this->change($status); }
        $this->assertCount(0, $this->rows());
        $this->assertCount(0, $this->messages);
    }

    public function testNestedOuterRollbackRemovesAllStatusIntentsAndSendsNothing(): void
    {
        $this->captureJobs();
        DB::beginTransaction();
        try {
            DB::transaction(function () { $this->change(2); $this->change(4); });
            $this->assertSame(1, DB::transactionLevel());
            $this->assertCount(2, $this->rows());
            $this->assertCount(0, $this->messages);
            $this->assertCount(0, $this->jobs);
        } finally { DB::rollBack(); }
        $this->assertCount(0, $this->rows());
        $this->assertSame(1, (int) Order::first()->status);
    }

    public function testBareSaveStorageFailureRollsBackOrderAndIntent(): void
    {
        $this->app->instance(NotificationOutboxService::class, new class extends NotificationOutboxService {
            public function recordOrderStatus(OrderUpdated $event): void
            {
                parent::recordOrderStatus($event);
                throw new \RuntimeException('Injected storage failure');
            }
        });
        try { $this->change(4); $this->fail('Storage failure accepted'); }
        catch (\RuntimeException $e) { $this->assertSame('Injected storage failure', $e->getMessage()); }
        $this->assertSame(1, (int) Order::first()->status);
        $this->assertCount(0, $this->rows());
        $this->assertCount(0, $this->messages);
    }

    public function testActualSqlStorageFailureRollsBackBareUpdateAndAllowsRetry(): void
    {
        DB::unprepared("CREATE TRIGGER status_intent_failure BEFORE INSERT ON notification_outbox " .
            "WHEN NEW.channel = 'order-status-mail' BEGIN SELECT RAISE(ABORT, 'Injected SQL failure'); END");
        $order = Order::first();
        $order->status = 4;
        try { $order->save(); $this->fail('SQL failure accepted'); }
        catch (\Illuminate\Database\QueryException $e) { $this->assertStringContainsString('Injected SQL failure', $e->getMessage()); }
        $this->assertSame(1, (int) Order::first()->status);
        $this->assertCount(0, $this->rows());
        $this->assertCount(0, $this->messages);
        DB::unprepared('DROP TRIGGER status_intent_failure');
        $order->save();
        $this->assertSame(4, (int) Order::first()->status);
        $this->assertCount(1, $this->rows());
    }

    public function testListenerPersistsInsideOrderTransactionWithoutDispatch(): void
    {
        $service = new class extends NotificationOutboxService {
            public $levels = [];
            public function recordOrderStatus(OrderUpdated $event): void
            {
                $this->levels[] = DB::connection($event->connectionName)->transactionLevel();
                parent::recordOrderStatus($event);
            }
        };
        $this->app->instance(NotificationOutboxService::class, $service);
        $this->captureJobs();
        $this->change(4);
        $this->assertSame([1], $service->levels);
        $this->assertCount(0, $this->jobs);
        $this->assertCount(0, $this->messages);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testMissingEventKeySchemaRollsBackBareUpdate(): void
    {
        $this->oldSchema();
        try { $this->change(4); $this->fail('Missing migration accepted'); }
        catch (\Illuminate\Database\QueryException $e) { $this->assertStringContainsString('event_key', $e->getMessage()); }
        $this->assertSame(1, (int) Order::first()->status);
        $this->assertSame(0, DB::table('notification_outbox')->count());
        $this->assertCount(0, $this->messages);
    }

    public function testNamedOrderConnectionOwnsUpdateAndIntentTransaction(): void
    {
        $order = Order::first()->setConnection('sqlite');
        config(['database.connections.unrelated_status' => config('database.connections.sqlite')]);
        $other = DB::connection('unrelated_status');
        $other->getSchemaBuilder()->create('orders', function (Blueprint $t) { $t->increments('id'); $t->integer('status'); });
        $other->getSchemaBuilder()->create('notification_outbox', function (Blueprint $t) { $t->increments('id'); });
        $other->table('orders')->insert(['id' => 1, 'status' => 5]);
        $other->table('notification_outbox')->insert(['id' => 1]);
        $saving = false;
        Order::saving(function () use (&$saving) { $saving = true; });
        DB::setDefaultConnection('unrelated_status');
        try {
            $order->status = 4;
            try { $order->save(); $this->fail('Non-default status mail connection accepted'); }
            catch (\LogicException $e) { $this->assertStringContainsString('default database connection', $e->getMessage()); }
            $this->assertFalse($saving);
            $this->assertSame(0, DB::connection('sqlite')->table('notification_outbox')->count());
            $this->assertSame(1, (int) DB::connection('sqlite')->table('orders')->value('status'));
            $this->assertSame(5, (int) $other->table('orders')->value('status'));
            $this->assertSame(1, $other->table('notification_outbox')->count());
        } finally { DB::setDefaultConnection('sqlite'); DB::purge('unrelated_status'); }
    }

    public function testAuditNamedNewOrderFailsBeforeInsert(): void
    {
        config(['database.connections.named_status' => config('database.connections.sqlite')]);
        $other = DB::connection('named_status');
        $other->getSchemaBuilder()->create('orders', function (Blueprint $t) { $t->increments('id'); $t->integer('status'); });
        $other->getSchemaBuilder()->create('notification_outbox', function (Blueprint $t) { $t->increments('id'); });
        $creating = false;
        Order::creating(function () use (&$creating) { $creating = true; });
        $order = (new Order())->setConnection('named_status');
        $order->status = 4;
        try {
            try { $order->save(); $this->fail('Non-default insert accepted'); }
            catch (\LogicException $e) { $this->assertStringContainsString('default database connection', $e->getMessage()); }
            $this->assertFalse($creating);
            $this->assertSame(0, $other->table('orders')->count());
            $this->assertSame(0, $other->table('notification_outbox')->count());
            $this->assertSame(1, (int) Order::first()->status);
            $this->assertCount(0, $this->rows());
        } finally { DB::purge('named_status'); }
    }

    public function testAuditDisabledStatusFlagAllowsNamedSaveWithLegacyFlags(): void
    {
        $order = Order::first()->setConnection('sqlite');
        config(['dujiaoka.manual_status_mail_outbox' => false, 'dujiaoka.payment_mail_outbox' => true,
            'dujiaoka.api_hook_outbox' => true,
            'database.connections.unrelated_status' => config('database.connections.sqlite')]);
        DB::setDefaultConnection('unrelated_status');
        try {
            $order->status = 4;
            $this->assertTrue(Order::withoutEvents(function () use ($order) { return $order->save(); }));
            $this->assertSame(4, (int) DB::connection('sqlite')->table('orders')->value('status'));
            $this->assertSame(0, DB::connection('sqlite')->table('notification_outbox')->count());
            $this->assertFalse(Schema::hasTable('orders'));
            $this->assertFalse(Schema::hasTable('notification_outbox'));
        } finally { DB::setDefaultConnection('sqlite'); DB::purge('unrelated_status'); }
    }

    public function testPaymentManagerAndPendingClientStayIndependentWithoutExplicitEventDuplicate(): void
    {
        config(['dujiaoka.payment_mail_outbox' => true]);
        $this->captureJobs();
        $order = (new OrderProcessService())->completedOrder('STATUS', 10, 'trade');
        $this->assertSame(2, (int) $order->status);
        $this->assertSame(2, DB::table('notification_outbox')->count());
        $this->assertCount(1, $this->rows());
        $deliveries = array_values(array_filter($this->jobs, function ($job) { return $job instanceof DeliverNotification; }));
        $this->assertCount(2, $deliveries);
        $this->assertCount(3, $this->jobs);
        $this->assertInstanceOf(\App\Jobs\ApiHook::class, $this->jobs[2]);
        $explicit = new OrderUpdated($order);
        $this->assertNull($explicit->statusTransition);
        event($explicit);
        (new OrderProcessService())->completedOrder('STATUS', 10, 'trade');
        $this->assertSame(2, DB::table('notification_outbox')->count());
        foreach ($deliveries as $job) { $job->handle($this->outbox); }
        $this->assertCount(2, $this->messages);
        $this->assertContains('manager@example.test', $this->mailAddresses($this->messages[0], 'To'));
        $this->assertContains('buyer@example.test', $this->mailAddresses($this->messages[1], 'To'));
        $this->assertSame([0, 0], $this->levels);
    }

    public function testPaymentStatusOnlyFlagKeepsLegacyManagerChannel(): void
    {
        (new OrderProcessService())->completedOrder('STATUS', 10, 'trade');
        $this->assertCount(1, $this->rows());
        $this->assertSame(1, DB::table('notification_outbox')->count());
        $this->assertCount(2, $this->messages);
        $this->assertSame('pending_order STATUS', $this->messages[0]->getSubject());
        $this->assertSame('manual_send_manage_mail STATUS', $this->messages[1]->getSubject());
    }

    public function testPaymentStatusStorageFailureRollsBackFulfillment(): void
    {
        $this->oldSchema();
        try { (new OrderProcessService())->completedOrder('STATUS', 10, 'trade'); $this->fail('Missing migration accepted'); }
        catch (\App\Exceptions\RuleValidationException $e) { $this->assertStringContainsString('event_key', $e->getMessage()); }
        $this->assertSame(1, (int) Order::first()->status);
        $this->assertSame(5, (int) DB::table('goods')->value('in_stock'));
        $this->assertSame(0, (int) DB::table('goods')->value('sales_volume'));
        $this->assertSame(0, DB::table('notification_outbox')->count());
        $this->assertCount(0, $this->messages);
    }

    public function testQueueFailureKeepsEncryptedSnapshotAndRecoversViaCommand(): void
    {
        $this->change(4);
        $bus = \Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('PRIVATE-INFO-A buyer@example.test'));
        $this->app->instance(Dispatcher::class, $bus);
        $this->assertSame(['published' => 0, 'failed' => 1], $this->outbox->publishDue());
        $this->assertSame('Queue dispatch failed: RuntimeException', $this->row()->last_error);
        $this->assertSame(0, (int) $this->row()->attempts);
        $this->assertNull($this->row()->reservation_token);
        $this->assertStringNotContainsString('PRIVATE-INFO-A', $this->row()->payload);
        $this->assertStringNotContainsString('buyer@example.test', $this->row()->payload);
        $this->app->instance(Dispatcher::class, $this->bus);
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->assertSame(0, Artisan::call('notifications:dispatch'));
        $this->assertStringContainsString('Published: 1; dispatch failed: 0', Artisan::output());
        $this->assertNotNull($this->row()->delivered_at);
        $this->assertNull($this->row()->payload);
        $this->assertSame([0], $this->levels);
    }

    public function testTransportFailureBackoffRetainsCiphertextAndRecovers(): void
    {
        $this->change(5);
        $payload = $this->row()->payload;
        $this->sendFailure = true;
        $this->outbox->publishDue();
        $this->assertNull($this->row()->delivered_at);
        $this->assertSame($payload, $this->row()->payload);
        $this->assertStringNotContainsString('PRIVATE', $this->row()->last_error);
        $this->assertSame(1, (int) $this->row()->attempts);
        $this->assertSame(Carbon::now()->addSeconds(60)->toDateTimeString(), $this->row()->available_at);
        $this->assertSame(['published' => 0, 'failed' => 0], $this->outbox->publishDue());
        $this->sendFailure = false;
        Carbon::setTestNow(Carbon::parse($this->row()->available_at));
        $this->outbox->publishDue();
        $this->assertCount(1, $this->messages);
        $this->assertSame(2, (int) $this->row()->attempts);
        $this->assertNull($this->row()->payload);
    }

    public function testMissingTemplateCanBeRestoredWithoutResavingOrder(): void
    {
        DB::table('emailtpls')->where('tpl_token', 'completed_order')->delete();
        $this->change(4);
        $payload = $this->row()->payload;
        $this->outbox->publishDue();
        $this->assertNull($this->row()->delivered_at);
        $this->assertSame($payload, $this->row()->payload);
        $this->assertCount(0, $this->messages);
        $this->template('completed_order');
        Carbon::setTestNow(Carbon::parse($this->row()->available_at));
        $this->outbox->publishDue();
        $this->assertCount(1, $this->messages);
        $this->assertNull($this->row()->payload);
    }

    public function testCancellationExhaustionOperatorRetryAndPayloadClear(): void
    {
        $this->change(2);
        $this->cancel = true;
        $this->assertFalse($this->outbox->retryFailed((int) $this->row()->id));
        for ($i = 0; $i < NotificationOutboxService::MAX_ATTEMPTS; ++$i) {
            Carbon::setTestNow(Carbon::parse($this->row()->available_at));
            $this->outbox->publishDue();
        }
        $this->assertNotNull($this->row()->failed_at);
        $this->assertSame(NotificationOutboxService::MAX_ATTEMPTS, (int) $this->row()->attempts);
        $this->assertNotNull($this->row()->payload);
        $this->assertSame('Delivery failed: RuntimeException', $this->row()->last_error);
        $this->assertCount(0, $this->messages);
        $this->assertSame(0, Artisan::call('notifications:retry', ['id' => $this->row()->id]));
        $this->cancel = false;
        $this->outbox->publishDue();
        $this->assertNotNull($this->row()->delivered_at);
        $this->assertNull($this->row()->payload);
        $this->assertFalse($this->outbox->retryFailed((int) $this->row()->id));
        $this->assertCount(1, $this->messages);
    }

    public function testSnapshotAndQueuedMetadataRemainFrozenAcrossLaterEdits(): void
    {
        $this->change(4);
        $recipe = json_decode(Crypt::decryptString($this->row()->payload), true);
        $this->assertSame('buyer@example.test', $recipe['to']);
        $this->assertSame('PRIVATE-INFO-A<br/>PRIVATE-INFO-B', $recipe['data']['ord_info']);
        $this->captureJobs(); $this->outbox->publishDue();
        $this->assertInstanceOf(DeliverNotification::class, $this->jobs[0]);
        $serialized = serialize($this->jobs[0]);
        foreach (['PRIVATE-INFO', 'buyer@example.test', 'Frozen title', 'completed_order'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        DB::table('orders')->update(['title' => 'Changed title', 'email' => 'changed@example.test',
            'info' => 'Changed info', 'deleted_at' => Carbon::now()]);
        DB::table('goods')->update(['gd_name' => 'Changed product']);
        Cache::forever('system-setting', ['driver' => 'array', 'from_address' => 'sender@example.test', 'text_logo' => 'Changed shop']);
        $copy = unserialize($serialized);
        $this->jobs[0]->handle($this->outbox); $copy->handle($this->outbox);
        $this->assertCount(1, $this->messages);
        $this->assertContains('buyer@example.test', $this->mailAddresses($this->messages[0], 'To'));
        $this->assertStringContainsString('Frozen title', $this->mailBody($this->messages[0]));
        $this->assertStringContainsString('Frozen shop', $this->mailBody($this->messages[0]));
        $this->assertStringContainsString('PRIVATE-INFO-A', $this->mailBody($this->messages[0]));
        $this->assertStringNotContainsString('Changed', $this->mailBody($this->messages[0]));
        $this->assertSame(1, (int) $this->row()->attempts);
    }

    public function testExpiredLeaseReusesTokenAndRejectsDuplicateCopies(): void
    {
        $this->change(4); $this->captureJobs(); $this->outbox->publishDue();
        $token = $this->row()->reservation_token;
        Carbon::setTestNow(Carbon::now()->addSeconds(NotificationOutboxService::LEASE_SECONDS + 1));
        $this->outbox->publishDue();
        $this->assertSame($token, $this->row()->reservation_token);
        $this->jobs[0]->handle($this->outbox); $this->jobs[1]->handle($this->outbox);
        $this->assertCount(1, $this->messages);
    }

    public function testStatusSwitchPausesPublishingClaimAndRetryIndependently(): void
    {
        $this->change(4); $this->captureJobs(); $this->outbox->publishDue();
        config(['dujiaoka.manual_status_mail_outbox' => false, 'dujiaoka.payment_mail_outbox' => true]);
        Carbon::setTestNow(Carbon::now()->addSeconds(NotificationOutboxService::LEASE_SECONDS + 1));
        $this->assertSame(['published' => 0, 'failed' => 0], $this->outbox->publishDue());
        $this->jobs[0]->handle($this->outbox);
        $this->assertSame(0, (int) $this->row()->attempts);
        $this->assertCount(0, $this->messages);
        DB::table('notification_outbox')->update(['failed_at' => Carbon::now()]);
        $this->assertFalse($this->outbox->retryFailed((int) $this->row()->id));
        config(['dujiaoka.manual_status_mail_outbox' => true]);
        $this->assertTrue($this->outbox->retryFailed((int) $this->row()->id));
    }

    public function testThreeChannelsPublishIndependentlyAndLegacyIntentsKeepEmptyKey(): void
    {
        config(['dujiaoka.api_hook_outbox' => true, 'dujiaoka.payment_mail_outbox' => true]);
        $goods = \Mockery::mock(GoodsService::class)->makePartial();
        $goods->shouldReceive('detail')->andReturn((object) ['id' => 1, 'api_hook' => 'https://fixture.invalid/hook']);
        $this->app->instance('Service\GoodsService', $goods);
        $this->captureJobs();
        (new OrderProcessService())->completedOrder('STATUS', 10, 'trade');
        $this->assertCount(3, $this->jobs);
        foreach (DB::table('notification_outbox')->get() as $row) {
            $this->assertSame($row->channel === self::CHANNEL ? 64 : 0, strlen($row->event_key));
        }
        Carbon::setTestNow(Carbon::now()->addSeconds(NotificationOutboxService::LEASE_SECONDS + 1));
        config(['dujiaoka.api_hook_outbox' => false, 'dujiaoka.payment_mail_outbox' => false]);
        $this->assertSame(['published' => 1, 'failed' => 0], $this->outbox->publishDue());
        config(['dujiaoka.api_hook_outbox' => true, 'dujiaoka.manual_status_mail_outbox' => false]);
        $this->assertSame(['published' => 1, 'failed' => 0], $this->outbox->publishDue());
        config(['dujiaoka.api_hook_outbox' => false, 'dujiaoka.payment_mail_outbox' => true]);
        $this->assertSame(['published' => 1, 'failed' => 0], $this->outbox->publishDue());
        $this->assertCount(6, $this->jobs);
        $this->assertCount(0, $this->messages);
    }

    public function testStrictTransportNonAcceptanceRetainsStatusPayload(): void
    {
        if ($this->usesSymfonyMail()) {
            $transport = \Mockery::mock(\Symfony\Component\Mailer\Transport\TransportInterface::class);
            $transport->shouldReceive('send')->once()->andReturn(null);
        } else {
            $transport = \Mockery::mock(\Swift_Transport::class);
            $transport->shouldReceive('isStarted')->andReturn(true);
            $transport->shouldReceive('stop')->once();
            $transport->shouldReceive('send')->once()->andReturn(0);
        }
        $this->useMailTransport($transport);
        $this->change(4);
        $this->outbox->publishDue();
        $this->assertNull($this->row()->delivered_at);
        $this->assertNotNull($this->row()->payload);
        $this->assertSame('Delivery failed: RuntimeException', $this->row()->last_error);
    }

    public function testDefaultStatusFlagIsFalse(): void
    {
        $configuration = require base_path('config/dujiaoka.php');
        $this->assertFalse($configuration['manual_status_mail_outbox']);
    }

    public function testDisabledFlagNeedsNeitherOutboxTableNorNewColumn(): void
    {
        config(['dujiaoka.manual_status_mail_outbox' => false]);
        Schema::drop('notification_outbox');
        $this->change(2);
        $this->assertCount(1, $this->messages);
        $this->assertSame(0, Artisan::call('notifications:dispatch'));
        $this->assertStringContainsString('Notification outbox is disabled.', Artisan::output());
        $this->oldSchema();
        config(['dujiaoka.payment_mail_outbox' => true]);
        $this->change(4);
        DB::transaction(function () { $this->outbox->recordPayment(Order::first()); });
        $this->assertFalse(Schema::hasColumn('notification_outbox', 'event_key'));
        $this->assertSame(1, DB::table('notification_outbox')->count());
    }

    public function testPublicationInsideTransactionIsPrevented(): void
    {
        DB::beginTransaction();
        try {
            $this->change(4);
            try { $this->outbox->publishDue(); $this->fail('Early publication accepted'); }
            catch (\LogicException $e) { $this->assertStringContainsString('committed transaction', $e->getMessage()); }
            $this->assertCount(0, $this->messages);
        } finally { DB::rollBack(); }
    }

    public function testStatusOnlySchedulerRegistersDispatchWithOverlapGuard(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $method = new \ReflectionMethod($kernel, 'schedule'); $method->setAccessible(true);
        $method->invoke($kernel, $schedule);
        $events = array_values(array_filter($schedule->events(), function ($event) {
            return strpos($event->command ?? '', 'notifications:dispatch') !== false;
        }));
        $this->assertNotEmpty($events);
        $this->assertSame('* * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    public function testMigrationPreservesLegacyRowsAndBothUniquenessContracts(): void
    {
        $this->oldSchema();
        DB::table('notification_outbox')->insert(['order_id' => 1, 'channel' => 'payment-mail',
            'payload' => 'legacy-ciphertext', 'attempts' => 2, 'available_at' => Carbon::now()]);
        $options = ['--path' => [self::MIGRATION], '--force' => true];
        $this->assertSame(0, Artisan::call('migrate', $options));
        $this->assertSame(0, Artisan::call('migrate', $options));
        $legacy = DB::table('notification_outbox')->first();
        $this->assertSame('', $legacy->event_key);
        $this->assertSame('legacy-ciphertext', $legacy->payload);
        $this->assertSame(2, (int) $legacy->attempts);
        $this->assertSame(1, DB::table('migrations')->count());
        foreach (['A', 'B'] as $key) {
            DB::table('notification_outbox')->insert(['order_id' => 1, 'channel' => self::CHANNEL,
                'event_key' => $key, 'available_at' => Carbon::now()]);
        }
        foreach (['payment-mail' => '', self::CHANNEL => 'A'] as $channel => $key) {
            try {
                DB::table('notification_outbox')->insert(['order_id' => 1, 'channel' => $channel,
                    'event_key' => $key, 'available_at' => Carbon::now()]);
                $this->fail('Duplicate intent accepted');
            } catch (\Illuminate\Database\QueryException $e) { $this->assertStringContainsString('UNIQUE', $e->getMessage()); }
        }
        $this->assertSame(3, DB::table('notification_outbox')->count());
    }

    public function testMigrationDownRetainsStatusRowsAndExplicitlyStopsSqliteLegacyDown(): void
    {
        require_once base_path(self::MIGRATION);
        $this->change(4);
        $payload = $this->row()->payload;
        try { (new \AddEventKeyToNotificationOutbox())->down(); $this->fail('Destructive down accepted'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('status', $e->getMessage()); }
        $this->assertSame($payload, $this->row()->payload);
        $this->assertTrue(Schema::hasColumn('notification_outbox', 'event_key'));
        DB::table('notification_outbox')->delete();
        try { (new \AddEventKeyToNotificationOutbox())->down(); $this->fail('Unsupported SQLite down accepted'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('SQLite', $e->getMessage()); }
        $this->assertTrue(Schema::hasColumn('notification_outbox', 'event_key'));
    }
}
