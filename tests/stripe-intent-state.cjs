const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');
const source = fs.readFileSync(path.join(__dirname, '../public/assets/common/stripe-intent.js'), 'utf8');
const intent = {currency: 'usd', status: 'requires_payment_method', client_secret: 'pi_memory_secret_fixture'};
const waiting = {status: 'requires_payment_method', order_status: 1};
const flush = async () => { for (let i = 0; i < 30; i++) await Promise.resolve(); };

function fixture(options = {}) {
  let now = 0, timerId = 0;
  const timers = new Map(), nodes = new Map(), requests = [], redirects = [];
  const calls = {begins: 0, statuses: 0, confirms: 0, aborts: 0};
  const node = id => {
    if (!nodes.has(id)) {
      const handlers = {};
      nodes.set(id, {disabled: false, hidden: false, textContent: '', value: '',
        addEventListener(name, handler) { handlers[name] = handler; },
        fire(name) { return handlers[name]?.({preventDefault() {}}); }});
    }
    return nodes.get(id);
  };
  node('payment-config').textContent = JSON.stringify({currency: 'usd', orderid: 'MEMORY', csrf: 'fixture-csrf',
    beginUrl: '/intent', statusUrl: '/status', publishableKey: 'pk_test_fixture', returnUrl: '/return'});
  node('status-button').hidden = true;
  node('payment-currency').options = [{text: 'USD 10.00'}, {text: 'CNY 70.00'}];
  Object.defineProperty(node('payment-currency'), 'selectedIndex', {get() { return this.value === 'usd' ? 0 : 1; }});
  const document = {hidden: false, getElementById: node, addEventListener(name, fn) { this[name] = fn; }};
  const window = {location: {assign(url) { redirects.push(url); }}, addEventListener(name, fn) { this[name] = fn; }};
  const fetch = async (url, init) => {
    const body = JSON.parse(init.body);
    assert.equal(init.method, 'POST');
    assert.equal(init.credentials, 'same-origin');
    assert.equal(init.cache, 'no-store');
    assert.equal(init.headers['X-CSRF-TOKEN'], 'fixture-csrf');
    assert.ok(init.signal instanceof AbortSignal);
    assert.doesNotMatch(JSON.stringify({body, headers: init.headers}), /secret_fixture/);
    requests.push({url, body, signal: init.signal});
    if (url === '/status') assert.deepEqual(body, {orderid: 'MEMORY'});
    const index = url === '/intent' ? ++calls.begins : ++calls.statuses;
    const result = await (url === '/intent' ? options.begin?.(index, init, body) ?? intent
      : options.status?.(index, init) ?? waiting);
    if (result instanceof Error) throw result;
    if (result === 'hang' || result === 'ignore-abort') {
      return new Promise((resolve, reject) => init.signal.addEventListener('abort', () => {
        calls.aborts++;
        if (result === 'hang') reject(new DOMException('Aborted', 'AbortError'));
      }, {once: true}));
    }
    return {ok: true, json: () => result === 'hang-body' ? new Promise(() => {}) : Promise.resolve(result)};
  };
  vm.runInNewContext(source, {document, window, fetch, AbortController,
    Stripe(key) {
      assert.equal(key, 'pk_test_fixture');
      return {elements(settings) {
        assert.equal(settings.clientSecret, intent.client_secret);
        return {create() { return {mount() {}}; }};
      }, async confirmPayment(settings) {
        calls.confirms++;
        assert.equal(settings.redirect, 'if_required');
        assert.equal(settings.confirmParams.return_url, '/return');
        return options.confirm?.() ?? {paymentIntent: {status: 'processing'}};
      }};
    },
    setTimeout(fn, delay) { const id = ++timerId; timers.set(id, {fn, due: now + delay}); return id; },
    clearTimeout(id) { timers.delete(id); }}, {filename: 'stripe-intent.js'});
  const advance = async ms => {
    const end = now + ms;
    await flush();
    for (;;) {
      const next = [...timers].filter(([, value]) => value.due <= end).sort((a, b) => a[1].due - b[1].due)[0];
      if (!next) break;
      now = next[1].due;
      timers.delete(next[0]);
      next[1].fn();
      await flush();
    }
    now = end;
    await flush();
  };
  return {node, calls, requests, redirects, timers, document, window, advance,
    submit: () => node('payment-form').fire('submit'),
    async query() { node('status-button').fire('click'); await flush(); }};
}

for (const [name, state, text] of [
  ['expired', {status: 'requires_payment_method', order_status: -1}, /订单已过期/],
  ['paid', {status: 'requires_payment_method', order_status: 4, order_url: '/order'}, /付款已完成/],
  ['canceled', {status: 'canceled', order_status: 1}, /付款已取消/]
]) {
  test(name + ' before submit: latest status blocks confirmation and stays terminal', async () => {
    const f = fixture({status: () => state});
    await f.submit();
    assert.equal(f.calls.statuses, 0);
    await f.submit();
    assert.equal(f.calls.statuses, 1);
    assert.equal(f.calls.confirms, 0);
    assert.match(f.node('message').textContent, text);
    assert.equal(f.node('pay-button').disabled, true);
    assert.equal(f.node('payment-currency').disabled, true);
    assert.equal(f.node('status-button').disabled, true);
    await f.submit();
    await f.query();
    f.document.visibilitychange();
    await f.advance(400000);
    assert.equal(f.calls.begins, 1);
    assert.equal(f.calls.statuses, 1);
    assert.equal(f.calls.confirms, 0);
    assert.equal(f.timers.size, 0);
    assert.deepEqual(f.redirects, name === 'paid' ? ['/order'] : []);
  });
}

