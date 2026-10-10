const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {Readable, Transform} = require('node:stream');
const {pipeline} = require('node:stream/promises');
const root = path.resolve(__dirname, '../../..');
const snapshots = path.join(root, 'tests/.runtime/modern-image/registry-47');
const parent = path.join(root, 'tests/.runtime/modern-image');
const digest = bytes => 'sha256:' + crypto.createHash('sha256').update(bytes).digest('hex');
const manifestDigest = 'sha256:a77a6fa44506f01ad9c61e7621fccf5a3036bf9e6fd3786bbf5a98d6b988aba6';
const configDigest = 'sha256:b80ffe888ac6dddfabfb22e74d98aa5e9fef111b09065cea0a2a9d8aedac6521';
function ownedDirectory(directory) {
  const resolved = fs.realpathSync(directory);
  if (resolved !== path.resolve(directory)) throw new Error('Linked owned directory detected');
  for (let part = resolved; part !== path.dirname(part); part = path.dirname(part)) {
    if (fs.lstatSync(part).isSymbolicLink()) throw new Error('Linked owned ancestor detected');
  }
  return resolved;
}
async function fileDigest(file) {
  const sha = crypto.createHash('sha256'); let bytes = 0;
  for await (const chunk of fs.createReadStream(file)) {sha.update(chunk); bytes += chunk.length;}
  return {digest: 'sha256:' + sha.digest('hex'), bytes};
}
(async () => {
  ownedDirectory(root); ownedDirectory(parent); ownedDirectory(snapshots);
  const manifestBytes = fs.readFileSync(path.join(snapshots, 'manifest.json'));
  const configBytes = fs.readFileSync(path.join(snapshots, 'config.json'));
  if (digest(manifestBytes) !== manifestDigest || digest(configBytes) !== configDigest) throw new Error('Original frozen manifest/config bytes changed');
  const manifest = JSON.parse(manifestBytes); const config = JSON.parse(configBytes);
  if (manifest.config.digest !== configDigest || manifest.config.size !== configBytes.length
      || config.architecture !== 'amd64' || config.os !== 'linux' || config.rootfs.type !== 'layers'
      || manifest.layers.length !== 20 || config.rootfs.diff_ids.length !== 20
      || manifest.layers.some(x => !/^sha256:[0-9a-f]{64}$/.test(x.digest) || x.mediaType !== 'application/vnd.docker.image.rootfs.diff.tar.gzip' || !Number.isSafeInteger(x.size) || x.size < 1)) throw new Error('Native frozen twenty layer manifest required');
  const expectedTotal = manifest.layers.reduce((sum, x) => sum + x.size, 0);
  if (expectedTotal !== 431655842) throw new Error('Frozen image transfer size changed');
  const marker = crypto.randomBytes(12).toString('hex');
  const date = new Date().toISOString().replace(/[-:]/g,'').replace('T','-').slice(0,15);
  const run = path.join(parent, 'run-layers-' + date + '-' + marker);
  fs.mkdirSync(run, {recursive:false}); fs.mkdirSync(path.join(run,'blobs'));
  fs.writeFileSync(path.join(run,'marker'), marker, {flag:'wx'});
  fs.writeFileSync(path.join(run,'manifest.json'), manifestBytes, {flag:'wx'});
  fs.writeFileSync(path.join(run,'config.json'), configBytes, {flag:'wx'});
  const cfg = {root:root.replaceAll('\\','/'),run:run.replaceAll('\\','/'),marker,manifestDigest,configDigest,linuxRoot:'/var/tmp/dujiaoka-image50-'+marker,rootfs:'/var/tmp/dujiaoka-image50-'+marker+'/rootfs'};
  fs.writeFileSync(path.join(run,'run.json'), JSON.stringify(cfg,null,2)+'\n', {flag:'wx'});
  console.log(JSON.stringify({event:'PullStarted',run:cfg.run,layers:20,expectedBytes:expectedTotal}));
  if (process.argv[2] === '--from-pull') {
    const source = ownedDirectory(path.resolve(process.argv[3] || ''));
    if (path.dirname(source) !== parent || !/^run-layers-\d{8}-\d{6}-[a-f0-9]{24}$/.test(path.basename(source))) throw new Error('Owned completed original pull required');
    const old = JSON.parse(fs.readFileSync(path.join(source,'pull-summary.json')));
    const oldCfg = JSON.parse(fs.readFileSync(path.join(source,'run.json')));
    if (old.status !== 'PASS' || old.manifestDigest !== manifestDigest || old.configDigest !== configDigest || old.totalBytes !== expectedTotal
        || oldCfg.run !== source.replaceAll('\\','/') || fs.readFileSync(path.join(source,'marker'),'utf8') !== oldCfg.marker
        || digest(fs.readFileSync(path.join(source,'manifest.json'))) !== manifestDigest || digest(fs.readFileSync(path.join(source,'config.json'))) !== configDigest) throw new Error('Original fixed completed pull identity mismatch');
    const results=[];
    for(const [position,layer]of manifest.layers.entries()){
      const name=layer.digest.slice(7)+'.tar.gz';const original=path.join(source,'blobs',name);const copied=path.join(run,'blobs',name);
      const before=await fileDigest(original);if(before.digest!==layer.digest||before.bytes!==layer.size)throw new Error('Completed original blob changed');
      fs.copyFileSync(original,copied,fs.constants.COPYFILE_EXCL);const after=await fileDigest(copied);
      if(after.digest!==before.digest||after.bytes!==before.bytes)throw new Error('Reused copied layer bytes differ');
      results.push({position,digest:layer.digest,bytes:layer.size,file:'blobs/'+name});
    }
    const summary={status:'PASS',run:cfg.run,manifestDigest,configDigest,platform:'linux/amd64',layers:results,totalBytes:expectedTotal,fromPull:source.replaceAll('\\','/'),scope:'New owned immutable run copies all twenty already downloaded exact original layers and rehashes before/after. No new registry fetch. Previous ephemeral rootfs/run remains historical. Persistent rootfs still requires complete diffIDs and OCI merge.'};
    fs.writeFileSync(path.join(run,'pull-summary.json'),JSON.stringify(summary,null,2)+'\n',{flag:'wx'});console.log(JSON.stringify({status:'PASS',run:cfg.run,layers:20,reusedOriginal:true}));return;
  }
  const response = await fetch('https://auth.docker.io/token?service=registry.docker.io&scope=repository:webdevops/php-nginx:pull',{signal:AbortSignal.timeout(30000)});
  if (!response.ok) throw new Error('Public anonymous registry auth returned '+response.status);
  const token = (await response.json()).token;
  if (typeof token !== 'string' || !token) throw new Error('Anonymous pull response has no token');
  let index = 0; const results = []; const failures = [];
  async function worker() {
    while (index < manifest.layers.length) {
      const position = index++; const layer = manifest.layers[position];
      const file = path.join(run,'blobs',layer.digest.slice(7)+'.tar.gz');
      const partial = file+'.partial';
      try {
        const response = await fetch('https://registry-1.docker.io/v2/webdevops/php-nginx/blobs/'+layer.digest,{headers:{Authorization:'Bearer '+token},signal:AbortSignal.timeout(600000)});
        if (!response.ok || !response.body) throw new Error('Public layer returned '+response.status);
        const sha = crypto.createHash('sha256'); let bytes = 0;
        const observer = new Transform({transform(chunk,encoding,callback){bytes += chunk.length;if(bytes>layer.size){callback(new Error('Layer exceeds frozen bytes'));return;}sha.update(chunk);callback(null,chunk);}});
        await pipeline(Readable.fromWeb(response.body),observer,fs.createWriteStream(partial,{flags:'wx'}));
        const actual = 'sha256:'+sha.digest('hex');
        if (bytes !== layer.size || actual !== layer.digest) throw new Error('Actual layer bytes/digest mismatch');
        const advertised = response.headers.get('docker-content-digest');
        if (advertised && advertised !== actual) throw new Error('Advertised layer digest mismatch');
        fs.renameSync(partial,file);
        results[position] = {position,digest:actual,bytes,file:'blobs/'+path.basename(file)};
        console.log(JSON.stringify({event:'LayerVerified',position,digest:actual,bytes}));
      } catch(error) {failures.push({position,digest:layer.digest,error:error.message});}
    }
  }
  // Three independent bounded streams; no bearer credentials or redirect URLs are recorded.
  await Promise.all([worker(),worker(),worker()]);
  if (failures.length || results.filter(Boolean).length !== 20) {
    fs.writeFileSync(path.join(run,'pull-failure.json'),JSON.stringify({status:'FAIL',layersVerified:results.filter(Boolean).length,failures},null,2)+'\n',{flag:'wx'});
    throw new Error('Frozen layer pull incomplete; owned failed artifacts retained in '+cfg.run);
  }
  for(const result of results){const recheck=await fileDigest(path.join(run,result.file));if(recheck.digest!==result.digest||recheck.bytes!==result.bytes)throw new Error('Saved layer changed before completion');}
  const summary = {status:'PASS',run:cfg.run,manifestDigest,configDigest,platform:'linux/amd64',layers:results,totalBytes:expectedTotal,scope:'Original frozen twenty gzip layer compressed bytes fetched anonymously, exact descriptor digest/size reopened. Raw config/manifest immutable. DiffIDs, OCI whiteout application, rootfs, Docker build/run, permissions and services not yet verified.'};
  fs.writeFileSync(path.join(run,'pull-summary.json'),JSON.stringify(summary,null,2)+'\n',{flag:'wx'});
  console.log(JSON.stringify({status:'PASS',run:cfg.run,layers:20,totalBytes:expectedTotal}));
})().catch(error => {console.error(error.message);process.exitCode=1;});
