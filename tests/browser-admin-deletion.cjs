const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {execFileSync} = require('node:child_process');
const {chromium} = require('./.runtime/browser/node_modules/playwright');
const tree = process.argv.includes('--candidate') ? 'candidate' : 'root';
const base = 'http://127.0.0.1:8201';
const output = path.join(__dirname, '.runtime', 'admin-deletion-' + tree);
const database = path.join(output, 'fixture.sqlite');
const option = name => { const index = process.argv.indexOf(name); return index < 0 ? undefined : process.argv[index + 1]; };
const php = option('--php') || path.join(__dirname, '.runtime', tree === 'root' ? 'php74' : 'php85', 'php.exe');
const ini = option('--php-ini') || path.join(__dirname, '.runtime', tree === 'root' ? 'php74/php.ini' : 'php85-fixture.ini');
const timeout = 12000;
const labels = {trash: '回收站', login: '登录'};
const resources = {goods:'goods', 'goods-group':'goods_group', carmis:'carmis', coupon:'coupons', emailtpl:'emailtpls', pay:'pays', order:'orders'};
const report = {tree, base, startedAt:new Date().toISOString(), status:'running', pages:[], actions:[], permissions:[], assets:{}, errors:[], failedLocal:[], expectedHTTP:[], unexpectedHTTP:[], blockedExternal:[]};
const hash = data => crypto.createHash('sha256').update(data).digest('hex');
const stateHash = data => hash(JSON.stringify(data));
const trashRoute = resource => '/admin/' + resource + '?_scope_=' + encodeURIComponent(labels.trash);
const inspectCode = '$pdo=new PDO("sqlite:".$argv[1]); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $pdo->exec("PRAGMA query_only=ON"); $pdo->beginTransaction(); if ($pdo->query("SELECT name FROM fixture_marker")->fetchColumn()!=="dujiaoka-admin-browser-v1") throw new RuntimeException("Fixture marker mismatch"); $tables=[]; foreach (["goods","goods_group","carmis","coupons","emailtpls","pays","orders"] as $table) $tables[$table]=$pdo->query("SELECT * FROM ".$table." ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); $tables["coupons_goods"]=$pdo->query("SELECT * FROM coupons_goods ORDER BY goods_id")->fetchAll(PDO::FETCH_ASSOC); $pdo->rollBack(); echo json_encode(["php"=>PHP_VERSION,"binary"=>PHP_BINARY,"ini"=>php_ini_loaded_file(),"tables"=>$tables],JSON_THROW_ON_ERROR);';
function snapshot() {
  assert.ok(fs.existsSync(database), 'Initialize isolated --deletion fixture first');
  const data=JSON.parse(execFileSync(php,['-c',ini,'-r',inspectCode,database],{encoding:'utf8',cwd:path.join(__dirname,'..'),timeout:8000}));
  report.php={version:data.php,binary:data.binary,ini:data.ini};
  const files={};
  function walk(dir) { if(!fs.existsSync(dir)) return; for(const item of fs.readdirSync(dir,{withFileTypes:true}).sort((a,b)=>a.name.localeCompare(b.name))) { const file=path.join(dir,item.name); assert.ok(!item.isSymbolicLink()); if(item.isDirectory()) walk(file); else if(item.isFile()) files[path.relative(path.join(output,'storage/app'),file).split(path.sep).join('/')]=hash(fs.readFileSync(file)); } }
  walk(path.join(output,'storage/app'));
  return {tables:data.tables,files};
}
const row = (state,resource,id) => state.tables[resources[resource]].find(item=>Number(item.id)===id);

async function settled(page) {
  await page.waitForLoadState('networkidle', {timeout});
}

