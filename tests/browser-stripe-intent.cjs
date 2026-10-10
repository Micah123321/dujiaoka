const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./.runtime/browser/node_modules/playwright');
const html = fs.readFileSync(path.join(__dirname, '.runtime/stripe-intent-checkout.html'), 'utf8');
const script = fs.readFileSync(path.join(__dirname, '../public/assets/common/stripe-intent.js'), 'utf8');
function fakeStripe(key) {
  window.stripeCalls = {key, confirms: 0};
  return {
    elements(options) {
      window.stripeCalls.secret = options.clientSecret;
      return {create(type) { return {mount(selector) { document.querySelector(selector).textContent = '付款组件替身'; }}; }};
    },
    async confirmPayment(options) {
      window.stripeCalls.confirms++;
      window.stripeCalls.redirect = options.redirect;
      window.stripeCalls.returnUrl = options.confirmParams.return_url;
      return {paymentIntent: {id: 'pi_browser', status: 'succeeded'}};
    }
  };
}
(async () => {
  const browser = await chromium.launch({channel: 'chrome', headless: true});
  try {
    for (const [width, currency] of [[1440, 'usd'], [390, 'cny']]) {
      const page = await browser.newPage({viewport: {width, height: 900}});
      const errors = [], posts = [];
      let releaseBegin, statusReads = 0;
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', async route => {
        const request = route.request(), url = new URL(request.url());
        if (url.hostname === 'js.stripe.com') return route.fulfill({contentType: 'text/javascript', body: 'window.Stripe = ' + fakeStripe.toString()});
        if (url.pathname === '/assets/common/stripe-intent.js') return route.fulfill({contentType: 'text/javascript', body: script});
        if (url.pathname === '/pay/stripe/card/INTENTROUTE') return route.fulfill({contentType: 'text/html', body: html});
        if (url.pathname === '/detail-order-sn/INTENTROUTE') return route.fulfill({contentType: 'text/html', body: '<h1>Fixture order detail</h1>'});
        if (url.pathname === '/pay/stripe/intent' || url.pathname === '/pay/stripe/intent/status') {
          assert.equal(request.method(), 'POST');
          assert.equal(request.headers()['x-csrf-token'], 'fixture-csrf');
          const data = request.postDataJSON();
          assert.equal(data.orderid, 'INTENTROUTE');
          assert.equal(data.payment_intent, undefined);
          assert.equal(data.amount, undefined);
          posts.push(data);
          if (url.pathname.endsWith('/status')) {
            statusReads++;
            return route.fulfill({json: {status: statusReads === 1 ? 'requires_payment_method' : statusReads === 2 ? 'processing' : 'succeeded',
              order_status: statusReads < 3 ? 1 : 4, order_url: '/detail-order-sn/INTENTROUTE'}});
          }
          await new Promise(resolve => { releaseBegin = resolve; });
          return route.fulfill({json: {intent_id: 'pi_browser', client_secret: 'pi_browser_secret_fixture', status: 'requires_payment_method',
            currency: data.currency, amount: data.currency === 'usd' ? '1000' : '7000'}});
        }
        return route.abort();
      });
      await page.goto('http://localhost/pay/stripe/card/INTENTROUTE');
      await page.locator('#payment-currency').selectOption(currency);
      await page.getByRole('button', {name: '继续付款', exact: true}).click();
      await page.waitForFunction(() => document.getElementById('payment-currency').disabled);
      while (!releaseBegin) await new Promise(resolve => setTimeout(resolve, 10));
      assert.equal(posts[0].currency, currency);
      releaseBegin();
      await page.getByRole('button', {name: '确认付款', exact: true}).waitFor();
      assert.match(await page.locator('#selected-method').textContent(), currency === 'usd' ? /USD 10.00/ : /70.00/);
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      await page.screenshot({path: path.join(__dirname, '.runtime/stripe-intent-' + (width === 390 ? 'mobile' : 'desktop') + '.png'), fullPage: true});
      await page.getByRole('button', {name: '确认付款', exact: true}).click();
      await page.getByText('付款正在处理中，请稍候。').waitFor();
      assert.match(page.url(), /\/pay\/stripe\/card\/INTENTROUTE$/);
      const calls = await page.evaluate(() => window.stripeCalls);
      assert.equal(calls.confirms, 1);
      assert.equal(calls.redirect, 'if_required');
      assert.match(calls.returnUrl, /intent-return\?orderid=INTENTROUTE$/);
      assert.equal(calls.key, 'pk_test_fixture');
      await page.getByRole('button', {name: '我已付款，查询状态'}).click();
      await page.getByRole('heading', {name: 'Fixture order detail'}).waitFor();
      assert.deepEqual(errors, []);
      await page.close();
    }
    const page = await browser.newPage();
    const errors = [], currencies = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(() => {
      const nativeTimeout = window.setTimeout.bind(window), nativeFetch = window.fetch.bind(window);
      // Accelerate only the request deadline; keep native fetch and AbortSignal.
      window.setTimeout = (fn, delay, ...args) => nativeTimeout(fn, delay === 10000 ? 250 : delay, ...args);
      window.fetchAborts = 0;
      window.fetch = (url, options) => nativeFetch(url, options).catch(error => {
        if (error.name === 'AbortError' && options.signal.aborted) window.fetchAborts++;
        throw error;
      });
    });
    await page.route('**/*', async route => {
      const request = route.request(), url = new URL(request.url());
      if (url.hostname === 'js.stripe.com') return route.fulfill({contentType: 'text/javascript', body: 'window.Stripe = ' + fakeStripe.toString()});
      if (url.pathname === '/assets/common/stripe-intent.js') return route.fulfill({contentType: 'text/javascript', body: script});
      if (url.pathname === '/pay/stripe/card/INTENTROUTE') return route.fulfill({contentType: 'text/html', body: html});
      if (url.pathname === '/pay/stripe/intent') {
        assert.equal(request.method(), 'POST');
        assert.equal(request.headers()['x-csrf-token'], 'fixture-csrf');
        const data = request.postDataJSON();
        currencies.push(data.currency);
        // Leave the first real browser fetch waiting for headers until it aborts.
        if (currencies.length === 1) return;
        return route.fulfill({json: {intent_id: 'pi_browser', client_secret: 'pi_browser_secret_fixture',
          status: 'requires_payment_method', currency: data.currency, amount: '1000'}});
      }
      if (url.pathname === '/pay/stripe/intent/status') {
        assert.deepEqual(request.postDataJSON(), {orderid: 'INTENTROUTE'});
        return route.fulfill({json: {status: 'requires_payment_method', order_status: 1}});
      }
      return route.abort();
    });
    await page.goto('http://localhost/pay/stripe/card/INTENTROUTE');
    await page.getByRole('button', {name: '继续付款', exact: true}).click();
    await page.getByText('请求超时，请稍后查询付款状态或重试。', {exact: true}).waitFor();
    await page.waitForFunction(() => window.fetchAborts === 1);
    assert.equal(await page.locator('#pay-button').isDisabled(), false);
    assert.equal(await page.locator('#payment-currency').isDisabled(), true);
    assert.equal(await page.locator('#payment-currency').inputValue(), 'usd');
    assert.deepEqual(currencies, ['usd']);
    await page.evaluate(() => { document.getElementById('payment-currency').value = 'cny'; });
    await page.getByRole('button', {name: '继续付款', exact: true}).click();
    await page.getByRole('button', {name: '确认付款', exact: true}).waitFor();
    assert.deepEqual(currencies, ['usd', 'usd']);
    assert.equal(await page.evaluate(() => window.stripeCalls.confirms), 0);
    assert.deepEqual(errors, []);
    await page.close();
    console.log('PASS: 3 browser scenarios: actual Blade desktop/mobile, CSRF POST, confirmation/completion, native fetch abort and same-currency retry');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
