'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('./.runtime/browser/node_modules/playwright');
const args = process.argv.slice(2);
const candidate = args.includes('--candidate');
const value = (name, fallback) => { const at = args.indexOf(name); return at < 0 ? fallback : args[at + 1]; };
const tree = candidate ? 'candidate' : 'root';
const php = path.resolve(value('--php', candidate ? 'tests/.runtime/php85/php.exe' : 'tests/.runtime/php74/php.exe'));
const ini = path.resolve(value('--php-ini', candidate ? 'tests/.runtime/php85-fixture.ini' : 'tests/.runtime/php74/php.ini'));
const runtime = path.resolve('tests/.runtime/admin-status-mail-' + tree);
const base = 'http://127.0.0.1:8200';
const report = {tree, pages: [], actions: [], errors: [], unexpectedHTTP: [], assets: {}, blockedExternal: [], states: [], console: []};
function cli(action) {
  const argv = ['-c', ini, path.resolve('tests/admin-fixture.php'), '--status-mail', action];
  if (candidate) argv.push('--candidate');
  const data = JSON.parse(execFileSync(php, argv, {cwd: path.resolve('.'), encoding: 'utf8', timeout: 30000}));
  if (action !== '--inspect-isolated-admin') {
    assert.equal(data.exit, 0);
    report.console.push({command: data.command, exit: data.exit, php: data.state.php, framework: data.state.framework});
    return data.state;
  }
  return data;
}
function state(label) {
  const data = cli('--inspect-isolated-admin');
  assert.equal(data.enabled, true);
  assert.ok(data.php.startsWith(candidate ? '8.5.' : '7.4.'));
  assert.ok(data.framework.startsWith(candidate ? '13.' : '6.'));
  assert.ok(path.resolve(data.database).startsWith(runtime + path.sep));
  assert.ok(data.outbox.every(row => !row.privateDataInCiphertext));
  assert.ok(data.jobs.every(row => !row.privateDataInPayload));
  report.states.push({label, ...data});
  return data;
}
const business = data => JSON.stringify({orders: data.orders, outbox: data.outbox, jobs: data.jobs, mail: data.mail});
(async () => {
  const browser = await chromium.launch({channel: 'chrome', headless: true, timeout: 15000});
  const contexts = [];
  try {
    const initial = state('initial');
    assert.equal(initial.outbox.length, 0); assert.equal(initial.jobs.length, 0); assert.equal(initial.mail.length, 0);
    assert.equal(Number(initial.orders.find(row => Number(row.id) === 2).status), 3);
    const actor = async name => {
      const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
      contexts.push(context);
      await context.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.origin !== base && !['data:', 'blob:'].includes(url.protocol)) {
          report.blockedExternal.push(url.origin + url.pathname); return route.abort();
        }
        return route.continue();
      });
      const page = await context.newPage();
      page.on('pageerror', error => report.errors.push(error.message));
      page.on('response', response => {
        const url = new URL(response.url());
        if (url.origin !== base) return;
        if (url.pathname.startsWith('/vendor/')) report.assets[url.pathname] = response.status();
        if (response.status() >= 400 && response.status() !== 403 && response.status() !== 419) report.unexpectedHTTP.push({url: url.pathname, status: response.status()});
      });
      const visit = async route => {
        const response = await page.goto(base + route, {waitUntil: 'networkidle'});
        assert.equal(response.status(), 200, route);
        assert.equal(response.headers()['x-fixture-tree'], tree);
        assert.ok(response.headers()['x-fixture-php'].startsWith(candidate ? '8.5.' : '7.4.'));
        await page.waitForFunction(() => window.Dcat && Dcat.token);
        report.pages.push({actor: name, route, status: response.status()});
      };
      await visit('/admin/auth/login');
      await page.locator('[name=username]').fill('fixture-' + name);
      await page.locator('[name=password]').fill('fixture-password');
      const login = page.waitForResponse(response => new URL(response.url()).pathname === '/admin/auth/login' && response.request().method() === 'POST');
      await page.locator('button[type=submit]').click();
      assert.equal((await login).status(), 200);
      await page.waitForURL(url => url.pathname === '/admin' || url.pathname === '/admin/order');
      return {page, context, visit};
    };
    const reader = await actor('status-reader');
    await reader.visit('/admin/order/2/edit');
    const readerToken = await reader.page.evaluate(() => Dcat.token);
    let before = state('before-reader');
    let denied = await reader.context.request.put(base + '/admin/order/2', {form: {_token: readerToken, status: '4', info: 'DENIED-STATUS-INFO'}, headers: {'X-Requested-With': 'XMLHttpRequest'}});
    assert.equal(denied.status(), 403);
    assert.equal(business(before), business(state('after-reader')));
    report.actions.push({action: 'reader-state-write-denied', status: denied.status(), unchanged: true});
    const editor = await actor('status-editor');
    const save = async (status, info) => {
      await editor.visit('/admin/order/2/edit');
      await editor.page.locator('textarea[name=info]').fill(info);
      await editor.page.locator('input[name=status][value="' + status + '"]').check({force: true});
      const saved = editor.page.waitForResponse(response => new URL(response.url()).pathname === '/admin/order/2' && ['PUT', 'POST'].includes(response.request().method()));
      const [response] = await Promise.all([saved, editor.page.getByRole('button', {name: '提交', exact: false}).click()]);
      const data = await response.json();
      assert.equal(response.status(), 200); assert.equal(data.status, true, JSON.stringify(data));
      await editor.page.waitForURL(url => url.pathname === '/admin/order');
      report.actions.push({action: 'real-edit-form-save', status});
    };
    await save(4, 'STATUS-INFO-A');
    const first = state('completed-A');
    assert.equal(first.outbox.length, 1); assert.equal(first.mail.length, 0); assert.equal(first.jobs.length, 0);
    assert.ok(first.outbox[0].event_key); assert.ok(first.outbox[0].payloadBytes > 0);
    await save(4, 'STATUS-INFO-B-EDIT-ONLY');
    const same = state('same-status-info-edit');
    assert.equal(same.outbox.length, 1); assert.equal(same.outbox[0].payloadHash, first.outbox[0].payloadHash);
    report.actions.push({action: 'same-status-does-not-create-mail', count: same.outbox.length});
    const inline = async status => {
      await editor.visit('/admin/order');
      const select = editor.page.locator('select.grid-column-select[data-name=status][data-url$="/order/2"]');
      assert.equal(await select.count(), 1);
      const changed = editor.page.waitForResponse(response => new URL(response.url()).pathname === '/admin/order/2' && ['PUT', 'POST'].includes(response.request().method()));
      await select.selectOption(String(status));
      const response = await changed; const data = await response.json();
      assert.equal(response.status(), 200); assert.equal(data.status, true, JSON.stringify(data));
      report.actions.push({action: 'real-grid-inline-status', status});
    };
    for (const status of [5, 4, 2]) await inline(status);
    const intents = state('four-distinct-transitions');
    assert.equal(intents.outbox.length, 4); assert.equal(new Set(intents.outbox.map(row => row.event_key)).size, 4);
    assert.ok(intents.outbox.every(row => row.channel === 'order-status-mail' && row.payloadHash && Number(row.attempts) === 0));
    assert.equal(intents.mail.length, 0); assert.equal(intents.jobs.length, 0);
    assert.equal(Number(intents.orders.find(row => Number(row.id) === 2).status), 2);
    const token = await editor.page.evaluate(() => Dcat.token);
    before = state('before-missing-CSRF');
    denied = await editor.context.request.put(base + '/admin/order/2', {form: {status: '5'}, headers: {'X-Requested-With': 'XMLHttpRequest'}});
    assert.equal(denied.status(), 419); assert.equal(business(before), business(state('after-missing-CSRF')));
    denied = await editor.context.request.delete(base + '/admin/order/2', {form: {_token: token}, headers: {'X-Requested-With': 'XMLHttpRequest'}});
    assert.equal(denied.status(), 403); assert.equal(business(before), business(state('after-editor-delete-denied')));
    report.actions.push({action: 'missing-CSRF-and-delete-denied', statuses: [419, 403], unchanged: true});
    fs.writeFileSync(path.join(runtime, 'fail-next-mail'), 'isolated-array-failure\n');
    const published = cli('--publish-status-mail');
    assert.equal(published.jobs.length, 4); assert.equal(published.mail.length, 0);
    assert.ok(published.jobs.every(row => !row.privateDataInPayload));
    cli('--work-status-mail');
    const failed = state('native-first-delivery-failed');
    assert.equal(Number(failed.outbox[0].attempts), 1); assert.equal(failed.outbox[0].delivered_at, null);
    assert.equal(failed.outbox[0].last_error, 'Delivery failed: RuntimeException');
    assert.ok(failed.outbox[0].payloadHash); assert.equal(failed.mail.length, 0); assert.equal(failed.jobs.length, 3);
    for (let index = 0; index < 3; index++) cli('--work-status-mail');
    const beforeRetry = state('three-native-mails-accepted');
    assert.equal(beforeRetry.mail.length, 3); assert.equal(beforeRetry.jobs.length, 0);
    assert.equal(cli('--publish-status-mail').jobs.length, 0);
    cli('--advance-status-mail');
    report.simulatedRetryClock = 'Only this marked fixture advances available_at; no wall-clock sleep.';
    assert.equal(cli('--publish-status-mail').jobs.length, 1);
    cli('--work-status-mail');
    const final = state('final');
    assert.equal(final.mail.length, 4); assert.equal(final.jobs.length, 0); assert.equal(final.failedJobs, 0);
    assert.deepEqual(final.outbox.map(row => Number(row.attempts)), [2, 1, 1, 1]);
    assert.ok(final.outbox.every(row => row.delivered_at && !row.failed_at && row.payloadHash === null && row.last_error === null));
    assert.equal(final.worker.length, 10); assert.ok(final.worker.every(row => row.transactionLevel === 0 && row.connection === 'database'));
    assert.ok(final.mail.every(mail => mail.transactionLevel === 0 && mail.to.includes('status-buyer@example.test')));
    const titles = final.mail.map(mail => mail.subject).sort();
    assert.deepEqual(titles, ['Completed FIXTURE-ADMIN-ORDER-2', 'Completed FIXTURE-ADMIN-ORDER-2', 'Failed FIXTURE-ADMIN-ORDER-2', 'Pending FIXTURE-ADMIN-ORDER-2']);
    const frozen = final.mail.find(mail => mail.body.includes('STATUS-INFO-A'));
    assert.ok(frozen); assert.ok(!frozen.body.includes('STATUS-INFO-B-EDIT-ONLY'));
    assert.equal(final.outbox[0].event_key, first.outbox[0].event_key);
    report.actions.push({action: 'native-database-worker-retry-and-snapshot', attempts: [2, 1, 1, 1], mails: 4, processes: report.console.filter(row => row.command === 'work').length});
    await editor.visit('/admin/order');
    await editor.page.screenshot({path: path.join(runtime, 'manual-status-grid-37.png'), fullPage: true});
    assert.deepEqual(report.errors, []); assert.deepEqual(report.unexpectedHTTP, []);
    assert.ok(Object.keys(report.assets).length > 20); assert.ok(Object.values(report.assets).every(status => status === 200));
    report.status = 'passed'; report.final = final;
    fs.writeFileSync(path.join(runtime, 'browser-summary-37.json'), JSON.stringify(report, null, 2) + '\n');
    console.log(JSON.stringify({tree, status: report.status, pages: report.pages.length, actions: report.actions.length, assets: Object.keys(report.assets).length, intents: 4, mails: 4, nativeWorkerProcesses: 5, errors: report.errors.length}));
  } catch (error) {
    report.status = 'failed'; report.failure = error.stack;
    fs.writeFileSync(path.join(runtime, 'browser-failure-37.json'), JSON.stringify(report, null, 2) + '\n');
    throw error;
  } finally {
    for (const context of contexts) await context.close();
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
