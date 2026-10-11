import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { createFormGuideBridge, parseFormGuideConfig, type FormGuideBridge, type Profile } from './form-guide';

const bridges: FormGuideBridge[] = [];
function profile(label: string, checks: Profile['checks']): Profile {
  return {
    label, note: label + '用于收款', notify: '/pay/fixture/notify_url', checks,
    fields: {
      merchant_id: { label: label + '商户号', help: '填写渠道提供的商户编号。', placeholder: '商户编号', required: true },
      merchant_key: { label: label + '密钥', help: '用于确认支付结果。', placeholder: '渠道密钥', required: true },
      merchant_pem: { label: label + '证书', help: '用于签名核验。', placeholder: '渠道证书', required: false }
    }
  };
}
const catalog = {
  alpha: profile('渠道甲', [{ value: 'a-card', label: '银行卡', method: 1, client: 1 }, { value: 'a-qr', label: '扫码', method: 2, client: 3 }]),
  beta: profile('渠道乙', [{ value: 'b-bank', label: '银行支付', method: 2, client: 3 }])
};
function createForm(kind: 'payment' | 'goods', editing = true) {
  const form = document.createElement('form');
  if (kind === 'payment') {
    form.innerHTML = '<select name="pay_handleroute"><option value="alpha">渠道甲</option><option value="beta">渠道乙</option><option value="legacy-route">历史渠道</option></select>' +
      '<select name="pay_check"><option value="a-card">银行卡</option><option value="a-qr">扫码</option><option value="b-bank">银行支付</option><option value="legacy-check">旧标识</option></select>' +
      '<input name="pay_name" value="已有名称"><input type="radio" name="pay_method" value="1"><input type="radio" name="pay_method" value="2"><input type="radio" name="pay_method" value="4" checked>' +
      '<select name="pay_client"><option value="1">电脑</option><option value="3">通用</option><option value="7" selected>历史设备</option></select>' +
      '<div class="form-group"><label for="fixture-merchant-id" class="control-label">商户编号</label><input id="fixture-merchant-id" name="merchant_id" value="fixture-id"></div>' +
      '<div class="form-group"><label for="fixture-merchant-key">商户密钥</label><input id="fixture-merchant-key" name="merchant_key" value="fixture-key" placeholder="原有提示"></div>' +
      '<div class="form-group"><label for="fixture-merchant-pem">商户证书</label><textarea id="fixture-merchant-pem" name="merchant_pem">fixture-pem</textarea></div>';
  } else {
    form.innerHTML = '<input type="radio" name="type" value="1" checked><input type="radio" name="type" value="2">' +
      '<div class="form-group" data-stock-group><label>可售库存</label><input type="number" name="in_stock" value="17"></div>' +
      '<div class="form-group"><label>批发价格原文</label><textarea name="wholesale_price_cnf"></textarea></div>' +
      '<div class="form-group"><label>买家信息原文</label><textarea name="other_ipu_cnf"></textarea></div>';
  }
  const root = document.createElement('div');
  root.setAttribute('data-admin-form-guide', '');
  const script = document.createElement('script');
  script.type = 'application/json';
  script.setAttribute('data-form-guide-config', '');
  script.textContent = JSON.stringify({ kind, editing, ...(kind === 'payment' ? { catalog } : {}) });
  const save = document.createElement('button'); save.type = 'submit'; save.textContent = '保存';
  form.prepend(root); form.append(script, save); document.body.append(form);
  return { form, root, save };
}
function mountGuide() {
  const bridge = createFormGuideBridge(); bridges.push(bridge); bridge.refresh(); return bridge;
}
function native<T extends HTMLElement = HTMLInputElement>(form: HTMLFormElement, name: string) {
  return form.querySelector<T>('[name="' + name + '"]')!;
}
async function edit(element: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement, value: string, event = 'input') {
  element.value = value; element.dispatchEvent(new Event(event, { bubbles: true })); await nextTick();
}
async function click(root: Element, selector: string) {
  const button = root.querySelector<HTMLButtonElement>(selector)!;
  button.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true })); await nextTick();
}
function submit(form: HTMLFormElement) {
  const event = new Event('submit', { bubbles: true, cancelable: true }); form.dispatchEvent(event); return event;
}
function rawTextarea(element: HTMLTextAreaElement, raw: string) {
  const setter = vi.fn((next: string) => { raw = next; });
  Object.defineProperty(element, 'value', { configurable: true, get: () => raw, set: setter });
  return setter;
}
function jqueryFixture() {
  const handlers = new Map<Element, Map<string, (event: Event) => void>>();
  const trigger = vi.fn((element: Element, name: string) => {
    for (const [bound, handler] of handlers.get(element) || []) {
      if (bound.split('.')[0] === name.split('.')[0] && (!name.includes('.') || bound === name)) handler(new Event(name.split('.')[0], { cancelable: true }));
    }
  });
  const jquery = (element: Element) => ({
    on: (events: string, handler: (event: Event) => void) => {
      const bound = handlers.get(element) || new Map();
      events.split(' ').forEach(event => bound.set(event, handler)); handlers.set(element, bound);
    },
    off: (events: string) => { events.split(' ').forEach(event => handlers.get(element)?.delete(event)); },
    trigger: (event: string) => trigger(element, event)
  });
  vi.stubGlobal('jQuery', jquery);
  return { jquery, handlers, trigger };
}

