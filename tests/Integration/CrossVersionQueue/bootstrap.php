<?php
require_once dirname(__DIR__).'/QueueLifecycle/bootstrap.php';

function cq_manifest($run)
{
    $root=ql_normalize(realpath(dirname(__DIR__,3)));$actual=ql_normalize(realpath($run)?:'');
    if(dirname($actual)!==$root.'/tests/.runtime/cross-version-queue'||!preg_match('/^run-[a-zA-Z0-9-]+$/D',basename($actual)))throw new RuntimeException('Owned cross-version queue run required.');
    $cfg=json_decode(file_get_contents($actual.'/run.json'),true,512,JSON_THROW_ON_ERROR);
    if($cfg['root']!==$root||$cfg['run']!==$actual||!hash_equals(trim(file_get_contents($actual.'/marker')),$cfg['marker']))throw new RuntimeException('Cross-version queue marker mismatch.');
    foreach([$actual,$actual.'/queue.sqlite',$actual.'/run.json',$actual.'/marker']as$p)if(is_link($p))throw new RuntimeException('Fixture ancestor link detected.');
    return $cfg;
}
function cq_boot(array $cfg,$tree,callable $observeError)
{
    if(!in_array($tree,['root','candidate'],true))throw new RuntimeException('Named dependency tree required.');
    $runtime=$cfg['runtimes'][$tree];$root=$cfg['root'];$run=$cfg['run'];$storage=$run.'/'.$tree.'/storage';
    if(ql_normalize(realpath(PHP_BINARY))!==$runtime['binary']||ql_normalize(php_ini_loaded_file())!==$runtime['ini']
        ||($tree==='root'?PHP_VERSION!=='7.4.33':PHP_VERSION!=='8.5.11'))throw new RuntimeException('Expected queue binary and complete INI required.');
    $vendor=$tree==='root'?$root.'/tests/Compatibility/Laravel6/vendor':$root.'/tests/Compatibility/Laravel12/vendor';
    $env=['APP_BASE_PATH'=>$root,'APP_ENV'=>'cross-version-queue','APP_DEBUG'=>'false','APP_KEY'=>$cfg['key'],'APP_NAME'=>'Cross Version Queue',
        'APP_URL'=>'http://queue.fixture.invalid','COMPOSER_VENDOR_DIR'=>$vendor,'LARAVEL_STORAGE_PATH'=>$storage,
        'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$run.'/queue.sqlite','DATABASE_URL'=>'','DB_HOST'=>'','DB_PORT'=>'','DB_USERNAME'=>'','DB_PASSWORD'=>'',
        'CACHE_DRIVER'=>'file','CACHE_STORE'=>'file','CACHE_PREFIX'=>'cq-'.$cfg['marker'],'SESSION_DRIVER'=>'array',
        'QUEUE_CONNECTION'=>'database','QUEUE_FAILED_DRIVER'=>'database','MAIL_DRIVER'=>'array','MAIL_MAILER'=>'array','MAIL_HOST'=>'','MAIL_USERNAME'=>'','MAIL_PASSWORD'=>'',
        'API_HOOK_OUTBOX'=>'false','PAYMENT_MAIL_OUTBOX'=>'false','MANUAL_STATUS_MAIL_OUTBOX'=>'false','PAYMENT_QUOTE_FREEZE'=>'false',
        'STRIPE_PAYMENT_INTENTS'=>'false','STRIPE_RECONCILIATION'=>'false','PAYPAL_ORDERS_V2'=>'false','PAYPAL_RECONCILIATION'=>'false','PAYPAL_WEBHOOK_IDS'=>'{}',
        'DUJIAO_ADMIN_LANGUAGE'=>'en','ADMIN_HTTPS'=>'false','ADMIN_ROUTE_DOMAIN'=>'','ADMIN_ASSETS_SERVER'=>'','LOG_CHANNEL'=>'single','BROADCAST_DRIVER'=>'log',
        'AWS_ACCESS_KEY_ID'=>'','AWS_SECRET_ACCESS_KEY'=>'','REDIS_PASSWORD'=>'','MEMCACHED_USERNAME'=>'','MEMCACHED_PASSWORD'=>''];
    $relative='tests/.runtime/cross-version-queue/'.basename($run).'/'.$tree;
    foreach(['CONFIG','ROUTES','PACKAGES','SERVICES','EVENTS']as$name)$env['APP_'.$name.'_CACHE']=$relative.'/'.strtolower($name).'.php';
    foreach($env as$key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
    $loader=require $vendor.'/autoload.php';$app=require $root.'/bootstrap/app.php';
    $app->useEnvironmentPath($run)->loadEnvironmentFrom('.env.fixture-not-present');$app->useStoragePath($storage);$app->instance('path.vendor',$vendor);
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\HandleExceptions::class,function()use($observeError){set_error_handler($observeError);});
    $app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\RegisterProviders::class,function($app)use($storage){$app['config']->set([
        'queue.connections.database.connection'=>'sqlite','queue.connections.database.queue'=>'fixture48','queue.connections.database.retry_after'=>90,
        'cache.stores.file.path'=>$storage.'/framework/cache/data','cache.stores.file.lock_path'=>$storage.'/framework/cache/locks','view.compiled'=>$storage.'/framework/views']);});
    $manifest=$app->make(Illuminate\Foundation\PackageManifest::class);$manifest->vendorPath=$vendor;
    $manifest->build();$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if(config('dujiaoka.manual_status_mail_outbox')!==false||$app->environment()!=='cross-version-queue'||is_file($app->environmentFilePath())||$app->storagePath()!==$storage
        ||config('database.connections.sqlite.database')!==$run.'/queue.sqlite'||config('queue.default')!=='database'||config('queue.failed.driver')!=='database'
        ||get_class($app['queue']->connection())!=='Illuminate'.chr(92).'Queue'.chr(92).'DatabaseQueue'
        ||get_class($app['queue.failer'])!=='Illuminate'.chr(92).'Queue'.chr(92).'Failed'.chr(92).'DatabaseFailedJobProvider')throw new RuntimeException('Actual isolated legacy queue/provider identity required.');
    $app['db']->connection()->statement('PRAGMA busy_timeout=5000');
    if(ql_normalize($app['db']->select('PRAGMA database_list')[0]->file)!==$run.'/queue.sqlite')throw new RuntimeException('Actual owned SQLite file required.');
    foreach(['Config','Routes','Packages','Services','Events']as$name){$method='getCached'.$name.'Path';if(ql_normalize($app->$method())!==$run.'/'.$tree.'/'.strtolower($name).'.php')throw new RuntimeException('Private tree cache path required.');}
    $loaders=array_keys(Composer\Autoload\ClassLoader::getRegisteredLoaders());if(count($loaders)!==1||ql_normalize($loaders[0])!==$vendor)throw new RuntimeException('Exactly one selected vendor loader required.');
    $sources=[];foreach([App\Console\Kernel::class,App\Jobs\OrderExpired::class,App\Service\OrderService::class,Illuminate\Queue\Worker::class,
        Illuminate\Queue\Queue::class,Illuminate\Queue\Console\RetryCommand::class,Illuminate\Queue\Failed\DatabaseFailedJobProvider::class]as$class){
        $file=ql_normalize((new ReflectionClass($class))->getFileName());$prefix=strpos($class,'App'.chr(92))===0?$root.'/app/':$vendor.'/laravel/framework/src/';
        if(strpos($file,$prefix)!==0)throw new RuntimeException('Actual App and selected vendor origins required.');$sources[$class]=['path'=>$file,'sha256'=>hash_file('sha256',$file)];}
    if($app->version()!==($tree==='root'?'6.20.42':'13.35.0'))throw new RuntimeException('Expected framework version required.');
    $identity=['tree'=>$tree,'php'=>PHP_VERSION,'framework'=>$app->version(),'pid'=>getmypid(),'binary'=>ql_normalize(PHP_BINARY),'ini'=>ql_normalize(php_ini_loaded_file()),
        'environment'=>$app->environment(),'env_path'=>$app->environmentFilePath(),'base'=>$app->basePath(),'storage'=>$app->storagePath(),
        'db'=>ql_normalize($app['db']->select('PRAGMA database_list')[0]->file),'queue'=>config('queue.default'),'failed_driver'=>config('queue.failed.driver'),
        'provider'=>get_class($app['queue.failer']),'timezone'=>config('app.timezone'),'loaders'=>array_map('ql_normalize',$loaders),'sources'=>$sources];
    $app['cache']->forever('system-setting',['order_expire_time'=>5,'driver'=>'array','is_open_server_jiang'=>0,'is_open_telegram_push'=>0,'is_open_bark_push'=>0,'is_open_qywxbot_push'=>0]);
    return [$app,$identity];
}
