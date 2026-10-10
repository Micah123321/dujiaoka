<?php

namespace Tests\Fixtures;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderStatusMailData
{
    public static function configure(string $runtime, bool $initialize): void
    {
        config(['dujiaoka.manual_status_mail_outbox' => true, 'dujiaoka.api_hook_outbox' => false,
            'dujiaoka.payment_mail_outbox' => false, 'queue.default' => 'database',
            'queue.connections.database.connection' => 'sqlite', 'queue.connections.database.table' => 'jobs',
            'queue.connections.database.queue' => 'default', 'queue.failed.driver' => 'database',
            'queue.failed.database' => 'sqlite', 'queue.failed.table' => 'failed_jobs']);
        if ($initialize) {
            foreach (['000001_create_notification_outbox_table', '000002_add_payload_to_notification_outbox',
                '000008_add_event_key_to_notification_outbox'] as $migration) {
                require_once dirname(__DIR__, 2) . '/database/migrations/2026_01_01_' . $migration . '.php';
            }
            (new \CreateNotificationOutboxTable())->up();
            (new \AddPayloadToNotificationOutbox())->up();
            (new \AddEventKeyToNotificationOutbox())->up();
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id'); $table->string('queue'); $table->longText('payload');
                $table->unsignedTinyInteger('attempts'); $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at'); $table->unsignedInteger('created_at');
            });
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->bigIncrements('id'); $table->text('connection'); $table->text('queue');
                $table->longText('payload'); $table->longText('exception'); $table->timestamp('failed_at')->useCurrent();
            });
            DB::table('orders')->where('id', 2)->update(['goods_id' => 2, 'type' => 2, 'status' => 3,
                'title' => 'Fixture Manual Status', 'email' => 'status-buyer@example.test', 'info' => 'INITIAL-STATUS-INFO']);
            foreach ([2 => ['status-editor', 'GET,PUT,PATCH'], 3 => ['status-reader', 'GET']] as $id => $role) {
                $stamp = ['created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')];
                DB::table('admin_users')->insert(array_merge($stamp, ['id' => $id, 'username' => 'fixture-' . $role[0],
                    'name' => 'Fixture ' . $role[0], 'password' => password_hash('fixture-password', PASSWORD_BCRYPT)]));
                DB::table('admin_roles')->insert(array_merge($stamp, ['id' => $id, 'slug' => 'fixture-' . $role[0], 'name' => $role[0]]));
                DB::table('admin_role_users')->insert(array_merge($stamp, ['role_id' => $id, 'user_id' => $id]));
                DB::table('admin_permissions')->insert(array_merge($stamp, ['id' => $id, 'parent_id' => 0, 'order' => $id,
                    'slug' => 'fixture-' . $role[0], 'name' => $role[0], 'http_method' => $role[1], 'http_path' => '/order,/order/*']));
                DB::table('admin_role_permissions')->insert(array_merge($stamp, ['role_id' => $id, 'permission_id' => $id]));
                DB::table('admin_role_menu')->insert(array_merge($stamp, ['role_id' => $id, 'menu_id' => 8]));
            }
            foreach (['pending_order' => 'Pending', 'completed_order' => 'Completed', 'failed_order' => 'Failed'] as $token => $title) {
                DB::table('emailtpls')->insert(['tpl_token' => $token, 'tpl_name' => $title . ' {order_id}',
                    'tpl_content' => '<p>{ord_title}; {ord_info}; {order_id}; {ord_price}; {webname}; {created_at}</p>']);
            }
            Cache::forever('system-setting', array_merge(cache('system-setting'), ['driver' => 'array',
                'from_address' => 'status-sender@example.test', 'from_name' => 'Fixture Status Mail']));
            foreach (['mail-events.jsonl', 'worker-events.jsonl', 'fail-next-mail'] as $file) {
                if (is_file($runtime . '/' . $file)) unlink($runtime . '/' . $file);
            }
        }
        $events = app('events');
        $events->listen(\Illuminate\Mail\Events\MessageSending::class, function () use ($runtime) {
            if ((cache('system-setting')['driver'] ?? '') !== 'array' || DB::transactionLevel() !== 0) {
                throw new \LogicException('Status fixture requires array mail after commit.');
            }
            if (is_file($runtime . '/fail-next-mail')) {
                unlink($runtime . '/fail-next-mail');
                throw new \RuntimeException('PRIVATE-STATUS-FIXTURE-ERROR');
            }
        });
        $events->listen(\Illuminate\Mail\Events\MessageSent::class, function ($event) use ($runtime) {
            $message = $event->message;
            $modern = method_exists($message, 'getHtmlBody');
            $addresses = $modern ? array_map(function ($address) { return $address->getAddress(); }, $message->getTo())
                : array_keys($message->getTo() ?: []);
            self::log($runtime, 'mail-events.jsonl', ['subject' => $message->getSubject(), 'to' => $addresses,
                'body' => $modern ? $message->getHtmlBody() : $message->getBody(), 'transactionLevel' => DB::transactionLevel(),
                'php' => PHP_VERSION, 'framework' => app()->version()]);
        });
        foreach ([\Illuminate\Queue\Events\JobProcessing::class => 'processing',
            \Illuminate\Queue\Events\JobProcessed::class => 'processed'] as $event => $phase) {
            $events->listen($event, function ($event) use ($runtime, $phase) {
                self::log($runtime, 'worker-events.jsonl', ['phase' => $phase, 'jobId' => $event->job->getJobId(),
                    'transactionLevel' => DB::transactionLevel(), 'connection' => $event->connectionName,
                    'php' => PHP_VERSION, 'framework' => app()->version()]);
            });
        }
    }

    private static function log(string $runtime, string $file, array $data): void
    {
        file_put_contents($runtime . '/' . $file, json_encode($data) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function state(string $runtime): array
    {
        $outbox = DB::table('notification_outbox')->orderBy('id')->get()->map(function ($row) {
            $values = (array) $row;
            $values['payloadHash'] = $row->payload === null ? null : hash('sha256', $row->payload);
            $values['payloadBytes'] = $row->payload === null ? 0 : strlen($row->payload);
            $values['privateDataInCiphertext'] = $row->payload !== null &&
                (strpos($row->payload, 'STATUS-INFO-') !== false || strpos($row->payload, 'status-buyer@example.test') !== false);
            unset($values['payload']);
            return $values;
        })->all();
        $jobs = DB::table('jobs')->orderBy('id')->get()->map(function ($row) {
            return ['id' => $row->id, 'attempts' => $row->attempts, 'payloadHash' => hash('sha256', $row->payload),
                'privateDataInPayload' => strpos($row->payload, 'STATUS-INFO-') !== false || strpos($row->payload, 'status-buyer@example.test') !== false];
        })->all();
        return ['php' => PHP_VERSION, 'framework' => app()->version(), 'binary' => PHP_BINARY, 'ini' => php_ini_loaded_file(),
            'database' => config('database.connections.sqlite.database'), 'storage' => app()->storagePath(),
            'enabled' => config('dujiaoka.manual_status_mail_outbox'),
            'orders' => DB::table('orders')->orderBy('id')->get(['id', 'order_sn', 'type', 'status', 'title', 'info', 'email'])->all(),
            'outbox' => $outbox, 'jobs' => $jobs, 'failedJobs' => DB::table('failed_jobs')->count(),
            'mail' => self::lines($runtime . '/mail-events.jsonl'), 'worker' => self::lines($runtime . '/worker-events.jsonl')];
    }

    private static function lines(string $file): array
    {
        return is_file($file) ? array_map(function ($line) { return json_decode($line, true); }, file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
    }
}
