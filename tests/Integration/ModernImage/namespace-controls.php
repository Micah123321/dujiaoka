<?php
require dirname(__DIR__).'/QueueLifecycle/bootstrap.php';require dirname(__DIR__).'/LinuxLifecycle/process.php';require dirname(__DIR__).'/ScheduleWorkerLifecycle/process.php';
if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==1000)throw new RuntimeException('Actual Linux outside uid1000 required.');
$root=realpath(dirname(__DIR__,3));$marker=bin2hex(random_bytes(12));$run=$root.'/tests/.runtime/modern-image/run-namespace-controls-'.gmdate('Ymd-His').'-'.$marker;mkdir($run,0700,true);file_put_contents($run.'/marker',$marker);
$GLOBALS['ql_run']=$run;$GLOBALS['ql_role']='namespace-controls';$GLOBALS['ql_config']=['root'=>$root,'marker'=>$marker];$GLOBALS['ll_processes']=[];$failure=null;$cleanup=[];$child=null;
try{
    $prefix=['/usr/bin/setsid','/usr/bin/unshare','--user','--map-root-user','--mount','--pid','--fork','--kill-child'];
    ll_spawn(array_merge($prefix,['/usr/bin/id']),'positive');ql_check(ll_wait('positive',10)===0&&strpos(file_get_contents($run.'/positive.stdout'),'uid=0(root)')!==false,'actual_new_pid_namespace_positive_child0');
    ll_spawn(array_merge($prefix,['/usr/bin/sleep','30']),'cleanup');$pid=$GLOBALS['ll_processes']['cleanup']['pid'];
    $child=ll_until(function()use($pid){$children=@file_get_contents('/proc/'.$pid.'/task/'.$pid.'/children');foreach(preg_split('/\s+/',trim((string)$children))as$id){if(!ctype_digit($id))continue;$stat=sw_stat((int)$id);$ns=@readlink('/proc/'.$id.'/ns/pid');if($stat&&$ns&&$ns!==readlink('/proc/self/ns/pid'))return['pid'=>(int)$id,'start_ticks'=>$stat['start_ticks'],'namespace'=>$ns];}return false;},'actual separate PID namespace child',5);
    ql_check($child['namespace']!==readlink('/proc/self/ns/pid'),'actual_expected_namespace_change_recorded');
    ll_cleanup_owned('cleanup');ql_check($GLOBALS['ll_processes']['cleanup']['closed'],'actual_owned_unshare_parent_closed');
    ll_until(function()use($child){$stat=sw_stat($child['pid']);return!$stat||$stat['start_ticks']!==$child['start_ticks']||$stat['state']==='Z';},'actual_kill_child_namespace_teardown',5);
    ql_check(true,'kernel_kill_child_actual_identity_not_running');
}catch(Throwable $e){$failure=['class'=>get_class($e),'message'=>$e->getMessage()];}
finally{foreach(array_keys($GLOBALS['ll_processes'])as$label)try{ll_cleanup_owned($label);}catch(Throwable $e){$cleanup[]=$e->getMessage();}}
$result=['status'=>!$failure&&!$cleanup?'PASS':'FAIL','run'=>$run,'failure'=>$failure,'cleanup_errors'=>$cleanup,'outside_uid'=>posix_geteuid(),'actual_namespace_child'=>$child,'processes'=>array_map(function($p){unset($p['proc']);return$p;},$GLOBALS['ll_processes']),'scope'=>'Actual same dedicated LL owned-handle runner used by corrected native image control accepts intentional PID namespace and child0. Owned unshare parent receives TERM then KILL if required; actual exit/signal are recorded. Kernel --kill-child tears down real namespace child; startticks/state prove no active tracked identity, not necessarily an already reaped zombie. Shared same-namespace SW guard unchanged. No App/image repeat or host privileges.' ];file_put_contents($run.'/summary.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);echo json_encode(['status'=>$result['status'],'failure'=>$failure,'run'=>$run],JSON_THROW_ON_ERROR),PHP_EOL;exit($result['status']==='PASS'?0:1);
