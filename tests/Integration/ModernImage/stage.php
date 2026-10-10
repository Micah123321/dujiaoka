<?php
require dirname(__DIR__).'/ProductionRelease/bootstrap.php';
$cfg=pr_manifest($argv[1]??'');
$stage=json_decode(file_get_contents($cfg['run'].'/stage-summary.json'),true,512,JSON_THROW_ON_ERROR);
$runtime=json_decode(file_get_contents($cfg['run'].'/runtime-summary.json'),true,512,JSON_THROW_ON_ERROR);
if($stage['status']!=='PASS'||$runtime['status']!=='PASS'||$runtime['cleanup_errors'])throw new RuntimeException('Closed successful release required for image preparation.');
$root=$cfg['root'];
$expected=json_decode(file_get_contents($cfg['run'].'/source-copy.json'),true,512,JSON_THROW_ON_ERROR);
foreach(array_keys($expected)as$file)if(strpos($file,'public/vendor/dcat-admin/')===0)unset($expected[$file]);
$published=json_decode(file_get_contents($cfg['run'].'/assets-published.json'),true,512,JSON_THROW_ON_ERROR);
if($published['status']!=='PASS')throw new RuntimeException('Original passing asset publication snapshot required.');
foreach($published['hashes']as$file=>$sha)$expected['public/vendor/dcat-admin/'.$file]=$sha;
$expected=array_filter($expected,function($file){return(preg_match('#^(app|bootstrap|config|database|resources|routes|public|deploy)/#',$file)||in_array($file,['artisan','composer.json','composer.lock'],true))&&!preg_match('#(^|/)([.]env[^/]*|[^/]*[.](pem|key|p12|pfx))$#i',$file)&&!preg_match('#^(bootstrap/cache|public/uploads|public/storage)/#',$file);},ARRAY_FILTER_USE_KEY);
$GLOBALS['mi_expected']=$expected;
$registry=json_decode(file_get_contents($root.'/tests/.runtime/modern-image/registry-47/registry-proof.json'),true,512,JSON_THROW_ON_ERROR);
foreach(['tag','manifest','config']as$name){$sha='sha256:'.hash_file('sha256',$root.'/tests/.runtime/modern-image/registry-47/'.$name.'.json');if($sha!==$registry[$name.'Digest'])throw new RuntimeException('Original registry bytes changed.');}
$tag=json_decode(file_get_contents($root.'/tests/.runtime/modern-image/registry-47/tag.json'),true,512,JSON_THROW_ON_ERROR);
$manifest=json_decode(file_get_contents($root.'/tests/.runtime/modern-image/registry-47/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$choices=isset($tag['manifests'])?array_values(array_filter($tag['manifests'],function($m){return($m['platform']['os']??'')==='linux'&&($m['platform']['architecture']??'')==='amd64'&&empty($m['platform']['variant']);})):[];
if(($choices&&(count($choices)!==1||$choices[0]['digest']!==$registry['manifestDigest']))||(!$choices&&$registry['tagDigest']!==$registry['manifestDigest'])||$manifest['config']['digest']!==$registry['configDigest'])throw new RuntimeException('Original registry tag-manifest-config chain mismatch.');
if($registry['status']!=='PASS'||$registry['platform']!=='linux/amd64'||!in_array('PHP_VERSION=8.5.11',$registry['env'],true))throw new RuntimeException('Verified8.5.11 image config required.');
$marker=bin2hex(random_bytes(12));$run=$root.'/tests/.runtime/modern-image/run-'.gmdate('Ymd-His').'-'.$marker;
mkdir($run.'/context',0700,true);file_put_contents($run.'/marker',$marker);$context=$run.'/context';$copied=[];
function mi_copy($source,$target,$prefix,&$copied){
    foreach(new DirectoryIterator($source)as$f){if($f->isDot())continue;$relative=$prefix.$f->getFilename();
        if(preg_match('#(^|/)([.]env[^/]*|[^/]*[.](pem|key|p12|pfx))$#i',$relative)||in_array($relative,['bootstrap/cache','public/uploads','public/storage'],true))continue;
        if($f->isLink())throw new RuntimeException('Image input symbolic link detected.');
        $to=$target.'/'.$f->getFilename();if($f->isDir()){mkdir($to,0700);mi_copy($f->getPathname(),$to,$relative.'/',$copied);}else{if(!isset($GLOBALS['mi_expected'][$relative]))throw new RuntimeException('Post-acceptance image input added.');
        if(!copy($f->getPathname(),$to)||hash_file('sha256',$to)!==$GLOBALS['mi_expected'][$relative])throw new RuntimeException('Actual image input copy failed.');$copied[$relative]=hash_file('sha256',$to);}
    }
}
foreach(['app','bootstrap','config','database','resources','routes','public','deploy']as$dir){$source=pr_owned_path($cfg,$cfg['release'].'/'.$dir);mkdir($context.'/'.$dir,0700);mi_copy($source,$context.'/'.$dir,$dir.'/',$copied);}
foreach(['artisan','composer.json','composer.lock']as$file){copy(pr_owned_path($cfg,$cfg['release'].'/'.$file),$context.'/'.$file);$copied[$file]=hash_file('sha256',$context.'/'.$file);}
ksort($copied);ksort($expected);if($copied!==$expected)throw new RuntimeException('Original accepted source/asset snapshot differs.');
ql_json($run.'/accepted-baseline.json',$expected);
if(hash_file('sha256',$context.'/composer.lock')!==hash_file('sha256',$root.'/tests/Compatibility/Laravel12/composer.lock'))throw new RuntimeException('Candidate image lock differs.');
$manifest=json_decode(file_get_contents($context.'/composer.json'),true,512,JSON_THROW_ON_ERROR);
if($manifest['autoload']['psr-4']!==['App'.chr(92)=>'app/']||$manifest['autoload']['files']!==['app/Helpers/functions.php']||isset($manifest['autoload-dev']))throw new RuntimeException('Root-local production autoload required.');
copy($root.'/deploy/modern/Dockerfile',$context.'/Dockerfile');$dockerfile=file_get_contents($context.'/Dockerfile');
if(strpos($dockerfile,'FROM webdevops/php-nginx@'.$registry['manifestDigest'].PHP_EOL)!==0)throw new RuntimeException('Current Dockerfile does not pin verified image.');
file_put_contents($context.'/.dockerignore',".env\n.env.*\n*.pem\n*.key\n*.p12\n*.pfx\nvendor\nnode_modules\ntests\n.git\nstorage\nbootstrap/cache\npublic/uploads\npublic/storage\ninstall.lock\n");
$compose="services:\n  web:\n    platform: linux/amd64\n    build:\n      context: ./context\n      dockerfile: Dockerfile\n    image: dujiaoka-modern:local\n    stop_signal: SIGTERM\n    stop_grace_period: 120s\n    ports:\n      - '127.0.0.1:8210:80'\n    environment:\n      WEB_DOCUMENT_ROOT: /app/public\n      TZ: Asia/Shanghai\n    volumes:\n      - type: bind\n        source: ./runtime/.env\n        target: /app/.env\n        read_only: true\n        bind:\n          create_host_path: false\n      - type: bind\n        source: ./runtime/install.lock\n        target: /app/install.lock\n        bind:\n          create_host_path: false\n      - type: bind\n        source: ./runtime/uploads\n        target: /app/public/uploads\n        bind:\n          create_host_path: false\n";
file_put_contents($run.'/compose.yml',$compose);file_put_contents($run.'/empty.env','');
ql_json($run.'/inputs.json',$copied);
$lock=json_decode(file_get_contents($context.'/composer.lock'),true,512,JSON_THROW_ON_ERROR);
ql_json($run.'/summary.json',['status'=>'PREPARED','run'=>$run,'context'=>$context,'source_release'=>$cfg['run'],'marker'=>$marker,'copied_files'=>count($copied),
    'base_manifest'=>$registry['manifestDigest'],'base_config'=>$registry['configDigest'],'config_php'=>'8.5.11','runtime_packages'=>count($lock['packages']),'development_packages_in_lock'=>count($lock['packages-dev']),
    'root_local_autoload'=>true,'copied_vendor'=>false,'copied_test_tree'=>false,'copied_runtime_state'=>false,'image_built'=>false,'engine_available'=>false,'actual_php_in_image_executed'=>false]);
echo json_encode(['status'=>'PREPARED','run'=>$run,'files'=>count($copied),'base'=>$registry['manifestDigest']],JSON_THROW_ON_ERROR),PHP_EOL;
