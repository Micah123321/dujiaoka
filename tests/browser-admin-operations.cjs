const { chromium } = require('./.runtime/browser/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const base = 'http://127.0.0.1:8203';
const out = path.join(__dirname, '.runtime/admin56/browser');
fs.mkdirSync(out, { recursive: true });
let checks = 0;
const assert = (value, label) => { if (!value) throw new Error(label); checks++; };
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1050 } });
  const errors = [], requests = [], statuses = [];
  await context.route('**/*', route => route.request().url().startsWith(base + '/') ? route.continue() : route.abort());
  const page = await context.newPage();
  page.on('pageerror', e => errors.push(e.message));
  page.on('request', r => { if (r.url().includes('/operations/')) requests.push({ url: r.url(), method: r.method() }); });
  page.on('response', r => { if (r.status() >= 500) statuses.push({ url: r.url(), status: r.status() }); });
  const login = async (name) => {
    await page.goto(base + '/admin/auth/login');
    await page.locator('input[name=username]').fill(name);
    await page.locator('input[name=password]').fill('fixture-password');
    await page.locator('button[type=submit]').click();
    await page.waitForURL(url => !url.pathname.includes('/auth/login'));
  };
  const ready = async () => {
    await page.locator('.wb h1').waitFor();
    await page.locator('.wb-records[aria-busy="false"]').waitFor({ timeout: 20000 });
    assert(await page.locator('.wb-message-error').count() === 0, 'No actual API load error');
    assert(await page.locator('.wb').count() === 1, 'Exactly one Vue workbench mount');
    const width = await page.evaluate(() => ({ page: document.documentElement.scrollWidth, viewport: innerWidth }));
    assert(width.page <= width.viewport + 1, 'No page horizontal overflow');
    const boxes = await page.evaluate(() => { const content=document.querySelector('.wb-heading').getBoundingClientRect(); const sidebar=document.querySelector('.main-sidebar')?.getBoundingClientRect(); return {left:content.left,right:sidebar?.right||0,viewport:innerWidth}; });
    if(boxes.viewport>=992) assert(boxes.left>=boxes.right, 'Desktop sidebar does not cover Vue title or metrics');
    const top = await page.evaluate(() => ({heading:document.querySelector('.wb-heading').getBoundingClientRect().top + window.scrollY,bar:document.querySelector('.header-navbar')?.getBoundingClientRect().bottom||0}));
    assert(top.heading>=top.bar, 'Fixed navbar does not cover the workbench title '+JSON.stringify(top)+' '+page.url());
  };
  const visit = async (section) => { await page.goto(base + '/admin/operations/' + section); await ready(); };
  const api = async (section, method = 'GET', body, token = true) => {
    return page.evaluate(async ({section,method,body,token}) => {
      const cfg = JSON.parse(document.querySelector('#admin-workbench-config').textContent);
      const headers = {Accept:'application/json','X-Requested-With':'XMLHttpRequest'};
      if (method === 'POST') { headers['Content-Type']='application/json'; if(token)headers['X-CSRF-TOKEN']=cfg.csrf; }
      const response = await fetch('/admin/operations/'+section+(method==='POST'?'/action':'/data'), {method,headers,body:body?JSON.stringify(body):undefined});
      let data; try{data=await response.json();}catch{data={};}
      return {status:response.status,data};
    }, { section, method, body, token });
  };
  try {
    const unauth = await context.request.get(base + '/admin/operations/stripe/data', { headers: { Accept: 'application/json' } });
    assert(unauth.status() === 401, 'Unauthenticated operation API returns 401');
    await login('fixture-admin'); await ready();
    assert(await page.locator('.wb-navigation-links a').count() === 6, 'Six operation sections are linked from admin home');
    assert(await page.locator('.wb-metric').count() === 4, 'Live order metrics render');
    await page.screenshot({ path: path.join(out,'overview-desktop.png'), fullPage: true });
    for (const section of ['stripe','paypal','notifications','quotes','runtime']) {
      await visit(section);
      const response = await api(section);
      assert(response.status === 200, section + ' actual authenticated API');
      assert(response.data.module.installed === true, section + ' complete real migration tables');
      const dump = JSON.stringify(response.data);
      assert(!/FIXTURE-PRIVATE|reservation_token|account_fingerprint|client_secret|merchant_pem|payload|exception/.test(dump), section + ' excludes private payloads');
      await page.screenshot({ path:path.join(out,section+'-desktop.png'),fullPage:true });
    }
    await visit('quotes');
    assert(await page.locator('.wb-records tbody tr').count() === 20, 'Quote pagination first page contains 20 records');
    const next = page.getByRole('button',{name:'下一页',exact:true});
    await next.click(); await ready();
    assert(await page.locator('.wb-pagination').innerText().then(t=>t.includes('2')), 'Quote pagination actually selects second page');
    await page.locator('#wb-search-input').fill('OPS-000055');
    await page.getByRole('button',{name:'搜索',exact:true}).click(); await ready();
    assert(await page.locator('.wb-records tbody tr').count() === 1, 'Live search filters frozen quotes');
    assert(await page.locator('.wb-records tbody').innerText().then(t=>t.includes('OPS-000055')), 'Search displays exact matched order');
    await visit('notifications');
    await page.locator('#wb-state').selectOption('failed'); await ready();
    assert(await page.locator('.wb-records tbody tr').count() === 2, 'Failed filter excludes active lease');
    const notification = await api('notifications');
    const chosen = notification.data.rows.find(row=>row.id===1);
    assert(chosen.actions.some(action=>action.enabled), 'Failed notification has eligible manual action');
    const before = requests.filter(r=>r.method==='POST').length;
    await page.locator('.wb-records tbody tr').filter({has:page.getByRole('button',{name:/^重新排队/})}).first().getByRole('button',{name:/^重新排队/}).click();
    await page.getByRole('dialog').waitFor();
    await page.getByRole('button',{name:'确认重新排队',exact:true}).click();
    await page.getByRole('dialog').getByText('操作已执行。',{exact:true}).waitFor();
    assert(requests.filter(r=>r.method==='POST').length === before+1, 'One confirmed operation sends exactly one POST');
    const after = await api('notifications');
    assert(after.data.rows.filter(row=>row.status==='failed').length===1, 'Actual notification row resets to pending');
    await page.getByRole('button',{name:'返回明细',exact:true}).click();
    const leased = await api('notifications','POST',{action:'retry',id:3});
    assert(leased.status===409, 'Active notification lease rejects direct retry');
    const missingCsrf = await context.request.post(base+'/admin/operations/notifications/action', {data:{action:'retry',id:2},headers:{Accept:'application/json','Sec-Fetch-Site':'cross-site',Origin:'https://cross-origin.fixture.test'}});
    assert(missingCsrf.status()===419, 'Real HTTP cross-site POST requires CSRF');
    await visit('stripe');
    const retried = await api('stripe','POST',{action:'retry',id:1});
    assert(retried.status===200 && retried.data.result.retried===1, 'Stripe actual reconciliation failure can be queued again');
    assert((await api('stripe','POST',{action:'retry',id:1})).status===409, 'Repeated Stripe reset is idempotently rejected');
    await visit('paypal');
    assert((await api('paypal','POST',{action:'retry',id:1})).status===200, 'PayPal actual reconciliation failure can be queued again');
    await visit('runtime');
    const queueRetry = await api('runtime','POST',{action:'retry-job',id:1});
    assert(queueRetry.status===200 && queueRetry.data.result.retried===1, 'Native failed job retry uses real database queue');
    assert((await api('runtime','POST',{action:'retry-job',id:1})).status===404, 'Repeated failed job retry sees removed record');
    assert(fs.readFileSync(path.join(__dirname,'.runtime/admin-operations-root/provider-attempts.txt'),'utf8')==='', 'All six page GETs and reset actions perform zero provider IO');
    const due = await api('stripe','POST',{action:'reconcile'});
    assert(due.status===200 && fs.readFileSync(path.join(__dirname,'.runtime/admin-operations-root/provider-attempts.txt'),'utf8').includes('stripe'), 'Manual batch reconciliation reaches only synthetic provider');
    await page.setViewportSize({width:390,height:844});
    for(const section of ['overview','stripe','notifications','quotes','runtime']) {
      await visit(section);
      await page.getByRole('button',{name:/切换页面/}).click();
      assert(await page.locator('.wb-navigation-links').isVisible(), 'Mobile operation navigation expands');
      await page.getByRole('button',{name:/收起导航/}).click();
      await page.screenshot({path:path.join(out,section+'-mobile.png'),fullPage:true});
    }
    await page.setViewportSize({width:1440,height:1050}); await visit('overview');
    const firstRequest=requests.length;
    await page.locator('.wb-navigation-links a').filter({hasText:'通知投递'}).click();
    await page.waitForURL('**/operations/notifications'); await ready();
    await page.waitForFunction(() => JSON.parse(document.querySelector('#admin-workbench-config').textContent).section === 'notifications' && document.querySelector('.wb-records')?.getAttribute('aria-busy') === 'false');
    assert(requests.slice(firstRequest).filter(r=>r.method==='GET' && r.url.includes('/notifications/data')).length===1,'PJAX navigation mounts once and loads data once');
    await page.goto(base+'/admin/goods');
    assert(await page.locator('table').count()>0,'Original goods CRUD still renders');
    await page.goto(base+'/admin/order');
    assert(await page.locator('table').count()>0,'Original order CRUD still renders');
    await context.clearCookies(); await login('fixture-reader'); await ready();
    await visit('notifications');
    assert(await page.locator('.wb-readonly-note').count()===1,'Read-only account displays its access scope');
    assert(await page.locator('.wb-row-action:not(:disabled), .wb-bulk-bar button:not(:disabled)').count()===0,'Read-only account cannot launch business actions');
    assert((await api('notifications','POST',{action:'retry',id:2})).status===403,'Direct POST remains forbidden for read-only role');
    assert((await api('overview','POST',{action:'expire'})).status===403,'Overview mutation respects independent POST grant');
    await page.screenshot({path:path.join(out,'readonly-desktop.png'),fullPage:true});
    assert(errors.length===0,'No browser runtime errors');
    assert(statuses.length===0,'No actual HTTP 500 responses');
    fs.writeFileSync(path.join(out,'result.json'),JSON.stringify({status:'PASS',checks,errors,statuses,requests},null,2));
    console.log(JSON.stringify({status:'PASS',checks,screenshots:out}));
  } catch(error) {
    await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});
    fs.writeFileSync(path.join(out,'result-failed.json'),JSON.stringify({status:'FAIL',checks,error:String(error),errors,statuses,requests,url:page.url()},null,2));
    console.error(error.stack); process.exitCode=1;
  } finally { await browser.close(); }
})();
