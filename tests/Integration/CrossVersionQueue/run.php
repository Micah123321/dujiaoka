<?php
require __DIR__.'/bootstrap.php';
$GLOBALS['cq_checks']=0;$GLOBALS['cq_processes']=[];$GLOBALS['cq_tasks']=0;
function cq_check($condition,$name){if(!$condition)throw new RuntimeException('Cross-version queue assertion failed: '.$name);$GLOBALS['cq_checks']++;}
function cq_child(array $cfg,$tree,array $task)
{
    $n=sprintf('%03d',++$GLOBALS['cq_tasks']);$file=$cfg['run'].'/tasks/'.$n.'-'.$tree.'-'.$task['operation'].'.json';ql_json($file,$task);
    $runtime=$cfg['runtimes'][$tree];$args=[$runtime['binary'],'-c',$runtime['ini'],__DIR__.'/worker.php',$cfg['run'],$tree,$file];
    $pipes=[];$proc=proc_open($args,[0=>['file','NUL','r'],1=>['file',$file.'.stdout','w'],2=>['file',$file.'.stderr','w']],$pipes,$cfg['root'],null,['bypass_shell'=>true]);
    if(!is_resource($proc))throw new RuntimeException('Native cross-version child failed to start.');$state=proc_get_status($proc);
    $GLOBALS['cq_processes'][$n]=['handle'=>$proc,'pid'=>$state['pid'],'args'=>$args,'closed'=>false,'exit'=>null];$deadline=microtime(true)+35;
    do{$state=proc_get_status($proc);if(!$state['running'])break;usleep(50000);}while(microtime(true)<$deadline);
    if($state['running'])throw new RuntimeException('Native cross-version child timeout.');$exit=$state['exitcode'];$close=proc_close($proc);if($exit<0&&$close>=0)$exit=$close;
    $GLOBALS['cq_processes'][$n]['closed']=true;$GLOBALS['cq_processes'][$n]['exit']=$exit;
    $result=is_file($file.'.result.json')?json_decode(file_get_contents($file.'.result.json'),true,512,JSON_THROW_ON_ERROR):null;
    cq_check($exit===0&&$result&&$result['status']==='PASS','original_child_'.$n.'_exit0');
    cq_check(!$result['issues']&&!$result['last_php_error']&&!filesize($file.'.stderr'),'original_child_'.$n.'_no_php_issue');
    cq_check($result['db_closed']&&$result['transaction_level']===0,'original_child_'.$n.'_closed_clean_db');$identity=$result['identity'];
    cq_check($identity['pid']===$state['pid']&&$identity['binary']===$runtime['binary']&&$identity['ini']===$runtime['ini'],'actual_child_'.$n.'_identity');
    cq_check($identity['framework']===($tree==='root'?'6.20.42':'13.35.0')&&$identity['php']===($tree==='root'?'7.4.33':'8.5.11'),'actual_child_'.$n.'_versions');
    cq_check($identity['db']===$cfg['run'].'/queue.sqlite'&&$identity['storage']===$cfg['run'].'/'.$tree.'/storage'&&$identity['failed_driver']==='database','actual_child_'.$n.'_legacy_db_provider');
    return $result;
}
function cq_state(array $cfg,$label)
{
    $db=new PDO('sqlite:'.$cfg['run'].'/queue.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->exec('PRAGMA query_only=ON');$db->beginTransaction();
    cq_check($db->query("SELECT count(*) FROM fixture_marker WHERE name='cross-version-queue-".$cfg['marker']."'")->fetchColumn()==1,'readonly_owned_marker_'.$label);
    $tables=$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);$rows=[];
    foreach($tables as$table){if(!preg_match('/^[a-z_]+$/D',$table))throw new RuntimeException('Known simple fixture table name required.');$rows[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);}
    $db->commit();$db=null;ql_json($cfg['run'].'/state-'.$label.'.json',$rows);return $rows;
}
function cq_order(array $state,$sn){$rows=array_values(array_filter($state['orders'],function($r)use($sn){return$r['order_sn']===$sn;}));cq_check(count($rows)===1,'exact_order_'.$sn);return $rows[0];}
function cq_coupon(array $state,$id){$rows=array_values(array_filter($state['coupons'],function($r)use($id){return(int)$r['id']===$id;}));cq_check(count($rows)===1,'exact_coupon_'.$id);return $rows[0];}
function cq_work(array $cfg,$tree){return cq_child($cfg,$tree,['operation'=>'console','command'=>['queue:work','database','--queue=fixture48','--once','--sleep=0','--tries=1',$tree==='root'?'--delay=0':'--backoff=0','--timeout=20']]);}
function cq_business(array $state){foreach(['jobs','failed_jobs']as$table)unset($state[$table]);return $state;}
function cq_expired_transition(array $before,array $after,$sn,$cid,$label)
{
    $expected=cq_business($before);$actual=cq_business($after);
    foreach($expected['orders']as&$row)if($row['order_sn']===$sn){$changed=cq_order($after,$sn);$row['status']=-1;$row['coupon_ret_back']=1;$row['updated_at']=$changed['updated_at'];}
    unset($row);foreach($expected['coupons']as&$row)if((int)$row['id']===$cid){$changed=cq_coupon($after,$cid);$row['ret']=(int)$row['ret']+1;$row['updated_at']=$changed['updated_at'];}
    unset($row);cq_check($expected===$actual,'only_expiry_return_and_update_time_columns_'.$label);
}
function cq_done(array $state,$sn,$coupon){$order=cq_order($state,$sn);cq_check((int)$order['status']===-1&&(int)$order['coupon_ret_back']===1&&(int)cq_coupon($state,$coupon)['ret']===1,'expired_exactly_once_'.$sn);}

