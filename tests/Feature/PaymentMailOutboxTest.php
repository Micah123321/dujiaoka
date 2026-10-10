<?php

namespace Tests\Feature;

use App\Exceptions\RuleValidationException;
use App\Jobs\DeliverNotification;
use App\Models\Carmis;
use App\Models\Order;
use App\Service\GoodsService;
use App\Service\NotificationOutboxService;
use App\Service\OrderProcessService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentMailOutboxTest extends TestCase
{
    use \Tests\Concerns\InteractsWithMailRuntime;

    const PAYLOAD_MIGRATION = 'database/migrations/2026_01_01_000002_add_payload_to_notification_outbox.php';

    private $bus;
    private $outbox;
    private $messages = [];
    private $levels = [];
    private $jobs = [];
    private $requests = [];
    private $cancel = false;
    private $sendFailure = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
        config(['dujiaoka.api_hook_outbox' => false, 'dujiaoka.payment_mail_outbox' => true]);
        require_once base_path(NotificationOutboxTest::MIGRATION);
        require_once base_path(self::PAYLOAD_MIGRATION);
        (new \CreateNotificationOutboxTable())->up();
        (new \AddPayloadToNotificationOutbox())->up();
        Schema::create('goods', function (Blueprint $t) {
            $t->increments('id'); $t->string('gd_name');
            $t->integer('in_stock')->default(2); $t->integer('sales_volume')->default(0);
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->increments('id'); $t->string('order_sn')->unique(); $t->integer('goods_id');
            $t->integer('type')->default(Order::AUTOMATIC_DELIVERY);
            $t->integer('status')->default(Order::STATUS_WAIT_PAY);
            $t->integer('buy_amount')->default(1); $t->decimal('actual_price', 10, 2)->default(10);
            $t->string('trade_no')->default(''); $t->string('title')->default('Fixture title');
            $t->string('email')->default('buyer@example.test'); $t->text('info')->nullable();
            $t->timestamps(); $t->softDeletes();
        });
        Schema::create('carmis', function (Blueprint $t) {
            $t->increments('id'); $t->integer('goods_id'); $t->integer('status')->default(Carmis::STATUS_UNSOLD);
            $t->integer('is_loop')->default(0); $t->text('carmi'); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('emailtpls', function (Blueprint $t) {
            $t->increments('id'); $t->string('tpl_token'); $t->string('tpl_name'); $t->text('tpl_content');
            $t->timestamps(); $t->softDeletes();
        });
        DB::table('goods')->insert(['id' => 1, 'gd_name' => 'Fixture product']);
        DB::table('orders')->insert(['order_sn' => 'MAIL', 'goods_id' => 1, 'created_at' => Carbon::now()]);
        DB::table('carmis')->insert(['goods_id' => 1, 'carmi' => 'CARD-SNAPSHOT-A']);
        $this->template('card_send_user_email', 'Delivery {order_id}');
        $this->template('manual_send_manage_mail', 'Manager {order_id}');
        Cache::forever('system-setting', ['driver' => 'array', 'from_address' => 'sender@example.test',
            'from_name' => 'Fixture', 'manage_email' => 'manager@example.test', 'text_logo' => 'Fixture shop']);
        $this->goodsHook('');
        $stack = HandlerStack::create(new MockHandler([new Response(204)]));
        $stack->push(Middleware::history($this->requests));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));
        $this->app['events']->listen(\Illuminate\Mail\Events\MessageSending::class, function () {
            if ((cache('system-setting')['driver'] ?? '') !== 'array') {
                throw new \LogicException('Fixture requires the isolated array mail transport.');
            }
            $this->levels[] = DB::transactionLevel();
            if ($this->sendFailure) {
                throw $this->transportException();
            }
            return $this->cancel ? false : null;
        });
        $this->app['events']->listen(\Illuminate\Mail\Events\MessageSent::class, function ($event) {
            $this->messages[] = $event->message;
        });
        $this->bus = $this->app->make(Dispatcher::class);
        $this->outbox = $this->app->make(NotificationOutboxService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function template(string $token, string $title): void
    {
        DB::table('emailtpls')->insert(['tpl_token' => $token, 'tpl_name' => $title,
            'tpl_content' => '<p>{product_name}: {ord_info}; {order_id}; {ord_price}; {created_at}</p>']);
    }

    private function goodsHook(string $url): void
    {
        $goods = \Mockery::mock(GoodsService::class)->makePartial();
        $goods->shouldReceive('detail')->andReturn((object) ['id' => 1, 'api_hook' => $url, 'gd_name' => 'Fixture product']);
        $this->app->instance('Service\GoodsService', $goods);
    }

    private function captureJobs(): void
    {
        $bus = \Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->andReturnUsing(function ($job) { $this->jobs[] = $job; return count($this->jobs); });
        $this->app->instance(Dispatcher::class, $bus);
    }

    private function pay(): Order
    {
        return (new MailOutboxOrderProcess())->completedOrder('MAIL', 10, 'trade');
    }

    private function previousSchema(): void
    {
        // Build the old empty fixture directly; Laravel 6 cannot drop SQLite columns with DBAL 3.
        Schema::drop('notification_outbox');
        (new \CreateNotificationOutboxTable())->up();
    }

    private function row()
    {
        return DB::table('notification_outbox')->where('channel', NotificationOutboxService::PAYMENT_MAIL_CHANNEL)->first();
    }

    public function testAutoMailIsAcceptedAfterCommitAndDuplicateCallbackDoesNotSendAgain(): void
    {
        (new OrderProcessService())->completedOrder('MAIL', 10, 'trade');
        $this->assertSame([0], $this->levels);
        $this->assertCount(1, $this->messages);
        $this->assertSame('Delivery MAIL', $this->messages[0]->getSubject());
        $this->assertContains('buyer@example.test', $this->mailAddresses($this->messages[0], 'To'));
        $this->assertStringContainsString('CARD-SNAPSHOT-A', $this->mailBody($this->messages[0]));
        $this->assertStringContainsString('Fixture product', $this->mailBody($this->messages[0]));
        $this->assertNotNull($this->row()->delivered_at);
        $this->assertSame(1, (int) $this->row()->attempts);
        $this->assertNull($this->row()->payload);
        $this->assertInstanceOf(\Illuminate\Mail\Transport\ArrayTransport::class, $this->mailTransport());
        (new OrderProcessService())->completedOrder('MAIL', 10, 'trade');
        $this->assertCount(1, $this->messages);
        $this->assertSame(1, DB::table('notification_outbox')->count());
        $this->assertSame(Carmis::STATUS_SOLD, (int) DB::table('carmis')->value('status'));
    }

    public function testManualManageMailUsesManagerRecipientAndOriginalTemplate(): void
    {
        DB::table('orders')->update(['type' => Order::MANUAL_PROCESSING, 'info' => 'Manual fixture details']);
        $this->assertSame(Order::STATUS_PENDING, (int) $this->pay()->status);
        $this->assertSame('Manager MAIL', $this->messages[0]->getSubject());
        $this->assertContains('manager@example.test', $this->mailAddresses($this->messages[0], 'To'));
        $this->assertStringContainsString('Manual fixture details', $this->mailBody($this->messages[0]));
        $this->assertNotNull($this->row()->delivered_at);
    }

    public function testManualStatusMailsRemainOnEventPathWithOutboxEnabled(): void
    {
        $this->template('pending_order', 'Pending {order_id}');
        $this->template('completed_order', 'Completed {order_id}');
        DB::table('orders')->update(['type' => Order::MANUAL_PROCESSING, 'info' => 'Manual details']);
        (new OrderProcessService())->completedOrder('MAIL', 10, 'trade');
        $this->assertCount(2, $this->messages);
        $this->assertSame('Manager MAIL', $this->messages[0]->getSubject());
        $this->assertSame('Pending MAIL', $this->messages[1]->getSubject());
        $this->assertContains('manager@example.test', $this->mailAddresses($this->messages[0], 'To'));
        $this->assertContains('buyer@example.test', $this->mailAddresses($this->messages[1], 'To'));
        $order = Order::first();
        $order->status = Order::STATUS_COMPLETED;
        $order->save();
        $this->assertCount(3, $this->messages);
        $this->assertSame('Completed MAIL', $this->messages[2]->getSubject());
        $this->assertSame(1, DB::table('notification_outbox')->count());
        $this->assertSame([0, 0, 0], $this->levels);
    }

    public function testPublicationFailureKeepsEncryptedSnapshotAndRecovers(): void
    {
        $job = null;
        $bus = \Mockery::mock(Dispatcher::class);
        $bus->shouldReceive('dispatch')->once()->andReturnUsing(function ($value) use (&$job) {
            $job = $value;
            throw new \RuntimeException('PRIVATE SMTP credential');
        });
        $this->app->instance(Dispatcher::class, $bus);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $this->pay()->status);
        $this->assertSame(0, (int) $this->row()->attempts);
        $this->assertSame('Queue dispatch failed: RuntimeException', $this->row()->last_error);
        $this->assertStringNotContainsString('CARD-SNAPSHOT-A', $this->row()->payload);
        $this->assertStringNotContainsString('buyer@example.test', $this->row()->payload);
        $recipe = json_decode(Crypt::decryptString($this->row()->payload), true);
        $this->assertSame('buyer@example.test', $recipe['to']);
        $this->assertSame('CARD-SNAPSHOT-A', $recipe['data']['ord_info']);
        $this->assertInstanceOf(DeliverNotification::class, $job);
        $serialized = serialize($job);
        $this->assertStringNotContainsString('CARD-SNAPSHOT-A', $serialized);
        $this->assertStringNotContainsString('buyer@example.test', $serialized);
        $this->assertCount(0, $this->messages);
        $this->app->instance(Dispatcher::class, $this->bus);
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->assertSame(0, Artisan::call('notifications:dispatch'));
        $this->assertCount(1, $this->messages);
        $this->assertNotNull($this->row()->delivered_at);
    }

    public function testMissingTemplateKeepsPaymentAndRetriesAfterRepair(): void
    {
        DB::table('emailtpls')->where('tpl_token', 'card_send_user_email')->delete();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $this->pay()->status);
        $this->assertNull($this->row()->delivered_at);
        $this->assertSame('Delivery failed: TypeError', $this->row()->last_error);
        $this->assertSame(Carmis::STATUS_SOLD, (int) DB::table('carmis')->value('status'));
        $this->assertCount(0, $this->messages);
        $this->template('card_send_user_email', 'Repaired {order_id}');
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->outbox->publishDue();
        $this->assertNotNull($this->row()->delivered_at);
        $this->assertSame('Repaired MAIL', $this->messages[0]->getSubject());
    }

    public function testCanceledMailIsNotMarkedDeliveredAndCanRecover(): void
    {
        $this->cancel = true;
        $this->pay();
        $this->assertNull($this->row()->delivered_at);
        $this->assertSame('Delivery failed: RuntimeException', $this->row()->last_error);
        $this->assertCount(0, $this->messages);
        $this->assertInstanceOf(\Illuminate\Mail\Transport\ArrayTransport::class, $this->mailTransport());
        $this->cancel = false;
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->outbox->publishDue();
        $this->assertNotNull($this->row()->delivered_at);
        $this->assertCount(1, $this->messages);
    }

    public function testMailFailureIsRedactedAndRetryUsesRestoredTransport(): void
    {
        $this->sendFailure = true;
        $this->pay();
        $this->assertSame('Delivery failed: ' . class_basename($this->transportException()), $this->row()->last_error);
        $this->assertNull($this->row()->delivered_at);
        $this->assertInstanceOf(\Illuminate\Mail\Transport\ArrayTransport::class, $this->mailTransport());
        $this->sendFailure = false;
        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->outbox->publishDue();
        $this->assertNotNull($this->row()->delivered_at);
        $this->assertCount(1, $this->messages);
    }

    public function testZeroAcceptedRecipientsDoesNotMarkMailDelivered(): void
    {
        if ($this->usesSymfonyMail()) {
            $transport = \Mockery::mock(\Symfony\Component\Mailer\Transport\TransportInterface::class);
            $transport->shouldReceive('send')->once()->andReturnNull();
        } else {
            $transport = \Mockery::mock(\Swift_Transport::class);
            $transport->shouldReceive('isStarted')->andReturn(true);
            $transport->shouldReceive('stop')->once();
            $transport->shouldReceive('send')->once()->andReturnUsing(function ($message, &$failedRecipients) {
                $failedRecipients = ['buyer@example.test'];
                return 0;
            });
        }
        $this->useMailTransport($transport);
        $this->pay();
        $this->assertNull($this->row()->delivered_at);
        $this->assertSame('Delivery failed: RuntimeException', $this->row()->last_error);
        $this->assertSame(1, (int) $this->row()->attempts);
        $this->assertSame($transport, $this->mailTransport());
    }

    public function testTransportExceptionKeepsSnapshotAndRetryCanUseARepairedTransport(): void
    {
        $transport = \Mockery::mock($this->usesSymfonyMail()
            ? \Symfony\Component\Mailer\Transport\TransportInterface::class : \Swift_Transport::class);
        if (!$this->usesSymfonyMail()) {
            $transport->shouldReceive('isStarted')->andReturn(true);
            $transport->shouldReceive('stop')->once();
        }
        $transport->shouldReceive('send')->once()->andThrow($this->transportException());
        $this->useMailTransport($transport);
        $this->pay();
        $this->assertNull($this->row()->delivered_at);
        $this->assertNotNull($this->row()->payload);
        $this->assertSame('Delivery failed: ' . class_basename($this->transportException()), $this->row()->last_error);
        $this->assertSame(1, (int) $this->row()->attempts);
        $this->assertSame($transport, $this->mailTransport());
        $this->assertCount(0, $this->messages);
        $this->useMailTransport(new \Illuminate\Mail\Transport\ArrayTransport());
        Carbon::setTestNow(Carbon::parse($this->row()->available_at));
        $this->outbox->publishDue();
        $this->assertNotNull($this->row()->delivered_at);
        $this->assertNull($this->row()->payload);
        $this->assertSame(2, (int) $this->row()->attempts);
        $this->assertCount(1, $this->messages);
    }

    public function testEmptyRecipientEnvelopeIsNotMarkedDelivered(): void
    {
        if ($this->usesSymfonyMail()) {
            $transport = \Mockery::mock(\Symfony\Component\Mailer\Transport\TransportInterface::class);
            $transport->shouldReceive('send')->once()->andReturnUsing(function ($message) {
                $envelope = \Mockery::mock(\Symfony\Component\Mailer\Envelope::class);
                $envelope->shouldReceive('getRecipients')->andReturn([]);
                return new \Symfony\Component\Mailer\SentMessage($message, $envelope);
            });
        } else {
            $transport = \Mockery::mock(\Swift_Transport::class);
            $transport->shouldReceive('isStarted')->andReturn(true);
            $transport->shouldReceive('stop')->once();
            $transport->shouldReceive('send')->once()->andReturn(0);
        }
        $this->useMailTransport($transport);
        $this->pay();
        $this->assertNull($this->row()->delivered_at);
        $this->assertNotNull($this->row()->payload);
        $this->assertSame('Delivery failed: RuntimeException', $this->row()->last_error);
        $this->assertSame($transport, $this->mailTransport());
    }

    public function testExhaustedMailCanBeResetByOperatorAndSnapshotClearedOnAcceptance(): void
    {
        $this->cancel = true;
        $this->pay();
        for ($attempt = 1; $attempt < NotificationOutboxService::MAX_ATTEMPTS; ++$attempt) {
            Carbon::setTestNow(Carbon::parse($this->row()->available_at));
            $this->outbox->publishDue();
        }
        $this->assertNotNull($this->row()->failed_at);
        $this->assertSame(NotificationOutboxService::MAX_ATTEMPTS, (int) $this->row()->attempts);
        $this->assertNotNull($this->row()->payload);
        $this->assertSame(['published' => 0, 'failed' => 0], $this->outbox->publishDue());
        $this->assertSame(0, Artisan::call('notifications:retry', ['id' => $this->row()->id]));
        $this->cancel = false;
        $this->outbox->publishDue();
        $this->assertNotNull($this->row()->delivered_at);
        $this->assertNull($this->row()->payload);
        $this->assertCount(1, $this->messages);
    }

    public function testTamperedCiphertextIsNotSentAndFailureIsRedacted(): void
    {
        $this->captureJobs();
        $this->pay();
        DB::table('notification_outbox')->update(['payload' => 'TAMPERED CARD-SNAPSHOT-A buyer@example.test']);
        $this->jobs[0]->handle($this->outbox);
        $this->assertNull($this->row()->delivered_at);
        $this->assertSame('Delivery failed: DecryptException', $this->row()->last_error);
        $this->assertCount(0, $this->messages);
    }

    public function testQueuedRecipeKeepsRecipientAndContentAcrossLaterEdits(): void
    {
        $this->captureJobs();
        $this->pay();
        DB::table('orders')->update(['email' => 'changed@example.test', 'info' => 'CHANGED-CONTENT', 'deleted_at' => Carbon::now()]);
        DB::table('goods')->update(['gd_name' => 'CHANGED-PRODUCT', 'deleted_at' => Carbon::now()]);
        Cache::forever('system-setting', ['driver' => 'array', 'from_address' => 'changed-sender@example.test']);
        $this->jobs[0]->handle($this->outbox);
        $this->jobs[0]->handle($this->outbox);
        $this->assertCount(1, $this->messages);
        $this->assertContains('buyer@example.test', $this->mailAddresses($this->messages[0], 'To'));
        $this->assertContains('changed-sender@example.test', $this->mailAddresses($this->messages[0], 'From'));
        $this->assertStringContainsString('CARD-SNAPSHOT-A', $this->mailBody($this->messages[0]));
        $this->assertStringNotContainsString('CHANGED-', $this->mailBody($this->messages[0]));
        $this->assertNotNull($this->row()->delivered_at);
    }

    public function testChannelSwitchDoesNotPublishDisabledWebhooks(): void
    {
        config(['dujiaoka.api_hook_outbox' => true]);
        $this->goodsHook('https://fixture.invalid/hook');
        $this->captureJobs();
        $this->pay();
        $this->assertCount(2, $this->jobs);
        config(['dujiaoka.api_hook_outbox' => false]);
        Carbon::setTestNow(Carbon::now()->addSeconds(NotificationOutboxService::LEASE_SECONDS + 1));
        $this->assertSame(['published' => 1, 'failed' => 0], $this->outbox->publishDue());
        $this->assertCount(3, $this->jobs);
        $this->jobs[2]->handle($this->outbox);
        $this->jobs[1]->handle($this->outbox);
        $this->assertCount(1, $this->messages);
        $this->assertCount(0, $this->requests);
        $api = DB::table('notification_outbox')->where('channel', NotificationOutboxService::CHANNEL)->first();
        $this->assertSame(0, (int) $api->attempts);
    }

    public function testMissingPayloadMigrationRollsBackAndApiOnlyStillWorks(): void
    {
        $this->previousSchema();
        try {
            $this->pay();
            $this->fail('Missing payload column accepted');
        } catch (RuleValidationException $exception) {
            $this->assertStringContainsString('payload', $exception->getMessage());
        }
        $this->assertSame(Order::STATUS_WAIT_PAY, (int) Order::first()->status);
        $this->assertSame(Carmis::STATUS_UNSOLD, (int) DB::table('carmis')->value('status'));
        $this->assertSame(0, DB::table('notification_outbox')->count());
        config(['dujiaoka.payment_mail_outbox' => false, 'dujiaoka.api_hook_outbox' => true]);
        $this->goodsHook('https://fixture.invalid/hook');
        $this->pay();
        $this->assertSame(1, DB::table('notification_outbox')->count());
        $this->assertNotNull(DB::table('notification_outbox')->value('delivered_at'));
        $this->assertCount(1, $this->requests);
    }

    public function testPayloadMigrationRunsOnceAndPreservesExistingWebhookMetadata(): void
    {
        $this->previousSchema();
        DB::table('notification_outbox')->insert(['order_id' => 1, 'channel' => NotificationOutboxService::CHANNEL,
            'attempts' => 2, 'available_at' => Carbon::now()]);
        $options = ['--path' => [self::PAYLOAD_MIGRATION], '--force' => true];
        $this->assertSame(0, Artisan::call('migrate', $options));
        $this->assertSame(0, Artisan::call('migrate', $options));
        $this->assertTrue(Schema::hasColumn('notification_outbox', 'payload'));
        $this->assertSame(1, DB::table('migrations')->count());
        $this->assertSame(2, (int) DB::table('notification_outbox')->value('attempts'));
    }

    public function testCorruptSnapshotFailsWithoutSendingOrLeakingBody(): void
    {
        $this->captureJobs();
        $this->pay();
        DB::table('notification_outbox')->update(['payload' => Crypt::encryptString('INVALID JSON CARD-SNAPSHOT-A')]);
        $this->jobs[0]->handle($this->outbox);
        $this->assertNull($this->row()->delivered_at);
        $this->assertSame('Delivery failed: UnexpectedValueException', $this->row()->last_error);
        $this->assertCount(0, $this->messages);
    }

    public function testDisabledMailUsesLegacyPathWithoutNewTable(): void
    {
        config(['dujiaoka.payment_mail_outbox' => false]);
        Schema::drop('notification_outbox');
        (new OrderProcessService())->completedOrder('MAIL', 10, 'trade');
        $this->assertCount(1, $this->messages);
        $this->assertSame('Delivery MAIL', $this->messages[0]->getSubject());
        $this->assertSame([0], $this->levels);
        $this->assertSame(Order::STATUS_COMPLETED, (int) Order::first()->status);
    }
}

class MailOutboxOrderProcess extends OrderProcessService
{
    protected function notifyOrder(Order $order): void
    {
        // Isolate legacy status mail and push channels in the recovery fixtures.
    }
}
