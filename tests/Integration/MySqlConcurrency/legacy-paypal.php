<?php

use App\Http\Controllers\Pay\PaypalPayController;
use App\Models\Carmis;
use App\Models\Order;
use App\Models\Pay;
use App\Payments\CurrencyRateClient;
use App\Payments\PaymentReceipt;
use App\Payments\PaypalPaymentsClient;
use App\Service\ExchangeRateService;
use App\Service\PaymentQuoteService;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Definition-only module: the existing coordinator/worker owns boot and gates.
function fixtureLegacyPaypalReceipt(array $task): array
{
    $approved = $task['phase'] === 'approved';
    return ['id' => $task['payment_id'], 'intent' => 'sale', 'state' => $approved ? 'approved' : 'created',
        'transactions' => [['invoice_number' => $task['sn'], 'amount' => ['currency' => 'USD', 'total' => '2.00'],
            'related_resources' => $approved ? [['sale' => ['id' => $task['sale_id'], 'state' => 'completed',
                'parent_payment' => $task['payment_id'], 'amount' => ['currency' => 'USD', 'total' => '2.00']]]] : []]]];
}

function fixtureLegacyPaypalWorker(array $task, string $path, string $dir, FixtureOrderProcess $process): array
{
    fixtureCheck(in_array($task['phase'] ?? null, ['unknown', 'retry', 'approved'], true), 'legacy-paypal-phase');
    config(['dujiaoka.payment_quote_freeze' => true, 'dujiaoka.paypal_orders_v2' => false,
        'dujiaoka.stripe_payment_intents' => false, 'dujiaoka.api_hook_outbox' => false,
        'dujiaoka.payment_mail_outbox' => false]);
    app()->instance('Service\OrderProcessService', $process);
    app()->instance(App\Service\OrderProcessService::class, $process);
    $requests = [];
    $transportFailed = false;
    $proof = ['persistedBeforeNetwork' => false, 'independentRead' => false, 'peerResultBeforeResponse' => false];
    $expectedKey = substr(hash('sha256', 'execute' . "\0" . 'fixtureClient' . "\0"
        . $task['payment_id'] . "\0" . $task['payer_id']), 0, 32);
    $paymentPath = '/v1/payments/payment/' . $task['payment_id'];
    $handler = function ($request, $options) use ($task, $path, $dir, $paymentPath, $expectedKey, &$proof, &$transportFailed) {
        try {
            fixtureCheck(DB::transactionLevel() === 0, 'legacy-paypal-network-tx0');
            fixtureCheck($request->getUri()->getScheme() === 'https'
                && $request->getUri()->getHost() === 'api-m.paypal.com', 'legacy-paypal-network-host');
            $method = $request->getMethod();
            $uri = $request->getUri()->getPath();
            if ($method === 'POST' && $uri === '/v1/oauth2/token') {
                fixtureCheck($request->getHeaderLine('PayPal-Request-Id') === '', 'legacy-paypal-oauth-no-request-key');
                $body = ['access_token' => 'fixtureBearer', 'token_type' => 'Bearer', 'expires_in' => 3600];
            } elseif ($method === 'GET' && $uri === $paymentPath) {
                fixtureCheck($request->getHeaderLine('PayPal-Request-Id') === '', 'legacy-paypal-get-no-request-key');
                $body = fixtureLegacyPaypalReceipt($task);
                fixtureCheck(PaymentReceipt::paypalOrderMatches($body, $task['payment_id'], $task['sn'], '2.00'),
                    'legacy-paypal-accepted-payment');
                if ($task['phase'] === 'approved') {
                    fixtureCheck(PaymentReceipt::paypalSaleId($body, $task['payment_id'], $task['sn'], '2.00') === $task['sale_id'],
                        'legacy-paypal-completed-sale');
                }
                file_put_contents($path . '.provider-read', 'transaction-level=0');
                fixtureWait(function () use ($dir) { return is_file($dir . '/provider-read-gate'); }, 'legacy-paypal-provider-read-gate', 10);
            } elseif ($method === 'POST' && $uri === $paymentPath . '/execute') {
                fixtureCheck($task['phase'] === 'unknown', 'legacy-paypal-no-reexecute');
                fixtureCheck($request->getHeaderLine('PayPal-Request-Id') === $expectedKey, 'legacy-paypal-execute-key');
                fixtureCheck(json_decode((string) $request->getBody(), true) === ['payer_id' => $task['payer_id']],
                    'legacy-paypal-execute-body');
                // A separate fixture-user connection proves the marker was committed, not merely visible to its writer.
                $connection = DB::connection();
                $config = $connection->getConfig();
                fixtureCheck($config['host'] === '127.0.0.1' && (int) $config['port'] === 13316
                    && $config['database'] === 'dujiaoka_concurrency_fixture'
                    && $config['username'] === 'dujiaoka_fixture', 'legacy-paypal-read-connection-guard');
                $reader = new PDO('mysql:host=127.0.0.1;port=13316;dbname=dujiaoka_concurrency_fixture',
                    $config['username'], (string) $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT => 5, PDO::ATTR_EMULATE_PREPARES => false]);
                $proof['independentRead'] = (int) $reader->query('SELECT CONNECTION_ID()')->fetchColumn()
                    !== (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
                $prefix = $connection->getTablePrefix();
                fixtureCheck($prefix === 'mc_', 'legacy-paypal-read-prefix');
                $read = $reader->prepare('SELECT trade_no, status FROM mc_orders WHERE order_sn = ? AND deleted_at IS NULL');
                $read->execute([$task['sn']]);
                $row = $read->fetch(PDO::FETCH_ASSOC);
                $proof['persistedBeforeNetwork'] = is_array($row) && $row['trade_no'] === $task['payment_id']
                    && (int) $row['status'] === Order::STATUS_WAIT_PAY;
                fixtureCheck($proof['persistedBeforeNetwork'] && $proof['independentRead'], 'legacy-paypal-committed-marker');
                $reader = null;
                fixtureWait(function () use ($path, $dir) {
                    foreach (glob($dir . '/*.result') as $result) {
                        if ($result !== $path . '.result') return true;
                    }
                    return false;
                }, 'legacy-paypal-reservation-loser-completes', 10);
                $peers = array_values(array_filter(glob($dir . '/*.result'), function ($result) use ($path) {
                    return $result !== $path . '.result';
                }));
                fixtureCheck(count($peers) === 1, 'legacy-paypal-one-loser-result');
                $peer = json_decode(file_get_contents($peers[0]), true, 512, JSON_THROW_ON_ERROR);
                fixtureCheck(($peer['category'] ?? null) === 'legacy-paypal' && ($peer['outcome'] ?? null) === 'waiting'
                    && $peer['GETreads'] === 1 && $peer['execPOSTcount'] === 0 && $peer['markerpersisted'],
                    'legacy-paypal-loser-query-only');
                $proof['peerResultBeforeResponse'] = true;
                file_put_contents($path . '.legacy-paypal-proof.json', json_encode($proof, JSON_THROW_ON_ERROR));
                return Create::promiseFor(new Response(503, ['Content-Type' => 'application/json'], '{"name":"FIXTURE_UNKNOWN"}'));
            } else {
                throw new RuntimeException('legacy-paypal-unexpected-request');
            }
            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
        } catch (Throwable $exception) {
            $transportFailed = true;
            throw $exception;
        }
    };
    $stack = HandlerStack::create($handler);
    $stack->push(Middleware::tap(function ($request) use ($path, &$requests) {
        // Persist only method/path, transaction depth and the public idempotency hash.
        $requests[] = ['method' => $request->getMethod(), 'path' => $request->getUri()->getPath(),
            'transaction_level' => DB::transactionLevel(), 'request_key' => $request->getHeaderLine('PayPal-Request-Id')];
        file_put_contents($path . '.legacy-paypal-http.json', json_encode($requests, JSON_THROW_ON_ERROR));
    }), 'legacy-paypal-trace');
    $http = new Client(['handler' => $stack]);
    app()->instance(PaypalPaymentsClient::class, new PaypalPaymentsClient($http));
    $rates = new ExchangeRateService(new CurrencyRateClient($http), app('cache'));
    $quotes = new class($rates) extends PaymentQuoteService {
        public $lockedReads = 0;
        public $snapshotReads = 0;
        public function frozenUsd(Order $order, Pay $gateway, string $source): string
        {
            $locked = DB::transactionLevel() > 0;
            $usd = parent::frozenUsd($order, $gateway, $source);
            if ($locked) ++$this->lockedReads;
            else ++$this->snapshotReads;
            return $usd;
        }
    };
    app()->instance(PaymentQuoteService::class, $quotes);
    $controller = new PaypalPayController();
    $response = $controller->returnUrl(Request::create('https://fixture.test/pay/paypal/return_url', 'GET',
        ['success' => 'ok', 'orderSN' => $task['sn'], 'paymentId' => $task['payment_id'], 'PayerID' => $task['payer_id']]));
    $detail = 'https://fixture.test/detail-order-sn/' . rawurlencode($task['sn']);
    fixtureCheck($response instanceof Symfony\Component\HttpFoundation\RedirectResponse
        && $response->getTargetUrl() === $detail && $response->headers->get('Cache-Control') === 'no-store, private',
        'legacy-paypal-real-detail-redirect');
    $reentry = true;
    if ($task['phase'] === 'retry') {
        $before = count($requests);
        $checkout = (new PaypalPayController())->gateway('paypal-v1-fixture', $task['sn']);
        $reentry = $checkout instanceof Symfony\Component\HttpFoundation\RedirectResponse
            && $checkout->getTargetUrl() === $detail && count($requests) === $before;
        fixtureCheck($reentry, 'legacy-paypal-no-replacement-payment');
    }
    fixtureCheck(!$transportFailed && DB::transactionLevel() === 0, 'legacy-paypal-transport-and-transaction');
    fixtureCheck($quotes->snapshotReads === 1 && $quotes->lockedReads === 1, 'legacy-paypal-real-locked-quote-guard');
    $gets = array_values(array_filter($requests, function ($r) use ($paymentPath) {
        return $r['method'] === 'GET' && $r['path'] === $paymentPath;
    }));
    $executes = array_values(array_filter($requests, function ($r) use ($paymentPath) {
        return $r['method'] === 'POST' && $r['path'] === $paymentPath . '/execute';
    }));
    fixtureCheck(count($gets) === 1 && count($executes) <= 1, 'legacy-paypal-one-get-per-callback');
    fixtureCheck(count($requests) === 2 + count($executes), 'legacy-paypal-no-extra-provider-io');
    foreach ($requests as $request) fixtureCheck($request['transaction_level'] === 0, 'legacy-paypal-all-io-tx0');
    if ($executes) fixtureCheck($proof['persistedBeforeNetwork'] && $proof['peerResultBeforeResponse'], 'legacy-paypal-execute-proof');
    $order = Order::where('order_sn', $task['sn'])->firstOrFail();
    $completed = $task['phase'] === 'approved';
    $marker = $order->trade_no === ($completed ? $task['sale_id'] : $task['payment_id']);
    fixtureCheck((int) $order->status === ($completed ? Order::STATUS_COMPLETED : Order::STATUS_WAIT_PAY)
        && $marker, 'legacy-paypal-callback-state');
    return ['category' => 'legacy-paypal', 'phase' => $task['phase'], 'outcome' => $completed ? 'completed' : 'waiting',
        'GETreads' => count($gets), 'execPOSTcount' => count($executes), 'status' => (int) $order->status,
        'markerpersisted' => $marker, 'requestkey' => !$executes || $executes[0]['request_key'] === $expectedKey,
        'tx0' => true, 'lockedQuoteReads' => $quotes->lockedReads, 'quoteSnapshotReads' => $quotes->snapshotReads,
        'acceptedPayment' => true, 'completedSale' => $completed, 'noReplacementPayment' => $reentry, 'proof' => $proof];
}

