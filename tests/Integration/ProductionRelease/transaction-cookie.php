<?php
require_once __DIR__ . '/bootstrap.php';
$cfg = pr_manifest($argv[1] ?? '');
if (empty($cfg['transactions']) || is_file($cfg['run'].'/runtime-summary.json')) throw new RuntimeException('Active owned transaction release required.');
pr_environment($cfg);
require $cfg['release'].'/vendor/autoload.php';
$app = require $cfg['release'].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cipher = null;
foreach (file($cfg['run'].'/cookies',FILE_IGNORE_NEW_LINES) as $line) {
    $parts = explode(chr(9),$line);
    if (count($parts)===7 && $parts[5]==='dujiaoka_orders') $cipher=rawurldecode($parts[6]);
}
if (!$cipher) throw new RuntimeException('Actual received order cookie required.');
$middleware = new App\Http\Middleware\EncryptCookies($app->make('encrypter'));
$decode = function ($value) use ($middleware) {
    $request=Illuminate\Http\Request::create('/create-order','POST',[],['dujiaoka_orders'=>$value]); $plain=null;
    $middleware->handle($request,function ($r) use (&$plain) { $plain=$r->cookies->get('dujiaoka_orders'); return new Illuminate\Http\Response('Synthetic native cookie verification'); });
    return $plain;
};
$plain=$decode($cipher); $sns=json_decode(file_get_contents($cfg['run'].'/transaction-orders.json'),true,512,JSON_THROW_ON_ERROR);
if ($cipher===$plain || json_decode($plain,true,512,JSON_THROW_ON_ERROR)!==$sns || strpos($cipher,$sns[0])!==false) throw new RuntimeException('Native encrypted cookie exact contents required.');
$changed=substr_replace($cipher,$cipher[10]==='A'?'B':'A',10,1);
if ($decode($changed)!==null) throw new RuntimeException('Native cookie tamper verification required.');
$failer=$app->make('queue.failer');
$db=$app->make('db')->connection(); $beforeJobs=$db->table('jobs')->orderBy('id')->get()->toArray();
if ($db->table('failed_jobs')->count()!==0) throw new RuntimeException('Clean provider control baseline required.');
$failurePayload=json_encode(['uuid'=>(string) Illuminate\Support\Str::uuid(),'synthetic_release_failure'=>46],JSON_THROW_ON_ERROR);
$uuid=$failer->log('database','default',$failurePayload,new RuntimeException('Injected production failure provider control'));
$failedRow=(array)$db->table('failed_jobs')->where('uuid',$uuid)->first();
if (count($failedRow)===0 || !preg_match('/^[a-f0-9-]{36}$/D',$uuid) || $failedRow['connection']!=='database'
    || $failer->forget($uuid)!==true || $db->table('failed_jobs')->count()!==0 || $db->table('jobs')->orderBy('id')->get()->toArray()!=$beforeJobs) throw new RuntimeException('Original UUID provider log/forget control failed.');
ql_json($cfg['run'].'/transaction-failure-provider.json',['status'=>'PASS','driver'=>config('queue.failed.driver'),'provider'=>(new ReflectionClass($failer))->getFileName(),'logged'=>$failedRow,'forgotten'=>true,'jobs_unchanged'=>true]);
ql_json($cfg['run'].'/transaction-cookie-proof.json',['status'=>'PASS','orders'=>$sns,'cipher_differs'=>true,'cipher_sha256'=>hash('sha256',$cipher),'tamper_rejected'=>true,'middleware'=>(new ReflectionClass($middleware))->getFileName()]);
$app->make('db')->disconnect();
echo 'Actual native encrypted order cookie and tamper control PASS',PHP_EOL;
