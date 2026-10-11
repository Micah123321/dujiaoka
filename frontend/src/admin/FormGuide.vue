<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import type { FormGuideConfig, MerchantField, Profile } from './form-guide';

const props = defineProps<{ config: FormGuideConfig; form: HTMLFormElement }>();
type NativeControl = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;
type JQueryHandle = { on: (events: string, handler: (event: Event) => void) => unknown; off: (events: string, handler: (event: Event) => void) => unknown; trigger: (event: string) => unknown };
type JQuery = (element: Element) => JQueryHandle;
const jquery = (window as Window & { jQuery?: JQuery }).jQuery;
const cleanup: (() => void)[] = [];
const view = ref<HTMLElement>();
const rawHost = ref<HTMLElement>();
const manualDetails = ref<HTMLDetailsElement>();
const route = ref('');
const check = ref('');
const routeChanged = ref(false);
const goodsType = ref('');
const actualPrice = ref<string | null>(null);
let initialPrice: string | null = null;
const submitError = ref(false);
const profile = computed(() => props.config.catalog?.[route.value]);
const legacyCheck = computed(() => !!profile.value && !profile.value.checks.some(item => item.value === check.value));
const fields: MerchantField[] = ['merchant_id', 'merchant_key', 'merchant_pem'];

function controls(name: string): NativeControl[] {
  return Array.from(props.form.querySelectorAll<NativeControl>('input, select, textarea')).filter(element => element.name === name);
}
function control(name: string): NativeControl | undefined { return controls(name).find(element => !(element instanceof HTMLInputElement) || element.type !== 'hidden') || controls(name)[0]; }
function value(name: string): string {
  const list = controls(name);
  const radio = list.find(element => element instanceof HTMLInputElement && element.type === 'radio' && element.checked);
  return radio?.value ?? control(name)?.value ?? '';
}
function listen(element: Element, events: string, handler: (event: Event) => void, useJquery = false) {
  const names = events.split(' ');
  for (const name of names) element.addEventListener(name, handler);
  cleanup.push(() => { for (const name of names) element.removeEventListener(name, handler); });
  if (useJquery && jquery) {
    const namespaced = names.map(name => name + '.dujiaokaFormGuide').join(' ');
    jquery(element).on(namespaced, handler);
    cleanup.push(() => jquery(element).off(namespaced, handler));
  }
}
function notify(element: NativeControl) {
  element.dispatchEvent(new Event('change', { bubbles: true }));
  if (element instanceof HTMLSelectElement) jquery?.(element).trigger('change.select2');
}
function setNative(name: string, next: string) {
  for (const element of controls(name)) {
    if (element instanceof HTMLInputElement && element.type === 'radio') {
      const checked = element.value === next;
      if (element.checked !== checked) { element.checked = checked; if (checked) notify(element); }
    } else if (element.value !== next) { element.value = next; notify(element); }
  }
}

