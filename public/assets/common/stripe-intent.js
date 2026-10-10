(() => {
  'use strict';
  const config = JSON.parse(document.getElementById('payment-config').textContent);
  const form = document.getElementById('payment-form');
  const choice = document.getElementById('payment-currency');
  const button = document.getElementById('pay-button');
  const statusButton = document.getElementById('status-button');
  const message = document.getElementById('message');
  let stripe, elements, timer, terminal, busy = false, polls = 0, failures = 0;
  let lockedCurrency = choice.disabled ? config.currency : undefined;
  choice.value = config.currency;
  const say = text => { message.textContent = text; };
  const controls = () => {
    button.disabled = statusButton.disabled = busy || !!terminal;
    choice.disabled = busy || !!terminal || !!lockedCurrency;
  };
  const post = async (url, data = {}) => {
    const controller = new AbortController();
    let timeout;
    const deadline = new Promise((resolve, reject) => {
      timeout = setTimeout(() => {
        reject(new Error('请求超时，请稍后查询付款状态或重试。'));
        controller.abort();
      }, 10000);
    });
    try {
      return await Promise.race([deadline, (async () => {
        const response = await fetch(url, {method: 'POST', credentials: 'same-origin', cache: 'no-store',
          signal: controller.signal,
          headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': config.csrf},
          body: JSON.stringify({orderid: config.orderid, ...data})});
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || '付款暂不可用，请稍后重试。');
        return result;
      })()]);
    } finally { clearTimeout(timeout); }
  };
  const cancelPoll = () => { clearTimeout(timer); timer = undefined; };
  const schedule = () => {
    cancelPoll();
    if (!terminal && !statusButton.hidden && polls < 24 && !document.hidden) {
      const delay = failures ? 2000 * 2 ** Math.min(failures, 3) : 2000 + polls * 1000;
      timer = setTimeout(check, Math.min(15000, delay));
    }
  };
  const applyState = state => {
    if (terminal) return;
    const orderStatus = Number(state.order_status);
    if (orderStatus === -1) terminal = 'expired';
    // Fulfillment states are no longer payable, even if the intent data is stale.
    else if (state.status === 'succeeded' || [2, 3, 4, 5, 6].includes(orderStatus)) terminal = 'paid';
    else if (state.status === 'canceled') terminal = 'canceled';
    if (terminal) {
      cancelPoll();
      button.hidden = statusButton.hidden = true;
      controls();
      say(terminal === 'expired' ? '订单已过期，请返回订单详情重新下单。'
        : terminal === 'canceled' ? '付款已取消，请返回订单详情重新下单。' : '付款已完成，正在返回订单详情。');
      if (terminal === 'paid' && state.order_url) window.location.assign(state.order_url);
      return;
    }
    button.hidden = state.status === 'processing';
    say(state.status === 'processing' ? '付款正在处理中，请稍候。' : '暂未确认付款，可继续付款或稍后查询。');
  };
  const readState = async () => {
    polls++;
    try {
      const state = await post(config.statusUrl);
      failures = 0;
      applyState(state);
      return state;
    } catch (error) { failures++; throw error; }
  };
  const check = async () => {
    cancelPoll();
    if (terminal) return;
    if (busy || document.hidden) { schedule(); return; }
    busy = true;
    controls();
    try { await readState(); }
    catch (error) { say(error.message); }
    finally { busy = false; controls(); schedule(); }
  };
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || terminal) return;
    cancelPoll();
    busy = true;
    // A lost begin response may already have bound the order on the server.
    lockedCurrency = lockedCurrency || choice.value;
    choice.value = lockedCurrency;
    controls();
    statusButton.hidden = false;
    say('正在连接付款服务…');
    try {
      if (!elements) {
        const intent = await post(config.beginUrl, {currency: lockedCurrency});
        choice.value = lockedCurrency = intent.currency;
        if (intent.status === 'canceled' || Number(intent.order_status) === -1) { applyState(intent); return; }
        if (intent.status === 'succeeded' || intent.status === 'processing') {
          button.hidden = true;
          await readState();
          return;
        }
        stripe = Stripe(config.publishableKey);
        elements = stripe.elements({clientSecret: intent.client_secret, appearance: {theme: 'stripe'}});
        elements.create('payment', {layout: 'tabs'}).mount('#payment-element');
        document.getElementById('currency-picker').hidden = true;
        const selected = document.getElementById('selected-method');
        selected.textContent = choice.options[choice.selectedIndex].text;
        selected.hidden = false;
        button.textContent = '确认付款';
        say('请核对金额并填写付款信息。');
      } else {
        const state = await readState();
        if (terminal || state.status === 'processing') return;
        const result = await stripe.confirmPayment({elements, confirmParams: {return_url: config.returnUrl}, redirect: 'if_required'});
        if (result.error) { say(result.error.message); return; }
        await readState();
      }
    } catch (error) { say(error.message || '加载付款失败，请重试。'); }
    finally { busy = false; controls(); schedule(); }
  });
  statusButton.addEventListener('click', () => { if (!terminal) { polls = 0; check(); } });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) cancelPoll();
    else schedule();
  });
  window.addEventListener('pagehide', cancelPoll);
})();