async function visit(actor, route, expected = 200) {
  report.currentStep = actor.name + ': visit ' + route;
  if (expected >= 400) actor.expected.set('GET ' + new URL(base + route).pathname, expected);
  const response = await actor.page.goto(base + route, {waitUntil: 'domcontentloaded'});
  assert.ok(response, route + ' returned no navigation response');
  assert.equal(response.status(), expected, actor.name + ' ' + route);
  assert.equal(response.headers()['x-fixture-tree'], tree);
  assert.equal(response.headers()['x-fixture-php'], report.php.version);
  assert.equal(response.headers()['x-fixture-framework'], tree === 'candidate' ? '13.35.0' : '6.20.42');
  report.httpRuntime = {tree, php: response.headers()['x-fixture-php'], framework: response.headers()['x-fixture-framework']};
  await settled(actor.page);
  if (expected === 200) {
    await actor.page.locator('.content-wrapper').waitFor();
    actor.csrf = await actor.page.evaluate(() => window.Dcat && Dcat.token);
    assert.ok(actor.csrf, actor.name + ' page did not expose Dcat.token');
  }
  report.pages.push({actor: actor.name, route, status: response.status(), expected});
  return response;
}
async function actor(browser, name, landing) {
  const context = await browser.newContext({viewport: {width: 1440, height: 1000}, serviceWorkers: 'block'});
  context.setDefaultTimeout(timeout);
  context.setDefaultNavigationTimeout(15000);
  const page = await context.newPage();
  const result = {name, context, page, csrf: null, expected: new Map()};
  if (name !== 'admin') result.expected.set('GET /admin', 403);
  page.on('pageerror', error => report.errors.push({actor: name, error: error.message}));
  page.on('requestfailed', request => {
    if (new URL(request.url()).origin === base) report.failedLocal.push({actor: name, url: request.url(), error: request.failure()?.errorText});
  });
  page.on('response', response => {
    const url = new URL(response.url());
    if (url.origin !== base) return;
    if (url.pathname.startsWith('/vendor/') || url.pathname.startsWith('/fixture-uploads/')) {
      const header = response.headers()['x-fixture-asset-tree'];
      report.assets[url.pathname] = {status: response.status(), tree: header};
      if (header !== tree || response.status() !== 200) report.errors.push({actor: name, asset: url.pathname, status: response.status(), tree: header});
    }
    if (response.status() >= 400) {
      const entry = {actor: name, path: url.pathname, method: response.request().method(), status: response.status()};
      const expected = result.expected.get(entry.method + ' ' + entry.path);
      (expected === entry.status ? report.expectedHTTP : report.unexpectedHTTP).push(entry);
    }
  });
  await context.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.origin === base) return route.continue();
    report.blockedExternal.push(url.origin + url.pathname);
    return route.abort();
  });
  await page.goto(base + '/admin/auth/login');
  await settled(page);
  await page.locator('[name=username]').fill(name === 'admin' ? 'fixture-admin' : 'fixture-delete-' + name);
  await page.locator('[name=password]').fill('fixture-password');
  const waiting = page.waitForResponse(response => new URL(response.url()).pathname === '/admin/auth/login' && response.request().method() === 'POST');
  waiting.catch(() => {});
  await page.getByRole('button', {name: labels.login, exact: false}).click();
  await success(await waiting, name + ' login');
  await page.waitForURL(url => url.pathname !== '/admin/auth/login');
  await settled(page);
  await visit(result, landing);
  report.actions.push({actor: name, test: 'real browser login with independent cookie/CSRF context'});
  return result;
}

async function success(response, label) {
  assert.equal(response.status(), 200, label);
  const data = await response.json();
  assert.equal(data.status, true, label + ': ' + JSON.stringify(data));
  if (data.data && data.data.type) assert.equal(data.data.type, 'success', label + ': ' + JSON.stringify(data));
  return data;
}

function fieldsBody(fields) {
  const body = new URLSearchParams();
  for (const [name, value] of Object.entries(fields)) {
    if (Array.isArray(value)) value.forEach(item => body.append(name + '[]', String(item)));
    else if (value !== undefined && value !== null) body.append(name, String(value));
  }
  return body.toString();
}

