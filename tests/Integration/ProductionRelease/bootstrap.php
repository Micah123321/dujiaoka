<?php
require_once dirname(__DIR__) . '/QueueLifecycle/bootstrap.php';

function pr_manifest($run)
{
    $root = ql_normalize(realpath(dirname(__DIR__, 3)));
    $actual = ql_normalize(realpath($run) ?: '');
    if (dirname($actual) !== $root . '/tests/.runtime/production-release' || !preg_match('/^run-[a-zA-Z0-9-]+$/D', basename($actual))) throw new RuntimeException('Owned production release required.');
    $cfg = json_decode(file_get_contents($actual . '/run.json'), true, 512, JSON_THROW_ON_ERROR);
    if ($cfg['root'] !== $root || $cfg['run'] !== $actual || $cfg['release'] !== $actual . '/release'
        || !hash_equals(trim(file_get_contents($actual . '/marker')), $cfg['marker'])) throw new RuntimeException('Release marker mismatch.');
    return $cfg;
}

function pr_environment(array $cfg)
{
    $release = $cfg['release'];
    $env = ['APP_BASE_PATH' => $release, 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_NAME' => 'Production Release Fixture',
        'APP_KEY' => $cfg['key'], 'APP_URL' => $cfg['url'], 'COMPOSER_VENDOR_DIR' => $release . '/vendor',
        'LARAVEL_STORAGE_PATH' => $release . '/storage', 'VIEW_COMPILED_PATH' => $release . '/storage/framework/views',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $release . '/storage/fixture.sqlite', 'DATABASE_URL' => '',
        'DB_HOST' => '', 'DB_PORT' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => '',
        'CACHE_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'CACHE_PREFIX' => 'release-' . $cfg['marker'],
        'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'release_' . $cfg['marker'], 'SESSION_DOMAIN' => '', 'SESSION_SECURE_COOKIE' => 'false',
        'QUEUE_CONNECTION' => !empty($cfg['transactions']) ? 'database' : 'sync', 'QUEUE_FAILED_DRIVER'=>!empty($cfg['transactions']) ? 'database-uuids' : 'database', 'MAIL_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'MAIL_HOST' => '', 'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '',
        'API_HOOK_OUTBOX' => 'false', 'PAYMENT_MAIL_OUTBOX' => 'false', 'MANUAL_STATUS_MAIL_OUTBOX' => 'false', 'PAYMENT_QUOTE_FREEZE' => 'false',
        'STRIPE_PAYMENT_INTENTS' => 'false', 'STRIPE_RECONCILIATION' => 'false', 'PAYPAL_ORDERS_V2' => 'false', 'PAYPAL_RECONCILIATION' => 'false', 'PAYPAL_WEBHOOK_IDS' => '{}',
        'DUJIAO_ADMIN_LANGUAGE' => 'en', 'ADMIN_HTTPS' => 'false', 'ADMIN_ROUTE_DOMAIN' => '', 'ADMIN_ASSETS_SERVER' => '',
        'LOG_CHANNEL' => 'single', 'BROADCAST_DRIVER' => 'log', 'AWS_ACCESS_KEY_ID' => '', 'AWS_SECRET_ACCESS_KEY' => '',
        'REDIS_PASSWORD' => '', 'MEMCACHED_USERNAME' => '', 'MEMCACHED_PASSWORD' => ''];
    foreach (['CONFIG', 'ROUTES', 'PACKAGES', 'SERVICES', 'EVENTS'] as $name) $env['APP_' . $name . '_CACHE'] = $release . '/bootstrap/cache/' . strtolower($name) . '.php';
    foreach ($env as $key => $value) { putenv($key . '=' . $value); $_ENV[$key] = $value; $_SERVER[$key] = $value; }
    return $env;
}

function pr_owned_path(array $cfg, $path)
{
    $path = ql_normalize($path);
    if ($path !== $cfg['release'] && strpos($path, $cfg['release'] . '/') !== 0) throw new RuntimeException('Owned release path required.');
    if (preg_match('#(^|/)\.\.?(/|$)#', $path)) throw new RuntimeException('Release path traversal detected.');
    $current = $cfg['run'];
    foreach (explode('/', substr($path, strlen($cfg['run']) + 1)) as $part) {
        $current .= '/' . $part;
        if (is_link($current) || (file_exists($current) && ql_normalize(realpath($current) ?: '') !== $current)) throw new RuntimeException('Owned release ancestor link detected.');
    }
    return $path;
}