afterEach(() => { bridges.splice(0).forEach(bridge => bridge.dispose()); document.body.replaceChildren(); vi.unstubAllGlobals(); vi.restoreAllMocks(); });

describe('原生支付填写指导', () => {
  it('初始化不改变任何提交值，也不读取或重写商户凭据', async () => {
    const { form, root } = createForm('payment');
    native<HTMLSelectElement>(form, 'pay_check').value = 'legacy-check';
    const before = Array.from(new FormData(form).entries());
    const reads = ['merchant_id', 'merchant_key', 'merchant_pem'].map(name => vi.spyOn(native<HTMLInputElement | HTMLTextAreaElement>(form, name), 'value', 'get'));
    const writes = ['merchant_id', 'merchant_key', 'merchant_pem'].map(name => vi.spyOn(native<HTMLInputElement | HTMLTextAreaElement>(form, name), 'value', 'set'));
    const changes = vi.fn(); form.addEventListener('change', changes);
    const bridge = createFormGuideBridge(); bridges.push(bridge);
    expect(root.children).toHaveLength(0); bridge.refresh(); await nextTick();
    reads.forEach(spy => expect(spy).not.toHaveBeenCalled()); writes.forEach(spy => expect(spy).not.toHaveBeenCalled());
    expect(Array.from(new FormData(form).entries())).toEqual(before);
    expect(changes).not.toHaveBeenCalled();
    expect(Array.from(native<HTMLSelectElement>(form, 'pay_check').options).map(option => option.value)).toEqual(['legacy-check', 'a-card', 'a-qr']);
    expect(root.textContent).toContain('原有支付标识');
    expect(form.querySelector('label[for=fixture-merchant-key]')?.textContent).toBe('渠道甲密钥（必填）');
    expect(native(form, 'merchant_pem').getAttribute('aria-required')).toBe('false');
    expect(root.textContent).toContain('/pay/fixture/notify_url');
  });

  it('只有切换渠道或支付方式才映射原生方式和设备，名称仅在空值时补齐', async () => {
    const { form, root } = createForm('payment');
    native(form, 'pay_name').value = '';
    const getter = vi.spyOn(native(form, 'merchant_key'), 'value', 'get');
    const setter = vi.spyOn(native(form, 'merchant_key'), 'value', 'set');
    mountGuide(); await nextTick();
    expect(form.querySelector<HTMLInputElement>('[name=pay_method]:checked')?.value).toBe('4');
    expect(native<HTMLSelectElement>(form, 'pay_client').value).toBe('7');
    await edit(native<HTMLSelectElement>(form, 'pay_handleroute'), 'beta', 'change');
    expect(native<HTMLSelectElement>(form, 'pay_check').value).toBe('b-bank');
    expect(form.querySelector<HTMLInputElement>('[name=pay_method]:checked')?.value).toBe('2');
    expect(native<HTMLSelectElement>(form, 'pay_client').value).toBe('3');
    expect(native(form, 'pay_name').value).toBe('渠道乙');
    expect(root.textContent).toContain('原有商户凭据仍保留');
    await edit(native<HTMLSelectElement>(form, 'pay_handleroute'), 'alpha', 'change');
    expect(native(form, 'pay_name').value).toBe('渠道乙');
    expect(native<HTMLSelectElement>(form, 'pay_check').value).toBe('a-card');
    expect(form.querySelector<HTMLInputElement>('[name=pay_method]:checked')?.value).toBe('1');
    await edit(native<HTMLSelectElement>(form, 'pay_check'), 'a-qr', 'change');
    expect(form.querySelector<HTMLInputElement>('[name=pay_method]:checked')?.value).toBe('2');
    expect(native<HTMLSelectElement>(form, 'pay_client').value).toBe('3');
    expect(getter).not.toHaveBeenCalled(); expect(setter).not.toHaveBeenCalled();
  });

  it('未知渠道保留标识和原有字段说明，初始化不强选支付方式', async () => {
    const { form, root } = createForm('payment');
    native<HTMLSelectElement>(form, 'pay_handleroute').value = 'legacy-route';
    native<HTMLSelectElement>(form, 'pay_check').value = 'legacy-check';
    mountGuide(); await nextTick();
    expect(native<HTMLSelectElement>(form, 'pay_check').value).toBe('legacy-check');
    expect(native(form, 'merchant_key').placeholder).toBe('原有提示');
    expect(root.textContent).toContain('当前渠道暂无填写说明');
    expect(form.querySelectorAll('.fg-native-help')).toHaveLength(3);
  });

  it('接收 jQuery 的选择事件，重复 change 不重置名称或重复挂载，卸载清理事件', async () => {
    const { jquery, handlers } = jqueryFixture();
    const { form, root } = createForm('payment');
    const bridge = mountGuide(); await nextTick();
    const route = native<HTMLSelectElement>(form, 'pay_handleroute');
    native(form, 'pay_name').value = '';
    route.value = 'beta'; jquery(route).trigger('change'); await nextTick();
    expect(native<HTMLSelectElement>(form, 'pay_check').value).toBe('b-bank');
    native(form, 'pay_name').value = '自定义名称'; jquery(route).trigger('select2:select'); bridge.refresh(); await nextTick();
    expect(native(form, 'pay_name').value).toBe('自定义名称');
    expect(root.querySelectorAll('.fg')).toHaveLength(1);
    bridge.dispose();
    expect(handlers.get(route)?.size).toBe(0);
    expect(native(form, 'merchant_key').placeholder).toBe('原有提示');
    expect(form.querySelector('label[for=fixture-merchant-key]')?.textContent).toBe('商户密钥');
    expect(form.querySelectorAll('.fg-native-help')).toHaveLength(0);
  });
});