async function request(actor, method, route, fields = {}, file) {
  const url = new URL(route, base);
  assert.equal(url.origin, base, 'API request left the fixture origin');
  assert.ok(url.pathname.startsWith('/admin/') || url.pathname.startsWith('/fixture-uploads/'));
  const headers = {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'};
  const options = {method, headers, maxRedirects: 0, timeout, failOnStatusCode: false};
  if (method !== 'GET') {
    assert.ok(actor.csrf, actor.name + ' missing its own CSRF token');
    const ownFields = {...fields, _token: actor.csrf};
    headers['X-CSRF-TOKEN'] = actor.csrf;
    if (file) options.multipart = {...Object.fromEntries(Object.entries(ownFields).map(([key, value]) => [key, String(value)])), _file_: file};
    else {
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
      options.data = fieldsBody(ownFields);
    }
  }
  return actor.context.request.fetch(url.href, options);
}

async function unchanged(actor, label, method, route, fields = {}, file, expected = 403) {
  report.currentStep = actor.name + ': ' + label;
  const before = snapshot();
  const response = await request(actor, method, route, fields, file);
  const after = snapshot();
  const entry = {actor: actor.name, test: label, method, route: new URL(route, base).pathname, expected, status: response.status(), before: stateHash(before), after: stateHash(after), unchanged: stateHash(before) === stateHash(after)};
  report.permissions.push(entry);
  assert.equal(entry.status, expected, report.currentStep);
  assert.deepEqual(after, before, report.currentStep + ' changed goods, carmis, or local business files');
}

async function denied(actor,label,route,fields={},expected=403) { return unchanged(actor,label,'POST',route,{_method:'DELETE',...fields},undefined,expected); }
async function chosen(actor,ids) { for(const id of ids) await actor.page.locator('table tbody input[type=checkbox][data-id="'+id+'"]').first().check({force:true}); }
async function batchDelete(actor,resource,ids,trash=false,fail=false) {
  await visit(actor,trash?trashRoute(resource):'/admin/'+resource);
  await chosen(actor,ids);
  const item=actor.page.locator('[data-action="batch-delete"]');
  assert.equal(await item.getAttribute('data-url'),base+'/admin/'+resource);
  const group=item.locator('xpath=ancestor::*[contains(concat(" ",normalize-space(@class)," ")," btn-group ")][1]');
  await group.locator('[data-toggle=dropdown]').click();
  const endpoint='/admin/'+resource+'/'+ids.join(',');
  report.currentStep=actor.name+': real checkbox '+(trash?'permanent':'soft')+' '+resource+' '+ids.join(',')+(fail?' injected rollback':'');
  const before=snapshot();
  const waiting=actor.page.waitForResponse(response=>{ const pathname=decodeURIComponent(new URL(response.url()).pathname); const prefix='/admin/'+resource+'/'; return response.request().method()==='POST'&&pathname.startsWith(prefix)&&pathname.slice(prefix.length).split(',').sort().join(',')===ids.map(String).sort().join(','); });
  waiting.catch(()=>{});
  await item.click(); await actor.page.locator('.swal2-confirm').click();
  const response=await waiting;
  const posted=new URLSearchParams(response.request().postData()||'');
  assert.equal(posted.get('_method'),'DELETE'); assert.equal(posted.get('_token'),actor.csrf);
  if(fail) { assert.equal(response.status(),200); const json=await response.json(); assert.equal(json.status,false,JSON.stringify(json)); assert.deepEqual(snapshot(),before,'Failed batch changed business rows or files'); }
  else { await success(response,report.currentStep); const after=snapshot(); for(const id of ids) { if(trash) assert.equal(row(after,resource,id),undefined,'Permanent row survived'); else assert.ok(row(after,resource,id).deleted_at,'Soft row is active'); } }
  // Wait for Dcat's own PJAX refresh before starting a separate verification navigation.
  await actor.page.waitForFunction(({ids,fail})=>window.jQuery&&jQuery.active===0&&(fail||ids.every(id=>!document.querySelector('table tbody input[data-id="'+id+'"]'))),{ids,fail});
  await visit(actor,trash?trashRoute(resource):'/admin/'+resource);
  if(!fail) for(const id of ids) assert.equal(await actor.page.locator('table tbody input[data-id="'+id+'"]').count(),0,'Deleted row remains in reloaded grid');
  report.actions.push({actor:actor.name,resource,ids,trash,fail,method:'POST',spoof:'DELETE',postedPath:new URL(response.url()).pathname,realCheckboxes:true,realConfirmation:true,nativeRefresh:!fail,reloaded:true});
}
function failNext(table,id) { fs.writeFileSync(path.join(output,'fail-next-delete.json'),JSON.stringify({table,id})); }
(async()=>{
 let browser; let deadline;
 try {
  report.initial=snapshot();
  assert.equal(Object.keys(report.initial.files).length,7,'Require clean seven-picture baseline');
  for(const resource of Object.keys(resources)) { for(const id of [10,11,12]) assert.equal(row(report.initial,resource,id).deleted_at,null); for(const id of [20,21]) assert.ok(row(report.initial,resource,id).deleted_at); }
  browser=await chromium.launch({channel:'chrome',headless:true,timeout:15000}); report.browser=browser.version();
  deadline=setTimeout(()=>{report.errors.push({error:'Deletion browser exceeded six minutes'});browser.close().catch(()=>{});},360000); deadline.unref();
  const reader=await actor(browser,'reader','/admin/goods');
  if(process.argv.includes('--probe-emailtpl-scope')) { await visit(reader,trashRoute('emailtpl')); assert.equal(await reader.page.locator('table tbody input[data-id="20"]').count(),1,'Email template recycle scope must show trashed ID20'); report.status='passed'; return; }
  for(const resource of Object.keys(resources)) { await visit(reader,'/admin/'+resource); await denied(reader,'reader batch denied '+resource,'/admin/'+resource+'/10,11'); await denied(reader,'reader permanent denied '+resource,'/admin/'+resource+'/20,21'); }
  const limited=await actor(browser,'limited','/admin/goods');
  await denied(limited,'prefix grant cannot borrow ungranted ID4','/admin/goods/3,4');
  await denied(limited,'encoded comma has same per-ID restriction','/admin/goods/3%2C4');
  await success(await request(limited,'POST','/admin/goods/3',{_method:'DELETE'}),'prefix legitimate single permanent delete');
  assert.equal(row(snapshot(),'goods',3),undefined); assert.ok(row(snapshot(),'goods',4)); assert.ok(!snapshot().files['admin/images/deletion-3.png']); assert.ok(row(snapshot(),'carmis',42).deleted_at);
  const deleter=await actor(browser,'deleter','/admin/goods');
  for(const key of ['0','10,','10,,11','-1','1e1']) await denied(deleter,'invalid keys rejected '+key,'/admin/goods/'+key,{},400);
  await denied(deleter,'DELETE grant cannot PUT','/admin/goods/12',{_method:'PUT',_editable:1,name:'is_open',value:0});
  { const before=snapshot(); const response=await deleter.context.request.post(base+'/admin/goods/12',{headers:{Accept:'application/json'},form:{_method:'DELETE',_token:'fixture-invalid'},maxRedirects:0}); assert.equal(response.status(),419); assert.deepEqual(snapshot(),before); report.permissions.push({actor:'deleter',test:'invalid CSRF cannot delete',status:419,unchanged:true}); }
  for(const resource of ['carmis','coupon','emailtpl','pay','order','goods','goods-group']) {
   if(resource==='goods') { failNext('goods',11); await batchDelete(deleter,resource,[10,11],false,true); }
   await batchDelete(deleter,resource,[10,11]);
   if(resource==='goods') { assert.ok(snapshot().files['admin/images/deletion-10.png']); assert.ok(row(snapshot(),'carmis',40).deleted_at&&row(snapshot(),'carmis',41).deleted_at); failNext('goods',21); await batchDelete(deleter,resource,[20,21],true,true); }
   await batchDelete(deleter,resource,[20,21],true);
   if(resource==='goods') { assert.ok(!snapshot().files['admin/images/deletion-20.png']&&!snapshot().files['admin/images/deletion-21.png']); }
   await success(await request(deleter,'POST','/admin/'+resource+'/12,10',{_method:'DELETE'}),'mixed active/trash '+resource);
   assert.equal(row(snapshot(),resource,10),undefined); assert.ok(row(snapshot(),resource,12).deleted_at); assert.ok(row(snapshot(),resource,11).deleted_at); assert.ok(row(snapshot(),resource,1));
   report.actions.push({actor:'deleter',resource,ids:[12,10],mixed:true});
  }
  await visit(deleter,trashRoute('goods'));
  await deleter.page.screenshot({path:path.join(output,'deleted-goods-grid-38.png'),fullPage:false});
  report.final=snapshot();
  assert.deepEqual(Object.keys(report.final.files).sort(),['admin/images/deletion-11.png','admin/images/deletion-12.png','admin/images/deletion-4.png']);
  report.deleteEvents=fs.readFileSync(path.join(output,'delete-events.jsonl'),'utf8').trim().split(String.fromCharCode(10)).map(line=>JSON.parse(line));
  assert.ok(report.deleteEvents.length>0&&report.deleteEvents.every(event=>event.transactionLevel>0),'Actual delete hooks ran outside transaction');
  assert.deepEqual(report.errors,[]);assert.deepEqual(report.unexpectedHTTP,[]);assert.deepEqual(report.failedLocal,[]);assert.deepEqual(report.blockedExternal,[]);
  report.status='passed';
 } catch(error) { report.status='failed';report.failure={step:report.currentStep,message:error.message,stack:error.stack};try {report.final=snapshot();}catch(ignore){} process.exitCode=1; }
 finally {clearTimeout(deadline);if(browser)await browser.close();report.finishedAt=new Date().toISOString();fs.writeFileSync(path.join(output,process.argv.includes('--probe-emailtpl-scope')?'emailtpl-scope-after-38.json':(report.status==='passed'?'browser-summary-38.json':'browser-failure-38.json')),JSON.stringify(report,null,2)+String.fromCharCode(10));console.log(JSON.stringify({tree,status:report.status,pages:report.pages.length,actions:report.actions.length,permissions:report.permissions.length,assets:Object.keys(report.assets).length,failure:report.failure}));}
})().catch(error=>{console.error(error);process.exitCode=1;});