const fieldHints: { key: MerchantField; element: NativeControl; label?: HTMLElement; labelNodes: Node[]; placeholder: string | null; required: string | null; help: HTMLParagraphElement; group?: HTMLElement; unused: boolean }[] = [];
function prepareFieldHints() {
  for (const key of fields) {
    const element = control(key);
    if (!element) continue;
    const group = element.closest<HTMLElement>('.form-group') || undefined;
    const label = Array.from(props.form.querySelectorAll<HTMLLabelElement>('label')).find(item => element.id && item.htmlFor === element.id) || group?.querySelector<HTMLElement>('.control-label') || undefined;
    const help = document.createElement('p');
    help.className = 'fg-native-help';
    (element.closest('.input-group') || element).insertAdjacentElement('afterend', help);
    fieldHints.push({ key, element, label, group, unused: !!group?.classList.contains('fg-unused-field'), labelNodes: label ? Array.from(label.childNodes) : [], placeholder: element.getAttribute('placeholder'), required: element.getAttribute('aria-required'), help });
  }
  cleanup.push(() => {
    for (const hint of fieldHints) {
      hint.label?.replaceChildren(...hint.labelNodes);
      restoreAttribute(hint.element, 'placeholder', hint.placeholder);
      restoreAttribute(hint.element, 'aria-required', hint.required);
      hint.help.remove();
      hint.group?.classList.toggle('fg-unused-field', hint.unused);
    }
  });
}
function restoreAttribute(element: Element, name: string, old: string | null) {
  if (old === null) element.removeAttribute(name); else element.setAttribute(name, old);
}
function updateFieldHints() {
  for (const hint of fieldHints) {
    const spec = profile.value?.fields[hint.key];
    hint.group?.classList.toggle('fg-unused-field', spec?.label === '此渠道无需填写');
    if (spec) {
      if (hint.label) hint.label.textContent = spec.label + (spec.required ? '（必填）' : '（选填）');
      hint.element.setAttribute('placeholder', spec.placeholder);
      hint.element.setAttribute('aria-required', String(spec.required));
      hint.help.textContent = spec.help;
    } else {
      hint.label?.replaceChildren(...hint.labelNodes);
      restoreAttribute(hint.element, 'placeholder', hint.placeholder);
      restoreAttribute(hint.element, 'aria-required', hint.required);
      hint.help.textContent = '';
    }
  }
}
let checkSelect: HTMLSelectElement | undefined;
let originalOptions: HTMLOptionElement[] = [];
function filterChecks(keepCurrent: boolean) {
  if (!checkSelect) return;
  const current = checkSelect.value;
  if (!profile.value) {
    const options = originalOptions.map(option => option.cloneNode(true) as HTMLOptionElement);
    if (!options.some(option => option.value === current)) options.push(new Option(current, current));
    checkSelect.replaceChildren(...options);
    checkSelect.value = current;
  } else {
    const options = profile.value.checks.map(item => new Option(item.label, item.value));
    if (keepCurrent && !options.some(option => option.value === current)) {
      const oldLabel = originalOptions.find(option => option.value === current)?.textContent;
      options.unshift(new Option(current ? (oldLabel || '原有支付标识') + '（原有配置，请核对）' : '请选择支付方式', current));
    }
    checkSelect.replaceChildren(...options);
    if (keepCurrent) checkSelect.value = current;
  }
  jquery?.(checkSelect).trigger('change.select2');
}
function applyCheck(item: Profile['checks'][number] | undefined) {
  if (!item) return;
  setNative('pay_method', String(item.method));
  setNative('pay_client', String(item.client));
}
function paymentReady() {
  const routeSelect = control('pay_handleroute');
  const checkControl = control('pay_check');
  route.value = value('pay_handleroute');
  check.value = value('pay_check');
  checkSelect = checkControl instanceof HTMLSelectElement ? checkControl : undefined;
  originalOptions = checkSelect ? Array.from(checkSelect.options).map(option => option.cloneNode(true) as HTMLOptionElement) : [];
  prepareFieldHints();
  updateFieldHints();
  filterChecks(true);
  if (checkSelect) cleanup.push(() => {
    const current = checkSelect!.value;
    const options = originalOptions.map(option => option.cloneNode(true) as HTMLOptionElement);
    if (!options.some(option => option.value === current)) options.push(new Option(current, current));
    checkSelect!.replaceChildren(...options);
    checkSelect!.value = current;
    jquery?.(checkSelect!).trigger('change.select2');
  });
  if (routeSelect) listen(routeSelect, 'change select2:select', () => {
    const next = value('pay_handleroute');
    if (next === route.value) return;
    route.value = next;
    routeChanged.value = true;
    updateFieldHints();
    if (profile.value?.checks.length) {
      const match = profile.value.checks.find(item => item.value === check.value) || profile.value.checks[0];
      filterChecks(false);
      setNative('pay_check', match.value);
      check.value = value('pay_check');
      applyCheck(match);
      const name = control('pay_name');
      if (name && !name.value.trim()) setNative('pay_name', profile.value.label);
    } else { filterChecks(true); check.value = value('pay_check'); }
  }, true);
  if (checkControl) listen(checkControl, 'change select2:select', () => {
    const next = value('pay_check');
    if (next === check.value) return;
    check.value = next;
    applyCheck(profile.value?.checks.find(item => item.value === next));
  }, true);
}

