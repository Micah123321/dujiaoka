<?php

namespace App\Admin\Service;

use App\Admin\Support\OperationsAccess;
use App\Models\Order;
use App\Service\NotificationOutboxService;
use App\Service\PaypalReconciliationService;
use App\Service\StripeReconciliationService;
use Carbon\Carbon;
use Dcat\Admin\Admin;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OperationsService
{
    private $columns = [];
    private const STATES = ['all', 'pending', 'failed', 'completed', 'reserved', 'manual', 'expired', 'open', 'closed'];

    public function pageConfig(string $section): array
    {
        $this->section($section);
        abort_unless(Admin::user(), 401);
        $navigation = [];
        foreach (OperationsAccess::sections() as $key => $label) {
            if (OperationsAccess::allows($key, 'GET')) {
                $navigation[] = ['key' => $key, 'label' => $label, 'href' => OperationsAccess::url('operations/' . $key)];
            }
        }
        return ['section' => $section, 'userName' => (string) (Admin::user()->name ?: Admin::user()->username),
            'csrf' => csrf_token(), 'endpoints' => ['data' => OperationsAccess::url('operations/' . $section . '/data'),
                'action' => OperationsAccess::url('operations/' . $section . '/action')],
            'navigation' => $navigation, 'management' => OperationsAccess::management(),
            'canOperate' => OperationsAccess::allows($section, 'POST')];
    }

    public function data(string $section, array $input = []): array
    {
        $this->section($section);
        abort_unless(Admin::user(), 401);
        abort_unless(OperationsAccess::allows($section, 'GET'), 403);
        $query = $this->query($input);
        $module = $this->module($section);
        $data = ['section' => $section, 'module' => $module, 'cards' => [], 'columns' => [], 'rows' => [],
            'pagination' => ['page' => $query['page'], 'per_page' => $query['per_page'], 'total' => 0, 'last_page' => 1],
            'bulkActions' => [], 'features' => [], 'runtime' => []];
        if ($section === 'overview') {
            $this->overview($data, $query);
        } elseif ($section === 'stripe' || $section === 'paypal') {
            $this->payments($data, $query, $section);
        } elseif ($section === 'notifications') {
            $this->notifications($data, $query);
        } elseif ($section === 'quotes') {
            $this->quotes($data, $query);
        } else {
            $this->runtime($data, $query);
        }
        return $data;
    }

    public function action(string $section, array $input): array
    {
        $this->section($section);
        abort_unless(Admin::user(), 401);
        abort_unless(OperationsAccess::allows($section, 'POST'), 403);
        abort_if(array_diff(array_keys($input), ['action', 'id', '_token']), 422);
        $allowed = ['overview' => ['expire'], 'stripe' => ['retry', 'retry-event', 'reconcile'],
            'paypal' => ['retry', 'retry-event', 'reconcile'], 'notifications' => ['retry', 'publish'],
            'quotes' => [], 'runtime' => ['retry-job']];
        $action = $input['action'] ?? null;
        abort_unless(is_string($action) && in_array($action, $allowed[$section], true), 422);
        $single = in_array($action, ['retry', 'retry-event', 'retry-job'], true);
        abort_unless($single === array_key_exists('id', $input), 422);
        $id = $input['id'] ?? null;
        if ($single) {
            $integer = (is_int($id) || is_string($id)) && preg_match('/\A[1-9][0-9]{0,17}\z/', (string) $id);
            $uuid = $section === 'runtime' && is_string($id) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $id);
            abort_unless($integer || $uuid, 422);
        }
        $module = $this->module($section);
        abort_unless($module['installed'] && $module['enabled'], 409);
        if ($section === 'stripe' || $section === 'paypal') {
            $service = app($section === 'stripe' ? StripeReconciliationService::class : PaypalReconciliationService::class);
            if ($action === 'reconcile') {
                $result = $service->reconcileDue(25);
            } else {
                $table = $action === 'retry-event' ? $section . '_webhook_events' : $section . '_reconciliations';
                $key = $action === 'retry-event' ? 'id' : ($section === 'stripe' ? 'intent_id' : 'order_binding_id');
                $row = DB::table($table)->where($key, $id)->first([$key, 'failed_at', 'leased_until',
                    $action === 'retry-event' ? 'processed_at' : 'finished_at']);
                abort_unless($row, 404);
                abort_if($this->busy($row->leased_until) || $row->failed_at === null
                    || ($action === 'retry-event' && $row->processed_at !== null), 409);
                if ($action === 'retry') {
                    $binding = $section === 'stripe' ? 'stripe_payment_intents' : 'paypal_orders';
                    abort_unless(DB::table($binding)->where('id', $id)->exists(), 404);
                }
                $saved = $action === 'retry-event' ? $service->retryEvent((int) $id) : $service->retry((int) $id);
                abort_unless($saved, 409);
                $result = ['retried' => 1];
            }
        } elseif ($section === 'notifications') {
            $service = app(NotificationOutboxService::class);
            if ($action === 'publish') {
                $result = $service->publishDue(100);
            } else {
                $row = DB::table('notification_outbox')->where('id', $id)
                    ->first(['id', 'channel', 'reserved_until', 'failed_at', 'delivered_at']);
                abort_unless($row, 404);
                abort_if(!$this->channelEnabled($row->channel) || $this->busy($row->reserved_until)
                    || $row->failed_at === null || $row->delivered_at !== null, 409);
                // retryFailed owns the mutation and requires committed state; do not wrap it in a transaction.
                abort_unless($service->retryFailed((int) $id), 409);
                $result = ['retried' => 1];
            }
        } elseif ($section === 'overview') {
            abort_unless($this->has('orders', ['coupon_id', 'coupon_ret_back']), 409);
            $service = app('Service\\OrderService');
            $result = ['expired' => 0, 'failed' => 0];
            $orders = Order::query()->where('status', Order::STATUS_WAIT_PAY)
                ->where('created_at', '<=', Carbon::now()->subMinutes($service->expirationMinutes()))
                ->orderBy('id')->limit(100)->get(['id', 'order_sn']);
            foreach ($orders as $order) {
                try {
                    $result['expired'] += (int) $service->expiredOrderSN($order->order_sn);
                } catch (\Throwable $exception) {
                    ++$result['failed'];
                    Log::warning('admin.operations.expire', ['category' => 'expiration_failed']);
                }
            }
        } else {
            $result = $this->retryJob($id);
        }
        // Only service-defined numeric counters leave the action boundary.
        $summary = [];
        foreach (['retried', 'expired', 'failed', 'published', 'checked', 'completed', 'retry', 'manual', 'ignored'] as $key) {
            if (isset($result[$key]) && is_numeric($result[$key])) $summary[$key] = (int) $result[$key];
        }
        return ['message' => '操作已执行。', 'result' => $summary];
    }

    private function section(string $section): void
    {
        abort_unless(isset(OperationsAccess::sections()[$section]), 404);
    }

    private function query(array $input): array
    {
        abort_if(array_diff(array_keys($input), ['search', 'state', 'page', 'per_page']), 422);
        $search = $input['search'] ?? '';
        $state = $input['state'] ?? 'all';
        abort_unless(is_string($search) && ($search === '' || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $search)), 422);
        abort_unless(is_string($state) && in_array($state, self::STATES, true), 422);
        foreach (['page' => 1000000, 'per_page' => 50] as $key => $max) {
            if (isset($input[$key])) {
                abort_unless((is_int($input[$key]) || is_string($input[$key]))
                    && preg_match('/\A[1-9][0-9]{0,6}\z/', (string) $input[$key]) && (int) $input[$key] <= $max, 422);
            }
        }
        return ['search' => $search, 'state' => $state, 'page' => (int) ($input['page'] ?? 1),
            'per_page' => (int) ($input['per_page'] ?? 20)];
    }

    private function has(string $table, array $columns): bool
    {
        if (!array_key_exists($table, $this->columns)) {
            $this->columns[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        }
        return !array_diff($columns, $this->columns[$table]);
    }

    private function module(string $section): array
    {
        $installed = false;
        $enabled = true;
        if ($section === 'overview') {
            $installed = $this->has('orders', ['id', 'order_sn', 'status', 'actual_price', 'created_at', 'updated_at', 'deleted_at']);
        } elseif ($section === 'quotes') {
            $installed = $this->has('payment_quotes', ['id', 'order_id', 'pay_id', 'base_currency', 'currency', 'source', 'cny_amount', 'usd_amount', 'rate_date', 'created_at']);
            $enabled = (bool) config('dujiaoka.payment_quote_freeze', false);
        } elseif ($section === 'notifications') {
            $installed = $this->has('notification_outbox', ['id', 'order_id', 'channel', 'attempts', 'available_at',
                'reserved_until', 'delivered_at', 'failed_at', 'last_error', 'updated_at', 'created_at', 'reservation_token']);
            if (config('dujiaoka.payment_mail_outbox') || config('dujiaoka.manual_status_mail_outbox')) {
                $installed = $installed && $this->has('notification_outbox', ['payload']);
            }
            if (config('dujiaoka.manual_status_mail_outbox')) $installed = $installed && $this->has('notification_outbox', ['event_key']);
            $enabled = (bool) (config('dujiaoka.api_hook_outbox') || config('dujiaoka.payment_mail_outbox') || config('dujiaoka.manual_status_mail_outbox'));
        } elseif ($section === 'stripe' || $section === 'paypal') {
            $table = $section === 'stripe' ? 'stripe_payment_intents' : 'paypal_orders';
            $key = $section === 'stripe' ? 'intent_id' : 'order_binding_id';
            $installed = $this->has($table, ['id', 'order_id', 'pay_id', 'provider_id', 'cny_amount', 'created_at', 'updated_at'])
                && $this->has($section . '_reconciliations', ['id', $key, 'last_status', 'checks', 'failures', 'available_at',
                    'checked_at', 'finished_at', 'failed_at', 'last_error', 'lease_token', 'leased_until', 'updated_at'])
                && $this->has($section . '_webhook_events', ['id', 'pay_id', 'event_id', 'provider_id', 'event_type', 'account_fingerprint',
                    'attempts', 'available_at', 'processed_at', 'failed_at', 'last_error', 'lease_token', 'leased_until', 'created_at', 'updated_at'])
                && $this->has('orders', ['id', 'order_sn']) && $this->has('pays', ['id', 'pay_handleroute']);
            $bindingColumns = $section === 'stripe' ? ['currency', 'amount_minor', 'reference', 'account_fingerprint']
                : ['usd_amount', 'reference', 'create_key', 'capture_key', 'account_fingerprint', 'capture_id',
                    'capture_attempted_at', 'capture_lease_token', 'capture_leased_until', 'return_url', 'cancel_url'];
            $installed = $installed && $this->has($table, $bindingColumns)
                && $this->has('pays', ['merchant_id', 'merchant_pem', 'merchant_key', 'is_open', 'deleted_at']);
            if ($section === 'paypal') $installed = $installed && $this->has('paypal_webhook_events', ['resource_id']);
            $enabled = (bool) config('dujiaoka.' . $section . '_reconciliation') && (bool) config('dujiaoka.payment_quote_freeze')
                && (bool) config('dujiaoka.' . ($section === 'stripe' ? 'stripe_payment_intents' : 'paypal_orders_v2'));
        } else {
            $installed = $this->failedSchema();
        }
        return ['installed' => (bool) $installed, 'enabled' => (bool) $enabled,
            'message' => !$installed ? '部分数据表或字段尚未安装；已有历史记录仅供查看。'
                : (!$enabled ? '模块已关闭；历史记录仅供查看。' : ($section === 'quotes' ? '冻结报价只读。' : '模块状态正常。'))];
    }

    private function card(string $key, string $label, $value, string $tone = 'neutral'): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'hint' => '', 'tone' => $tone];
    }

    private function columns(array $columns): array
    {
        $result = [];
        foreach ($columns as $key => $definition) $result[] = ['key' => $key, 'label' => $definition[0], 'format' => $definition[1]];
        return $result;
    }

    private function paginate($builder, array $query, array &$data, array $searchColumns = ['order_sn', 'provider_id', 'event_id']): array
    {
        if ($query['state'] !== 'all') $builder->where('status', $query['state']);
        if ($query['search'] !== '') {
            // Escape LIKE's wildcard characters; every value remains a bound parameter on both engines.
            $prefix = str_replace(['!', '_'], ['!!', '!_'], $query['search']) . '%';
            $builder->where(function ($q) use ($prefix, $searchColumns) {
                foreach ($searchColumns as $column) $q->orWhereRaw($column . " LIKE ? ESCAPE '!'", [$prefix]);
            });
        }
        $total = (clone $builder)->count();
        $data['pagination']['total'] = $total;
        $data['pagination']['last_page'] = max(1, (int) ceil($total / $query['per_page']));
        return $builder->orderBy('created_at', 'desc')->orderBy('id', 'desc')
            ->offset(($query['page'] - 1) * $query['per_page'])->limit($query['per_page'])->get()->all();
    }

    private function overview(array &$data, array $query): void
    {
        $data['columns'] = $this->columns(['order_sn' => ['订单号', 'text'], 'title' => ['商品', 'text'],
            'amount' => ['实付金额', 'money'], 'status' => ['状态', 'status'], 'created_at' => ['创建时间', 'time']]);
        $ready = $data['module']['installed'];
        $data['bulkActions'] = [['key' => 'expire', 'label' => '关闭过期订单', 'enabled' => $ready
            && $this->has('orders', ['coupon_id', 'coupon_ret_back']) && OperationsAccess::allows('overview', 'POST')]];
        if (!$ready) return;
        $base = DB::table('orders')->whereNull('deleted_at');
        $today = (clone $base)->where('created_at', '>=', Carbon::today())->where('created_at', '<', Carbon::tomorrow());
        $paid = [Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_COMPLETED];
        $data['cards'] = [$this->card('today_orders', '今日订单', (clone $today)->count()),
            $this->card('pending_orders', '待支付', (clone $base)->where('status', Order::STATUS_WAIT_PAY)->count(), 'warn'),
            $this->card('abnormal_orders', '异常订单', (clone $base)->whereIn('status', [Order::STATUS_FAILURE, Order::STATUS_ABNORMAL])->count(), 'warn'),
            $this->card('today_sales', '今日销售额（元）', (string) (clone $today)->whereIn('status', $paid)->sum('actual_price'), 'good')];
        foreach (['今日创建的全部订单', '尚未完成付款的订单', '失败或状态异常的订单', '今日已收款订单金额'] as $index => $hint) {
            $data['cards'][$index]['hint'] = $hint;
        }
        $status = "CASE WHEN status = 1 THEN 'pending' WHEN status IN (2,3) THEN 'open' WHEN status = 4 THEN 'completed' "
            . "WHEN status = -1 THEN 'expired' ELSE 'failed' END";
        $title = $this->has('orders', ['title']) ? 'title' : "'' as title";
        $rows = $this->paginate(DB::query()->fromSub((clone $base)->select(['id', 'order_sn', 'actual_price as amount',
            'created_at', DB::raw($title), DB::raw($status . ' as status')]), 'records'), $query, $data, ['order_sn']);
        foreach ($rows as $row) {
            $record = (array) $row;
            $record['actions'] = [];
            $data['rows'][] = $record;
        }
        $trend = (clone $base)->where('created_at', '>=', Carbon::today()->subDays(6))
            ->where('created_at', '<', Carbon::tomorrow())->selectRaw('DATE(created_at) as day, COUNT(*) as orders')
            ->selectRaw('SUM(CASE WHEN status IN (2,3,4) THEN actual_price ELSE 0 END) as sales')->groupByRaw('DATE(created_at)')->get()->keyBy('day');
        $data['trend'] = [];
        for ($i = 6; $i >= 0; --$i) {
            $day = Carbon::today()->subDays($i)->format('Y-m-d');
            $data['trend'][] = ['day' => $day, 'orders' => isset($trend[$day]) ? (int) $trend[$day]->orders : 0,
                'sales' => isset($trend[$day]) ? (string) $trend[$day]->sales : '0.00'];
        }
    }

    private function payments(array &$data, array $query, string $section): void
    {
        $data['columns'] = $this->columns(['kind' => ['记录类型', 'text'], 'order_sn' => ['订单号', 'text'],
            'provider_id' => ['支付平台编号', 'text'], 'event_id' => ['事件编号', 'text'], 'amount' => ['订单金额', 'money'],
            'status' => ['状态', 'status'], 'attempts' => ['检查次数', 'number'], 'error' => ['处理摘要', 'text'], 'created_at' => ['创建时间', 'time']]);
        $operate = $data['module']['installed'] && $data['module']['enabled'] && OperationsAccess::allows($section, 'POST');
        $data['bulkActions'] = [['key' => 'reconcile', 'label' => '执行一批对账', 'enabled' => $operate]];
        $binding = $section === 'stripe' ? 'stripe_payment_intents' : 'paypal_orders';
        $stateKey = $section === 'stripe' ? 'intent_id' : 'order_binding_id';
        $states = $section . '_reconciliations';
        $events = $section . '_webhook_events';
        $parts = [];
        $joinOrders = $this->has('orders', ['id', 'order_sn']);
        if ($this->has($binding, ['id', 'order_id', 'provider_id', 'cny_amount', 'created_at'])) {
            $builder = DB::table($binding . ' as b');
            if ($joinOrders) $builder->leftJoin('orders as o', 'o.id', '=', 'b.order_id');
            $reconciled = $this->has($states, [$stateKey, 'failed_at', 'finished_at', 'last_status', 'checks', 'leased_until', 'last_error']);
            if ($reconciled) $builder->leftJoin($states . ' as r', 'r.' . $stateKey, '=', 'b.id');
            $fields = ['b.id', DB::raw("'binding' as kind"), DB::raw($joinOrders ? 'o.order_sn' : 'NULL as order_sn'),
                'b.provider_id', DB::raw('NULL as event_id'), DB::raw('NULL as event_type'), 'b.cny_amount as amount', 'b.created_at'];
            if ($reconciled) {
                $fields[] = DB::raw("CASE WHEN r.leased_until > '" . Carbon::now()->format('Y-m-d H:i:s') . "' THEN 'reserved' "
                    . "WHEN r.failed_at IS NOT NULL THEN 'failed' WHEN r.finished_at IS NOT NULL THEN "
                    . "CASE WHEN r.last_status IN ('canceled','VOIDED') THEN 'closed' ELSE 'completed' END ELSE 'pending' END as status");
                $fields[] = 'r.checks as attempts'; $fields[] = 'r.last_error as error_code';
                $fields[] = DB::raw('CASE WHEN r.failed_at IS NOT NULL THEN 1 ELSE 0 END as action_ready');
            } else {
                $fields[] = DB::raw("'pending' as status"); $fields[] = DB::raw('0 as attempts');
                $fields[] = DB::raw('NULL as error_code'); $fields[] = DB::raw('0 as action_ready');
            }
            $parts[] = $builder->select($fields);
        }
        if ($this->has($events, ['id', 'event_id', 'provider_id', 'event_type', 'attempts', 'last_error', 'leased_until', 'processed_at', 'failed_at', 'created_at'])) {
            $builder = DB::table($events . ' as e');
            $fields = ['e.id', DB::raw("'webhook' as kind"), DB::raw('NULL as order_sn'), 'e.provider_id', 'e.event_id',
                'e.event_type', DB::raw('NULL as amount'), 'e.created_at',
                DB::raw("CASE WHEN e.leased_until > '" . Carbon::now()->format('Y-m-d H:i:s') . "' THEN 'reserved' "
                    . "WHEN e.processed_at IS NOT NULL THEN 'completed' WHEN e.failed_at IS NOT NULL THEN 'failed' ELSE 'pending' END as status"),
                'e.attempts', 'e.last_error as error_code',
                DB::raw('CASE WHEN e.failed_at IS NOT NULL AND e.processed_at IS NULL THEN 1 ELSE 0 END as action_ready')];
            $parts[] = $builder->select($fields);
        }
        if (!$parts) return;
        $union = array_shift($parts);
        foreach ($parts as $part) $union->unionAll($part);
        $rows = $this->paginate(DB::query()->fromSub($union, 'records'), $query, $data);
        foreach ($rows as $row) {
            $record = (array) $row;
            $record['error'] = $this->errorSummary($record['error_code']);
            $ready = $record['action_ready'];
            unset($record['error_code'], $record['action_ready']);
            $record['actions'] = [['key' => $record['kind'] === 'webhook' ? 'retry-event' : 'retry',
                'label' => '重新排队', 'enabled' => (bool) ($operate && $ready && $record['status'] !== 'reserved')]];
            $data['rows'][] = $record;
        }
    }

    private function notifications(array &$data, array $query): void
    {
        $data['columns'] = $this->columns(['order_sn' => ['订单号', 'text'], 'channel' => ['通知渠道', 'text'],
            'status' => ['状态', 'status'], 'attempts' => ['投递次数', 'number'], 'error' => ['处理摘要', 'text'], 'created_at' => ['创建时间', 'time']]);
        $operate = $data['module']['installed'] && $data['module']['enabled'] && OperationsAccess::allows('notifications', 'POST');
        $data['bulkActions'] = [['key' => 'publish', 'label' => '发布一批通知', 'enabled' => $operate]];
        if (!$this->has('notification_outbox', ['id', 'order_id', 'channel', 'attempts', 'last_error', 'reserved_until', 'failed_at', 'delivered_at', 'created_at'])) return;
        $builder = DB::table('notification_outbox as n');
        $joined = $this->has('orders', ['id', 'order_sn']);
        if ($joined) $builder->leftJoin('orders as o', 'o.id', '=', 'n.order_id');
        $builder->select(['n.id', 'n.channel', 'n.attempts', 'n.created_at', 'n.last_error as error_code',
            DB::raw($joined ? 'o.order_sn' : 'NULL as order_sn'),
            DB::raw("CASE WHEN n.reserved_until > '" . Carbon::now()->format('Y-m-d H:i:s') . "' THEN 'reserved' "
                . "WHEN n.delivered_at IS NOT NULL THEN 'completed' WHEN n.failed_at IS NOT NULL THEN 'failed' ELSE 'pending' END as status")]);
        $rows = $this->paginate(DB::query()->fromSub($builder, 'records'), $query, $data, ['order_sn']);
        foreach ($rows as $row) {
            $record = (array) $row;
            $record['error'] = $this->errorSummary($record['error_code']);
            unset($record['error_code']);
            $record['actions'] = [['key' => 'retry', 'label' => '重新排队', 'enabled' => $operate
                && $record['status'] === 'failed' && $this->channelEnabled($record['channel'])]];
            $data['rows'][] = $record;
        }
    }

    private function quotes(array &$data, array $query): void
    {
        $data['columns'] = $this->columns(['order_sn' => ['订单号', 'text'], 'currency' => ['支付币种', 'text'],
            'cny_amount' => ['人民币金额', 'money'], 'usd_amount' => ['美元金额', 'money'], 'source' => ['报价来源', 'text'],
            'rate_date' => ['汇率日期', 'time'], 'created_at' => ['冻结时间', 'time']]);
        if (!$data['module']['installed']) return;
        $builder = DB::table('payment_quotes as q');
        $joined = $this->has('orders', ['id', 'order_sn']);
        if ($joined) $builder->leftJoin('orders as o', 'o.id', '=', 'q.order_id');
        $builder->select(['q.id', 'q.currency', 'q.base_currency', 'q.source', 'q.cny_amount', 'q.usd_amount', 'q.rate_date',
            'q.created_at', DB::raw($joined ? 'o.order_sn' : 'NULL as order_sn'), DB::raw("'completed' as status")]);
        foreach ($this->paginate(DB::query()->fromSub($builder, 'records'), $query, $data, ['order_sn']) as $row) {
            $record = (array) $row; $record['actions'] = []; $data['rows'][] = $record;
        }
    }

    private function failedSchema(): bool
    {
        $connection = config('queue.failed.database') ?: config('database.default');
        return $connection === config('database.default')
            && $this->has((string) config('queue.failed.table', 'failed_jobs'), ['id', 'connection', 'queue', 'failed_at']);
    }

    private function queueContext(): array
    {
        $connection = (string) config('queue.default', 'sync');
        return ['connection' => $connection, 'driver' => (string) config('queue.connections.' . $connection . '.driver', 'sync'),
            'queue' => (string) config('queue.connections.' . $connection . '.queue', 'default')];
    }

    private function ownedJobs()
    {
        $context = $this->queueContext();
        return DB::table((string) config('queue.failed.table', 'failed_jobs'))
            ->where('connection', $context['connection'])->where('queue', $context['queue']);
    }

    private function canRetryJobs(): bool
    {
        $context = $this->queueContext();
        $driver = config('queue.failed.driver', 'database');
        return $this->failedSchema() && $this->has((string) config('queue.failed.table', 'failed_jobs'), ['payload'])
            && in_array($context['driver'], ['database', 'redis', 'beanstalkd', 'sqs'], true)
            && in_array($driver, ['database', 'database-uuids'], true)
            && ($driver !== 'database-uuids' || (class_exists(\Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider::class)
                && $this->has((string) config('queue.failed.table', 'failed_jobs'), ['uuid'])))
            && class_exists(\Illuminate\Queue\Console\RetryCommand::class);
    }

    private function runtime(array &$data, array $query): void
    {
        $context = $this->queueContext();
        $pending = null;
        $jobsTable = (string) config('queue.connections.' . $context['connection'] . '.table', 'jobs');
        $jobsConnection = config('queue.connections.' . $context['connection'] . '.connection') ?: config('database.default');
        $jobsInstalled = $context['driver'] === 'database' && $jobsConnection === config('database.default')
            && $this->has($jobsTable, ['id', 'queue']);
        if ($jobsInstalled) $pending = DB::table($jobsTable)->where('queue', $context['queue'])->count();
        $failed = $this->failedSchema() ? $this->ownedJobs()->count() : 0;
        $data['cards'] = [$this->card('pending_jobs', '待执行任务', $pending), $this->card('failed_jobs', '失败任务', $failed, 'warn')];
        if (!$jobsInstalled) $data['cards'][0]['hint'] = '当前队列积压需从队列服务读取';
        $data['columns'] = $this->columns(['id' => ['任务编号', 'text'], 'queue' => ['队列', 'text'],
            'status' => ['状态', 'status'], 'failed_at' => ['失败时间', 'time']]);
        $data['runtime'] = [['label' => '数据库驱动', 'value' => DB::connection()->getDriverName()],
            ['label' => '队列驱动', 'value' => $context['driver']],
            ['label' => '缓存驱动', 'value' => (string) config('cache.default')],
            ['label' => '会话驱动', 'value' => (string) config('session.driver')],
            ['label' => '任务表', 'value' => $jobsInstalled ? '已安装' : '未安装或不使用数据库队列'],
            ['label' => '商品列表缓存', 'value' => max(0, (int) config('dujiaoka.catalog_cache_seconds', 10)) . ' 秒',
                'hint' => '0 秒表示关闭；下单仍实时校验商品'],
            ['label' => '汇率缓存', 'value' => max(0, (int) config('dujiaoka.currency_quote_cache_seconds', 300)) . ' 秒',
                'hint' => '已冻结的订单报价保持原快照'],
            ['label' => '任务消费者与调度器', 'value' => '需单独核实运行情况',
                'hint' => '此页显示配置和积压，不采集进程心跳']];
        foreach (['stripe', 'paypal', 'notifications', 'quotes'] as $section) {
            $module = $this->module($section);
            $data['features'][] = ['key' => $section, 'label' => OperationsAccess::sections()[$section],
                'enabled' => $module['enabled'], 'installed' => $module['installed']];
        }
        foreach (['stripe_payment_intents', 'stripe_reconciliation', 'paypal_orders_v2', 'paypal_reconciliation',
            'payment_quote_freeze', 'api_hook_outbox', 'payment_mail_outbox', 'manual_status_mail_outbox'] as $flag) {
            $section = strpos($flag, 'stripe') === 0 ? 'stripe' : (strpos($flag, 'paypal') === 0 ? 'paypal'
                : ($flag === 'payment_quote_freeze' ? 'quotes' : 'notifications'));
            $data['features'][] = ['key' => $flag, 'label' => $flag, 'enabled' => (bool) config('dujiaoka.' . $flag),
                'installed' => $this->module($section)['installed']];
        }
        if (!$this->failedSchema()) return;
        $table = (string) config('queue.failed.table', 'failed_jobs');
        $uuid = config('queue.failed.driver') === 'database-uuids' && $this->has($table, ['uuid']);
        $builder = $this->ownedJobs()->select([DB::raw(($uuid ? 'uuid' : 'id') . ' as id'), 'queue', 'failed_at',
            'failed_at as created_at', DB::raw("'failed' as status")]);
        $operate = $this->canRetryJobs() && OperationsAccess::allows('runtime', 'POST');
        foreach ($this->paginate(DB::query()->fromSub($builder, 'records'), $query, $data, ['id']) as $row) {
            $record = (array) $row;
            $record['actions'] = [['key' => 'retry-job', 'label' => '重试任务', 'enabled' => $operate]];
            $data['rows'][] = $record;
        }
    }

    private function retryJob($id): array
    {
        abort_unless($this->canRetryJobs(), 409);
        $table = (string) config('queue.failed.table', 'failed_jobs');
        $column = is_string($id) && strpos($id, '-') !== false ? 'uuid' : 'id';
        abort_unless($this->has($table, [$column]), 404);
        $fields = ['id'];
        if (config('queue.failed.driver') === 'database-uuids') $fields[] = 'uuid';
        $candidate = $this->ownedJobs()->where($column, $id)->first(['id']);
        abort_unless($candidate, 404);
        // Canonicalize integer/UUID aliases. The row lock protects separate processes;
        // the cache lock also prevents reentry from native retry event listeners.
        $lock = Cache::getStore() instanceof LockProvider
            ? Cache::lock('admin-failed-retry:' . hash('sha256', $table . ':' . $candidate->id), 120) : null;
        abort_if($lock !== null && !$lock->get(), 409);
        try {
            return DB::transaction(function () use ($candidate, $fields) {
                $job = $this->ownedJobs()->where('id', $candidate->id)->lockForUpdate()->first($fields);
                abort_unless($job, 404);
                $nativeId = config('queue.failed.driver') === 'database-uuids' ? $job->uuid : $job->id;
                // Only the native command reads payloads; hold the lock through push and forget.
                $code = Artisan::call('queue:retry', ['id' => [(string) $nativeId]]);
                abort_if($code !== 0 || $this->ownedJobs()->where('id', $job->id)->exists(), 409);
                return ['retried' => 1];
            });
        } finally {
            if ($lock !== null) $lock->release();
        }
    }

    private function channelEnabled(string $channel): bool
    {
        $flags = ['api-hook' => 'api_hook_outbox', 'payment-mail' => 'payment_mail_outbox', 'order-status-mail' => 'manual_status_mail_outbox'];
        return isset($flags[$channel]) && (bool) config('dujiaoka.' . $flags[$channel]);
    }

    private function busy($until): bool
    {
        return $until !== null && Carbon::parse($until)->gt(Carbon::now());
    }

    private function errorSummary($code): string
    {
        $known = ['event_sync_failed' => '事件同步失败', 'intent_sync_failed' => '支付同步失败',
            'provider_id_unknown' => '支付平台编号待确认', 'Delivery attempt limit reached.' => '投递次数已达上限',
            'Delivery failed: RuntimeException' => '通知投递失败', 'Delivery failed: UnexpectedValueException' => '通知内容待检查',
            'Queue dispatch failed: RuntimeException' => '队列发布失败'];
        return $code === null || $code === '' ? '' : ($known[$code] ?? '处理失败，请检查模块状态。');
    }
}