function fixtureLegacyPaypalRaces(string $root): array
{
    $goods = 40;
    $sn = 'legacy-paypal-v1-reservation';
    $paymentId = 'PAY-1B56960729604235TKQQIYVY';
    $saleId = '1B56960729604235T';
    fixtureCheck(!DB::table('pays')->where('id', 4)->exists(), 'legacy-paypal-gateway-id-isolated');
    DB::table('pays')->insert(['id' => 4, 'pay_handleroute' => '/pay/paypal', 'pay_check' => 'paypal-v1-fixture',
        'merchant_id' => 'fixtureMerchant', 'merchant_key' => 'fixtureClient', 'merchant_pem' => 'fixtureSecret']);
    fixtureSeed($goods, [$sn]);
    $id = (int) DB::table('orders')->where('order_sn', $sn)->value('id');
    DB::table('orders')->where('id', $id)->update(['pay_id' => 4, 'actual_price' => '10.00']);
    $now = date('Y-m-d H:i:s');
    DB::table('payment_quotes')->insert(['order_id' => $id, 'pay_id' => 4, 'base_currency' => 'CNY', 'currency' => 'USD',
        'source' => 'frankfurter', 'cny_amount' => '10.00', 'usd_amount' => '2.00', 'numerator' => '1', 'denominator' => '5',
        'rate_date' => date('Y-m-d'), 'created_at' => $now, 'updated_at' => $now]);
    $all = [];
    $phases = [];
    foreach (['unknown', 'retry', 'approved'] as $phase) {
        $name = 'legacy-paypal-' . $phase;
        $task = ['action' => 'legacy-paypal', 'phase' => $phase, 'sn' => $sn, 'payment_id' => $paymentId,
            'sale_id' => $saleId, 'payer_id' => 'fixturePayer'];
        $results = fixtureRace($root, $name, array_fill(0, 2, $task), 'orders', [$id],
            function () use ($root, $name, $paymentId) {
                // Both business GETs must precede the observed order-row waits, not happen after lock release.
                for ($i = 0; $i < 2; ++$i) {
                    $trace = $root . '/' . $name . '/' . $i . '.legacy-paypal-http.json';
                    fixtureCheck(is_file($trace), 'legacy-paypal-get-before-order-lock');
                    $requests = json_decode(file_get_contents($trace), true, 512, JSON_THROW_ON_ERROR);
                    fixtureCheck(is_array($requests) && count($requests) === 2, 'legacy-paypal-before-release-request-count');
                    fixtureCheck($requests[0]['method'] === 'POST' && $requests[0]['path'] === '/v1/oauth2/token'
                        && $requests[1]['method'] === 'GET' && $requests[1]['path'] === '/v1/payments/payment/' . $paymentId,
                        'legacy-paypal-both-gets-before-reservation-or-completion');
                    foreach ($requests as $request) fixtureCheck($request['transaction_level'] === 0, 'legacy-paypal-before-release-tx0');
                }
            }, true);
        fixtureCheck(count($results) === 2 && array_sum(array_column($results, 'GETreads')) === 2
            && array_sum(array_column($results, 'execPOSTcount')) === ($phase === 'unknown' ? 1 : 0),
            'legacy-paypal-phase-request-counts');
        foreach ($results as $result) {
            fixtureCheck($result['category'] === 'legacy-paypal' && $result['phase'] === $phase
                && $result['outcome'] === ($phase === 'approved' ? 'completed' : 'waiting')
                && $result['markerpersisted'] && $result['requestkey'] && $result['tx0']
                && $result['acceptedPayment'] && $result['completedSale'] === ($phase === 'approved'),
                'legacy-paypal-phase-worker-evidence');
        }
        $order = Order::findOrFail($id);
        $card = (int) DB::table('carmis')->where('goods_id', $goods)->where('status', Carmis::STATUS_SOLD)->count();
        $sales = (int) DB::table('goods')->where('id', $goods)->value('sales_volume');
        $completed = $phase === 'approved';
        fixtureCheck((int) $order->status === ($completed ? Order::STATUS_COMPLETED : Order::STATUS_WAIT_PAY)
            && $order->trade_no === ($completed ? $saleId : $paymentId)
            && $card === ($completed ? 1 : 0) && $sales === ($completed ? 1 : 0), 'legacy-paypal-phase-durable-state');
        if ($completed) {
            $cardRow = DB::table('carmis')->where('goods_id', $goods)->first();
            fixtureCheck($order->info === $cardRow->carmi && DB::table('carmis')->where('goods_id', $goods)->count() === 1,
                'legacy-paypal-single-actual-card-delivery');
        }
        $phases[$phase] = ['execPOSTcount' => array_sum(array_column($results, 'execPOSTcount')), 'GETreads' => 2,
            'status' => (int) $order->status, 'card' => $card, 'sales' => $sales, 'markerpersisted' => true,
            'requestkey' => true, 'tx0' => true, 'lockedQuoteReads' => array_sum(array_column($results, 'lockedQuoteReads'))];
        $all = array_merge($all, $results);
    }
    $executors = array_values(array_filter($all, function ($r) { return $r['execPOSTcount'] === 1; }));
    fixtureCheck(count($executors) === 1 && $executors[0]['proof']['persistedBeforeNetwork']
        && $executors[0]['proof']['independentRead'] && $executors[0]['proof']['peerResultBeforeResponse'],
        'legacy-paypal-one-committed-executor-total');
    fixtureCheck(array_sum(array_column($all, 'GETreads')) === 6, 'legacy-paypal-six-business-gets');
    fixtureCheck(DB::table('payment_quotes')->where('order_id', $id)->where('pay_id', 4)->count() === 1
        && DB::table('payment_quotes')->where('order_id', $id)->value('usd_amount') === '2.00', 'legacy-paypal-frozen-quote-retained');
    return ['legacy_paypal' => ['contention_groups' => 3, 'worker_processes' => 6, 'execPOSTcount' => 1, 'GETreads' => 6,
        'status' => $phases['approved']['status'], 'card' => 1, 'sales' => 1, 'markerpersisted' => true,
        'persistedBeforeNetwork' => true, 'independentRead' => true, 'peerResultBeforeResponse' => true,
        'requestkey' => true, 'tx0' => true, 'noReplacementPayment' => true, 'frozenUsd' => '2.00', 'phases' => $phases]];
}
