<?php
error_reporting(E_ALL);set_error_handler(function($level,$message,$file,$line){if($level&error_reporting())throw new ErrorException($message,0,$level,$file,$line);return false;});
if(PHP_OS_FAMILY!=='Linux'||posix_geteuid()!==1000)throw new RuntimeException('Existing Linux user1000 required.');
$root=realpath(dirname(__DIR__,3));$run=realpath($argv[1]??'');
if(!$run||dirname($run)!==$root.'/tests/.runtime/modern-image'||!preg_match('/^run-layers-[0-9-]+-[a-f0-9]{24}$/D',basename($run)))throw new RuntimeException('Owned original layer run required.');
$read=function($file){return json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);};$cfg=$read($run.'/run.json');$linux=function($path){return preg_replace('#^E:/#','/mnt/e/',$path);};
if($linux($cfg['root'])!==$root||$linux($cfg['run'])!==$run||trim(file_get_contents($run.'/marker'))!==$cfg['marker'])throw new RuntimeException('Actual image marker mismatch.');
$base='/var/tmp/dujiaoka-image50-'.$cfg['marker'];$rootfs=$base.'/rootfs';
if($cfg['linuxRoot']!==$base||$cfg['rootfs']!==$rootfs||realpath($base)!==$base||realpath($rootfs)!==$rootfs||trim(file_get_contents($base.'/.marker'))!==$cfg['marker']||$read($run.'/unpack-summary.json')['status']!=='PASS')throw new RuntimeException('Verified owned image rootfs required.');
if(is_file($run.'/native-stage-summary.json')||file_exists($rootfs.'/probe/native-stage.json'))throw new RuntimeException('Existing native image stage retained.');
$contextRun=$root.'/tests/.runtime/modern-image/run-20261010-082024-a00d2575ff346333c36cb60e';$context=$contextRun.'/context';
$releaseRun=$root.'/tests/.runtime/production-release/run-20261010-085729-d9536fdb0d9b02253d9961c3';$release=$releaseRun.'/release';
if($read($releaseRun.'/runtime-summary.json')['status']!=='PASS'||$read($releaseRun.'/parent-proof-49.json')['status']!=='PASS')throw new RuntimeException('Current accepted release required.');
$input=$read($contextRun.'/inputs.json');foreach($input as$file=>$sha)if(hash_file('sha256',$context.'/'.$file)!==$sha||hash_file('sha256',$release.'/'.$file)!==$sha)throw new RuntimeException('Original1271 input changed: '.$file);
$installed=$read($release.'/vendor/composer/installed.json');if($installed['dev']!==false||count($installed['packages'])!==100||hash_file('sha256',$release.'/composer.lock')!==hash_file('sha256',$context.'/composer.lock'))throw new RuntimeException('Same fixed100 no-dev vendor required.');
$fromNative=null;$localApp=null;$previousVendor=null;
if(($argv[2]??'')==='--from-native'){
    $fromNative=realpath($argv[3]??'');
    if(!$fromNative||dirname($fromNative)!==$root.'/tests/.runtime/modern-image'||!preg_match('/^run-layers-[0-9-]+-[a-f0-9]{24}$/D',basename($fromNative))||$fromNative===$run)throw new RuntimeException('Distinct completed native source run required.');
    $oldCfg=$read($fromNative.'/run.json');$oldStage=$read($fromNative.'/native-stage-summary.json');$oldProof=$read($fromNative.'/native-parent-proof-bound-final.json');
    $oldBase='/var/tmp/dujiaoka-image50-'.$oldCfg['marker'];$localApp=$oldBase.'/rootfs/app';
    if($linux($oldCfg['root'])!==$root||$linux($oldCfg['run'])!==$fromNative||trim(file_get_contents($fromNative.'/marker'))!==$oldCfg['marker']
        ||$oldCfg['rootfs']!==$oldBase.'/rootfs'||realpath($localApp)!==$localApp||trim(file_get_contents($oldBase.'/.marker'))!==$oldCfg['marker']
        ||$oldStage['status']!=='PASS'||$oldProof['status']!=='PASS'||$oldProof['php']!=='8.5.11'||$oldProof['framework']!=='13.35.0'
        ||($oldProof['bound_inputs_required']??false)!==true||($oldProof['run']??null)!==$fromNative
        ||$oldProof['image_hash']!==$cfg['manifestDigest'])throw new RuntimeException('Accepted same-image local source identity required.');
    $sourceInputs=$read($fromNative.'/native-copied-inputs.json');$previousVendor=$read($fromNative.'/native-vendor-inputs.json');
    if(count($sourceInputs)!==1273||count($previousVendor)!==12623||hash_file('sha256',$localApp.'/composer.lock')!==hash_file('sha256',$context.'/composer.lock'))throw new RuntimeException('Complete accepted local source set required.');
    foreach($sourceInputs as$file=>$sha){
        if(preg_match('#(^/|\\\\|(^|/)\\.\\.?(/|$))#',$file)||realpath($localApp.'/'.$file)!==$localApp.'/'.$file
            ||hash_file('sha256',$localApp.'/'.$file)!==$sha||hash_file('sha256',$context.'/'.$file)!==$sha)throw new RuntimeException('Original context/local input bytes differ: '.$file);
    }
    foreach($input as$file=>$sha)if(($sourceInputs[$file]??null)!==$sha)throw new RuntimeException('Original accepted input omitted from local copy.');
    $localInstalled=$read($localApp.'/vendor/composer/installed.json');$p=[];foreach($localInstalled['packages']as$package)$p[$package['name']]=$package;
    if($localInstalled['dev']!==false||count($p)!==100)throw new RuntimeException('Local original100 no-dev package identity required.');
    foreach($read($context.'/composer.lock')['packages']as$package)if(!isset($p[$package['name']])||$p[$package['name']]['version']!==$package['version']||($p[$package['name']]['source']['reference']??null)!==($package['source']['reference']??null))throw new RuntimeException('Local fixed runtime provenance changed.');
    $samePackageFiles=0;
    foreach($previousVendor as$file=>$sha){
        if(strpos($file,'composer/')===0||$file==='autoload.php'||strpos($file,'bin/')===0)continue;
        if(preg_match('#(^/|\\\\|(^|/)\\.\\.?(/|$))#',$file)||realpath($localApp.'/vendor/'.$file)!==$localApp.'/vendor/'.$file||hash_file('sha256',$localApp.'/vendor/'.$file)!==$sha)throw new RuntimeException('Original package source changed in local reuse.');$samePackageFiles++;
    }
    if($samePackageFiles!==12600)throw new RuntimeException('Complete original package file set required.');
}
function in_copy($src,$dst,&$hashes,$prefix=''){
    if(is_link($src)||is_link($dst))throw new RuntimeException('Source or destination link in copied app tree.');
    if(is_dir($src)){if(file_exists($dst)&&!is_dir($dst))throw new RuntimeException('Original target directory type mismatch.');if(!is_dir($dst))mkdir($dst,0755);foreach(scandir($src)as$name)if($name!=='.'&&$name!=='..')in_copy($src.'/'.$name,$dst.'/'.$name,$hashes,$prefix===''?$name:$prefix.'/'.$name);return;}
    if(!is_file($src)||file_exists($dst))throw new RuntimeException('Fresh regular copied source required: '.$prefix);
    if(!copy($src,$dst)||hash_file('sha256',$src)!==hash_file('sha256',$dst))throw new RuntimeException('Native copied bytes mismatch.');chmod($dst,fileperms($src)&0777);$hashes[$prefix]=hash_file('sha256',$dst);
}
$protected=[];foreach(['/entrypoint','/usr/local/bin/php','/usr/local/sbin/php-fpm','/usr/sbin/nginx','/usr/local/bin/composer']as$file){$physical=$rootfs.$file;if(is_link($physical))continue;if(is_file($physical))$protected[$file]=hash_file('sha256',$physical);}
$app=$rootfs.'/app';if(is_link($app)||is_dir($app)&&count(array_diff(scandir($app),['.','..']))!==0)throw new RuntimeException('Original empty app directory required.');$copied=[];$vendor=[];
if($localApp){
    if(!is_dir($app))mkdir($app,0755);
    foreach($sourceInputs as$file=>$sha){$directory=dirname($app.'/'.$file);if(!is_dir($directory))mkdir($directory,0755,true);in_copy($localApp.'/'.$file,$app.'/'.$file,$copied,$file);if($copied[$file]!==$sha)throw new RuntimeException('Local source changed while copying.');}
    in_copy($localApp.'/vendor',$app.'/vendor',$vendor);$expectedFiles=array_keys($previousVendor);$actualFiles=array_keys($vendor);sort($expectedFiles);sort($actualFiles);
    if($expectedFiles!==$actualFiles)throw new RuntimeException('Copied local runtime package set differs.');
    foreach($previousVendor as$file=>$sha)if(strpos($file,'composer/')!==0&&$file!=='autoload.php'&&strpos($file,'bin/')!==0&&$vendor[$file]!==$sha)throw new RuntimeException('Original package changed during local copy.');
}else{in_copy($context,$app,$copied);in_copy($release.'/vendor',$app.'/vendor',$vendor);}
$probe=$rootfs.'/probe';if(file_exists($probe)||is_link($probe))throw new RuntimeException('Fresh private image probe required.');mkdir($probe,0700);
foreach(['image-contract.php','native-commands.sh']as$file){copy(__DIR__.'/'.$file,$probe.'/'.$file);chmod($probe.'/'.$file,0644);}
foreach(['storage/logs','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','bootstrap/cache']as$dir)if(!is_dir($app.'/'.$dir))mkdir($app.'/'.$dir,0755,true);
if(file_exists($app.'/.env')||file_exists($app.'/storage/image-fixture.sqlite'))throw new RuntimeException('Native fixture files must be fresh.');file_put_contents($app.'/.env','');touch($app.'/storage/image-fixture.sqlite');
file_put_contents($run.'/native-copied-inputs.json',json_encode($copied,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);file_put_contents($run.'/native-vendor-inputs.json',json_encode($vendor,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
foreach($protected as$file=>$sha)if(hash_file('sha256',$rootfs.$file)!==$sha)throw new RuntimeException('Original image binary changed.');
$result=['status'=>'PASS','run'=>$run,'rootfs'=>$rootfs,'context'=>$contextRun,'source_release'=>$releaseRun,'from_native'=>$fromNative,'local_package_files_matched'=>$fromNative?$samePackageFiles:null,'copied_input_count'=>count($copied),'matched_inputs'=>count($input),'vendor_file_count'=>count($vendor),'installed_runtime'=>100,'dev'=>false,'protected_original_binaries'=>$protected,'scope'=>'Copied verified current1271 app/deploy/asset inputs plus no-dev same-lock100 vendor into original digest-verified image rootfs. Only fresh owned app/probe changes. No host installs or claimed Dockerfile chown/user drop/Engine build.'];
file_put_contents($probe.'/native-stage.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);file_put_contents($run.'/native-stage-summary.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);echo json_encode(['status'=>'PASS','matched_inputs'=>count($input),'vendor_files'=>count($vendor),'rootfs'=>$rootfs],JSON_THROW_ON_ERROR),PHP_EOL;
