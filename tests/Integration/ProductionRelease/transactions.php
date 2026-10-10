<?php
function pr_modern(array $response)
{
    if (!preg_match('#<script[^>]*id="modern-data"[^>]*>(.*?)</script>#s', $response['body'], $match)) throw new RuntimeException('Actual modern data required.');
    return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
}

function pr_state(array $cfg, $label)
{
    $db = new PDO('sqlite:' . pr_owned_path($cfg, $cfg['release'] . '/storage/fixture.sqlite'));
    $db->exec('PRAGMA query_only=ON'); $db->beginTransaction();
    if ($db->query('SELECT name FROM fixture_marker')->fetchColumn() !== 'dujiaoka-admin-browser-v1') throw new RuntimeException('Original synthetic marker required.');
    $state = [];
    foreach (['orders','coupons','coupons_goods','goods','carmis','pays','emailtpls','jobs','failed_jobs'] as $table) {
        $state[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY ' . ($table === 'coupons_goods' ? 'coupons_id,goods_id' : 'id'))->fetchAll(PDO::FETCH_ASSOC);
    }
    $db->rollBack();
    ql_json($cfg['run'] . '/transaction-' . $label . '.json', $state);
    return $state;
}

function pr_transaction_order(array $state, $sn)
{
    $rows = array_values(array_filter($state['orders'], function ($row) use ($sn) { return $row['order_sn'] === $sn; }));
    if (count($rows) !== 1) throw new RuntimeException('Exact transaction order required.');
    return $rows[0];
}

function pr_cli_transaction_identity(array $cfg, $label)
{
    $proof=json_decode(file_get_contents($cfg['run'].'/'.$label.'.php-proof.json'),true,512,JSON_THROW_ON_ERROR);
    $x=$proof['identity'];
    ql_check($x && $x['base']===$cfg['release'] && $x['storage']===$cfg['release'].'/storage' && $x['db']===$cfg['release'].'/storage/fixture.sqlite'
        && $x['environment']==='production' && $x['queue']==='database' && $x['failed_driver']==='database-uuids' && $x['config_cached'] && $x['routes_cached']
        && $x['loaders']===[$cfg['release'].'/vendor'] && $x['app_source']===$cfg['release'].'/app/Console/Kernel.php', 'native_transaction_cli_identity_'.$label);
}

function pr_transactions(array $cfg, array $php)
{
    $startedRequest = $GLOBALS['pr_requests'];
    $seed = json_decode(file_get_contents($cfg['run'].'/seed-proof.json'),true,512,JSON_THROW_ON_ERROR);
    $timezone = new DateTimeZone($seed['timezone']);
    ql_check($seed['queue'] === 'database', 'production_seed_actual_database_queue');
    $initial = pr_state($cfg, 'initial');
    ql_check(count($initial['orders']) === 2 && !$initial['jobs'] && !$initial['failed_jobs'], 'transaction_fresh_original_seed');
    $auto = pr_modern(pr_request('/buy/1')); $manual = pr_modern(pr_request('/buy/2'));
    ql_check($auto['page'] === 'buy' && $auto['buy']['stock'] === 1 && $manual['buy']['stock'] === 10 && $auto['csrf'] === $manual['csrf'], 'actual_original_vue_fields_and_session');
    $input = ['_token'=>$auto['csrf'],'gid'=>1,'email'=>'auto46@example.test','payway'=>1,'by_amount'=>1,'coupon_code'=>'FIXTURE-COUPON'];
    $missing = $input; unset($missing['_token']);
    ql_check(pr_request('/create-order','POST',$missing)['status'] === 419 && pr_state($cfg,'after-csrf') === $initial, 'transaction_real419_zero_business_or_queue_write');
    foreach (['amount'=>array_merge($input,['gid'=>2,'by_amount'=>11,'coupon_code'=>'']), 'email'=>array_merge($input,['email'=>'not-an-email']), 'coupon'=>array_merge($input,['coupon_code'=>'MISSING-COUPON'])] as $label=>$invalid) {
        $r = pr_request('/create-order','POST',$invalid);
        ql_check($r['status'] === 200 && pr_modern($r)['page'] === 'error' && pr_state($cfg,'invalid-'.$label) === $initial, 'transaction_original_validator_no_writes_'.$label);
    }
    pr_command(array_merge($php,[__DIR__.'/transaction-probe.php',$cfg['run'],'install-fault']),'transaction-install-fault');
    $failed = pr_request('/create-order','POST',$input);
    ql_check($failed['status'] === 200 && strpos(pr_modern($failed)['error']['content'],'Injected production coupon failure') !== false
        && pr_state($cfg,'after-insert-fault') === $initial, 'actual_after_coupon_update_fault_rolls_back_insert_and_coupon');
    pr_command(array_merge($php,[__DIR__.'/transaction-probe.php',$cfg['run'],'remove-fault']),'transaction-remove-fault');
    $sns = []; $created = null;
    foreach (['auto-one'=>$input,'auto-two'=>array_merge($input,['email'=>'second46@example.test']), 'manual'=>array_merge($input,['gid'=>2,'by_amount'=>3,'coupon_code'=>'','email'=>'manual46@example.test'])] as $label=>$valid) {
        $r = pr_request('/create-order','POST',$valid);
        ql_check($r['status'] === 302 && preg_match('#Location: '.preg_quote($cfg['url'],'#').'/bill/([A-Z0-9]{16})#i',$r['head'],$match) === 1, 'actual_native_order_redirect_'.$label);
        $sn = $match[1]; $sns[] = $sn; $created = pr_state($cfg,'created-'.$label); $row = pr_transaction_order($created,$sn);
        $isManual = $label === 'manual';
        ql_check((int)$row['status'] === 1 && (int)$row['goods_id'] === ($isManual?2:1) && (int)$row['buy_amount'] === ($isManual?3:1)
            && (int)$row['pay_id'] === 1 && $row['email'] === $valid['email'] && $row['buy_ip'] === '127.0.0.1', 'actual_native_order_fields_'.$label);
        ql_check(bccomp((string)$row['actual_price'],$isManual?'27.00':'8.00',2) === 0 && bccomp((string)$row['total_price'],$isManual?'27.00':'9.00',2) === 0
            && (int)$row['coupon_id'] === ($isManual?0:1) && (int)$row['coupon_ret_back'] === 0, 'original_exact_money_coupon_'.$label);
        ql_check(count($created['orders']) === 2+count($sns) && count($created['jobs']) === count($sns) && (int)$created['coupons'][0]['ret'] === max(0,2-count($sns)), 'one_native_postcommit_job_and_coupon_consumption_'.$label);
        $job = end($created['jobs']); $payload = json_decode($job['payload'],true,512,JSON_THROW_ON_ERROR);
        $deadline = (new DateTimeImmutable($row['created_at'],$timezone))->getTimestamp()+300;
        ql_check($job['queue'] === 'default' && (int)$job['attempts'] === 0 && $job['reserved_at'] === null && (int)$job['available_at'] === $deadline
            && $payload['displayName'] === 'App\Jobs\OrderExpired' && strpos($payload['data']['command'],$sn) !== false
            && strpos($payload['data']['command'],'i:'.$deadline.';') !== false, 'original_delayed_serialized_job_'.$label);
        $bill = pr_request('/bill/'.$sn); $billData = pr_modern($bill);
        ql_check($bill['status'] === 200 && $billData['page'] === 'bill' && $billData['order']['sn'] === $sn
            && bccomp($billData['order']['price'],$isManual?'27.00':'8.00',2) === 0, 'cached_original_bill_'.$label);
        $status = pr_request('/check-order-status/'.$sn,'GET',[],'application/json');
        ql_check($status['status'] === 200 && json_decode($status['body'],true)['code'] === 400000, 'native_unpaid_status_'.$label);
    }
    ql_json($cfg['run'].'/transaction-orders.json',$sns);
    $search = pr_request('/search-order-by-browser','POST',['_token'=>$auto['csrf']]); $searchData = pr_modern($search);
    $actual= array_column($searchData['orders'],'sn'); $expected=$sns; sort($actual); sort($expected);
    ql_check($actual===$expected,'original_browser_history_exact_three_cookie_orders');
    pr_command(array_merge($php,[__DIR__.'/transaction-cookie.php',$cfg['run']]),'transaction-cookie');
    $crypt=json_decode(file_get_contents($cfg['run'].'/transaction-cookie-proof.json'),true,512,JSON_THROW_ON_ERROR);
    $failureProvider=json_decode(file_get_contents($cfg['run'].'/transaction-failure-provider.json'),true,512,JSON_THROW_ON_ERROR);
    ql_check($failureProvider['status']==='PASS' && $failureProvider['driver']==='database-uuids' && $failureProvider['forgotten'] && $failureProvider['jobs_unchanged'], 'actual_original_uuid_failure_provider_log_forget');
    ql_check($crypt['status']==='PASS' && $crypt['orders']===$sns && $crypt['cipher_differs'] && $crypt['tamper_rejected'], 'actual_native_encryptcookies_decrypt_and_tamper_control');
    ql_check($search['status'] === 200 && $searchData['page'] === 'orders', 'native_original_browser_history');
    $noCoupon = pr_request('/create-order','POST',$input);
    ql_check($noCoupon['status'] === 200 && pr_modern($noCoupon)['page'] === 'error' && pr_state($cfg,'after-exhausted') === $created, 'exhausted_coupon_no_duplicate_order_or_job');
    $earlyId = (int)$created['jobs'][0]['id'];
    pr_command(array_merge($php,[__DIR__.'/transaction-probe.php',$cfg['run'],'early-job',(string)$earlyId]),'transaction-early-job');
    $earlyReady = pr_state($cfg,'early-ready');
    ql_check($earlyReady['jobs'][0]['payload'] === $created['jobs'][0]['payload'] && (int)$earlyReady['jobs'][0]['available_at'] <= time(), 'early_probe_retains_original_future_deadline');
    pr_command(array_merge($php,[$cfg['release'].'/artisan','queue:work','database','--queue=default','--once','--sleep=0','--tries=3','--timeout=20']),'transaction-worker-early');
    pr_cli_transaction_identity($cfg,'transaction-worker-early');
    $early = pr_state($cfg,'after-early-worker');
    ql_check($early['orders'] === $created['orders'] && $early['coupons'] === $created['coupons'] && !$early['failed_jobs'] && count($early['jobs']) === 2
        && $early['jobs'] === array_slice($created['jobs'],1), 'original_native_worker_consumes_early_job_without_expiring');
    pr_command(array_merge($php,[__DIR__.'/transaction-probe.php',$cfg['run'],'age-orders']),'transaction-age-orders');
    $aged = pr_state($cfg,'aged');
    pr_command(array_merge($php,[$cfg['release'].'/artisan','orders:expire']),'transaction-sweep-one');
    pr_cli_transaction_identity($cfg,'transaction-sweep-one');
    ql_check(strpos(file_get_contents($cfg['run'].'/transaction-sweep-one.stdout'),'Expired: 3; failed: 0') !== false, 'original_sweep_expires_exact_three_new_orders');
    $expired = pr_state($cfg,'expired');
    foreach ($sns as $sn) { $row = pr_transaction_order($expired,$sn); ql_check((int)$row['status'] === -1 && (int)$row['coupon_ret_back'] === ((int)$row['coupon_id']?1:0), 'native_expiry_atomic_coupon_flag'); }
    ql_check((int)$expired['coupons'][0]['ret'] === 2 && $expired['jobs'] === $early['jobs'] && !$expired['failed_jobs'], 'original_coupon_restored_exactly_once_with_queued_deadlines_preserved');
    pr_command(array_merge($php,[$cfg['release'].'/artisan','orders:expire']),'transaction-sweep-two');
    pr_cli_transaction_identity($cfg,'transaction-sweep-two');
    ql_check(strpos(file_get_contents($cfg['run'].'/transaction-sweep-two.stdout'),'Expired: 0; failed: 0') !== false && pr_state($cfg,'expired-twice') === $expired, 'real_second_sweep_is_idempotent');
    foreach ($sns as $sn) {
        $r = pr_request('/bill/'.$sn); ql_check($r['status'] === 200 && pr_modern($r)['page'] === 'error', 'cached_bill_rejects_expired_order');
        $r = pr_request('/check-order-status/'.$sn,'GET',[],'application/json'); ql_check($r['status'] === 200 && json_decode($r['body'],true)['code'] === 400001, 'cached_status_reports_real_expiry');
    }
    foreach (['goods','carmis','pays','emailtpls','coupons_goods'] as $table) ql_check($initial[$table] === $expired[$table], 'no_payment_or_fulfillment_side_effect_'.$table);
    ql_check(array_slice($expired['orders'],0,2) === $initial['orders'], 'old_original_fixture_orders_untouched');
    $GLOBALS['pr_transaction_requests'] = $GLOBALS['pr_requests']-$startedRequest;
    ql_json($cfg['run'].'/transaction-summary.json',['status'=>'PASS','requests'=>$GLOBALS['pr_transaction_requests'],'orders'=>$sns,'queue'=>'database','initial'=>$initial,'created'=>$created,'early'=>$early,'aged'=>$aged,'expired'=>$expired,
        'controls'=>['real_missing_csrf419','original_invalid_amount_email_coupon','real_after_update_trigger_rollback','exhausted_coupon','original_payload_early_worker','setup_only_age_new_orders','native_two_sweeps']]);
}
