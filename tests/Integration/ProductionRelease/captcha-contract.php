<?php
require_once __DIR__.'/bootstrap.php';
$cfg=pr_manifest($argv[1]??'');$mode=$argv[2]??'';
if(is_file($cfg['run'].'/runtime-summary.json')||!in_array($mode,['cold','cached'],true))throw new RuntimeException('Active owned captcha release contract required.');
pr_environment($cfg);$loader=require $cfg['release'].'/vendor/autoload.php';$app=require $cfg['release'].'/bootstrap/app.php';$checks=0;$issues=[];
$check=function($ok,$name)use(&$checks){if(!$ok)throw new RuntimeException('Native captcha contract failed: '.$name);$checks++;};
$observe=function($severity,$message,$file,$line)use(&$issues){if(!($severity&error_reporting()))return true;$issues[]=compact('severity','message','file','line');throw new ErrorException($message,0,$severity,$file,$line);};
$app->afterBootstrapping(Illuminate\Foundation\Bootstrap\HandleExceptions::class,function()use($observe){set_error_handler($observe);});
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();set_error_handler($observe);
$check($app->basePath()===$cfg['release']&&$app->storagePath()===$cfg['release'].'/storage'&&$app->environment()==='production','actual_release_identity');
$check(array_map('ql_normalize',array_keys(Composer\Autoload\ClassLoader::getRegisteredLoaders()))===[$cfg['release'].'/vendor'],'only_independent_vendor');
$check(!class_exists('Tests'.chr(92).'TestCase',false)&&!is_dir($cfg['release'].'/tests'),'no_test_application');
$check($app->configurationIsCached()===($mode==='cached')&&$app->routesAreCached()===($mode==='cached'),'actual_cache_mode');
$name='Mews'.chr(92).'Captcha'.chr(92).'CaptchaServiceProvider';$provider=$app->getProvider($name);
$check($provider&&get_class($provider)===$name&&$app->register($name)===$provider&&count($app->getProviders($name))===1,'native_discovery_identity_and_dedup');
$path=(new ReflectionClass($provider))->getFileName();$check(ql_normalize($path)===$cfg['release'].'/bootstrap/compat/captcha-service-provider.php','actual_release_compat_origin');
$paths=Illuminate\Support\ServiceProvider::pathsToPublish($name,'config');
$check(count($paths)===1&&array_values($paths)===[$cfg['release'].'/config/captcha.php']&&is_file(array_keys($paths)[0]),'original_provider_publish_mapping');
$cache=$app['cache'];$hash=$app['hash']->make('a9b7zq21');$cache->put('captcha_'.md5($hash),true,60);$rule='captcha_api:'.$hash.',default';
$check($app['validator']->make(['answer'=>'A9B7ZQ21'],['answer'=>$rule])->passes(),'original_api_correct_answer');
$check(!$app['validator']->make(['answer'=>'A9B7ZQ21'],['answer'=>$rule])->passes(),'original_api_consumed_key');
$hash=$app['hash']->make('a9b7zq21');$cache->put('captcha_'.md5($hash),true,60);
$check(!$app['validator']->make(['answer'=>'wrong-answer'],['answer'=>'captcha_api:'.$hash.',default'])->passes(),'original_api_wrong_answer');
$oldRouter=$app['router'];$config=$app['config'];$disable=$config->get('captcha.disable');$config->set('captcha.disable',true);
$router=new Illuminate\Routing\Router($app['events'],$app);$app->instance('router',$router);
try{$provider->boot();$check(count($router->getRoutes())===0,'disable_has_no_routes');$check($app['validator']->make(['answer'=>'unused'],['answer'=>'captcha'])->passes(),'disable_validation_branch');}
finally{$config->set('captcha.disable',$disable);$app->instance('router',$oldRouter);}
$check(!$issues&&error_get_last()===null,'strict_boot_and_runtime_no_php_issue');$app['db']->disconnect();
ql_json($cfg['run'].'/captcha-'.$mode.'-proof.json',['status'=>'PASS','checks'=>$checks,'mode'=>$mode,'provider'=>$name,'source'=>ql_normalize($path),'source_sha256'=>hash_file('sha256',$path),
    'php'=>PHP_VERSION,'pid'=>getmypid(),'framework'=>$app->version(),'base'=>$app->basePath(),'storage'=>$app->storagePath(),'config_cached'=>$app->configurationIsCached(),'routes_cached'=>$app->routesAreCached(),
    'loaders'=>array_map('ql_normalize',array_keys(Composer\Autoload\ClassLoader::getRegisteredLoaders())),'issues'=>$issues]);
echo 'Original captcha provider '.$mode.' contract PASS '.$checks,PHP_EOL;