interface WholesaleRow { id: number; quantity: string; price: string }
interface BuyerRow { id: number; key: string; label: string; required: boolean; placeholder: string }
let rowId = 0;
const wholesale = reactive({ source: null as HTMLTextAreaElement | null, rows: [] as WholesaleRow[], recognized: true, dirty: false });
const buyer = reactive({ source: null as HTMLTextAreaElement | null, rows: [] as BuyerRow[], recognized: true, dirty: false });
const forbidden = /[=\r\n]/;
function wholesaleErrors(rows: WholesaleRow[]): string[] {
  return rows.map(row => {
    if (!/^[1-9]\d*$/.test(row.quantity) || !Number.isSafeInteger(Number(row.quantity))) return '起购数量请填写大于零的整数。';
    if (!/^\d+(?:\.\d{1,2})?$/.test(row.price) || !Number.isFinite(Number(row.price)) || Number(row.price) <= 0) return '单价须大于零，最多保留两位小数。';
    if (actualPrice.value !== null && Number(row.price) > Number(actualPrice.value)) return '优惠单价应小于或等于商品实际售价。';
    if (rows.some(other => other.id !== row.id && Number(other.quantity) === Number(row.quantity))) return '起购数量重复，请为每个数量设置一档价格。';
    return '';
  });
}
function buyerErrors(rows: BuyerRow[]): string[] {
  return rows.map(row => {
    if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(row.key)) return '字段标识请用英文字母、数字和下划线，首位为字母。';
    if (['gid', 'goods_id', 'payway', 'by_amount', 'buy_amount', 'email', 'search_pwd', 'coupon_code', 'img_verify_code', 'img_code', 'geetest_challenge', 'geetest_validate', 'geetest_seccode'].includes(row.key)) return '此标识与系统字段重复，请换一个名称。';
    if (rows.some(other => other.id !== row.id && other.key === row.key)) return '字段标识重复，请使用唯一标识。';
    if (!row.label.trim()) return '请填写展示给买家的字段名称。';
    if (forbidden.test(row.label) || forbidden.test(row.placeholder)) return '字段名称和占位提示中请勿使用等号或换行。';
    return '';
  });
}
const wholesaleIssues = computed(() => wholesaleErrors(wholesale.rows));
const buyerIssues = computed(() => buyerErrors(buyer.rows));
const invalidDraft = computed(() => ((wholesale.dirty || (initialPrice !== null && Number(actualPrice.value) !== Number(initialPrice))) && wholesaleIssues.value.some(Boolean)) || (buyer.dirty && buyerIssues.value.some(Boolean)));
function lines(raw: string) { return raw.split(/\r\n|\n|\r/).filter(line => line.trim() !== ''); }
function readWholesale() {
  const rows = lines(wholesale.source?.value || '').map(line => {
    const parts = line.split('=');
    return { id: ++rowId, quantity: parts.length === 2 ? parts[0] : '', price: parts.length === 2 ? parts[1] : '' };
  });
  wholesale.recognized = !wholesaleErrors(rows).some(Boolean);
  wholesale.rows = wholesale.recognized ? rows : [];
  wholesale.dirty = false;
}
function readBuyer() {
  let valid = true;
  const rows = lines(buyer.source?.value || '').map(line => {
    const parts = line.split('=');
    if ((parts.length !== 3 && parts.length !== 4) || (parts[2] !== 'true' && parts[2] !== 'false')) valid = false;
    return { id: ++rowId, key: parts[0] || '', label: parts[1] || '', required: parts[2] === 'true', placeholder: parts[3] || '' };
  });
  buyer.recognized = valid && !buyerErrors(rows).some(Boolean);
  buyer.rows = buyer.recognized ? rows : [];
  buyer.dirty = false;
}
function writeSource(source: HTMLTextAreaElement | null, text: string) {
  if (!source || source.value === text) return;
  source.value = text;
  source.dispatchEvent(new Event('input', { bubbles: true }));
  source.dispatchEvent(new Event('change', { bubbles: true }));
}
let writing = false;
function syncWholesale() {
  wholesale.dirty = true;
  if (wholesaleIssues.value.some(Boolean)) return;
  writing = true;
  try { writeSource(wholesale.source, wholesale.rows.map(row => row.quantity + '=' + row.price).join('\n')); }
  finally { writing = false; }
  submitError.value = false;
}
function syncBuyer() {
  buyer.dirty = true;
  if (buyerIssues.value.some(Boolean)) return;
  writing = true;
  try { writeSource(buyer.source, buyer.rows.map(row => [row.key, row.label, String(row.required), row.placeholder].join('=')).join('\n')); }
  finally { writing = false; }
  submitError.value = false;
}
function editWholesale(row: WholesaleRow, key: 'quantity' | 'price', event: Event) {
  row[key] = (event.target as HTMLInputElement).value;
  syncWholesale();
}
function editBuyer(row: BuyerRow, key: 'key' | 'label' | 'placeholder', event: Event) {
  row[key] = (event.target as HTMLInputElement).value;
  syncBuyer();
}
function editRequired(row: BuyerRow, event: Event) { row.required = (event.target as HTMLInputElement).checked; syncBuyer(); }
function addWholesale() { wholesale.rows.push({ id: ++rowId, quantity: '', price: '' }); syncWholesale(); }
function addBuyer() { const id = ++rowId; let key = 'buyer_field_' + id; while (buyer.rows.some(row => row.key === key)) key += '_new'; buyer.rows.push({ id, key, label: '', required: false, placeholder: '' }); syncBuyer(); }
function removeWholesale(index: number) { wholesale.rows.splice(index, 1); syncWholesale(); }
function removeBuyer(index: number) { buyer.rows.splice(index, 1); syncBuyer(); }
function blockSubmission(event: Event) {
  if (!invalidDraft.value) return;
  event.preventDefault();
  event.stopImmediatePropagation();
  submitError.value = true;
  void nextTick(() => view.value?.querySelector<HTMLInputElement>('[aria-invalid="true"]')?.focus());
}
function goodsReady() {
  const wholesaleControl = control('wholesale_price_cnf');
  const buyerControl = control('other_ipu_cnf');
  wholesale.source = wholesaleControl instanceof HTMLTextAreaElement ? wholesaleControl : null;
  buyer.source = buyerControl instanceof HTMLTextAreaElement ? buyerControl : null;
  const priceControl = control('actual_price');
  actualPrice.value = priceControl?.value ?? null;
  initialPrice = actualPrice.value;
  if (priceControl) listen(priceControl, 'input change', () => { actualPrice.value = priceControl.value; if (wholesale.dirty) syncWholesale(); });
  readWholesale(); readBuyer();
  if (manualDetails.value && (!wholesale.recognized || !buyer.recognized)) manualDetails.value.open = true;
  const moved = new Set<HTMLElement>();
  for (const source of [wholesale.source, buyer.source]) {
    if (!source || !rawHost.value) continue;
    const group = source.closest<HTMLElement>('.form-group');
    const node = group && !group.contains(view.value!) ? group : source;
    if (moved.has(node)) continue;
    moved.add(node);
    const parent = node.parentNode!;
    const sibling = node.nextSibling;
    const hadClass = node.classList.contains('fg-raw-field');
    node.classList.add('fg-raw-field');
    rawHost.value.append(node);
    cleanup.push(() => {
      parent.insertBefore(node, sibling?.parentNode === parent ? sibling : null);
      if (!hadClass) node.classList.remove('fg-raw-field');
    });
  }
  if (wholesale.source) listen(wholesale.source, 'input change', () => { if (!writing) { readWholesale(); submitError.value = false; } });
  if (buyer.source) listen(buyer.source, 'input change', () => { if (!writing) { readBuyer(); submitError.value = false; } });
  const inventory = control('in_stock');
  const stockGroup = inventory?.closest<HTMLElement>('.form-group') || inventory;
  const oldHidden = stockGroup?.hidden;
  const hadStockClass = stockGroup?.classList.contains('fg-stock-hidden');
  function updateType() {
    goodsType.value = value('type');
    if (stockGroup) {
      stockGroup.hidden = goodsType.value === '1';
      stockGroup.classList.toggle('fg-stock-hidden', goodsType.value === '1');
    }
  }
  updateType();
  for (const element of controls('type')) listen(element, 'change', updateType, true);
  if (stockGroup) cleanup.push(() => { stockGroup.hidden = !!oldHidden; stockGroup.classList.toggle('fg-stock-hidden', !!hadStockClass); });
  props.form.addEventListener('submit', blockSubmission, true);
  cleanup.push(() => props.form.removeEventListener('submit', blockSubmission, true));
  const clickGuard = (event: Event) => {
    const target = event.target instanceof Element ? event.target.closest('button, input') : null;
    if ((target instanceof HTMLButtonElement || target instanceof HTMLInputElement) && target.type === 'submit') blockSubmission(event);
  };
  props.form.addEventListener('click', clickGuard, true);
  cleanup.push(() => props.form.removeEventListener('click', clickGuard, true));
  if (jquery) {
    jquery(props.form).on('submit.dujiaokaFormGuide', blockSubmission);
    cleanup.push(() => jquery(props.form).off('submit.dujiaokaFormGuide', blockSubmission));
  }
}
onMounted(() => { if (props.config.kind === 'payment') paymentReady(); else goodsReady(); });
onBeforeUnmount(() => { for (const dispose of cleanup.reverse()) dispose(); });
</script>