$root=ql_normalize(realpath(dirname(__DIR__,3)));$base=$root.'/tests/.runtime/cross-version-queue';if(!is_dir($base))mkdir($base,0700,true);
$run=$base.'/run-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(12));mkdir($run,0700);
mkdir($run.'/tasks',0700);
foreach(['root','candidate']as$tree)foreach(['framework/cache/data','framework/cache/locks','framework/views','framework/sessions','logs']as$dir)mkdir($run.'/'.$tree.'/storage/'.$dir,0700,true);
$cfg=['root'=>$root,'run'=>$run,'marker'=>bin2hex(random_bytes(12)),'key'=>'base64:'.base64_encode(str_repeat('Q',32)),
    'runtimes'=>['root'=>['binary'=>$root.'/tests/.runtime/php74/php.exe','ini'=>$root.'/tests/.runtime/php74/php.ini'],
    'candidate'=>['binary'=>$root.'/tests/.runtime/php85/php.exe','ini'=>$root.'/tests/.runtime/php85-fixture.ini']]];
ql_json($run.'/run.json',$cfg);file_put_contents($run.'/marker',$cfg['marker']);touch($run.'/queue.sqlite');$started=microtime(true);$failure=null;$cleanup=[];
try{
    cq_child($cfg,'root',['operation'=>'seed']);$initial=cq_state($cfg,'initial');
    // The original old producer sets a near future deadline; no task clock is mocked.
    $deadline=time()+15;$sn='CQ48-FUTURE';$published=cq_child($cfg,'root',['operation'=>'publish','sn'=>$sn,'coupon_id'=>2,'deadline'=>$deadline]);
    $payload=json_decode($published['published']['payload'],true,512,JSON_THROW_ON_ERROR);cq_check(!isset($payload['uuid'])&&$payload['maxTries']===3&&$payload['timeout']===20,'real_old_payload_without_uuid');
    $queued=cq_state($cfg,'future-published');$early=cq_work($cfg,'candidate');$state=cq_state($cfg,'future-early');
    cq_check(time()<$deadline&&(int)cq_order($state,$sn)['status']===1&&(int)cq_coupon($state,2)['ret']===0&&count($state['jobs'])===0,'old_future_candidate_early_ack_no_return');
    cq_check(cq_business($queued)===cq_business($state),'early_ack_all_business_fields_identical');
    cq_check(count($early['events'])===2&&$early['events'][0]['event']==='JobProcessing'&&$early['events'][1]['event']==='JobProcessed','real_early_worker_processed');
    cq_child($cfg,'root',['operation'=>'replay','sn'=>$sn]);$beforeWait=cq_state($cfg,'future-replayed');
    while(time()<=$deadline)usleep(50000);$late=cq_work($cfg,'candidate');$state=cq_state($cfg,'future-due');cq_done($state,$sn,2);cq_expired_transition($beforeWait,$state,$sn,2,'real_future');$expired=$state;
    cq_check($late['events'][0]['time']>=$deadline&&$late['events'][0]['payload_sha256']===hash('sha256',$published['published']['payload']),'original_payload_real_deadline_consumed');
    cq_child($cfg,'root',['operation'=>'replay','sn'=>$sn]);cq_work($cfg,'root');$state=cq_state($cfg,'future-duplicate');cq_done($state,$sn,2);
    cq_check(!$state['jobs']&&!$state['failed_jobs'],'native_old_duplicate_ack_empty');cq_check(cq_business($expired)===cq_business($state),'old_duplicate_all_business_fields_identical');
    $due=time()-1;$sn='CQ48-OLD-FAIL';$published=cq_child($cfg,'root',['operation'=>'publish','sn'=>$sn,'coupon_id'=>3,'deadline'=>$due]);
    cq_child($cfg,'candidate',['operation'=>'uuid-control','sn'=>$sn]);cq_child($cfg,'root',['operation'=>'fault-on','coupon_id'=>3]);
    $before=cq_state($cfg,'old-fault-before');$failEvents=[];
    for($attempt=1;$attempt<=3;$attempt++){
        $worker=cq_work($cfg,'candidate');$state=cq_state($cfg,'old-fault-'.$attempt);$failEvents=array_merge($failEvents,$worker['events']);
        cq_check(cq_business($before)===cq_business($state),'old_fault_'.$attempt.'_all_business_rollback');
        $exceptions=array_values(array_filter($worker['events'],function($e){return $e['event']==='JobExceptionOccurred';}));
        cq_check(count($exceptions)===1&&strpos($exceptions[0]['exception_message'],'Injected cross-version coupon return failure')!==false,'old_fault_'.$attempt.'_actual_injected_exception');
        foreach($worker['events']as$event)cq_check($event['transaction_level']===0,'old_event_transaction_zero_'.$attempt.'_'.$event['event']);
        cq_check(cq_order($state,$sn)===cq_order($before,$sn)&&cq_coupon($state,3)===cq_coupon($before,3),'old_fault_'.$attempt.'_complete_business_rollback');
        cq_check($worker['events'][0]['attempts']===$attempt&&$worker['events'][0]['payload_sha256']===hash('sha256',$published['published']['payload']),'old_native_payload_attempt_'.$attempt);
        if($attempt<3)cq_check(count($state['jobs'])===1&&(int)$state['jobs'][0]['attempts']===$attempt&&$state['jobs'][0]['reserved_at']===null&&!$state['failed_jobs'],'old_retry_released_'.$attempt);
    }
    cq_check(!$state['jobs']&&count($state['failed_jobs'])===1&&$state['failed_jobs'][0]['payload']===$published['published']['payload']&&strpos($state['failed_jobs'][0]['exception'],'Injected cross-version coupon return failure')!==false,'old_exhausted_native_numeric_record_exact_payload');
    $failedId=(int)$state['failed_jobs'][0]['id'];cq_check(count(array_filter($failEvents,function($e){return$e['event']==='JobFailed'&&$e['attempts']===3&&$e['transaction_level']===0;}))===1,'old_original_job_failed_once_attempt3');
    cq_child($cfg,'root',['operation'=>'fault-off']);cq_child($cfg,'candidate',['operation'=>'console','command'=>['queue:retry',(string)$failedId]]);$retried=cq_state($cfg,'old-retried');
    cq_check(!$retried['failed_jobs']&&count($retried['jobs'])===1&&(int)$retried['jobs'][0]['attempts']===0&&$retried['jobs'][0]['payload']===$published['published']['payload'],'old_id_retry_preserved_raw_payload_without_uuid');
    cq_work($cfg,'candidate');$state=cq_state($cfg,'old-recovered');cq_done($state,$sn,3);cq_expired_transition($retried,$state,$sn,3,'old_failure_recovery');
    // Candidate-created serialization is consumed by the original worker and failed provider.
    $sn='CQ48-NEW-FAIL';$published=cq_child($cfg,'candidate',['operation'=>'publish','sn'=>$sn,'coupon_id'=>4,'deadline'=>time()-1]);
    $payload=json_decode($published['published']['payload'],true,512,JSON_THROW_ON_ERROR);cq_check(isset($payload['uuid'])&&strlen($payload['uuid'])===36,'native_new_payload_has_uuid');
    cq_child($cfg,'root',['operation'=>'fault-on','coupon_id'=>4]);$before=cq_state($cfg,'new-fault-before');$newEvents=[];
    for($attempt=1;$attempt<=3;$attempt++){$worker=cq_work($cfg,'root');$state=cq_state($cfg,'new-fault-'.$attempt);$newEvents=array_merge($newEvents,$worker['events']);
        cq_check(cq_business($before)===cq_business($state),'new_fault_'.$attempt.'_all_business_rollback');
        $exceptions=array_values(array_filter($worker['events'],function($e){return$e['event']==='JobExceptionOccurred';}));
        cq_check(count($exceptions)===1&&strpos($exceptions[0]['exception_message'],'Injected cross-version coupon return failure')!==false,'new_fault_'.$attempt.'_actual_injected_exception');
        foreach($worker['events']as$event)cq_check($event['transaction_level']===0,'new_event_transaction_zero_'.$attempt.'_'.$event['event']);
        if($attempt<3)cq_check(count($state['jobs'])===1&&(int)$state['jobs'][0]['attempts']===$attempt&&$state['jobs'][0]['reserved_at']===null&&!$state['failed_jobs'],'new_retry_released_'.$attempt);
        cq_check(cq_order($state,$sn)===cq_order($before,$sn)&&cq_coupon($state,4)===cq_coupon($before,4),'new_fault_'.$attempt.'_complete_rollback');
        cq_check($worker['events'][0]['attempts']===$attempt&&$worker['events'][0]['payload_sha256']===hash('sha256',$published['published']['payload']),'new_payload_old_native_attempt_'.$attempt);}
    cq_check(!$state['jobs']&&count($state['failed_jobs'])===1&&$state['failed_jobs'][0]['payload']===$published['published']['payload'],'old_worker_persisted_new_uuid_payload_legacy_id');
    cq_check(count(array_filter($newEvents,function($e){return$e['event']==='JobFailed'&&$e['attempts']===3&&$e['transaction_level']===0;}))===1,'new_original_job_failed_once_attempt3');
    $failedId=(int)$state['failed_jobs'][0]['id'];cq_child($cfg,'root',['operation'=>'fault-off']);cq_child($cfg,'candidate',['operation'=>'console','command'=>['queue:retry',(string)$failedId]]);
    $retried=cq_state($cfg,'new-retried');cq_check(!$retried['failed_jobs']&&$retried['jobs'][0]['payload']===$published['published']['payload']&&(int)$retried['jobs'][0]['attempts']===0,'actual_old_failed_record_new_kernel_retry');
    cq_work($cfg,'candidate');$state=cq_state($cfg,'new-recovered');cq_done($state,$sn,4);cq_expired_transition($retried,$state,$sn,4,'new_failure_recovery');$expired=$state;
    cq_child($cfg,'candidate',['operation'=>'replay','sn'=>$sn]);cq_work($cfg,'root');$final=cq_state($cfg,'final');cq_done($final,$sn,4);cq_check(!$final['jobs']&&!$final['failed_jobs'],'all_original_jobs_and_failures_cleared');cq_check(cq_business($expired)===cq_business($final),'new_duplicate_all_business_fields_identical');
    cq_check(array_values(array_map(function($row){return$row['order_sn'];},array_slice($final['orders'],2)))===['CQ48-FUTURE','CQ48-OLD-FAIL','CQ48-NEW-FAIL'],'exact_only_three_added_order_sns');
    foreach($initial as$table=>$rows)if(!in_array($table,['orders','coupons','jobs','failed_jobs'],true))cq_check($final[$table]===$rows,'protected_whole_table_'.$table);
    cq_check(array_slice($final['orders'],0,2)===$initial['orders']&&$final['coupons'][0]===$initial['coupons'][0],'original_two_orders_coupon_unchanged');
}catch(Throwable $e){$failure=['class'=>get_class($e),'message'=>$e->getMessage()];}
finally{
    foreach($GLOBALS['cq_processes']as$label=>&$process){if($process['closed'])continue;
        foreach([15,9]as$signal){if(!proc_get_status($process['handle'])['running'])break;proc_terminate($process['handle'],$signal);$until=microtime(true)+3;do{if(!proc_get_status($process['handle'])['running'])break;usleep(50000);}while(microtime(true)<$until);}
        if(proc_get_status($process['handle'])['running']){$cleanup[]=$label;continue;}$process['exit']=proc_close($process['handle']);$process['closed']=true;}
    unset($process);
}
$processes=$GLOBALS['cq_processes'];foreach($processes as&$process)unset($process['handle']);unset($process);
$report=['status'=>!$failure&&!$cleanup?'PASS':'FAIL','checks'=>$GLOBALS['cq_checks'],'tasks'=>$GLOBALS['cq_tasks'],'seconds'=>microtime(true)-$started,'run'=>$run,
    'failure'=>$failure,'cleanup_errors'=>$cleanup,'processes'=>$processes,'scope'=>'Original installed Windows PHP7.4/L6 and PHP8.5/L13 queue kernels, syntheticSQLite and numeric failed records; not no-dev/image/real five-minute wallclock or providers'];
ql_json($run.'/summary.json',$report);echo json_encode(['status'=>$report['status'],'checks'=>$report['checks'],'tasks'=>$report['tasks'],'run'=>$run,'failure'=>$failure],JSON_THROW_ON_ERROR),PHP_EOL;exit($report['status']==='PASS'?0:1);
