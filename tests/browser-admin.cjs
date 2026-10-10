const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {chromium} = require('./.runtime/browser/node_modules/playwright');
const tree = process.argv.includes('--candidate') ? 'candidate' : 'root';
const base = 'http://127.0.0.1:8198';
const output = path.join(__dirname, '.runtime', 'admin-' + tree);
const report = {tree, startedAt: new Date().toISOString(), pages: [], metrics: [], actions: [], assets: {}, blockedExternal: [], errors: [], failedLocal: []};
if (fs.existsSync(path.join(output, 'browser-summary.json'))) fs.unlinkSync(path.join(output, 'browser-summary.json'));
const hash = value => crypto.createHash('sha256').update(value).digest('hex');
(async () => {
  const browser = await chromium.launch({channel: 'chrome', headless: true});
  report.browser = browser.version();
  try {
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
    const page = await context.newPage();
    page.setDefaultTimeout(12000);
    const bodies = [];
    page.on('pageerror', error => report.errors.push(error.message));
    page.on('requestfailed', request => {if (request.url().startsWith(base)) report.failedLocal.push(new URL(request.url()).pathname);});
    page.on('response', response => {
      const url = new URL(response.url());
      if (url.origin !== base) return;
      if (response.status() >= 400 && !url.pathname.endsWith('/auth/login')) report.errors.push('HTTP ' + response.status() + ' ' + url.pathname);
      if (url.pathname.startsWith('/vendor/')) {
        assert.equal(response.headers()['x-fixture-asset-tree'], tree, 'Loaded the wrong asset dependency tree');
        report.assets[url.pathname] = response.status();
      }
      if (url.pathname.endsWith('/operations/overview/data')) bodies.push(response.json().then(data => {
        assert.equal(response.status(), 200);
        if (!report.metrics.length) report.metrics.push(...data.cards);
      }));
    });
    await page.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.origin === base) return route.continue();
      report.blockedExternal.push(url.origin + url.pathname);
      return route.abort();
    });
    const visit = async route => {
      const response = await page.goto(base + route);
      assert.equal(response.status(), 200, route);
      await page.waitForLoadState('networkidle');
      await page.locator('.content-wrapper').waitFor();
      assert.match(await page.locator('body').textContent(), /Fixture Admin/, route);
      report.pages.push({route, status: response.status(), viewport: page.viewportSize().width});
    };
    const save = async (endpoint, confirmation = false) => {
      const waiting = page.waitForResponse(response => new URL(response.url()).pathname === endpoint && response.request().method() === 'POST');
      await page.getByRole('button', {name: '提交', exact: false}).click();
      waiting.catch(() => {});
      if (confirmation) await page.locator('.swal2-confirm').click();
      const response = await waiting;
      const data = await response.json();
      assert.equal(response.status(), 200);
      assert.equal(data.status, true, JSON.stringify(data));
      assert.equal(data.data.type, 'success', JSON.stringify(data));
      return data;
    };
    const unauthorized = await context.request.get(base + '/admin/goods', {maxRedirects: 0});
    assert.equal(unauthorized.status(), 302);
    assert.match(unauthorized.headers().location, /admin\/auth\/login$/);
    await page.goto(base + '/admin/auth/login');
    await page.waitForLoadState('networkidle');
    assert.equal(await page.evaluate(() => typeof Dcat.Form), 'function');
    await page.locator('[name=username]').fill('fixture-admin');
    await page.locator('[name=password]').fill('wrong-password');
    const badLogin = page.waitForResponse(response => response.url().endsWith('/auth/login') && response.request().method() === 'POST');
    await page.getByRole('button', {name: '登录', exact: false}).click();
    assert.equal((await badLogin).status(), 422);
    await page.getByText('账号或密码错误', {exact: false}).waitFor();
    await page.locator('[name=password]').fill('fixture-password');
    await page.getByRole('button', {name: '登录', exact: false}).click();
    await page.waitForURL(url => ['/admin', '/admin/goods'].includes(url.pathname));
    if (page.url() !== base + '/admin') await page.goto(base + '/admin');
    await page.waitForLoadState('networkidle');
    await Promise.all(bodies);
    assert.equal(report.metrics.length, 4);
    const values = Object.fromEntries(report.metrics.map(card => [card.key, Number(card.value)]));
    assert.equal(values.today_orders, 2);
    assert.equal(values.pending_orders, 1);
    assert.equal(values.abnormal_orders, 0);
    assert.equal(values.today_sales, 9);
    assert.equal(await page.locator('.wb-metric').count(), 4);
    assert.equal(await page.locator('.wb-trend svg').count(), 1);
    const csrf = await context.request.post(base + '/admin/goods-group', {form: {gp_name: 'CSRF MUST NOT SAVE'}});
    assert.equal(csrf.status(), 419);
    report.actions.push('unauthenticated redirect, wrong password rejection, real session login, missing CSRF rejected, four database-backed metrics');
    await page.screenshot({path: path.join(output, 'dashboard-desktop.png'), fullPage: false});
    // Hash the bytes delivered at the same URLs against the installed package, not published legacy files.
    const dist = path.join(__dirname, tree === 'candidate' ? 'Compatibility/Laravel12/vendor/dcat-x/laravel-admin/resources/dist' : '../vendor/dcat-x/laravel-admin/resources/dist');
    report.assetHashes = {};
    for (const relative of ['dcat/js/dcat-app.js', 'dcat/css/dcat-app.css']) {
      const response = await context.request.get(base + '/vendor/dcat-admin/' + relative);
      const served = await response.body();
      const expected = fs.readFileSync(path.join(dist, relative));
      assert.equal(hash(served), hash(expected));
      report.assetHashes[relative] = hash(served);
    }
    const resources = ['goods', 'goods-group', 'carmis', 'coupon', 'emailtpl', 'pay', 'order'];
    for (const resource of resources) {
      for (const suffix of ['', '/create', '/1/edit', '/1']) await visit('/admin/' + resource + suffix);
    }
    for (const route of ['/admin/import-carmis', '/admin/system-setting', '/admin/email-test', '/admin/auth/setting']) await visit(route);
    await visit('/admin/goods?gd_name=Fixture%20Auto');
    assert.equal(await page.locator('table tbody tr').filter({hasText: 'Fixture Auto Goods'}).count(), 1);
    assert.equal(await page.locator('table tbody tr').filter({hasText: 'Fixture Manual Goods'}).count(), 0);
    assert.doesNotMatch(await page.locator('table').innerText(), /T\d{2}:\d{2}:\d{2}[.]\d+Z/);
    report.actions.push('all seven resource list/create/edit/detail views, settings/import/mail/account views, goods filter, local date display');
    await visit('/admin/goods-group/create');
    await page.locator('[name=gp_name]').fill('Browser Created Group');
    await save('/admin/goods-group');
    await page.waitForURL(base + '/admin/goods-group');
    await page.waitForLoadState('networkidle');
    const created = page.locator('table tbody tr').filter({hasText: 'Browser Created Group'});
    assert.equal(await created.count(), 1);
    const editUrl = await created.locator('a[href$="/edit"]').getAttribute('href');
    const groupId = editUrl.match(/goods-group\/(\d+)\/edit$/)[1];
    await visit('/admin/goods-group/' + groupId + '/edit');
    await page.locator('[name=gp_name]').fill('Browser Updated Group');
    await save('/admin/goods-group/' + groupId);
    await page.waitForURL(base + '/admin/goods-group');
    await page.waitForLoadState('networkidle');
    assert.equal(await page.locator('table tbody tr').filter({hasText: 'Browser Updated Group'}).count(), 1);
    report.actions.push('category create/update persisted through actual authenticated AJAX and reloaded grids');
    await visit('/admin/goods');
    const goodsRow = page.locator('table tbody tr').filter({hasText: 'Fixture Auto Goods'});
    await goodsRow.locator('.grid-dropdown-actions [data-toggle=dropdown]').click();
    await goodsRow.locator('a[href$="/edit"]').click();
    await page.waitForURL(base + '/admin/goods/1/edit');
    await page.waitForLoadState('networkidle');
    await page.locator('[name=gd_name]').fill('Browser Updated Goods');
    await page.waitForFunction(() => typeof tinymce !== 'undefined' && tinymce.editors.length >= 2 && tinymce.editors.every(editor => editor.initialized));
    await save('/admin/goods/1');
    await page.waitForURL(base + '/admin/goods');
    await page.waitForLoadState('networkidle');
    assert.equal(await page.locator('table tbody tr').filter({hasText: 'Browser Updated Goods'}).count(), 1);
    report.actions.push('goods update with real editor initialization and persisted fields');
    await visit('/admin/import-carmis');
    await page.locator('select[name=goods_id]').selectOption('1');
    await page.locator('[name=carmis_list]').fill('BROWSER-LF-A\nBROWSER-LF-B\nBROWSER-LF-A\n0\n');
    const toggle = page.locator('input[type=checkbox][name=remove_duplication]');
    await toggle.locator('..').locator('.switchery').click();
    assert.equal(await toggle.isChecked(), true);
    await save('/admin/dcat-api/form', true);
    await page.waitForURL(base + '/admin/carmis');
    await page.waitForLoadState('networkidle');
    for (const value of ['BROWSER-LF-A', 'BROWSER-LF-B']) assert.equal(await page.locator('table tbody tr').filter({hasText: value}).count(), 1);
    assert.equal(await page.locator('table tbody tr').count(), 4);
    report.actions.push('LF text import, duplicate removal, string0, null file path, real widget confirmation and prefixed redirect');
    await visit('/admin/system-setting');
    await page.locator('[name=title]').fill('Browser Saved Store');
    await save('/admin/dcat-api/form', true);
    await visit('/admin/system-setting');
    assert.equal(await page.locator('[name=title]').inputValue(), 'Browser Saved Store');
    report.actions.push('system setting persisted in isolated file cache through actual widget confirmation and reload');
    await page.setViewportSize({width: 390, height: 844});
    for (const route of ['/admin', '/admin/goods', '/admin/import-carmis', '/admin/system-setting']) {
      await visit(route);
      const bounds = await page.locator('.content-wrapper').boundingBox();
      assert.ok(bounds && bounds.width <= 391 && bounds.x >= -1, 'Mobile content shell bounds ' + route);
      if (route === '/admin') {
        const links = await page.locator('.wb-navigation-links a').evaluateAll(es => es.map(e => ({width: e.getBoundingClientRect().width, parent: e.parentElement.getBoundingClientRect().width})));
        assert.equal(links.length, 6);
        await page.locator('.wb-mobile-nav').click();
        assert.equal(await page.locator('.wb-navigation-links').isVisible(), true);
        await page.locator('.wb-mobile-nav').click();
        await page.screenshot({path: path.join(output, 'dashboard-mobile.png'), fullPage: true});
        await page.locator('[data-widget=pushmenu]').click();
        await page.locator('body.sidebar-open').waitFor();
        await page.locator('.main-sidebar a[href$="/admin/goods"]').click();
        await page.waitForURL(base + '/admin/goods');
        await page.waitForLoadState('networkidle');
      }
    }
    await page.goto(base + '/admin/auth/logout');
    await page.waitForURL('**/admin/auth/login');
    const afterLogout = await context.request.get(base + '/admin/goods', {maxRedirects: 0});
    assert.equal(afterLogout.status(), 302);
    report.actions.push('mobile content layout and real logout session invalidation');
    await Promise.all(bodies);
    assert.deepEqual(report.failedLocal, []);
    assert.deepEqual(report.errors, []);
    assert.ok(Object.keys(report.assets).length > 35);
    assert.ok(Object.values(report.assets).every(status => status === 200));
    report.status = 'passed';
    report.metrics = report.metrics.length;
    report.blockedExternal = [...new Set(report.blockedExternal)];
    if (fs.existsSync(path.join(output, 'browser-failure.json'))) fs.unlinkSync(path.join(output, 'browser-failure.json'));
    fs.writeFileSync(path.join(output, 'browser-summary.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify({tree, status: report.status, views: report.pages.length, metrics: report.metrics, assets: Object.keys(report.assets).length, actions: report.actions}));
  } catch (error) {
    report.status = 'failed';
    report.failure = error.message;
    fs.writeFileSync(path.join(output, 'browser-failure.json'), JSON.stringify(report, null, 2));
    throw error;
  } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode = 1;});
