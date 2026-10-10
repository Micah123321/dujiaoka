const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const output = path.resolve(__dirname, '../../.runtime/modern-image/registry-47');
const hash = data => 'sha256:' + crypto.createHash('sha256').update(data).digest('hex');
(async () => {
  if (fs.existsSync(path.join(output,'registry-proof.json'))) throw new Error('Completed registry snapshot is retained; verify its existing bytes.');
  fs.mkdirSync(output, {recursive:true});
  const auth = await fetch('https://auth.docker.io/token?service=registry.docker.io&scope=repository:webdevops/php-nginx:pull');
  if (!auth.ok) throw new Error('Anonymous public registry authentication returned '+auth.status);
  const token=(await auth.json()).token;
  const headers={Authorization:'Bearer '+token, Accept:'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json'};
  async function get(reference, endpoint='manifests') {
    const response=await fetch('https://registry-1.docker.io/v2/webdevops/php-nginx/'+endpoint+'/'+reference,{headers});
    if(!response.ok)throw new Error('Public registry '+endpoint+' '+reference+' returned '+response.status);
    const bytes=Buffer.from(await response.arrayBuffer());const digest=hash(bytes);
    const advertised=response.headers.get('docker-content-digest');
    if((advertised&&advertised!==digest)||(reference.startsWith('sha256:')&&reference!==digest))throw new Error('Registry byte digest mismatch');
    return {bytes,digest,json:JSON.parse(bytes)};
  }
  const tag=await get('8.5');fs.writeFileSync(path.join(output,'tag.json'),tag.bytes);
  let manifest=tag;
  if(tag.json.manifests){const choices=tag.json.manifests.filter(x=>x.platform.os==='linux'&&x.platform.architecture==='amd64'&&!x.platform.variant);if(choices.length!==1)throw new Error('Exactly one native linux/amd64 manifest required');manifest=await get(choices[0].digest);}
  fs.writeFileSync(path.join(output,'manifest.json'),manifest.bytes);
  const config=await get(manifest.json.config.digest,'blobs');fs.writeFileSync(path.join(output,'config.json'),config.bytes);
  if(config.json.os!=='linux'||config.json.architecture!=='amd64')throw new Error('Native platform mismatch');
  const proof={status:'PASS',repository:'webdevops/php-nginx',tag:'8.5',tagDigest:tag.digest,manifestDigest:manifest.digest,configDigest:config.digest,platform:'linux/amd64',created:config.json.created,entrypoint:config.json.config.Entrypoint,cmd:config.json.config.Cmd,user:config.json.config.User,stopSignal:config.json.config.StopSignal,env:config.json.config.Env.filter(x=>/^(PHP_VERSION|WEB_DOCUMENT_ROOT|APPLICATION_UID|APPLICATION_GID|COMPOSER_VERSION)=/.test(x)),layers:manifest.json.layers.map(x=>({digest:x.digest,bytes:x.size})),totalLayerBytes:manifest.json.layers.reduce((n,x)=>n+x.size,0),verified:'Actual tag/amd64 manifest/config bytes only; layers and runtime not fetched or executed'};
  fs.writeFileSync(path.join(output,'registry-proof.json'),JSON.stringify(proof,null,2));console.log(JSON.stringify(proof));
})().catch(e=>{console.error(e.message);process.exitCode=1;});