<template>
  <section ref="view" class="fg" aria-label="表单填写指导">
    <template v-if="config.kind === 'payment'">
      <header v-if="config.editing" class="fg-heading"><h2>核对当前服务</h2></header>
      <div v-if="profile" class="fg-card">
        <h3>{{ profile.label }}</h3><p>{{ profile.note }}</p>
        <details class="fg-account-details"><summary>查看所需账户资料</summary><dl class="fg-payment-fields"><template v-for="key in fields" :key="key"><dt>{{ profile.fields[key].label }}<span>{{ profile.fields[key].required ? '必填' : '选填' }}</span></dt><dd>{{ profile.fields[key].help }}</dd></template></dl></details>
        <p class="fg-notify"><strong>支付结果回调路径</strong><span>{{ profile.notify || '以当前渠道后台提供的配置为准' }}</span></p>
      </div>
      <p v-else class="fg-notice">{{ route ? '当前渠道暂无填写说明，原有字段和支付标识已保留，请手动核对。' : '请选择收款渠道以查看填写说明。' }}</p>
      <p v-if="legacyCheck" class="fg-notice">原有支付标识与当前渠道说明未匹配，已保留原值，请核对后再保存。</p>
      <p :class="routeChanged ? 'fg-notice' : 'fg-hint'">{{ routeChanged ? '渠道已切换，原有商户凭据仍保留。请逐项核对新渠道的账号、密钥及证书后再保存。' : '账户资料在“填写账户”中设置。保存停用草稿时可稍后补齐；启用前须填写必需项目。' }}</p>
    </template>
    <template v-else>
      <header class="fg-heading"><h2>{{ config.editing ? '完善商品配置' : '填写商品配置' }}</h2><p>按行添加优惠价格和下单资料，无需记忆配置格式。</p></header>
      <p class="fg-stock" role="status">{{ goodsType === '1' ? '自动发货：库存由可用卡密数量决定，手动库存已隐藏，原值保留。' : goodsType === '2' ? '人工发货：请填写可售库存，成交后按订单安排交付。' : '请选择发货方式以查看库存填写说明。' }}</p>
      <p v-if="submitError" class="fg-error" role="alert">请先修正配置行中的错误，再保存商品。旧配置尚未更新。</p>
      <section class="fg-card" aria-label="批发价格配置">
        <div class="fg-section-heading"><div><h3>批发价格</h3><p>达到起购数量后，按对应单价购买。</p></div><button v-if="wholesale.source && wholesale.recognized" type="button" class="fg-add" data-fg-add="wholesale" @click="addWholesale">添加价格档</button></div>
        <p v-if="!wholesale.source" class="fg-notice">批发配置字段尚未就绪，请检查原生表单。</p>
        <p v-else-if="!wholesale.recognized" class="fg-notice" role="status">存在未识别的历史批发配置，原始文本完整保留。请在“手动编辑原始配置”中核对，本编辑器不会覆盖这些内容。</p>
        <div v-else class="fg-table-wrap"><table class="fg-table" data-fg-table="wholesale"><thead><tr><th scope="col">起购数量</th><th scope="col">单价（元）</th><th scope="col">操作</th></tr></thead><tbody>
          <tr v-for="(row, index) in wholesale.rows" :key="row.id"><td data-label="起购数量"><input :value="row.quantity" type="text" inputmode="numeric" :aria-label="'第' + (index + 1) + '档起购数量'" :aria-invalid="!!wholesaleIssues[index]" data-fg-field="quantity" @input="editWholesale(row, 'quantity', $event)"><p v-if="wholesaleIssues[index]" class="fg-error" role="alert">{{ wholesaleIssues[index] }}</p></td><td data-label="单价（元）"><input :value="row.price" type="text" inputmode="decimal" :aria-label="'第' + (index + 1) + '档单价'" :aria-invalid="!!wholesaleIssues[index]" data-fg-field="price" @input="editWholesale(row, 'price', $event)"></td><td data-label="操作"><button type="button" class="fg-remove" :aria-label="'删除第' + (index + 1) + '档价格'" @click="removeWholesale(index)">删除</button></td></tr>
          <tr v-if="!wholesale.rows.length"><td colspan="3" class="fg-empty">尚未设置批发价格，按商品原价销售。</td></tr>
        </tbody></table></div>
      </section>
      <section class="fg-card" aria-label="买家填写信息配置">
        <div class="fg-section-heading"><div><h3>买家填写信息</h3><p>仅人工处理使用。填买家看到的名称即可，内部标识自动生成；占位提示可以留空。</p></div><button v-if="buyer.source && buyer.recognized" type="button" class="fg-add" data-fg-add="buyer" @click="addBuyer">添加填写项</button></div>
        <p v-if="!buyer.source" class="fg-notice">买家信息配置字段尚未就绪，请检查原生表单。</p>
        <p v-else-if="!buyer.recognized" class="fg-notice" role="status">存在未识别的历史买家配置，原始文本完整保留。请在“手动编辑原始配置”中核对，本编辑器不会覆盖这些内容。</p>
        <div v-else class="fg-table-wrap"><table class="fg-table fg-buyer-table" data-fg-table="buyer"><thead><tr><th scope="col">内部标识（自动生成）</th><th scope="col">买家看到的名称</th><th scope="col">必填</th><th scope="col">占位提示</th><th scope="col">操作</th></tr></thead><tbody>
          <tr v-for="(row, index) in buyer.rows" :key="row.id"><td data-label="字段标识"><input :value="row.key" type="text" :aria-label="'第' + (index + 1) + '项字段标识'" :aria-invalid="!!buyerIssues[index]" data-fg-field="key" placeholder="如 account" @input="editBuyer(row, 'key', $event)"><p v-if="buyerIssues[index]" class="fg-error" role="alert">{{ buyerIssues[index] }}</p></td><td data-label="买家看到的名称"><input :value="row.label" type="text" :aria-label="'第' + (index + 1) + '项名称'" :aria-invalid="!!buyerIssues[index]" data-fg-field="label" placeholder="如 接收账号" @input="editBuyer(row, 'label', $event)"></td><td data-label="必填"><label class="fg-required"><input :checked="row.required" type="checkbox" :aria-label="'第' + (index + 1) + '项必填'" data-fg-field="required" @change="editRequired(row, $event)"><span>必填</span></label></td><td data-label="占位提示"><input :value="row.placeholder" type="text" :aria-label="'第' + (index + 1) + '项占位提示'" :aria-invalid="!!buyerIssues[index]" data-fg-field="placeholder" placeholder="可留空" @input="editBuyer(row, 'placeholder', $event)"></td><td data-label="操作"><button type="button" class="fg-remove" :aria-label="'删除第' + (index + 1) + '个填写项'" @click="removeBuyer(index)">删除</button></td></tr>
          <tr v-if="!buyer.rows.length"><td colspan="5" class="fg-empty">尚未设置额外信息，买家无需填写附加字段。</td></tr>
        </tbody></table></div>
      </section>
      <details ref="manualDetails" class="fg-manual"><summary>手动编辑原始配置</summary><p class="fg-hint">这里保留原生配置文本。行编辑有错误时，原文保持最近一次有效内容；手动修改后以手动内容为准。</p><div ref="rawHost" class="fg-raw-host"></div></details>
    </template>
  </section>
</template>