function pr_observe_php_errors()
{
    if(isset($GLOBALS['pr_php_watch']))spl_autoload_unregister($GLOBALS['pr_php_watch']);
    $GLOBALS['pr_php_observer']=['installed'=>false,'issues'=>[],'applications'=>[]];
    $watcher=null;
    $watcher=function($class)use(&$watcher){
        if(!in_array($class,['App'.chr(92).'Console'.chr(92).'Kernel','App'.chr(92).'Http'.chr(92).'Kernel'],true))return;
        pr_attach_php_observer(Illuminate\Container\Container::getInstance());
        spl_autoload_unregister($watcher);
    };
    $GLOBALS['pr_php_watch']=$watcher;
    spl_autoload_register($watcher,true,true);
}

function pr_attach_php_observer($app)
{
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\HandleExceptions::class,function($app){
        $GLOBALS['pr_php_observer']['installed']=true;$previous=null;$id=spl_object_id($app);
        $GLOBALS['pr_php_observer']['applications'][]=['id'=>$id,'base'=>$app->basePath()];
        $previous=set_error_handler(function($level,$message,$file,$line)use(&$previous,$id){
            if($level&error_reporting())$GLOBALS['pr_php_observer']['issues'][]=compact('level','message','file','line','id');
            return $previous?$previous($level,$message,$file,$line):false;
        });
    });
    if(!empty($GLOBALS['pr_control_original_config']))return;
    $app->extend(Illuminate\Foundation\Console\ConfigCacheCommand::class,function($command,$app){
        $reflection=new ReflectionMethod(Illuminate\Foundation\Console\ConfigCacheCommand::class,'getFreshConfiguration');
        $lines=file($reflection->getFileName(),FILE_IGNORE_NEW_LINES);$actual=implode("\n",array_slice($lines,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
        $expected="    protected function getFreshConfiguration()\n    {\n        \$app = require \$this->laravel->bootstrapPath('app.php');\n\n        \$app->useStoragePath(\$this->laravel->storagePath());\n\n        \$app->make(ConsoleKernelContract::class)->bootstrap();\n\n        return \$app['config']->all();\n    }";
        if(str_replace(chr(13),'',$actual)!==$expected)throw new RuntimeException('Original fresh config bootstrap changed; observation adapter must be reviewed.');
        // Attach observation to the fresh App; inherited native serialization stays unchanged.
        return new class($app->make('files')) extends Illuminate\Foundation\Console\ConfigCacheCommand {
            protected function getFreshConfiguration()
            {
                $app=require $this->laravel->bootstrapPath('app.php');
                $app->useStoragePath($this->laravel->storagePath());
                pr_attach_php_observer($app);
                if(isset($GLOBALS['pr_fresh_app_control']))($GLOBALS['pr_fresh_app_control'])($app);
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                return $app['config']->all();
            }
        };
    });
    $app->extend(Illuminate\Foundation\Console\RouteCacheCommand::class,function($command,$app){
        $reflection=new ReflectionMethod(Illuminate\Foundation\Console\RouteCacheCommand::class,'getFreshApplication');
        $lines=file($reflection->getFileName(),FILE_IGNORE_NEW_LINES);$actual=implode("\n",array_slice($lines,$reflection->getStartLine()-1,$reflection->getEndLine()-$reflection->getStartLine()+1));
        $expected="    protected function getFreshApplication()\n    {\n        return tap(require \$this->laravel->bootstrapPath('app.php'), function (\$app) {\n            \$app->make(ConsoleKernelContract::class)->bootstrap();\n\n            Facade::clearResolvedInstances();\n\n            Facade::setFacadeApplication(\$this->laravel);\n            Container::setInstance(\$this->laravel);\n        });\n    }";
        if(str_replace(chr(13),'',$actual)!==$expected)throw new RuntimeException('Original fresh route bootstrap changed; observation adapter must be reviewed.');
        return new class($app->make('files')) extends Illuminate\Foundation\Console\RouteCacheCommand {
            protected function getFreshApplication()
            {
                return tap(require $this->laravel->bootstrapPath('app.php'),function($app){
                    pr_attach_php_observer($app);
                    if(isset($GLOBALS['pr_fresh_app_control']))($GLOBALS['pr_fresh_app_control'])($app);
                    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                    Illuminate\Support\Facades\Facade::clearResolvedInstances();
                    Illuminate\Support\Facades\Facade::setFacadeApplication($this->laravel);
                    Illuminate\Container\Container::setInstance($this->laravel);
                });
            }
        };
    });
}

function pr_hashes($directory)
{
    $hashes = [];
    if (!is_dir($directory)) return $hashes;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isLink()) throw new RuntimeException('Release contains a symbolic link.');
        if ($file->isFile()) $hashes[substr(ql_normalize($file->getPathname()), strlen(ql_normalize($directory)) + 1)] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes); return $hashes;
}