describe('商品配置行与原生提交', () => {
  it('初始化逐字保留原文与换行，不发出 input/change，手动区域使用同一原生节点', async () => {
    const { form, root } = createForm('goods');
    const wholesale = native<HTMLTextAreaElement>(form, 'wholesale_price_cnf');
    const buyer = native<HTMLTextAreaElement>(form, 'other_ipu_cnf');
    const wholesaleRaw = '2=9.50\r\n10=8.00\r\n';
    const buyerRaw = 'email=联系邮箱=true=\r\n\r\n';
    const writes = [rawTextarea(wholesale, wholesaleRaw), rawTextarea(buyer, buyerRaw)];
    const events = vi.fn(); form.addEventListener('input', events); form.addEventListener('change', events);
    mountGuide(); await nextTick();
    expect(wholesale.value).toBe(wholesaleRaw); expect(buyer.value).toBe(buyerRaw);
    writes.forEach(spy => expect(spy).not.toHaveBeenCalled()); expect(events).not.toHaveBeenCalled();
    expect(root.querySelector('[data-fg-table=wholesale] tbody')?.children).toHaveLength(2);
    expect(root.querySelector('details textarea[name=other_ipu_cnf]')).toBe(buyer);
    expect(submit(form).defaultPrevented).toBe(false);
  });

  it('未知历史配置完整保留，禁用结构化覆盖；手动修正后可恢复行编辑', async () => {
    const { form, root } = createForm('goods');
    const wholesale = native<HTMLTextAreaElement>(form, 'wholesale_price_cnf');
    const buyer = native<HTMLTextAreaElement>(form, 'other_ipu_cnf');
    const historical = '旧格式:2:9.50\n10=8.00\n';
    wholesale.value = historical; buyer.value = 'email=邮箱=true';
    const writer = vi.spyOn(wholesale, 'value', 'set');
    mountGuide(); await nextTick();
    expect(wholesale.value).toBe(historical); expect(writer).not.toHaveBeenCalled();
    expect(root.textContent).toContain('未识别的历史批发配置'); expect(root.textContent).toContain('未识别的历史买家配置');
    expect(root.querySelector('[data-fg-add=wholesale]')).toBeNull(); expect(root.querySelector('[data-fg-add=buyer]')).toBeNull();
    expect(root.querySelector('details')?.open).toBe(true); expect(submit(form).defaultPrevented).toBe(false);
    await edit(buyer, 'contact_email=邮箱=true=');
    expect(root.querySelector('[data-fg-add=buyer]')).not.toBeNull();
    expect(buyer.value).toBe('contact_email=邮箱=true='); expect(wholesale.value).toBe(historical);
  });

  it('添加、修改、删除批发行生成正确文本，错误草稿拦截原生与按钮提交', async () => {
    const { form, root, save } = createForm('goods');
    const source = native<HTMLTextAreaElement>(form, 'wholesale_price_cnf'); source.value = '2=9.50\n';
    mountGuide(); await nextTick();
    await click(root, '[data-fg-add=wholesale]');
    expect(source.value).toBe('2=9.50\n'); expect(submit(form).defaultPrevented).toBe(true);
    const saveEvent = new MouseEvent('click', { bubbles: true, cancelable: true }); save.dispatchEvent(saveEvent); await nextTick();
    expect(saveEvent.defaultPrevented).toBe(true); expect(root.textContent).toContain('旧配置尚未更新');
    const added = root.querySelectorAll('[data-fg-table=wholesale] tbody tr')[1];
    await edit(added.querySelector<HTMLInputElement>('[data-fg-field=quantity]')!, '5');
    expect(source.value).toBe('2=9.50\n');
    await edit(added.querySelector<HTMLInputElement>('[data-fg-field=price]')!, '8.25');
    expect(source.value).toBe('2=9.50\n5=8.25'); expect(submit(form).defaultPrevented).toBe(false);
    expect(new FormData(form).get('wholesale_price_cnf')).toBe('2=9.50\n5=8.25');
    await click(added, 'button'); expect(source.value).toBe('2=9.50');
    await click(root, '[data-fg-table=wholesale] tbody button'); expect(source.value).toBe('');
  });

  it('生成买家配置，允许空占位并正确保存必填开关和删除结果', async () => {
    const { form, root } = createForm('goods');
    const source = native<HTMLTextAreaElement>(form, 'other_ipu_cnf');
    mountGuide(); await nextTick(); await click(root, '[data-fg-add=buyer]');
    const row = root.querySelector('[data-fg-table=buyer] tbody tr')!;
    await edit(row.querySelector<HTMLInputElement>('[data-fg-field=key]')!, 'account');
    expect(source.value).toBe('');
    await edit(row.querySelector<HTMLInputElement>('[data-fg-field=label]')!, '接收账号');
    expect(source.value).toBe('account=接收账号=false=');
    const required = row.querySelector<HTMLInputElement>('[data-fg-field=required]')!;
    required.checked = true; required.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
    expect(source.value).toBe('account=接收账号=true=');
    await edit(row.querySelector<HTMLInputElement>('[data-fg-field=placeholder]')!, '请填写账号');
    expect(new FormData(form).get('other_ipu_cnf')).toBe('account=接收账号=true=请填写账号');
    expect(Array.from(new FormData(form).keys()).sort()).toEqual(['in_stock', 'other_ipu_cnf', 'type', 'wholesale_price_cnf']);
    await click(row, 'button'); expect(source.value).toBe('');
  });

  it('重复或非英文字段标识、等号、重复数量及坏价格不会覆盖最近有效配置', async () => {
    const { form, root } = createForm('goods');
    const buyer = native<HTMLTextAreaElement>(form, 'other_ipu_cnf'); buyer.value = 'contact_email=邮箱=true=\nphone=手机=false=';
    const wholesale = native<HTMLTextAreaElement>(form, 'wholesale_price_cnf'); wholesale.value = '2=9\n5=8';
    mountGuide(); await nextTick();
    const buyerRows = root.querySelectorAll('[data-fg-table=buyer] tbody tr');
    await edit(buyerRows[1].querySelector<HTMLInputElement>('[data-fg-field=key]')!, 'contact_email');
    expect(buyer.value).toBe('contact_email=邮箱=true=\nphone=手机=false='); expect(root.textContent).toContain('字段标识重复');
    expect(submit(form).defaultPrevented).toBe(true);
    await edit(buyerRows[1].querySelector<HTMLInputElement>('[data-fg-field=key]')!, '手机号');
    expect(root.textContent).toContain('英文字母');
    await edit(buyerRows[1].querySelector<HTMLInputElement>('[data-fg-field=key]')!, 'phone');
    await edit(buyerRows[1].querySelector<HTMLInputElement>('[data-fg-field=placeholder]')!, '含=号');
    expect(root.textContent).toContain('等号或换行'); expect(buyer.value).toBe('contact_email=邮箱=true=\nphone=手机=false=');
    await edit(buyerRows[1].querySelector<HTMLInputElement>('[data-fg-field=placeholder]')!, '');
    const wholesaleRows = root.querySelectorAll('[data-fg-table=wholesale] tbody tr');
    await edit(wholesaleRows[1].querySelector<HTMLInputElement>('[data-fg-field=quantity]')!, '2');
    expect(root.textContent).toContain('起购数量重复'); expect(wholesale.value).toBe('2=9\n5=8');
    await edit(wholesaleRows[1].querySelector<HTMLInputElement>('[data-fg-field=quantity]')!, '5');
    await edit(wholesaleRows[1].querySelector<HTMLInputElement>('[data-fg-field=price]')!, '8=2');
    expect(wholesale.value).toBe('2=9\n5=8'); expect(submit(form).defaultPrevented).toBe(true);
  });

  it('只修改售价也会拦截失效的旧优惠档位，修正优惠后可以提交', async () => {
    const { form, root } = createForm('goods');
    const price = document.createElement('input'); price.name = 'actual_price'; price.value = '10'; form.append(price);
    const source = native<HTMLTextAreaElement>(form, 'wholesale_price_cnf'); source.value = '5=8'; mountGuide(); await nextTick();
    await edit(price, '6'); expect(submit(form).defaultPrevented).toBe(true); expect(source.value).toBe('5=8');
    await edit(root.querySelector<HTMLInputElement>('[data-fg-field=price]')!, '5');
    expect(submit(form).defaultPrevented).toBe(false); expect(source.value).toBe('5=5');
  });

  it('只填显示名称即可生成唯一内部标识，不必输入英文参数', async () => {
    const { form, root } = createForm('goods'); const source = native<HTMLTextAreaElement>(form, 'other_ipu_cnf'); mountGuide(); await nextTick();
    await click(root, '[data-fg-add=buyer]');
    await edit(root.querySelector<HTMLInputElement>('[data-fg-field=label]')!, '收货账号');
    expect(source.value).toMatch(/^buyer_field_\d+=收货账号=false=$/);
    expect(submit(form).defaultPrevented).toBe(false);
  });

  it('识别旧三段格式且仅真实编辑才增加占位字段，系统保留标识会拦截提交', async () => {
    const { form, root } = createForm('goods');
    const source = native<HTMLTextAreaElement>(form, 'other_ipu_cnf'); source.value = 'account=账号=true';
    const setter = vi.spyOn(source, 'value', 'set'); mountGuide(); await nextTick();
    expect(root.querySelector('[data-fg-field=key]')).not.toBeNull(); expect(setter).not.toHaveBeenCalled();
    await edit(root.querySelector<HTMLInputElement>('[data-fg-field=key]')!, 'email');
    expect(root.textContent).toContain('系统字段重复'); expect(submit(form).defaultPrevented).toBe(true);
    expect(source.value).toBe('account=账号=true');
  });

  it('优惠价超过售价或精度错误时保留有效配置，修正后继续保存', async () => {
    const { form, root } = createForm('goods');
    const price = document.createElement('input'); price.name = 'actual_price'; price.value = '10'; form.append(price);
    const source = native<HTMLTextAreaElement>(form, 'wholesale_price_cnf'); source.value = '5=8'; mountGuide(); await nextTick();
    const row = root.querySelector<HTMLInputElement>('[data-fg-field=price]')!;
    await edit(row, '12'); expect(submit(form).defaultPrevented).toBe(true); expect(source.value).toBe('5=8');
    await edit(row, '8.123'); expect(root.textContent).toContain('两位小数');
    await edit(row, '7.50'); expect(source.value).toBe('5=7.50'); expect(submit(form).defaultPrevented).toBe(false);
  });

  it('原生发货方式动态控制库存说明与显隐，库存值始终保留且卸载复原', async () => {
    const { form, root } = createForm('goods');
    const stock = native(form, 'in_stock'); const group = stock.closest<HTMLElement>('.form-group')!;
    const writes = vi.spyOn(stock, 'value', 'set');
    const bridge = mountGuide(); await nextTick();
    expect(group.hidden).toBe(true); expect(root.textContent).toContain('库存由可用卡密数量决定');
    const manual = form.querySelector<HTMLInputElement>('[name=type][value="2"]')!;
    manual.checked = true; manual.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
    expect(group.hidden).toBe(false); expect(root.textContent).toContain('人工发货：请填写可售库存');
    const automatic = form.querySelector<HTMLInputElement>('[name=type][value="1"]')!;
    automatic.checked = true; automatic.dispatchEvent(new Event('change', { bubbles: true })); await nextTick();
    expect(group.hidden).toBe(true); expect(stock.value).toBe('17'); expect(writes).not.toHaveBeenCalled();
    bridge.dispose(); expect(group.hidden).toBe(false); expect(group.classList.contains('fg-stock-hidden')).toBe(false);
    expect(native<HTMLTextAreaElement>(form, 'other_ipu_cnf').closest('details')).toBeNull();
  });
});

