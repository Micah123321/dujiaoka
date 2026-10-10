<?php
require_once __DIR__.'/bootstrap.php';
require dirname(__DIR__).'/LinuxLifecycle/process.php';
require dirname(__DIR__).'/ScheduleWorkerLifecycle/process.php';
require __DIR__.'/command.php';
function pr_route_self_metadata($bytes,&$count)
{
    $ids=[];$count=0;
    return preg_replace_callback('/s:4:"self";s:32:"([0-9a-f]{32})";/',function($match)use(&$ids,&$count){
        $count++;if(!isset($ids[$match[1]]))$ids[$match[1]]=str_pad(dechex(count($ids)+1),32,'0',STR_PAD_LEFT);
        return 's:4:"self";s:32:"'.$ids[$match[1]].'";';
    },$bytes);
}

if(($argv[1]??'')==='--child'){
    $control=pr_manifest($argv[2]??'');$source=pr_manifest($argv[3]??'');$mode=$argv[4]??'';
    if(!in_array($mode,['console','http','config','config-native','route','route-native'],true)||json_decode(file_get_contents($source['run'].'/runtime-summary.json'),true)['status']!=='PASS')throw new RuntimeException('Named kernel and closed synthetic release required.');
    $run=$control['run'];pr_environment($source);
    $private=['LARAVEL_STORAGE_PATH'=>$run.'/storage','VIEW_COMPILED_PATH'=>$run.'/storage/framework/views','DB_DATABASE'=>$run.'/fixture.sqlite','CACHE_PREFIX'=>'postboot-'.$control['marker'],'SESSION_DRIVER'=>'array'];
    foreach(['CONFIG','ROUTES','PACKAGES','SERVICES','EVENTS']as$name)$private['APP_'.$name.'_CACHE']=$run.'/cache/'.strtolower($name).'.php';
    foreach($private as$key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
    $GLOBALS['pr_control_original_config']=in_array($mode,['config-native','route-native'],true);
    require $source['release'].'/vendor/autoload.php';pr_observe_php_errors();$app=require $source['release'].'/bootstrap/app.php';
    $app->useEnvironmentPath($run)->loadEnvironmentFrom('.env.postboot');$app->useStoragePath($run.'/storage');
    $app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\RegisterProviders::class,function($app)use($run){$app['config']->set(['cache.stores.file.path'=>$run.'/storage/framework/cache/data','cache.stores.file.lock_path'=>$run.'/storage/framework/cache/locks']);});
    if($mode==='http')$app->instance('request',Illuminate\Http\Request::create($source['url'].'/captcha/default','GET'));
    $type=$mode==='http'?Illuminate\Contracts\Http\Kernel::class:Illuminate\Contracts\Console\Kernel::class;$kernel=$app->make($type);$kernel->bootstrap();
    if($app->storagePath()!==$run.'/storage'||$app['config']->get('database.connections.sqlite.database')!==$run.'/fixture.sqlite'||!$GLOBALS['pr_php_observer']['installed'])throw new RuntimeException('Actual private postboot observation required.');
    if(in_array($mode,['config','config-native','route','route-native'],true)){
        $kind=strpos($mode,'route')===0?'route':'config';
        $GLOBALS['pr_fresh_app_control']=function($fresh)use($run,$argv,$kind){$fresh->useEnvironmentPath($run)->loadEnvironmentFrom('.env.postboot');if(($argv[5]??'issue')==='issue')$fresh->afterBootstrapping(Illuminate\Foundation\Bootstrap\BootProviders::class,function()use($kind){trigger_error('PRODUCTION_RELEASE_POSTBOOT_'.$kind,E_USER_DEPRECATED);});};
        if($kind==='route'){
            // Only fixture generated route names share a deterministic public random factory.
            $routeSequence=0;Illuminate\Support\Str::createRandomStringsUsing(function($length)use(&$routeSequence){return substr(str_repeat(hash('sha256','route-proof-'.++$routeSequence),8),0,$length);});
        }
        try{if($kernel->call($kind.':cache')!==0)throw new RuntimeException('Original cache command failed.');}
        finally{if($kind==='route')Illuminate\Support\Str::createRandomStringsNormally();}
        $cached=$run.'/cache/'.($kind==='route'?'routes':'config').'.php';if(!is_file($cached))throw new RuntimeException('Actual private cache missing.');
        if(($argv[5]??'issue')==='clean'){
            copy($cached,$run.'/'.$mode.'-cache.php');
            if($kind==='route'){
                $prior=$app['router'];$router=new Illuminate\Routing\Router(new Illuminate\Events\Dispatcher($app),$app);$app->instance('router',$router);
                try{require $cached;$matched=[];foreach(['/','/buy/1','/captcha/default']as$path){$route=$router->getRoutes()->match(Illuminate\Http\Request::create($source['url'].$path,'GET'));$matched[$path]=['uri'=>$route->uri(),'methods'=>$route->methods(),'name'=>$route->getName(),'action'=>$route->getActionName()];}ql_json($run.'/'.$mode.'-matched.json',$matched);}
                finally{$app->instance('router',$prior);}
            }
        }
        if(in_array($mode,['config','route'],true)&&count($GLOBALS['pr_php_observer']['applications'])!==2)throw new RuntimeException('Both actual cache applications must be observed.');
    }elseif(($argv[5]??'issue')==='issue')trigger_error('PRODUCTION_RELEASE_POSTBOOT_'.$mode,E_USER_DEPRECATED);
    if(error_get_last()!==null)throw new RuntimeException('Original framework should handle the diagnostic.');
    $app['db']->disconnect();ql_json($run.'/'.$mode.'-'.($argv[5]??'issue').'.completed.json',['status'=>'PASS','mode'=>$mode,'pid'=>getmypid(),'private_db'=>$run.'/fixture.sqlite']);echo 'Original '.$mode.' kernel handles diagnostic and child still returns0',PHP_EOL;exit(0);
}
if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==1000)throw new RuntimeException('Existing Linux UID1000 required.');
$source=pr_manifest($argv[1]??'');$root=$source['root'];$marker=bin2hex(random_bytes(12));$run=$root.'/tests/.runtime/production-release/run-controls-postboot-'.gmdate('Ymd-His').'-'.$marker;
mkdir($run.'/release',0700,true);foreach(['cache','storage/framework/views','storage/framework/cache/data','storage/framework/cache/locks','storage/logs']as$dir)mkdir($run.'/'.$dir,0700,true);
file_put_contents($run.'/marker',$marker);file_put_contents($run.'/events.jsonl','');file_put_contents($run.'/.env.postboot','');touch($run.'/fixture.sqlite');
$cfg=['root'=>$root,'run'=>$run,'release'=>$run.'/release','marker'=>$marker,'key'=>'base64:'.base64_encode(str_repeat('N',32)),'port'=>1,'url'=>'http://127.0.0.1:1','ini'=>php_ini_loaded_file(),'php'=>PHP_BINARY];ql_json($run.'/run.json',$cfg);
$GLOBALS['ql_run']=$run;$GLOBALS['ql_role']='postboot-controls';$GLOBALS['ql_config']=['root'=>$root,'marker'=>$marker];$GLOBALS['ll_processes']=[];$GLOBALS['sw_owned_children']=[];$GLOBALS['sw_groups']=[];
$protected=[];foreach(['run.json','runtime-summary.json','stage-summary.json','access.log','http-events.jsonl','release/storage/fixture.sqlite','release/bootstrap/cache/config.php','release/bootstrap/cache/packages.php','release/bootstrap/cache/services.php','release/bootstrap/cache/routes.php']as$file)$protected[$file]=hash_file('sha256',$source['run'].'/'.$file);
$failure=null;$cleanup=[];
try{
    pr_command([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'--child',$run,$source['run'],'config-native','clean'],'config-native',30);
    ql_check(is_file($run.'/config-native-clean.completed.json')&&is_file($run.'/config-native-cache.php'),'actual_native_unmodified_config_cache_baseline');
    pr_command([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'--child',$run,$source['run'],'route-native','clean'],'route-native',30);
    ql_check(is_file($run.'/route-native-clean.completed.json')&&is_file($run.'/route-native-cache.php'),'actual_native_unmodified_route_cache_baseline');
    foreach(['console','http','config','route']as$mode){
    $cleanLabel='clean-'.$mode;pr_command([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'--child',$run,$source['run'],$mode,'clean'],$cleanLabel,30);
    $clean=json_decode(file_get_contents($run.'/'.$cleanLabel.'.php-proof.json'),true,512,JSON_THROW_ON_ERROR);
    $completion=json_decode(file_get_contents($run.'/'.$mode.'-clean.completed.json'),true,512,JSON_THROW_ON_ERROR);
    ql_check($completion['status']==='PASS'&&$completion['pid']===$clean['pid']&&$completion['mode']===$mode,'actual_'.$mode.'_positive_boot_completed');
    ql_check($clean['php_observer']['installed']&&!$clean['php_observer']['issues']&&$clean['last_php_error']===null,'actual_'.$mode.'_positive_observation_pass');
    $caught=null;$label='postboot-'.$mode;
    try{pr_command([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'--child',$run,$source['run'],$mode],$label,30);}catch(RuntimeException $e){$caught=$e->getMessage();}
    $proof=json_decode(file_get_contents($run.'/'.$label.'.php-proof.json'),true,512,JSON_THROW_ON_ERROR);
    $completion=json_decode(file_get_contents($run.'/'.$mode.'-issue.completed.json'),true,512,JSON_THROW_ON_ERROR);
    ql_check($completion['status']==='PASS'&&$completion['pid']===$proof['pid']&&$completion['mode']===$mode,'actual_'.$mode.'_negative_boot_completed');
    ql_check($GLOBALS['ll_processes'][$label]['closed']&&$GLOBALS['ll_processes'][$label]['exit']===0,'actual_'.$mode.'_child_still_exit0');
    ql_check($proof['last_php_error']===null&&$proof['php_observer']['installed']&&count($proof['php_observer']['issues'])===1&&$proof['php_observer']['issues'][0]['level']===E_USER_DEPRECATED
        &&$proof['php_observer']['issues'][0]['message']==='PRODUCTION_RELEASE_POSTBOOT_'.$mode,'actual_'.$mode.'_postboot_diagnostic_captured');
    ql_check($caught==='Assertion failed: native_'.$label.'_framework_php_diagnostics','actual_'.$mode.'_exit0_diagnostic_rejected');
    ql_check($proof['identity']['db']===$run.'/fixture.sqlite'&&$proof['identity']['storage']===$run.'/storage','actual_'.$mode.'_private_control_state');
}
ql_check(hash_file('sha256',$run.'/config-native-cache.php')===hash_file('sha256',$run.'/config-cache.php'),'original_and_observed_config_cache_bytes_identical');
$nativeRoute=file_get_contents($run.'/route-native-cache.php');$observedRoute=file_get_contents($run.'/route-cache.php');$nativeCount=0;$observedCount=0;
$normalizedNative=pr_route_self_metadata($nativeRoute,$nativeCount);$normalizedObserved=pr_route_self_metadata($observedRoute,$observedCount);
ql_check($nativeCount>0&&$nativeCount===$observedCount&&$normalizedNative===$normalizedObserved,'all_route_cache_bytes_equal_except_closure_self_identifiers');
ql_check(json_decode(file_get_contents($run.'/route-native-matched.json'),true)===json_decode(file_get_contents($run.'/route-matched.json'),true),'original_and_observed_actual_cached_routes_match');
ql_json($run.'/route-cache-comparison.json',['status'=>'PASS','native_sha'=>hash('sha256',$nativeRoute),'observed_sha'=>hash('sha256',$observedRoute),'raw_identical'=>$nativeRoute===$observedRoute,'normalized_sha'=>hash('sha256',$normalizedNative),'self_fields'=>$nativeCount,'scope'=>'All bytes equal after bijective encounter-order normalization of serialized closure self metadata only. Same public fixture Str factory stabilizes generated route names. Both unmodified original and observed cache files separately reload and match real home/buy/captcha routes without executing controllers.']);
foreach($protected as$file=>$sha)ql_check(hash_file('sha256',$source['run'].'/'.$file)===$sha,'successful_release_retained_'.$file);
}catch(Throwable $e){$failure=['class'=>get_class($e),'message'=>$e->getMessage()];}
finally{try{sw_track_children();sw_cleanup_children();}catch(Throwable $e){$cleanup[]=$e->getMessage();}foreach(array_keys($GLOBALS['ll_processes'])as$label)try{ll_cleanup_owned($label);}catch(Throwable $e){$cleanup[]=$e->getMessage();}}
$events=array_map(function($line){return json_decode($line,true,512,JSON_THROW_ON_ERROR);},file($run.'/events.jsonl',FILE_SKIP_EMPTY_LINES));$checks=count(array_filter($events,function($e){return$e['event']==='CheckPassed';}));
$report=['status'=>!$failure&&!$cleanup?'PASS':'FAIL','checks'=>$checks,'failure'=>$failure,'cleanup_errors'=>$cleanup,'source'=>$source['run'],'run'=>$run,'scope'=>'Real console/HTTP Kernel and config:cache/route:cache in ten separate no-dev CLI children. Original framework handles root or actual second-App deprecation, child0, validator rejects. Unmodified native config baseline and observed subclass inherit original handle; Exact private config cache bytes identical; route cache differs only closure self identity metadata, with all remaining bytes and actually reloaded route matches equal. Generated route names alone use the same public fixture Str factory. Fresh config/route adapters is external observation only with original method body guarded. No extra FPM request or accepted source/cache/SQL mutation.',
    'processes'=>array_map(function($p){unset($p['proc']);return$p;},$GLOBALS['ll_processes']),'owned'=>array_values($GLOBALS['sw_owned_children']),'protected'=>$protected];ql_json($run.'/controls-summary.json',$report);echo json_encode(['status'=>$report['status'],'checks'=>$checks,'run'=>$run,'failure'=>$failure],JSON_THROW_ON_ERROR),PHP_EOL;exit($report['status']==='PASS'?0:1);
