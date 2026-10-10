const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {execFileSync} = require('node:child_process');
const {chromium} = require('./.runtime/browser/node_modules/playwright');
const tree = process.argv.includes('--candidate') ? 'candidate' : 'root';
const option = name => { const i = process.argv.indexOf(name); return i < 0 ? undefined : process.argv[i + 1]; };
const php = option('--php'); const ini = option('--php-ini');
assert.ok(php && ini, 'Explicit verified runtime and INI required');
const base = 'http://127.0.0.1:8202';
const output = path.join(__dirname, '.runtime', 'admin-forms-' + tree);
const database = path.join(output, 'fixture.sqlite');
const run = path.join(output, 'run-' + Date.now() + '-' + crypto.randomBytes(6).toString('hex'));
fs.mkdirSync(run, {recursive: true});
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const report = {tree, base, status: 'running', startedAt: new Date().toISOString(), run, pages: [], checks: [], requests: [], assets: {}, assetHashes: {},
  blockedExternal: [], errors: [], unexpectedHTTP: [], failedLocal: [], snapshots: []};
const inspect = [
  '$db = new PDO("sqlite:" . $argv[1]); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);',
  '$db->exec("PRAGMA query_only=ON"); $db->beginTransaction();',
  'if ($db->query("SELECT name FROM fixture_marker")->fetchColumn() !== "dujiaoka-admin-browser-v1") throw new RuntimeException("Fixture marker mismatch");',
  '$out = ["php"=>PHP_VERSION, "ini"=>php_ini_loaded_file()];',
  'foreach (["coupons","coupons_goods","goods","orders","carmis","pays","emailtpls","goods_group"] as $table) $out[$table] = $db->query("SELECT * FROM " . $table . " ORDER BY " . ($table === "coupons_goods" ? "coupons_id,goods_id" : "id"))->fetchAll(PDO::FETCH_ASSOC);',
  '$db->rollBack(); echo json_encode($out, JSON_THROW_ON_ERROR);',
].join('\n');
function snapshot(label) {
  assert.ok(fs.lstatSync(database).isFile());
  const data = JSON.parse(execFileSync(php, ['-c', ini, '-r', inspect, database], {cwd: path.join(__dirname, '..'), encoding: 'utf8', timeout: 10000, maxBuffer: 4 * 1024 * 1024}));
  assert.equal(data.php, tree === 'root' ? '7.4.33' : '8.5.11');
  const file = label + '.state.json'; fs.writeFileSync(path.join(run, file), JSON.stringify(data, null, 2));
  report.snapshots.push({label, file, sha256: hash(fs.readFileSync(path.join(run, file)))}); return data;
}
function check(name, fn) { fn(); report.checks.push(name); }
const pivot = (state, id) => state.coupons_goods.filter(row => Number(row.coupons_id) === id).map(row => Number(row.goods_id));
const unrelated = state => Object.fromEntries(['goods','orders','carmis','pays','emailtpls','goods_group'].map(table => [table,state[table]]));
(async () => {
  const initial = snapshot('before');
  assert.equal(initial.coupons.length, 1); assert.equal(initial.goods.length, 3);
  const browser = await chromium.launch({channel: 'chrome', headless: true}); report.browser = browser.version();
  const pending = []; let page;
  try {
    const context = await browser.newContext({viewport: {width:1440,height:1000}});
    await context.route('**/*', route => { const url = new URL(route.request().url()); if(url.origin === base)return route.continue(); report.blockedExternal.push(url.origin + url.pathname); return route.abort(); });
    page = await context.newPage(); page.setDefaultTimeout(12000);
    page.on('pageerror', e => report.errors.push(e.message));
    page.on('requestfailed', request => { if(request.url().startsWith(base))report.failedLocal.push(request.url()); });
    page.on('response', response => {
      const url = new URL(response.url()); if(url.origin !== base)return;
      if(response.status() >= 400)report.unexpectedHTTP.push({path:url.pathname,status:response.status()});
      if(url.pathname.startsWith('/vendor/')) { assert.equal(response.headers()['x-fixture-asset-tree'],tree); report.assets[url.pathname]=response.status(); }
      if(response.request().method() === 'POST') {
        const i = report.requests.length; const entry = {path:url.pathname,method:'POST',form:response.request().postData(),status:response.status(),headers:response.headers()}; report.requests.push(entry);
        pending.push(response.body().then(body => {entry.bodyFile='response-' + i + '.json'; entry.bodySha256=hash(body); fs.writeFileSync(path.join(run,entry.bodyFile),body);}));
      }
    });
    async function visit(route) {
      const response = await page.goto(base + route); assert.equal(response.status(),200);
      assert.equal(response.headers()['x-fixture-tree'],tree); assert.equal(response.headers()['x-fixture-php'],tree==='root'?'7.4.33':'8.5.11');
      assert.equal(response.headers()['x-fixture-framework'],tree==='root'?'6.20.42':'13.35.0');
      await page.waitForLoadState('networkidle'); await page.locator('.content-wrapper').waitFor();
      const domFile='page-'+report.pages.length+'.html'; fs.writeFileSync(path.join(run,domFile),await page.content());
      report.pages.push({route,status:response.status(),viewport:page.viewportSize().width,domFile});
    }
    async function save(endpoint) {
      const promise = page.waitForResponse(r => new URL(r.url()).pathname === endpoint && r.request().method()==='POST');
      promise.catch(()=>{}); await page.getByRole('button',{name:'提交',exact:false}).click();
      const response = await promise; const data = await response.json(); assert.equal(response.status(),200); assert.equal(data.status,true,JSON.stringify(data)); assert.equal(data.data.type,'success');
      await page.waitForURL(base + '/admin/coupon'); await page.waitForLoadState('networkidle'); return data;
    }
    async function selectGoods(name) {
      if (!(await page.locator('.select2-container--open .select2-results').isVisible())) await page.locator('.select2-selection--multiple').click();
      await page.locator('.select2-results__option').filter({hasText:new RegExp('^'+name+'$')}).click();
      await page.keyboard.press('Escape');
    }
    async function removeGoods(name) {
      await page.locator('.select2-selection__choice').filter({hasText:name}).locator('.select2-selection__choice__remove').click();
      await page.keyboard.press('Escape');
    }
    const selected = () => page.locator('select[name="goods[]"]').evaluate(e => Array.from(e.selectedOptions).map(o=>Number(o.value)));
    await page.goto(base + '/admin/auth/login'); await page.waitForLoadState('networkidle');
    await page.locator('[name=username]').fill('fixture-admin'); await page.locator('[name=password]').fill('fixture-password');
    await page.getByRole('button',{name:'登录',exact:false}).click(); await page.waitForURL('**/admin'); await page.waitForLoadState('networkidle');
    check('real_session_login',()=>assert.equal(page.url(),base+'/admin'));
    await visit('/admin/coupon/create');
    const csrf = await page.evaluate(() => window.Dcat && Dcat.token); assert.ok(csrf);
    const missing = await context.request.post(base+'/admin/coupon',{form:{coupon:'',discount:'1.25','goods[]':'1',ret:'2',is_use:'1',is_open:'0',_token:csrf}});
    check('real_required_validation422_no_mutation',()=>assert.equal(missing.status(),422));
    const invalidBody = await missing.body(); fs.writeFileSync(path.join(run,'validation-response.json'),invalidBody);
    assert.equal(JSON.parse(invalidBody).status,false); assert.ok(JSON.parse(invalidBody).errors.coupon);
    assert.deepEqual(snapshot('after-validation'),initial);
    const noToken = await context.request.post(base+'/admin/coupon',{form:{coupon:'CSRF-MUST-NOT-SAVE',discount:'1.25','goods[]':'1'}});
    check('real_missing_csrf419_no_mutation',()=>assert.equal(noToken.status(),419)); assert.deepEqual(snapshot('after-csrf'),initial);
    await selectGoods('Fixture Auto Goods'); await selectGoods('Fixture Manual Goods');
    assert.deepEqual(await selected(),[1,2]);
    await page.locator('[name=discount]').fill('1.25'); await page.locator('[name=coupon]').fill('BROWSER-COUPON-45'); await page.locator('[name=ret]').fill('2');
    const open = page.locator('input[type=checkbox][name=is_open]'); if(await open.isChecked())await open.locator('..').locator('.switchery').click(); assert.equal(await open.isChecked(),false);
    await save('/admin/coupon');
    const created = snapshot('after-create'); const row = created.coupons.find(r=>r.coupon==='BROWSER-COUPON-45'); assert.ok(row); const id=Number(row.id); report.couponId=id;
    check('create_scalar_and_relation_exact',()=>{assert.equal(Number(row.discount),1.25);assert.equal(Number(row.ret),2);assert.equal(Number(row.is_open),0);assert.deepEqual(pivot(created,id),[1,2]);assert.deepEqual(unrelated(created),unrelated(initial));});
    await visit('/admin/coupon/'+id+'/edit'); assert.deepEqual(await selected(),[1,2]);
    await removeGoods('Fixture Auto Goods'); await selectGoods('Fixture Third Goods'); assert.deepEqual(await selected(),[2,3]);
    await page.locator('[name=discount]').fill('2.50'); await page.locator('[name=ret]').fill('0');
    await save('/admin/coupon/'+id);
    const replaced=snapshot('after-replace');
    check('replace_detaches_and_attaches_exactly',()=>{assert.deepEqual(pivot(replaced,id),[2,3]);assert.equal(Number(replaced.coupons.find(r=>Number(r.id)===id).discount),2.5);assert.equal(Number(replaced.coupons.find(r=>Number(r.id)===id).ret),0);assert.deepEqual(unrelated(replaced),unrelated(initial));});
    await visit('/admin/coupon/'+id+'/edit'); assert.deepEqual(await selected(),[2,3]);
    await page.screenshot({path:path.join(run,'coupon-selected-desktop.png'),fullPage:false});
    await removeGoods('Fixture Manual Goods'); await removeGoods('Fixture Third Goods'); assert.deepEqual(await selected(),[]);
    await save('/admin/coupon/'+id);
    const cleared=snapshot('after-clear');
    check('clear_empty_select_submission_removes_relations',()=>{assert.deepEqual(pivot(cleared,id),[]);assert.deepEqual(unrelated(cleared),unrelated(initial));assert.deepEqual(cleared.coupons.find(r=>Number(r.id)===1),initial.coupons[0]);assert.deepEqual(pivot(cleared,1),[1]);});
    await page.setViewportSize({width:390,height:844}); await visit('/admin/coupon/'+id+'/edit'); assert.deepEqual(await selected(),[]);
    assert.equal(await page.locator('[name=ret]').inputValue(),'0');
    const bounds=await page.locator('.content-wrapper').boundingBox(); check('mobile_coupon_form_shell_bounds',()=>assert.ok(bounds&&bounds.x>=-1&&bounds.width<=391));
    await page.screenshot({path:path.join(run,'coupon-cleared-mobile.png'),fullPage:false});
    await page.goto(base+'/admin/auth/logout'); await page.waitForURL('**/admin/auth/login');
    const unauthorized=await context.request.get(base+'/admin/coupon',{maxRedirects:0});check('logout_revokes_coupon_access',()=>assert.equal(unauthorized.status(),302));
    for(const relative of ['dcat/js/dcat-app.js','dcat/css/dcat-app.css']) {
      const response=await context.request.get(base+'/vendor/dcat-admin/'+relative);assert.equal(response.status(),200);assert.equal(response.headers()['x-fixture-asset-tree'],tree);
      const delivered=await response.body();const dist=path.join(__dirname,tree==='candidate'?'Compatibility/Laravel12/vendor/dcat-x/laravel-admin/resources/dist':'../vendor/dcat/laravel-admin/resources/dist');
      check('original_asset_bytes_'+relative,()=>assert.equal(hash(delivered),hash(fs.readFileSync(path.join(dist,relative)))));report.assetHashes[relative]=hash(delivered);
    }
    await Promise.all(pending);assert.deepEqual(report.errors,[]);assert.deepEqual(report.unexpectedHTTP,[]);assert.deepEqual(report.failedLocal,[]);
    assert.ok(Object.keys(report.assets).length>10);assert.equal(report.requests.filter(r=>r.path.startsWith('/admin/coupon')).length,3);
    for(const entry of report.requests.filter(r=>r.path.startsWith('/admin/coupon')))assert.equal(entry.status,200);
    check('three_real_form_posts_and_zero_local_errors',()=>assert.equal(report.requests.filter(r=>r.path.startsWith('/admin/coupon')).length,3));
    report.finalState=cleared;report.status='PASS'; await context.close();
  } catch(e) {report.status='FAIL';report.failure=e.stack;if(page){fs.writeFileSync(path.join(run,'failure-dom.html'),await page.content());await page.screenshot({path:path.join(run,'failure.png'),fullPage:false}).catch(()=>{});}throw e;}
  finally {await Promise.allSettled(pending);await browser.close();fs.writeFileSync(path.join(run,'browser-summary.json'),JSON.stringify(report,null,2));console.log(JSON.stringify({status:report.status,tree,checks:report.checks.length,pages:report.pages.length,assets:Object.keys(report.assets).length,run}));}
})().catch(error=>{console.error(error);process.exitCode=1;});