describe('显式 refresh 与 PJAX 生命周期', () => {
  it('重复 refresh 保留草稿和组件身份，PJAX 移除卸载，换页只挂一次且无全局观察器', async () => {
    const observer = vi.spyOn(globalThis, 'MutationObserver');
    const { form, root } = createForm('goods');
    const source = native<HTMLTextAreaElement>(form, 'wholesale_price_cnf'); source.value = '2=9\n';
    const originalParent = source.parentElement;
    const bridge = mountGuide(); await nextTick(); await click(root, '[data-fg-add=wholesale]');
    const draft = root.querySelectorAll<HTMLInputElement>('[data-fg-field=quantity]')[1];
    await edit(draft, '5');
    for (let count = 0; count < 5; count++) bridge.refresh(); await nextTick();
    expect(root.querySelectorAll<HTMLInputElement>('[data-fg-field=quantity]')[1]).toBe(draft);
    expect(draft.value).toBe('5'); expect(source.value).toBe('2=9\n'); expect(submit(form).defaultPrevented).toBe(true);
    form.remove(); bridge.refresh(); await nextTick();
    expect(root.querySelector('.fg')).toBeNull(); expect(source.parentElement).toBe(originalParent);
    expect(source.closest('details')).toBeNull(); expect(submit(form).defaultPrevented).toBe(false);
    const next = createForm('payment'); bridge.refresh(); bridge.refresh(); await nextTick();
    expect(next.root.querySelectorAll('.fg')).toHaveLength(1); expect(observer).not.toHaveBeenCalled();
    bridge.dispose(); bridge.refresh(); expect(next.root.querySelector('.fg')).toBeNull();
  });

  it('仅在同一表单读取配置，并可同时指导不同表单；畸形配置保持原生表单可用', async () => {
    const first = createForm('goods'); const second = createForm('payment');
    const invalid = createForm('goods'); invalid.form.querySelector('script')!.textContent = '{bad';
    const outside = document.createElement('div'); outside.setAttribute('data-admin-form-guide', ''); document.body.append(outside);
    mountGuide(); await nextTick();
    expect(first.root.querySelector('.fg')).not.toBeNull(); expect(second.root.querySelector('.fg')).not.toBeNull();
    expect(invalid.root.textContent).toContain('原生表单仍可使用'); expect(submit(invalid.form).defaultPrevented).toBe(false);
    expect(outside.children).toHaveLength(0);
    expect(() => parseFormGuideConfig({ kind: 'payment', editing: true, catalog: { alpha: { ...catalog.alpha, fields: {} } } })).toThrow();
  });
});