test('first status network failure automatically polls again and recovers', async () => {
  const f = fixture({status: n => n === 1 ? new Error('fixture network error') : waiting});
  await f.submit();
  await f.query();
  assert.match(f.node('message').textContent, /fixture network error/);
  assert.equal(f.node('status-button').disabled, false);
  await f.advance(3999);
  assert.equal(f.calls.statuses, 1);
  await f.advance(1);
  assert.equal(f.calls.statuses, 2);
  assert.match(f.node('message').textContent, /暂未确认付款/);
  assert.equal(f.timers.size, 1);
});

test('failed confirmation preflight never confirms and resumes polling', async () => {
  const f = fixture({status: n => n === 1 ? new Error('offline') : waiting});
  await f.submit();
  await f.submit();
  assert.equal(f.calls.confirms, 0);
  assert.equal(f.node('pay-button').disabled, false);
  await f.advance(4000);
  assert.equal(f.calls.statuses, 2);
});

for (const pending of ['hang', 'ignore-abort', 'hang-body']) {
  test(pending + ' begin times out, clears busy, retains currency and retries the same intent', async () => {
    const f = fixture({begin: n => n === 1 ? pending : intent});
    const pendingSubmit = f.submit();
    await flush();
    assert.equal(f.node('pay-button').disabled, true);
    assert.equal(f.node('payment-currency').disabled, true);
    await f.advance(9999);
    assert.equal(f.node('pay-button').disabled, true);
    await f.advance(1);
    await pendingSubmit;
    assert.match(f.node('message').textContent, /请求超时/);
    assert.equal(f.requests[0].signal.aborted, true);
    assert.equal(f.node('pay-button').disabled, false);
    assert.equal(f.node('payment-currency').disabled, true);
    assert.equal(f.node('payment-currency').value, 'usd');
    assert.equal(f.calls.begins, 1);
    assert.equal(f.calls.confirms, 0);
    f.node('payment-currency').value = 'cny';
    await f.submit();
    assert.equal(f.calls.begins, 2);
    assert.deepEqual(f.requests.filter(r => r.url === '/intent').map(r => r.body.currency), ['usd', 'usd']);
    assert.equal(f.calls.confirms, 0);
  });
}

test('hung status request aborts, clears busy and automatically recovers', async () => {
  const f = fixture({status: n => n === 1 ? 'hang' : waiting});
  await f.submit();
  await f.query();
  assert.equal(f.node('pay-button').disabled, true);
  await f.advance(10000);
  assert.equal(f.calls.aborts, 1);
  assert.equal(f.node('pay-button').disabled, false);
  assert.equal(f.node('status-button').disabled, false);
  assert.equal(f.node('payment-currency').disabled, true);
  await f.advance(4000);
  assert.equal(f.calls.statuses, 2);
});

test('repeated failures back off and stop after 24 automatic status attempts', async () => {
  const f = fixture({status: () => new Error('offline')});
  await f.submit();
  await f.advance(500000);
  assert.equal(f.calls.statuses, 24);
  assert.equal(f.timers.size, 0);
  assert.equal(f.calls.begins, 1);
  assert.equal(f.calls.confirms, 0);
});

test('canceled begin never mounts or confirms and finally keeps controls locked', async () => {
  const f = fixture({begin: () => ({...intent, status: 'canceled'})});
  await f.submit();
  await f.submit();
  await f.advance(500000);
  assert.equal(f.calls.begins, 1);
  assert.equal(f.calls.statuses, 0);
  assert.equal(f.calls.confirms, 0);
  assert.equal(f.node('pay-button').disabled, true);
  assert.equal(f.node('payment-currency').disabled, true);
});

for (const beginStatus of ['processing', 'succeeded']) {
  for (const [name, state] of [
    ['expired', {status: 'requires_payment_method', order_status: -1}],
    ['canceled', {status: 'canceled', order_status: 1}],
    ['paid', {status: 'succeeded', order_status: 4, order_url: '/order'}]
  ]) {
    test('existingreturnRedirectProcessing: ' + beginStatus + ' begin resolves to ' + name, async () => {
      const f = fixture({begin: () => ({...intent, status: beginStatus}), status: () => state});
      await f.submit();
      assert.equal(f.calls.statuses, 1);
      assert.equal(f.calls.confirms, 0);
      assert.equal(f.node('pay-button').disabled, true);
      assert.equal(f.node('payment-currency').disabled, true);
      await f.submit();
      await f.advance(500000);
      assert.equal(f.calls.begins, 1);
      assert.equal(f.calls.statuses, 1);
      assert.equal(f.timers.size, 0);
    });
  }
}

test('processing return keeps polling to paid without any confirmation', async () => {
  const f = fixture({begin: () => ({...intent, status: 'processing'}),
    status: n => n === 1 ? {status: 'processing', order_status: 1} : {status: 'succeeded', order_status: 4, order_url: '/order'}});
  await f.submit();
  assert.equal(f.node('pay-button').hidden, true);
  await f.advance(3000);
  assert.deepEqual(f.redirects, ['/order']);
  assert.equal(f.calls.confirms, 0);
  assert.equal(f.timers.size, 0);
});

test('confirmation reuses mounted elements and status requests contain only the order id', async () => {
  const f = fixture({status: n => n === 1 ? waiting : {status: 'succeeded', order_status: 4, order_url: '/order'}});
  await f.submit();
  await f.submit();
  assert.equal(f.calls.begins, 1);
  assert.equal(f.calls.statuses, 2);
  assert.equal(f.calls.confirms, 1);
  assert.deepEqual(f.redirects, ['/order']);
  assert.equal(f.timers.size, 0);
});
