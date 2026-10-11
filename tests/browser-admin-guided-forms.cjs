const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./.runtime/browser/node_modules/playwright');
const base = 'http://127.0.0.1:8202';
const out = path.join(__dirname, '.runtime/forms57/browser');
fs.mkdirSync(out, { recursive: true });
const report = { checks: [], errors: [], external: [], screenshots: [] };
const check = (value, label) => { assert.ok(value, label); report.checks.push(label); };
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await context.newPage();
  page.setDefaultTimeout(12000);
  page.on('pageerror', error => report.errors.push(error.message));
  await page.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.origin === base || url.protocol === 'data:') return route.continue();
    report.external.push(url.origin); return route.abort();
  });
  async function visit(route) {
    const response = await page.goto(base + '/admin/' + route);
    check(response.status() === 200, route + ' renders');
    await page.waitForLoadState('networkidle');
  }
  async function tab(text) { await page.locator('form .nav-tabs .nav-link').filter({ hasText: text }).click(); }
  async function select(name, value) {
    await page.locator('select[name=' + name + ']').evaluate((el, value) => {
      el.value = value; window.jQuery(el).trigger('change'); el.dispatchEvent(new Event('change', { bubbles: true }));
    }, value);
  }
  async function save(endpoint) {
    const waiting = page.waitForResponse(r => new URL(r.url()).pathname === endpoint && r.request().method() === 'POST');
    await page.getByRole('button', { name: '提交', exact: false }).click();
    const response = await waiting; const data = await response.json();
    check(response.status() === 200 && data.status === true, 'Native save persists: ' + endpoint);
    await page.waitForLoadState('networkidle');
    return data;
  }
  async function screenshot(name) {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForFunction(() => document.getAnimations().every(animation => animation.playState !== 'running'));
    await page.screenshot({ path: path.join(out, name + '.png'), fullPage: true });
    report.screenshots.push(name);
    const geometry = await page.evaluate(() => {
      const main = document.querySelector('.content-wrapper').getBoundingClientRect();
      const sidebar = document.querySelector('.main-sidebar').getBoundingClientRect();
      const header = document.querySelector('.header-navbar').getBoundingClientRect();
      return { left: main.left, width: main.width, viewport: window.innerWidth, scroll: document.documentElement.scrollWidth, sidebarRight: sidebar.right, headerLeft: header.left };
    });
    check(geometry.left >= -1 && geometry.width <= geometry.viewport + 1 && geometry.scroll <= geometry.viewport + 1, name + ' fits viewport');
    if (geometry.viewport < 992) check(geometry.sidebarRight <= 1 && geometry.headerLeft <= 1, name + ' leaves the form unobstructed by the closed sidebar');
  }
  try {
    await page.goto(base + '/admin/auth/login');
    await page.locator('[name=username]').fill('fixture-admin');
    await page.locator('[name=password]').fill('fixture-password');
    await page.getByRole('button', { name: '登录', exact: false }).click();
    await page.waitForURL('**/admin');
    await visit('pay/create');
    check(await page.locator('select[name=pay_handleroute] option').count() >= 14, 'All 13 actual payment services are selectable');
    await select('pay_handleroute', 'pay/payjs');
    check(await page.locator('select[name=pay_check]').inputValue() === 'payjswescan', 'Provider selection fills the matching payment identifier');
    check(await page.locator('input[type=radio][name=pay_method][value="2"]').isChecked(), 'Scan mode is suggested');
    await page.locator('[name=pay_name]').fill('Browser Guided Payjs');
    await screenshot('payment-service-desktop');
    await tab('填写账户');
    check(await page.locator('form').innerText().then(t => t.includes('通讯密钥')), 'Credential labels describe the selected provider');
    await page.locator('[name=merchant_id]').fill('100001');
    await page.locator('[name=merchant_pem]').fill('SYNTHETIC-GUIDED-SECRET');
    await screenshot('payment-account-desktop');
    await tab('保存与启用');
    await save('/admin/pay');
    await visit('pay');
    check(!(await page.locator('body').innerText()).includes('SYNTHETIC-GUIDED-SECRET'), 'Payment list conceals stored keys');
    const row = page.locator('table tbody tr').filter({ hasText: 'Browser Guided Payjs' });
    check(await row.count() === 1, 'New draft appears in the actual payment list');
    const href = await row.locator('a[href$="/edit"]').getAttribute('href');
    await page.goto(new URL(href, base).href); await page.waitForLoadState('networkidle');
    check(await page.locator('select[name=pay_handleroute]').inputValue() === 'pay/payjs', 'Edit restores provider');
    check(await page.locator('select[name=pay_check]').inputValue() === 'payjswescan', 'Edit restores identifier without replacement');
    await tab('填写账户');
    check(await page.locator('[name=merchant_pem]').inputValue() === 'SYNTHETIC-GUIDED-SECRET', 'Editing preserves the complete stored synthetic key');
    await page.setViewportSize({ width: 390, height: 844 });
    await screenshot('payment-account-mobile');
    await page.setViewportSize({ width: 1440, height: 1000 });
    await visit('goods/create');
    await page.locator('[name=gd_name]').fill('Browser Guided Goods');
    await select('group_id', '1');
    await page.locator('[name=actual_price]').fill('10.00');
    await screenshot('goods-basic-desktop');
    await tab('发货与库存');
    check(!(await page.locator('[name=in_stock]').isVisible()), 'Automatic delivery hides manual stock');
    await page.locator('input[name=type][value="2"]').check();
    check(await page.locator('[name=in_stock]').isVisible(), 'Manual delivery reveals stock');
    await page.locator('[name=in_stock]').fill('20');
    await screenshot('goods-delivery-desktop');
    await tab('优惠与买家资料');
    check(await page.locator('[data-admin-form-guide]').count() === 1, 'Exactly one configuration editor mounts');
    await page.locator('[data-fg-add=wholesale]').click();
    await page.locator('[data-fg-field=quantity]').fill('5');
    await page.locator('[data-fg-field=price]').fill('8.00');
    check(await page.locator('[name=wholesale_price_cnf]').inputValue() === '5=8.00', 'Price row writes the real native configuration field');
    await page.locator('[data-fg-add=buyer]').click();
    await page.locator('[data-fg-field=label]').fill('收货账号');
    await page.locator('[data-fg-field=required]').check();
    check(/^buyer_field_[0-9]+=收货账号=true=$/.test(await page.locator('[name=other_ipu_cnf]').inputValue()), 'Display name alone creates a valid required buyer field');
    await page.locator('[data-fg-field=price]').fill('20');
    check(await page.locator('[name=wholesale_price_cnf]').inputValue() === '5=8.00', 'Invalid price draft retains the most recent valid source');
    let saves = 0; const observe = request => { if (new URL(request.url()).pathname === '/admin/goods' && request.method() === 'POST') saves++; };
    page.on('request', observe);
    await page.getByRole('button', { name: '提交', exact: false }).click();
    check(saves === 0 && await page.locator('.fg-error').count() > 0, 'Invalid row draft blocks actual Dcat submission');
    page.off('request', observe);
    await page.locator('[data-fg-field=price]').fill('8.00');
    await screenshot('goods-configuration-desktop');
    await page.setViewportSize({ width: 390, height: 844 });
    await screenshot('goods-configuration-mobile');
    await page.setViewportSize({ width: 1440, height: 1000 });
    await save('/admin/goods');
    await visit('goods');
    const product = page.locator('table tbody tr').filter({ hasText: 'Browser Guided Goods' });
    check(await product.count() === 1, 'Minimal product saves without manually entering SEO fields');
    const edit = await product.locator('a[href$="/edit"]').getAttribute('href');
    await page.goto(new URL(edit, base).href); await page.waitForLoadState('networkidle');
    await tab('优惠与买家资料');
    check(await page.locator('[data-fg-field=quantity]').inputValue() === '5' && await page.locator('[data-fg-field=price]').inputValue() === '8.00', 'Saved price rows reload without replacing configuration');
    check(await page.locator('[data-fg-field=label]').inputValue() === '收货账号' && await page.locator('[data-fg-field=required]').isChecked(), 'Saved generated buyer field reloads correctly');
    await tab('更多设置');
    check(await page.locator('[name=gd_description]').inputValue() === 'Browser Guided Goods', 'Search summary automatically receives the product name');
    for (const route of ['goods-group/create','carmis/create','coupon/create','emailtpl/create','order/1/edit','system-setting','import-carmis','email-test']) {
      await visit(route);
      check(await page.locator('.af-intro').count() >= 1, route + ' has an explicit filling guide');
    }
    check(report.errors.length === 0, 'No browser runtime errors');
    check(report.external.length === 0, 'All tested forms stay within the isolated fixture');
    fs.writeFileSync(path.join(out, 'result.json'), JSON.stringify({ ...report, status: 'PASS' }, null, 2));
    console.log(JSON.stringify({ status: 'PASS', checks: report.checks.length, screenshots: out }));
  } catch (error) {
    await page.screenshot({ path: path.join(out, 'failed.png'), fullPage: true }).catch(() => {});
    fs.writeFileSync(path.join(out, 'failed.json'), JSON.stringify({ ...report, message: error.message, url: page.url() }, null, 2));
    throw error;
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
