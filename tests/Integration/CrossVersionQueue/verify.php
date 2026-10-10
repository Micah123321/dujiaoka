<?php
require __DIR__.'/bootstrap.php';
$cfg=cq_manifest($argv[1]??'');$run=$cfg['run'];$report=json_decode(file_get_contents($run.'/summary.json'),true,512,JSON_THROW_ON_ERROR);$checks=0;
$check=function($ok,$name)use(&$checks){if(!$ok)throw new RuntimeException('Independent queue proof failed: '.$name);$checks++;};
$check($report['status']==='PASS'&&!$report['failure']&&!$report['cleanup_errors'],'real_run_complete');
$tasks=glob($run.'/tasks/*.json');$tasks=array_values(array_filter($tasks,function($p){return substr($p,-12)!=='.result.json';}));sort($tasks);
$check(count($tasks)===$report['tasks']&&count($report['processes'])===$report['tasks'],'raw_task_count');$workerResults=[];$publish=[];$retry=[];
foreach($tasks as$file){
    $task=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$result=json_decode(file_get_contents($file.'.result.json'),true,512,JSON_THROW_ON_ERROR);
    $number=substr(basename($file),0,3);$proc=$report['processes'][$number];$tree=$result['identity']['tree'];$runtime=$cfg['runtimes'][$tree];
    $check($proc['closed']&&$proc['exit']===0&&$proc['pid']===$result['identity']['pid'],'closed_root_'.$number);
    $check($result['status']==='PASS'&&!$result['issues']&&!$result['last_php_error']&&$result['db_closed']&&$result['transaction_level']===0&&!filesize($file.'.stderr'),'raw_php_clean_'.$number);
    $identity=$result['identity'];$vendor=$tree==='root'?$cfg['root'].'/tests/Compatibility/Laravel6/vendor':$cfg['root'].'/tests/Compatibility/Laravel12/vendor';
    $check($identity['binary']===$runtime['binary']&&$identity['ini']===$runtime['ini']&&$identity['db']===$run.'/queue.sqlite'&&$identity['storage']===$run.'/'.$tree.'/storage','actual_child_paths_'.$number);
    $check($identity['php']===($tree==='root'?'7.4.33':'8.5.11')&&$identity['framework']===($tree==='root'?'6.20.42':'13.35.0')&&$identity['environment']==='cross-version-queue'
        &&$identity['failed_driver']==='database'&&$identity['provider']==='Illuminate'.chr(92).'Queue'.chr(92).'Failed'.chr(92).'DatabaseFailedJobProvider'&&$identity['loaders']===[$vendor],'original_version_provider_loader_'.$number);
    foreach($identity['sources']as$class=>$source){$prefix=strpos($class,'App'.chr(92))===0?$cfg['root'].'/app/':$vendor.'/laravel/framework/src/';$check(strpos($source['path'],$prefix)===0&&hash_file('sha256',$source['path'])===$source['sha256'],'actual_source_sha_'.$number.'_'.$class);}
    foreach($result['includes']as$included){$path=ql_normalize($included);$allowed=false;foreach([$vendor.'/',$cfg['root'].'/app/',$cfg['root'].'/config/',$cfg['root'].'/bootstrap/',$cfg['root'].'/routes/',$run.'/'.$tree.'/']as$prefix)if(strpos($path,$prefix)===0)$allowed=true;
        if(in_array($path,[$cfg['root'].'/tests/Integration/CrossVersionQueue/worker.php',$cfg['root'].'/tests/Integration/CrossVersionQueue/bootstrap.php',$cfg['root'].'/tests/Integration/QueueLifecycle/bootstrap.php',$cfg['root'].'/tests/Fixtures/AdminData.php'],true))$allowed=true;
        $check($allowed,'included_origin_'.$number.'_'.basename($path));}
    if($task['operation']==='publish'){$publish[$task['sn']]=['tree'=>$tree,'task'=>$task,'result'=>$result];$check(file_get_contents($run.'/payload-'.$task['sn'].'.json')===$result['published']['payload'],'actual_native_producer_payload_'.$task['sn']);}
    if($task['operation']==='console'&&$task['command'][0]==='queue:work')$workerResults[]=$result;
    if($task['operation']==='console'&&$task['command'][0]==='queue:retry')$retry[]=['task'=>$task,'identity'=>$identity];
    if($task['operation']==='uuid-control')$check($result['uuid_negative']['payload_uuid_absent']&&strpos($result['uuid_negative']['message'],'uuid')!==false,'actual_uuid_old_payload_control');
}
$check(count($publish)===3&&count($retry)===2,'native_producers_and_actual_id_retries');
$state=function($label)use($run){return json_decode(file_get_contents($run.'/state-'.$label.'.json'),true,512,JSON_THROW_ON_ERROR);};
$snOrder=function($s,$sn){$rows=array_values(array_filter($s['orders'],function($r)use($sn){return$r['order_sn']===$sn;}));if(count($rows)!==1)throw new RuntimeException('Exact proof order required.');return$rows[0];};
$coupon=function($s,$id){return array_values(array_filter($s['coupons'],function($r)use($id){return(int)$r['id']===$id;}))[0];};
foreach(['old'=>['CQ48-OLD-FAIL',3,'root','candidate'],'new'=>['CQ48-NEW-FAIL',4,'candidate','root']]as$label=>$spec){
    list($sn,$cid,$writer,$consumer)=$spec;$native=$publish[$sn]['result']['published']['payload'];$payload=json_decode($native,true,512,JSON_THROW_ON_ERROR);$before=$state($label.'-fault-before');
    $check($publish[$sn]['tree']===$writer&&isset($payload['uuid'])===($writer==='candidate')&&$payload['maxTries']===3,'genuine_cross_payload_'.$label);
    $events=[];foreach($workerResults as$r)foreach($r['events']as$e)if($e['payload_sha256']===hash('sha256',$native)){$check($r['identity']['tree']===$consumer||in_array($e['event'],['JobProcessing','JobProcessed'],true),'expected_exhaust_consumer_'.$label);$events[]=$e;}
    foreach([1,2,3]as$attempt){$s=$state($label.'-fault-'.$attempt);$check($snOrder($s,$sn)===$snOrder($before,$sn)&&$coupon($s,$cid)===$coupon($before,$cid),'actual_full_sql_rollback_'.$label.'_'.$attempt);
        $processing=array_values(array_filter($events,function($e)use($attempt){return$e['event']==='JobProcessing'&&$e['attempts']===$attempt;}));$check(count($processing)>=1,'actual_native_attempt_'.$label.'_'.$attempt);
        if($attempt<3)$check(count($s['jobs'])===1&&(int)$s['jobs'][0]['attempts']===$attempt&&$s['jobs'][0]['reserved_at']===null&&$s['jobs'][0]['payload']===$native&&!$s['failed_jobs'],'actual_release_payload_'.$label.'_'.$attempt);
        else $check(!$s['jobs']&&count($s['failed_jobs'])===1&&$s['failed_jobs'][0]['payload']===$native&&strpos($s['failed_jobs'][0]['exception'],'Injected cross-version coupon return failure')!==false,'actual_old_schema_exhausted_record_'.$label);
    }
    $fail=array_values(array_filter($events,function($e){return$e['event']==='JobFailed';}));$check(count($fail)===1&&$fail[0]['attempts']===3&&$fail[0]['transaction_level']===0,'actual_single_failure_'.$label);
    $retried=$state($label.'-retried');$check(!$retried['failed_jobs']&&count($retried['jobs'])===1&&(int)$retried['jobs'][0]['attempts']===0&&$retried['jobs'][0]['payload']===$native,'actual_original_retry_payload_'.$label);
    $recovered=$state($label.'-recovered');$order=$snOrder($recovered,$sn);$check((int)$order['status']===-1&&(int)$order['coupon_ret_back']===1&&(int)$coupon($recovered,$cid)['ret']===1,'actual_recovered_once_'.$label);
}
$future=$publish['CQ48-FUTURE'];$deadline=$future['task']['deadline'];$payload=$future['result']['published']['payload'];$early=$state('future-early');$due=$state('future-due');
$check((int)$snOrder($early,'CQ48-FUTURE')['status']===1&&(int)$coupon($early,2)['ret']===0&&!$early['jobs'],'actual_future_early_ack');
$check((int)$snOrder($due,'CQ48-FUTURE')['status']===-1&&(int)$coupon($due,2)['ret']===1,'actual_future_deadline_then_expiry');
$processing=[];foreach($workerResults as$r)foreach($r['events']as$e)if($e['event']==='JobProcessing'&&$e['payload_sha256']===hash('sha256',$payload))$processing[]=$e;
$check(count($processing)===3&&$processing[0]['time']<$deadline&&$processing[1]['time']>=$deadline&&$processing[2]['time']>=$deadline,'original_payload_real_clock_order');
$initial=$state('initial');$final=$state('final');$db=new PDO('sqlite:'.$run.'/queue.sqlite');$db->exec('PRAGMA query_only=ON');$db->beginTransaction();
foreach($final as$table=>$rows){$actual=$db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);$check($actual===$rows,'reopened_final_sql_'.$table);if(!in_array($table,['orders','coupons','jobs','failed_jobs'],true))$check($initial[$table]===$rows,'protected_whole_original_table_'.$table);}
$db->commit();$db=null;$check(!$final['jobs']&&!$final['failed_jobs']&&array_slice($final['orders'],0,2)===$initial['orders']&&$final['coupons'][0]===$initial['coupons'][0],'actual_old_records_and_final_empty_queues');
$proof=['status'=>'PASS','checks'=>$checks,'tasks'=>count($tasks),'original_native_producers'=>3,'numeric_id_retries'=>2,'real_future_seconds'=>15,'queue_empty'=>true,'failed_empty'=>true,'run'=>$run];
ql_json($run.'/parent-proof-48.json',$proof);echo json_encode($proof,JSON_THROW_ON_ERROR),PHP_EOL;
