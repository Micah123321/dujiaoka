<?php
require dirname(__DIR__).'/QueueLifecycle/bootstrap.php';
require dirname(__DIR__).'/LinuxLifecycle/process.php';
require dirname(__DIR__).'/ScheduleWorkerLifecycle/process.php';
if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==1000)throw new RuntimeException('Actual outside Linux uid1000 required.');
$root=realpath(dirname(__DIR__,3));$run=realpath($argv[1]??'');
if(!$run||dirname($run)!==$root.'/tests/.runtime/modern-image'||!preg_match('/^run-layers-[0-9-]+-[0-9a-f]{24}$/D',basename($run)))throw new RuntimeException('Owned actual native run required.');
$read=function($file){return json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);};$cfg=$read($run.'/run.json');$base='/var/tmp/dujiaoka-image50-'.$cfg['marker'];$rootfs=$base.'/rootfs';
if($cfg['rootfs']!==$rootfs||realpath($rootfs)!==$rootfs||trim(file_get_contents($base.'/.marker'))!==$cfg['marker']||trim(file_get_contents($run.'/marker'))!==$cfg['marker'])throw new RuntimeException('Actual original image marker required.');
$stage=$read($run.'/native-stage-summary.json');$unpack=$read($run.'/unpack-summary.json');
if($stage['status']!=='PASS'||$unpack['status']!=='PASS'||$stage['rootfs']!==$rootfs||file_exists($run.'/native-runtime-summary.json')||file_exists($rootfs.'/probe/native.started')||file_exists($rootfs.'/probe/http.started')||file_exists($rootfs.'/probe/http.namespace.started')||file_exists($rootfs.'/probe/queue.started')||file_exists($rootfs.'/probe/queue.namespace.started')||file_exists($run.'/http-runtime-summary.json')||file_exists($run.'/queue-runtime-summary.json'))throw new RuntimeException('Fresh staged original image only.');
file_put_contents($rootfs.'/probe/native.started',$cfg['marker']);
$GLOBALS['ql_run']=$run;$GLOBALS['ql_role']='original-image-native';$GLOBALS['ql_config']=['root'=>$root,'marker'=>$cfg['marker']];$GLOBALS['ll_processes']=[];$GLOBALS['sw_owned_children']=[];$GLOBALS['sw_groups']=[];
$failure=null;$cleanup=[];
try{
    ll_spawn(['/usr/bin/setsid','/usr/bin/unshare','--user','--map-root-user','--mount','--pid','--fork','--kill-child','/bin/sh',__DIR__.'/namespace-runtime.sh',$rootfs,$cfg['marker']],'native-image');
    ql_check(ll_wait('native-image',180)===0,'actual_original_image_namespace_child_exit0');
    ql_check(is_file($rootfs.'/probe/native.completed')&&is_file($rootfs.'/probe/image-contract.json'),'native_completed_marker_and_actual_php_contract');
    $p=$read($rootfs.'/probe/image-contract.json');ql_check($p['status']==='PASS'&&$p['php']==='8.5.11'&&$p['binary']==='/usr/local/bin/php'&&$p['sapi']==='cli'&&$p['framework']==='13.35.0'&&$p['uid']===0&&$p['base']==='/app'&&!$p['issues'],'actual_original_image_php_app_and_observation');
    ql_check(trim(preg_replace('/\s+/',':',trim(file_get_contents($rootfs.'/probe/namespace-uid-map.txt'))))==='0:1000:1','actual_single_namespace_uid_map');
    foreach($stage['protected_original_binaries']as$file=>$sha)ql_check(hash_file('sha256',$rootfs.$file)===$sha,'original_image_binary_hash_'.$file);
    foreach(glob($rootfs.'/probe/*.stderr')as$file)ql_check(!preg_match('/(?:^|[\r\n])(?:PHP )?(?:Fatal error|Warning|Notice|Deprecated|Parse error):/',file_get_contents($file)),'native_no_raw_php_diagnostic_'.basename($file));
    foreach(glob($rootfs.'/probe/*')as$file)if(is_file($file)&&!is_link($file)&&preg_match('/\.(?:json|stdout|stderr|txt|bin)$/D',basename($file)))copy($file,$run.'/'.basename($file));
}catch(Throwable $e){$failure=['class'=>get_class($e),'message'=>$e->getMessage()];}
finally{foreach(array_keys($GLOBALS['ll_processes'])as$label)try{ll_cleanup_owned($label);}catch(Throwable $e){$cleanup[]=$e->getMessage();}}
// Retain partial child output without asserting that an incomplete boot succeeded.
foreach(glob($rootfs.'/probe/*')as$file)if(is_file($file)&&!is_link($file)&&preg_match('/\.(?:json|stdout|stderr|txt|bin)$/D',basename($file))&&!file_exists($run.'/'.basename($file)))copy($file,$run.'/'.basename($file));
$result=['status'=>!$failure&&!$cleanup?'PASS':'FAIL','failure'=>$failure,'cleanup_errors'=>$cleanup,'run'=>$run,'rootfs'=>$rootfs,'outside_uid'=>posix_geteuid(),'processes'=>array_map(function($p){unset($p['proc']);return$p;},$GLOBALS['ll_processes']),'owned'=>array_values(array_filter(array_map(function($child){return$child['identity'];},$GLOBALS['ll_processes']))),'scope'=>'Actual original image /usr/local/bin/php and Composer in user/mount/PID chroot, single0->outside1000 UID mapping. Source/current100vendor copied; same original Composer validate/install/platform. No Engine build/run, stockroot to application, systemcron or FPM/Nginx service claim.'];
file_put_contents($run.'/native-runtime-summary.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);echo json_encode(['status'=>$result['status'],'failure'=>$failure,'run'=>$run],JSON_THROW_ON_ERROR),PHP_EOL;exit($result['status']==='PASS'?0:1);
