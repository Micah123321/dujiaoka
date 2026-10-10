<?php
require __DIR__.'/bootstrap.php';
$cfg=cq_manifest($argv[1]??'');
if(is_file($cfg['run'].'/summary.json')){fwrite(STDERR,'Completed cross-version queue run is retained.'.PHP_EOL);exit(2);}
$tree=$argv[2]??'';$taskFile=ql_normalize($argv[3]??'');
$canonical=ql_normalize(realpath($taskFile)?:'');
if(dirname($canonical)!==$cfg['run'].'/tasks'||$canonical!==$taskFile||is_link($taskFile)||is_link($cfg['run'].'/tasks')
    ||!preg_match('/^[0-9]{3}-(root|candidate)-(seed|publish|replay|fault-on|fault-off|uuid-control|console)[.]json$/D',basename($taskFile)))throw new RuntimeException('Canonical direct owned queue task required.');
$task=json_decode(file_get_contents($taskFile),true,512,JSON_THROW_ON_ERROR);$result=['status'=>'FAIL','issues'=>[],'events'=>[]];$app=null;$expectUuid=false;
$observeError=function($level,$message,$file,$line)use(&$result,&$expectUuid){
    if(!($level&error_reporting()))return true;
    if($expectUuid)throw new ErrorException($message,0,$level,$file,$line);
    $result['issues'][]=['level'=>$level,'message'=>$message,'file'=>$file,'line'=>$line];throw new ErrorException($message,0,$level,$file,$line);
};
set_error_handler($observeError);
register_shutdown_function(function()use(&$result,&$app,$taskFile){
    $result['last_php_error']=error_get_last();if($app&&$app->resolved('db')){$result['transaction_level']=$app['db']->connection()->transactionLevel();$app['db']->disconnect();$result['db_closed']=true;}
    $result['includes']=get_included_files();ql_json($taskFile.'.result.json',$result);
});
try{
    list($app,$identity)=cq_boot($cfg,$tree,$observeError);$result['identity']=$identity;
    set_error_handler($observeError);
    foreach(['JobProcessing','JobProcessed','JobExceptionOccurred','JobFailed']as$eventName)$app['events']->listen('Illuminate'.chr(92).'Queue'.chr(92).'Events'.chr(92).$eventName,function($event)use(&$result,$eventName,$app){
        $result['events'][]=['event'=>$eventName,'job_id'=>(int)$event->job->getJobId(),'attempts'=>$event->job->attempts(),'payload_sha256'=>hash('sha256',$event->job->getRawBody()),
            'transaction_level'=>$app['db']->connection()->transactionLevel(),'exception_class'=>isset($event->exception)?get_class($event->exception):null,'exception_message'=>isset($event->exception)?$event->exception->getMessage():null,'time'=>microtime(true)];
    });
    $db=$app['db']->connection();$operation=$task['operation'];
    if($operation!=='seed'&&$db->table('fixture_marker')->where('name','cross-version-queue-'.$cfg['marker'])->count()!==1)throw new RuntimeException('Actual owned database marker required before queue mutation.');
    if($operation==='seed'){
        if($db->select("SELECT name FROM sqlite_master WHERE type='table'"))throw new RuntimeException('Fresh queue fixture required.');
        require dirname(__DIR__,2).'/Fixtures/AdminData.php';Tests\Fixtures\AdminData::create(false);
        Illuminate\Support\Facades\Schema::create('jobs',function($t){$t->bigIncrements('id');$t->string('queue')->index();$t->longText('payload');$t->unsignedTinyInteger('attempts');$t->unsignedInteger('reserved_at')->nullable();$t->unsignedInteger('available_at');$t->unsignedInteger('created_at');});
        Illuminate\Support\Facades\Schema::create('failed_jobs',function($t){$t->bigIncrements('id');$t->text('connection');$t->text('queue');$t->longText('payload');$t->longText('exception');$t->timestamp('failed_at')->useCurrent();});
        $db->table('fixture_marker')->insert(['name'=>'cross-version-queue-'.$cfg['marker']]);
    }elseif($operation==='publish'){
        $sn=$task['sn'];$deadline=$task['deadline']??null;$now=date('Y-m-d H:i:s');
        $db->table('coupons')->insert(['id'=>$task['coupon_id'],'coupon'=>'FIXTURE48-'.$sn,'discount'=>'1.00','ret'=>0,'created_at'=>$now,'updated_at'=>$now]);
        $row=(array)$db->table('orders')->where('id',1)->first();unset($row['id']);$row['order_sn']=$sn;$row['coupon_id']=$task['coupon_id'];$row['coupon_ret_back']=0;$row['status']=1;
        $row['created_at']=$task['old_created']??$now;$row['updated_at']=$now;$db->table('orders')->insert($row);
        $job=(new App\Jobs\OrderExpired($sn,$deadline))->onConnection('database')->onQueue('fixture48');
        $app->make(Illuminate\Contracts\Bus\Dispatcher::class)->dispatch($job);
        $queued=(array)$db->table('jobs')->orderBy('id','desc')->first();$result['published']=$queued;
        file_put_contents($cfg['run'].'/payload-'.$sn.'.json',$queued['payload']);
    }elseif($operation==='replay'){
        $payload=file_get_contents($cfg['run'].'/payload-'.$task['sn'].'.json');$result['job_id']=$app['queue']->connection()->pushRaw($payload,'fixture48');
    }elseif($operation==='fault-on'){
        $db->unprepared("CREATE TRIGGER cross_queue_coupon_fault AFTER UPDATE OF ret ON coupons WHEN NEW.id=".(int)$task['coupon_id']." AND NEW.ret>OLD.ret BEGIN SELECT RAISE(ABORT,'Injected cross-version coupon return failure'); END");
    }elseif($operation==='fault-off'){
        $db->unprepared('DROP TRIGGER cross_queue_coupon_fault');
    }elseif($operation==='uuid-control'){
        if($tree!=='candidate')throw new RuntimeException('Native candidate UUID provider control required.');
        $payload=file_get_contents($cfg['run'].'/payload-'.$task['sn'].'.json');$before=$db->table('failed_jobs')->count();$expectUuid=true;
        try{$provider=new Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider($app['db'],'sqlite','failed_jobs');$provider->log('database','fixture48',$payload,new RuntimeException('Synthetic UUID incompatibility control'));throw new RuntimeException('Legacy payload unexpectedly accepted by UUID provider.');}
        catch(ErrorException $e){if(strpos($e->getMessage(),'uuid')===false)throw $e;$result['uuid_negative']=['class'=>get_class($e),'message'=>$e->getMessage(),'payload_uuid_absent'=>!isset(json_decode($payload,true)['uuid'])];}
        finally{$expectUuid=false;}
        if($db->table('failed_jobs')->count()!==$before)throw new RuntimeException('UUID negative control changed old failed rows.');
    }elseif($operation==='console'){
        $command=$task['command'];if(!in_array($command[0],['queue:work','queue:retry','orders:expire'],true))throw new RuntimeException('Allowed original queue command required.');
        $input=new Symfony\Component\Console\Input\ArgvInput(array_merge([__FILE__],$command));$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);
        $code=(int)$kernel->handle($input,new Symfony\Component\Console\Output\ConsoleOutput());$kernel->terminate($input,$code);$result['console_exit']=$code;$result['status']=$code===0?'PASS':'FAIL';
        exit($code);
    }else throw new RuntimeException('Known queue fixture operation required.');
    $result['status']='PASS';
}catch(Throwable $e){$result['failure']=['class'=>get_class($e),'message'=>$e->getMessage()];fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);}
