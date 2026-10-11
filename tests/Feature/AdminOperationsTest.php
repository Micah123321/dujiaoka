<?php

namespace Tests\Feature;

use App\Admin\Middleware\OperationsMiddleware;
use App\Admin\Middleware\ResourcePermission;
use App\Admin\Service\OperationsService;
use App\Admin\Support\OperationsAccess;
use App\Http\Middleware\VerifyCsrfToken;
use App\Jobs\DeliverNotification;
use App\Models\Order;
use App\Payments\PaypalOrdersClient;
use App\Payments\StripeIntentClient;
use Carbon\Carbon;
use Dcat\Admin\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\AdminOperationsData;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    private $token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private $stripe;
    private $paypal;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null, 'admin.database.connection' => 'sqlite',
            'admin.permission.enable' => true, 'admin.route.prefix' => 'admin', 'admin.route.domain' => null,
            'queue.failed.database' => 'sqlite', 'queue.failed.table' => 'failed_jobs', 'queue.failed.driver' => 'database',
            'cache.default' => 'array', 'session.driver' => 'array']);
        DB::purge('sqlite');
        Carbon::setTestNow('2026-06-20 12:00:00');
        AdminOperationsData::create();
        $this->withoutMiddleware([\App\Http\Middleware\DujiaoBoot::class, \App\Http\Middleware\InstallCheck::class]);
        $this->app->instance(VerifyCsrfToken::class, new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests() { return false; }
        });
        $this->withSession(['_token' => $this->token]);
        $this->stripe = new class extends StripeIntentClient {
            public $calls = 0;
            public function retrieve(string $key, string $id): array { ++$this->calls; throw new \RuntimeException('PRIVATE-MERCHANT-ERROR'); }
            public function create(string $key, array $params, string $idempotency): array { ++$this->calls; throw new \RuntimeException('PRIVATE-MERCHANT-ERROR'); }
        };
        $this->paypal = new class extends PaypalOrdersClient {
            public $calls = 0;
            public function get(array $credentials, string $id): array { ++$this->calls; throw new \RuntimeException('PRIVATE-MERCHANT-ERROR'); }
            public function getCapture(array $credentials, string $id): array { ++$this->calls; throw new \RuntimeException('PRIVATE-MERCHANT-ERROR'); }
        };
        $this->app->instance(StripeIntentClient::class, $this->stripe);
        $this->app->instance(PaypalOrdersClient::class, $this->paypal);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): void
    {
        $class = config('admin.database.users_model');
        $this->actingAs($class::findOrFail(1), config('admin.auth.guard'));
    }

    private function role(array $permissions): void
    {
        $this->actingAs(AdminOperationsData::user('operator-' . uniqid(), $permissions), config('admin.auth.guard'));
    }

    private function flags(): void
    {
        config(['dujiaoka.stripe_reconciliation' => true, 'dujiaoka.stripe_payment_intents' => true,
            'dujiaoka.paypal_reconciliation' => true, 'dujiaoka.paypal_orders_v2' => true,
            'dujiaoka.payment_quote_freeze' => true, 'dujiaoka.api_hook_outbox' => true,
            'dujiaoka.payment_mail_outbox' => true, 'dujiaoka.manual_status_mail_outbox' => true]);
    }

    private function postAction(string $section, array $body)
    {
        return $this->postJson('/admin/operations/' . $section . '/action', $body, ['X-CSRF-TOKEN' => $this->token]);
    }

    private function removeFixtureColumn(string $table, string $column): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $columns = Schema::getColumnListing($table);
        $this->assertContains($column, $columns);
        $remaining = array_filter($columns, function ($name) use ($column) { return $name !== $column; });
        $select = implode(', ', array_map(function ($name) { return '"' . $name . '"'; }, $remaining));
        // Build a genuine partial migration without Laravel 6's incompatible DBAL column alteration.
        DB::statement('CREATE TABLE "partial_' . $table . '" AS SELECT ' . $select . ' FROM "' . $table . '"');
        DB::statement('DROP TABLE "' . $table . '"');
        DB::statement('ALTER TABLE "partial_' . $table . '" RENAME TO "' . $table . '"');
    }

    private function noPrivate(string $body): void
    {
        foreach (['PRIVATE-', 'private-buyer', 'lease_token', 'reserved_until', 'account_fingerprint',
            'merchant_key', 'merchant_pem', 'payload', 'exception', 'search_pwd', 'whsec_'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_allowed_http_pages_render_the_shared_view_with_contract_config(): void
    {
        $this->admin();
        foreach (array_keys(OperationsAccess::sections()) as $section) {
            $response = $this->get('/admin/operations/' . $section)->assertStatus(200);
            $this->assertStringContainsString('id="admin-workbench-config"', $response->getContent());
            $this->assertStringContainsString('"section":"' . $section . '"', $response->getContent());
            $this->assertStringContainsString('id="admin-workbench"', $response->getContent());
            $this->noPrivate($response->getContent());
        }
    }

    public function test_workbench_links_follow_the_browser_origin_with_a_custom_admin_prefix(): void
    {
        $this->admin();
        config(['admin.route.prefix' => '/staff/control', 'admin.route.domain' => null]);
        app('router')->group(['as' => admin_route_name('')], function () { require app_path('Admin/routes.php'); });

        foreach (['https://shop.fixture.test', 'http://shop.fixture.test:55556'] as $origin) {
            config(['admin.https' => false]);
            $response = $this->get($origin . '/staff/control')->assertStatus(200);
            $this->assertSame(1, preg_match('/<script[^>]*id="admin-workbench-config"[^>]*>(.*?)<\/script>/s', $response->getContent(), $matches));
            $config = json_decode($matches[1], true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame('/staff/control/operations/overview/data', $config['endpoints']['data']);
            $this->assertSame('/staff/control/operations/overview/action', $config['endpoints']['action']);
            foreach (array_merge($config['navigation'], $config['management']) as $link) {
                $this->assertStringStartsWith('/staff/control/', $link['href']);
            }
            $this->getJson($origin . $config['endpoints']['data'])->assertStatus(200)->assertJson(['section' => 'overview']);
        }
    }

    public function test_home_lands_on_the_first_permitted_operations_module_without_overview_data(): void
    {
        config(['admin.menu.role_bind_menu' => false]);
        $this->role([['GET', 'operations.notifications.page']]);
        $this->get('/admin')->assertRedirect(OperationsAccess::url('operations/notifications'));
        $this->getJson('/admin/operations/overview/data')->assertStatus(403);
    }

    public function test_home_preserves_the_existing_goods_only_roles_landing_page(): void
    {
        config(['admin.menu.role_bind_menu' => false]);
        $this->role([['GET', '/goods']]);
        $this->get('/admin')->assertRedirect(OperationsAccess::url('goods'));
        $this->getJson('/admin/operations/overview/data')->assertStatus(403);
    }

    public function test_explicit_permissions_work_when_dcat_menu_fallback_is_disabled(): void
    {
        config(['admin.menu.role_bind_menu' => false]);
        $this->flags();
        $this->role([['GET', '/operations/stripe'], ['POST', 'operations.stripe.operate']]);
        $this->getJson('/admin/operations/stripe/data')->assertStatus(200);
        $this->postAction('stripe', ['action' => 'retry', 'id' => 1])->assertStatus(200);
        $this->getJson('/admin/operations/paypal/data')->assertStatus(403);
    }

    public function test_registered_http_api_uses_current_prefix_domain_and_independent_api_names(): void
    {
        $this->admin();
        config(['admin.route.prefix' => 'staff/control', 'admin.route.domain' => 'staff.fixture.test']);
        app('router')->group(['as' => admin_route_name('')], function () { require app_path('Admin/routes.php'); });
        $this->getJson('http://staff.fixture.test/staff/control/operations/stripe/data')->assertStatus(200);
        $this->getJson('http://other.fixture.test/staff/control/operations/stripe/data')->assertStatus(404);
        $route = app('router')->getRoutes()->match(Request::create('http://staff.fixture.test/staff/control/operations/stripe/data', 'GET'));
        $this->assertSame(admin_api_route_name('operations.stripe.data'), $route->getName());
        $this->assertSame('staff.fixture.test', $route->getDomain());
        $this->assertNotSame(OperationsAccess::names('stripe', 'GET'), $route->getName());
    }

    public function test_guest_receives_json_401_before_module_data_access(): void
    {
        Schema::drop('orders');
        $response = $this->getJson('/admin/operations/overview/data')->assertStatus(401);
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->noPrivate($response->getContent());
        $this->postAction('stripe', ['action' => 'retry', 'id' => 1])->assertStatus(401);
    }

    public function test_real_http_read_grant_does_not_grant_post_or_other_sections(): void
    {
        $this->flags();
        $this->role([['GET', '/operations/stripe']]);
        $this->getJson('/admin/operations/stripe/data')->assertStatus(200);
        $this->getJson('/admin/operations/paypal/data')->assertStatus(403);
        $this->getJson('/admin/operations/paypal')->assertStatus(403);
        $this->postAction('stripe', ['action' => 'retry', 'id' => 1])->assertStatus(403);
        $this->assertNotNull(DB::table('stripe_reconciliations')->value('failed_at'));
    }

    public function test_real_http_post_page_grant_permits_action_without_read_grant(): void
    {
        $this->flags();
        $this->role([['POST', '/operations/stripe']]);
        $this->getJson('/admin/operations/stripe/data')->assertStatus(403);
        $this->postAction('stripe', ['action' => 'retry', 'id' => 1])->assertStatus(200)->assertJson(['result' => ['retried' => 1]]);
        $this->assertNull(DB::table('stripe_reconciliations')->value('failed_at'));
        $this->postAction('stripe', ['action' => 'retry', 'id' => 1])->assertStatus(409);
    }

    public function test_api_path_and_api_route_name_alone_are_not_permission_authorities(): void
    {
        $this->flags();
        $this->role([['GET', '/operations/stripe/data,dcat-api.operations.stripe.data'],
            ['POST', '/operations/stripe/action,dcat-api.operations.stripe.action']]);
        $this->getJson('/admin/operations/stripe/data')->assertStatus(403);
        $this->postAction('stripe', ['action' => 'retry', 'id' => 1])->assertStatus(403);
    }

    public function test_named_permissions_map_to_distinct_get_post_names_and_restore_global_request(): void
    {
        $this->flags();
        $this->role([['GET', 'operations.stripe.page'], ['POST', 'operations.paypal.operate']]);
        $original = app('request');
        $this->assertTrue(OperationsAccess::allows('stripe', 'GET'));
        $this->assertFalse(OperationsAccess::allows('stripe', 'POST'));
        $this->assertTrue(OperationsAccess::allows('paypal', 'POST'));
        $this->assertFalse(OperationsAccess::allows('paypal', 'GET'));
        $this->assertSame($original, app('request'));
        $this->getJson('/admin/operations/stripe/data')->assertStatus(200);
        $this->postAction('paypal', ['action' => 'retry-event', 'id' => 1])->assertStatus(200);
    }

    public function test_synthetic_route_prefix_domain_and_names_match_their_own_page_path(): void
    {
        $this->role([['GET', 'operations.stripe.page'], ['POST', 'operations.stripe.operate']]);
        config(['admin.route.prefix' => 'staff/control', 'admin.route.domain' => 'staff.fixture.test']);
        foreach (['GET', 'POST'] as $method) {
            $route = OperationsAccess::routeTemplate('stripe', $method);
            $this->assertSame('staff/control/operations/stripe', $route->uri());
            $this->assertSame('staff.fixture.test', $route->getDomain());
            $this->assertTrue($route->matches(Request::create('http://staff.fixture.test/staff/control/operations/stripe', $method)));
            $this->assertFalse($route->matches(Request::create('http://other.fixture.test/staff/control/operations/stripe', $method)));
            $this->assertTrue(OperationsAccess::allows('stripe', $method));
        }
        $this->assertNotSame(OperationsAccess::names('stripe', 'GET'), OperationsAccess::names('stripe', 'POST'));
        $config = app(OperationsService::class)->pageConfig('stripe');
        $this->assertSame('http://staff.fixture.test/staff/control/operations/stripe/data', $config['endpoints']['data']);
    }

    public function test_page_config_filters_navigation_management_and_actions_by_stored_permissions(): void
    {
        $this->role([['GET', '/operations/overview,/operations/quotes,/goods,/order'], ['POST', '/operations/overview']]);
        $config = app(OperationsService::class)->pageConfig('overview');
        $this->assertSame(['overview', 'quotes'], array_column($config['navigation'], 'key'));
        $this->assertSame(['goods', 'order'], array_column($config['management'], 'key'));
        $this->assertTrue($config['canOperate']);
        $this->assertSame($this->token, $config['csrf']);
        $this->assertArrayHasKey('userName', $config);
        $this->assertSame('/admin/operations/overview/action', $config['endpoints']['action']);
        $route = app('router')->getRoutes()->match(Request::create('/admin/operations/stripe/action', 'POST'));
        $this->assertSame(admin_api_route_name('operations.stripe.action'), $route->getName());
        $this->assertNotSame(OperationsAccess::names('stripe', 'POST'), $route->getName());
        $middleware = $route->gatherMiddleware();
        $this->assertContains('web', $middleware);
        $this->assertContains('admin', $middleware);
        $this->assertContains(ResourcePermission::class, $middleware);
        $this->assertContains(OperationsMiddleware::class, $middleware);
    }

    public function test_forced_csrf_rejects_missing_and_wrong_token_without_mutation(): void
    {
        $this->admin(); $this->flags();
        $this->postJson('/admin/operations/stripe/action', ['action' => 'retry', 'id' => 1])->assertStatus(419);
        $this->postJson('/admin/operations/stripe/action', ['action' => 'retry', 'id' => 1], ['X-CSRF-TOKEN' => 'wrong'])->assertStatus(419);
        $this->assertNotNull(DB::table('stripe_reconciliations')->value('failed_at'));
        $this->postAction('stripe', ['action' => 'retry', 'id' => 1])->assertStatus(200);
    }

    public function test_all_modules_follow_contract_and_get_performs_no_provider_io(): void
    {
        $this->admin(); $this->flags();
        foreach (array_keys(OperationsAccess::sections()) as $section) {
            $response = $this->getJson('/admin/operations/' . $section . '/data')->assertStatus(200);
            $data = $response->json();
            $this->assertSame($section, $data['section']);
            $this->assertSame(['installed', 'enabled', 'message'], array_keys($data['module']));
            $this->assertSame(20, $data['pagination']['per_page']);
            foreach (['cards', 'columns', 'rows', 'bulkActions', 'features', 'runtime'] as $key) $this->assertIsArray($data[$key]);
            foreach ($data['rows'] as $row) $this->assertArrayHasKey('actions', $row);
            $this->noPrivate($response->getContent());
        }
        $this->assertSame(0, $this->stripe->calls);
        $this->assertSame(0, $this->paypal->calls);
    }

    public function test_disabled_modules_retain_history_but_all_manual_actions_are_disabled(): void
    {
        $this->admin();
        foreach (['stripe' => 'reconcile', 'paypal' => 'reconcile', 'notifications' => 'publish'] as $section => $action) {
            $data = $this->getJson('/admin/operations/' . $section . '/data')->assertStatus(200)->json();
            $this->assertTrue($data['module']['installed']);
            $this->assertFalse($data['module']['enabled']);
            $this->assertNotEmpty($data['rows']);
            $this->assertFalse($data['bulkActions'][0]['enabled']);
            foreach ($data['rows'] as $row) $this->assertFalse($row['actions'][0]['enabled']);
            $this->postAction($section, ['action' => $action])->assertStatus(409);
        }
        $this->getJson('/admin/operations/quotes/data')->assertStatus(200)->assertJsonCount(1, 'rows');
    }

    public function test_missing_tables_and_partial_migration_columns_degrade_to_read_only_state(): void
    {
        $this->admin(); $this->flags();
        Schema::drop('stripe_reconciliations');
        $data = $this->getJson('/admin/operations/stripe/data')->assertStatus(200)->json();
        $this->assertFalse($data['module']['installed']);
        $this->assertCount(2, $data['rows']);
        $this->assertFalse($data['bulkActions'][0]['enabled']);
        $this->postAction('stripe', ['action' => 'reconcile'])->assertStatus(409);
        Schema::drop('stripe_payment_intents'); Schema::drop('stripe_webhook_events');
        $this->getJson('/admin/operations/stripe/data')->assertStatus(200)->assertJsonCount(0, 'rows');
        Schema::drop('payment_quotes');
        $this->getJson('/admin/operations/quotes/data')->assertStatus(200)->assertJsonPath('module.installed', false);
        $this->removeFixtureColumn('notification_outbox', 'payload');
        $this->getJson('/admin/operations/notifications/data')->assertStatus(200)->assertJsonPath('module.installed', false);
        $this->postAction('notifications', ['action' => 'publish'])->assertStatus(409);
    }

    public function test_pagination_search_literal_prefix_and_normalized_state_filter(): void
    {
        $this->admin(); $this->flags();
        for ($id = 2; $id <= 31; ++$id) AdminOperationsData::event('stripe', $id);
        $response = $this->getJson('/admin/operations/stripe/data?state=failed&per_page=7&page=2&search=evt_fixture_')->assertStatus(200);
        $this->assertSame(['page' => 2, 'per_page' => 7, 'total' => 31, 'last_page' => 5], $response->json('pagination'));
        $this->assertCount(7, $response->json('rows'));
        $this->assertSame('webhook', $response->json('rows.0.kind'));
        $this->getJson('/admin/operations/stripe/data?search=evtXfixtureX')->assertStatus(200)->assertJsonCount(0, 'rows');
        $this->getJson('/admin/operations/stripe/data?per_page=50')->assertStatus(200)->assertJsonCount(32, 'rows');
        $this->getJson('/admin/operations/stripe/data?search=FIXTURE-ADMIN-ORDER-1')->assertStatus(200)->assertJsonCount(1, 'rows');
    }

    public function test_query_schema_action_ids_and_unknown_routes_are_validated(): void
    {
        $this->admin(); $this->flags();
        foreach (['per_page=51', 'page=0', 'state=secret', 'search=%25', 'sort=payload', 'search[]=x'] as $query) {
            $this->getJson('/admin/operations/stripe/data?' . $query)->assertStatus(422);
        }
        foreach ([['action' => 'unknown'], ['action' => 'retry'], ['action' => 'retry', 'id' => 0],
            ['action' => 'retry', 'id' => 'all'], ['action' => 'retry', 'id' => [1]],
            ['action' => 'reconcile', 'id' => 1], ['action' => 'reconcile', 'limit' => 1000]] as $body) {
            $this->postAction('stripe', $body)->assertStatus(422);
        }
        $this->postAction('stripe', ['action' => 'retry', 'id' => 999])->assertStatus(404);
        $this->postAction('quotes', ['action' => 'retry', 'id' => 1])->assertStatus(422);
        $this->getJson('/admin/operations/unknown/data')->assertStatus(404);
        $this->getJson('/admin/operations/stripe/action')->assertStatus(405);
    }

    public function test_real_payment_services_reject_busy_leases_and_duplicate_retries(): void
    {
        $this->admin(); $this->flags();
        foreach (['stripe', 'paypal'] as $section) {
            $key = $section === 'stripe' ? 'intent_id' : 'order_binding_id';
            foreach (['retry' => [$section . '_reconciliations', $key], 'retry-event' => [$section . '_webhook_events', 'id']] as $action => $record) {
                DB::table($record[0])->where($record[1], 1)->update(['leased_until' => Carbon::now()->addMinute()]);
                $data = $this->getJson('/admin/operations/' . $section . '/data?state=reserved')->assertStatus(200)->json();
                $this->assertFalse($data['rows'][0]['actions'][0]['enabled']);
                $this->postAction($section, ['action' => $action, 'id' => 1])->assertStatus(409);
                DB::table($record[0])->where($record[1], 1)->update(['leased_until' => Carbon::now()->subSecond()]);
                $this->postAction($section, ['action' => $action, 'id' => 1])->assertStatus(200);
                $this->assertNull(DB::table($record[0])->where($record[1], 1)->value('failed_at'));
                $this->postAction($section, ['action' => $action, 'id' => 1])->assertStatus(409);
            }
        }
    }

    public function test_notification_busy_channel_and_duplicate_retry_guards_use_real_service(): void
    {
        $this->admin(); $this->flags();
        DB::table('notification_outbox')->where('id', 1)->update(['reserved_until' => Carbon::now()->addMinute()]);
        $this->getJson('/admin/operations/notifications/data')->assertStatus(200)->assertJsonPath('rows.0.actions.0.enabled', false);
        $this->postAction('notifications', ['action' => 'retry', 'id' => 1])->assertStatus(409);
        $this->assertNotNull(DB::table('notification_outbox')->value('failed_at'));
        DB::table('notification_outbox')->where('id', 1)->update(['reserved_until' => null]);
        config(['dujiaoka.api_hook_outbox' => false]);
        $this->postAction('notifications', ['action' => 'retry', 'id' => 1])->assertStatus(409);
        config(['dujiaoka.api_hook_outbox' => true]);
        $this->postAction('notifications', ['action' => 'retry', 'id' => 1])->assertStatus(200);
        $this->assertSame(0, (int) DB::table('notification_outbox')->value('attempts'));
        $this->assertNull(DB::table('notification_outbox')->value('failed_at'));
        $this->postAction('notifications', ['action' => 'retry', 'id' => 1])->assertStatus(409);
    }

    public function test_notification_native_retry_rejects_lease_acquired_after_http_precheck(): void
    {
        $this->admin(); $this->flags();
        $injected = false;
        DB::listen(function ($query) use (&$injected) {
            if (!$injected && strpos($query->sql, 'select') === 0 && strpos($query->sql, 'notification_outbox') !== false
                && strpos($query->sql, 'reserved_until') !== false) {
                $injected = true;
                DB::table('notification_outbox')->where('id', 1)->update([
                    'reserved_until' => Carbon::now()->addMinute(), 'reservation_token' => 'worker-acquired-lease']);
            }
        });
        $this->postAction('notifications', ['action' => 'retry', 'id' => 1])->assertStatus(409);
        $this->assertTrue($injected);
        $this->assertSame('worker-acquired-lease', DB::table('notification_outbox')->value('reservation_token'));
        $this->assertNotNull(DB::table('notification_outbox')->value('failed_at'));
    }

    public function test_notification_publish_uses_native_service_with_fake_bus_and_cap(): void
    {
        $this->admin(); $this->flags(); Bus::fake();
        DB::table('notification_outbox')->where('id', 1)->update(['failed_at' => null, 'attempts' => 0]);
        for ($id = 2; $id <= 105; ++$id) {
            DB::table('notification_outbox')->insert(['order_id' => $id, 'channel' => 'api-hook', 'attempts' => 0,
                'available_at' => Carbon::now(), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
        }
        $this->postAction('notifications', ['action' => 'publish'])->assertStatus(200)->assertJson(['result' => ['published' => 100, 'failed' => 0]]);
        Bus::assertDispatched(DeliverNotification::class, 100);
        $this->assertSame(100, DB::table('notification_outbox')->whereNotNull('reserved_until')->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_bulk_reconciliation_calls_real_services_and_bounds_each_due_stream(): void
    {
        $this->admin(); $this->flags();
        foreach (['stripe', 'paypal'] as $section) {
            DB::table($section . '_webhook_events')->delete();
            DB::table($section . '_reconciliations')->update(['failed_at' => null]);
            for ($id = 2; $id <= 30; ++$id) {
                AdminOperationsData::binding($section, $id);
                AdminOperationsData::state($section, $id, ['failed_at' => null]);
            }
            $this->postAction($section, ['action' => 'reconcile'])->assertStatus(200);
            $this->assertSame(25, DB::table($section . '_reconciliations')->whereNotNull('checked_at')->count());
            $this->assertSame(0, DB::transactionLevel());
        }
        $this->assertSame(0, $this->stripe->calls);
        $this->assertSame(0, $this->paypal->calls);
    }

    public function test_overview_counts_trend_and_expiration_reuse_order_service(): void
    {
        $this->admin();
        DB::table('orders')->where('id', 1)->update(['created_at' => Carbon::now()->subMinutes(10)]);
        $response = $this->getJson('/admin/operations/overview/data')->assertStatus(200);
        $cards = array_column($response->json('cards'), 'value', 'key');
        $this->assertSame(2, $cards['today_orders']);
        $this->assertSame(1, $cards['pending_orders']);
        $this->assertSame('9', $cards['today_sales']);
        $this->assertCount(7, $response->json('trend'));
        $this->postAction('overview', ['action' => 'expire'])->assertStatus(200)->assertJson(['result' => ['expired' => 1, 'failed' => 0]]);
        $this->assertSame(Order::STATUS_EXPIRED, (int) DB::table('orders')->where('id', 1)->value('status'));
        $this->postAction('overview', ['action' => 'expire'])->assertStatus(200)->assertJson(['result' => ['expired' => 0]]);
        $this->noPrivate($response->getContent());
    }

    public function test_runtime_counts_and_native_integer_retry_are_scoped_to_owned_jobs(): void
    {
        $this->admin(); config(['queue.default' => 'database']);
        $id = AdminOperationsData::failedJob();
        AdminOperationsData::failedJob(['connection' => 'redis']);
        AdminOperationsData::failedJob(['queue' => 'other-tenant']);
        $response = $this->getJson('/admin/operations/runtime/data')->assertStatus(200);
        $this->assertSame(1, $response->json('pagination.total'));
        $this->assertTrue($response->json('rows.0.actions.0.enabled'));
        $this->noPrivate($response->getContent());
        $this->postAction('runtime', ['action' => 'retry-job', 'id' => $id])->assertStatus(200)->assertJson(['result' => ['retried' => 1]]);
        $this->assertSame(1, DB::table('jobs')->count());
        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertSame(0, $payload['attempts']);
        $this->assertSame(2, DB::table('failed_jobs')->count());
        $this->postAction('runtime', ['action' => 'retry-job', 'id' => $id])->assertStatus(404);
        $this->postAction('runtime', ['action' => 'retry-job', 'id' => $id + 1])->assertStatus(404);
    }

    public function test_runtime_native_uuid_retry_and_integer_lookup_preserve_legacy_id(): void
    {
        $this->admin(); config(['queue.default' => 'database', 'queue.failed.driver' => 'database-uuids']);
        $uuid = '12345678-1234-1234-1234-123456789abc';
        $id = AdminOperationsData::failedJob(['uuid' => $uuid]);
        $this->getJson('/admin/operations/runtime/data')->assertStatus(200)->assertJsonPath('rows.0.id', $uuid);
        if (!class_exists(\Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider::class)) {
            $this->getJson('/admin/operations/runtime/data')->assertStatus(200)->assertJsonPath('rows.0.actions.0.enabled', false);
            $this->postAction('runtime', ['action' => 'retry-job', 'id' => $id])->assertStatus(409);
            $this->assertSame(0, DB::table('jobs')->count());
            $this->assertSame(1, DB::table('failed_jobs')->count());
            return;
        }
        $this->postAction('runtime', ['action' => 'retry-job', 'id' => $id])->assertStatus(200);
        $next = '12345678-1234-1234-1234-123456789abd';
        AdminOperationsData::failedJob(['uuid' => $next]);
        $this->postAction('runtime', ['action' => 'retry-job', 'id' => $next])->assertStatus(200);
        $this->assertSame(2, DB::table('jobs')->count());
    }

    public function test_runtime_interleaved_native_retry_enqueues_one_job_and_rejects_second_request(): void
    {
        $this->admin(); config(['queue.default' => 'database']);
        $id = AdminOperationsData::failedJob();
        $entered = false; $nested = null;
        DB::listen(function ($event) use ($id, &$entered, &$nested) {
            if (!$entered && preg_match('/\Ainsert into ["\x60]jobs["\x60]/i', $event->sql)) {
                $entered = true;
                $nested = $this->postAction('runtime', ['action' => 'retry-job', 'id' => $id]);
            }
        });
        $this->postAction('runtime', ['action' => 'retry-job', 'id' => $id])->assertStatus(200);
        $this->assertTrue($entered, 'Interleave after the native push and before it forgets the failed record.');
        $nested->assertStatus(409);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->postAction('runtime', ['action' => 'retry-job', 'id' => $id])->assertStatus(404);
    }

    public function test_runtime_non_database_queue_does_not_report_stale_sqlite_jobs_as_its_backlog(): void
    {
        $this->admin(); config(['queue.default' => 'redis']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'PRIVATE-OLD-SQL-JOB',
            'attempts' => 0, 'reserved_at' => null, 'available_at' => 1, 'created_at' => 1]);
        $response = $this->getJson('/admin/operations/runtime/data')->assertStatus(200);
        $this->assertNull($response->json('cards.0.value'));
        $this->assertNotEmpty($response->json('cards.0.hint'));
        $this->assertSame('redis', $response->json('runtime.1.value'));
        $this->noPrivate($response->getContent());
    }

    public function test_runtime_sync_queue_is_read_only_and_never_executes_a_failed_payload(): void
    {
        $this->admin(); config(['queue.default' => 'sync']);
        $id = AdminOperationsData::failedJob(['connection' => 'sync']);
        $this->getJson('/admin/operations/runtime/data')->assertStatus(200)->assertJsonPath('rows.0.actions.0.enabled', false);
        $this->postAction('runtime', ['action' => 'retry-job', 'id' => $id])->assertStatus(409);
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_native_reconciliation_reaches_fake_provider_and_hides_merchant_error(): void
    {
        $this->admin(); $this->flags();
        DB::table('stripe_payment_intents')->where('id', 1)->update(['provider_id' => 'pi_fixture',
            'account_fingerprint' => hash('sha256', "pk_test_fixture\0sk_test_fixture"), 'reference' => str_repeat('a', 32)]);
        DB::table('stripe_reconciliations')->where('intent_id', 1)->update(['failed_at' => null, 'last_error' => null]);
        $response = $this->postAction('stripe', ['action' => 'reconcile'])->assertStatus(200);
        $this->assertSame(1, $this->stripe->calls);
        $this->assertSame(1, $response->json('result.retry'));
        $this->assertSame('intent_sync_failed', DB::table('stripe_reconciliations')->where('intent_id', 1)->value('last_error'));
        $data = $this->getJson('/admin/operations/stripe/data')->assertStatus(200);
        $this->noPrivate($response->getContent());
        $this->noPrivate($data->getContent());
        $this->assertSame(1, $this->stripe->calls);
    }

    public function test_missing_binding_migration_column_keeps_history_and_disables_actions(): void
    {
        $this->admin(); $this->flags();
        $this->removeFixtureColumn('stripe_payment_intents', 'currency');
        $data = $this->getJson('/admin/operations/stripe/data')->assertStatus(200)->json();
        $this->assertFalse($data['module']['installed']);
        $this->assertCount(2, $data['rows']);
        foreach ($data['rows'] as $row) $this->assertFalse($row['actions'][0]['enabled']);
        $this->postAction('stripe', ['action' => 'retry', 'id' => 1])->assertStatus(409);
    }

    public function test_native_service_errors_are_hidden_without_wrapping_committed_operations(): void
    {
        $this->admin(); $this->flags();
        DB::beginTransaction();
        try {
            $response = $this->postAction('stripe', ['action' => 'retry', 'id' => 1])->assertStatus(503);
            $this->noPrivate($response->getContent());
            $this->assertStringNotContainsString('committed', $response->getContent());
            $this->assertNotNull(DB::table('stripe_reconciliations')->value('failed_at'));
        } finally {
            DB::rollBack();
        }
    }
}
