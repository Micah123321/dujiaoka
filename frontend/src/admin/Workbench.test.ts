import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import Workbench from './Workbench.vue';
import { REQUEST_TIMEOUT, parseConfig, parseData } from './api';
import { pageMeta, publicColumns } from './presentation';
import { sections } from './types';
import { deferred, fixtureConfig, fixtureData, json } from './fixtures';
import type { Section } from './types';

const wrappers: { unmount: () => void }[] = [];
function render(section: Section = 'stripe', canOperate = true) { const wrapper = mount(Workbench, { props: { config: fixtureConfig(section, canOperate) }, attachTo: document.body }); wrappers.push(wrapper); return wrapper; }
function stub(value: unknown = fixtureData()) { const fetch = vi.fn().mockImplementation(() => Promise.resolve(json(value))); vi.stubGlobal('fetch', fetch); return fetch; }
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); document.body.replaceChildren(); vi.unstubAllGlobals(); vi.restoreAllMocks(); vi.useRealTimers(); });

describe('admin API contract and business pages', () => {
  it('matches the fixed API sections and data keys without forwarding metadata', () => {
    const contract = JSON.parse(readFileSync(resolve(process.cwd(), '../tests/.runtime/admin56/api-contract.json'), 'utf8'));
    expect(sections).toEqual(contract.sections);
    expect(Object.keys(fixtureData()).sort()).toEqual(Object.keys(contract.dataResponse).sort());
    const raw = { ...fixtureData(), columns: [{ key: 'payload', label: 'private', format: 'text' }, ...fixtureData().columns], rows: [{ ...fixtureData().rows[0], payload: 'PRIVATE-PAYLOAD', metadata: { secret: 'PRIVATE-METADATA' } }] };
    const data = parseData(raw, 'stripe'); expect(data.columns.every(column => Object.hasOwn(publicColumns, column.key))).toBe(true); expect(data.rows[0]).not.toHaveProperty('payload'); expect(data.rows[0]).not.toHaveProperty('metadata');
    expect(() => parseConfig({ ...fixtureConfig(), endpoints: { data: 'https://elsewhere.test/data', action: '/admin/action' } })).toThrow();
  });
  it.each(sections)('renders %s and fetches its exact section endpoint', async section => {
    const fetch = stub(fixtureData(section)); const wrapper = render(section); await flushPromises();
    expect(wrapper.get('h1').text()).toContain(pageMeta[section].title); expect(wrapper.get('.wb-table').text()).toContain('ORDER-001');
    const url = new URL(fetch.mock.calls[0][0], location.href); expect(url.pathname).toBe('/admin/operations/' + section + '/data'); expect(Object.fromEntries(url.searchParams)).toEqual({ page: '1', state: 'all', search: '' });
    expect(fetch.mock.calls[0][1].method).toBe('GET'); expect(wrapper.get('.wb-navigation [aria-current=page]').attributes('href')).toBe('/admin/operations/' + section);
  });
  it('sends search, state and page independently and resets pagination for filters', async () => {
    const fetch = vi.fn().mockImplementation((url: string) => { const page = Number(new URL(url, location.href).searchParams.get('page')); return Promise.resolve(json({ ...fixtureData(), pagination: { page, per_page: 20, total: 43, last_page: 3 } })); }); vi.stubGlobal('fetch', fetch);
    const wrapper = render(); await flushPromises(); await wrapper.get('.wb-pagination button:last-child').trigger('click'); await flushPromises();
    expect(new URL(fetch.mock.calls.at(-1)![0], location.href).searchParams.get('page')).toBe('2');
    await wrapper.get('#wb-search-input').setValue(' ORDER-2 '); await wrapper.get('form[role=search]').trigger('submit'); await flushPromises();
    let params = new URL(fetch.mock.calls.at(-1)![0], location.href).searchParams; expect(Object.fromEntries(params)).toEqual({ page: '1', state: 'all', search: 'ORDER-2' });
    await wrapper.get('#wb-state').setValue('failed'); await flushPromises(); params = new URL(fetch.mock.calls.at(-1)![0], location.href).searchParams; expect(params.get('state')).toBe('failed'); expect(params.get('search')).toBe('ORDER-2');
    await wrapper.get('.wb-filter-controls button').trigger('click'); await flushPromises(); expect(wrapper.get('#wb-search-input').element).toHaveProperty('value', ''); expect(new URL(fetch.mock.calls.at(-1)![0], location.href).searchParams.get('state')).toBe('all');
  });
  it('renders empty search results with a working clear action', async () => {
    const fetch = stub({ ...fixtureData(), rows: [], pagination: { page: 1, per_page: 20, total: 0, last_page: 1 } }); const wrapper = render(); await flushPromises();
    expect(wrapper.text()).toContain('暂无记录'); await wrapper.get('#wb-search-input').setValue('MISSING'); await wrapper.get('form').trigger('submit'); await flushPromises();
    expect(wrapper.text()).toContain('没有找到符合条件的记录'); await wrapper.get('.wb-empty button').trigger('click'); await flushPromises(); expect(fetch).toHaveBeenCalledTimes(3);
  });
  it.each([{ installed: false, enabled: true, message: '尚未配置' }, { installed: true, enabled: false, message: '已停用' }])('explains module state and disables every operation: %j', async module => {
    stub({ ...fixtureData(), module, rows: [] }); const wrapper = render(); await flushPromises();
    expect(wrapper.get('.wb-message-warn').text()).toContain(module.message); expect(wrapper.get('.wb-bulk-bar button').attributes('disabled')).toBeDefined(); expect(wrapper.text()).toContain(module.installed ? '此功能已停用' : '此功能尚未就绪');
  });
  it('uses filtered record totals for pages without KPI cards and marks quotes as read-only', async () => {
    stub({ ...fixtureData('quotes'), cards: [], bulkActions: [], rows: [{ ...fixtureData('quotes').rows[0], actions: [] }] }); const wrapper = render('quotes'); await flushPromises();
    expect(wrapper.findAll('.wb-metric')).toHaveLength(4); expect(wrapper.get('.wb-metric').text()).toContain('43'); expect(wrapper.text()).toContain('符合当前筛选的记录'); expect(wrapper.text()).toContain('报价快照保留订单创建时的金额与汇率，仅供查看'); expect(wrapper.find('.wb-row-action').exists()).toBe(false);
  });
  it('retains permitted historical records when a partially installed module is read-only', async () => {
    stub({ ...fixtureData(), module: { installed: false, enabled: false, message: '历史记录仅供查看' } }); const wrapper = render(); await flushPromises();
    expect(wrapper.get('.wb-table').text()).toContain('ORDER-001'); expect(wrapper.get('.wb-row-action').attributes('disabled')).toBeDefined();
  });
  it('shows readonly roles and server-disabled actions without sending POST', async () => {
    const fetch = stub(); const wrapper = render('stripe', false); await flushPromises(); expect(wrapper.text()).toContain('只读账号'); expect(wrapper.get('.wb-row-action').attributes('disabled')).toBeDefined(); await wrapper.get('.wb-row-action').trigger('click'); expect(wrapper.find('[role=dialog]').exists()).toBe(false); expect(fetch).toHaveBeenCalledTimes(1);
  });
  it('treats all labels and fields as text, hides unknown columns and marks unknown states', async () => {
    const row = { ...fixtureData().rows[0], order_sn: '<img src=x onerror=alert(1)>', status: 'provider-secret-status', metadata: { secret: 'PRIVATE' }, payload: 'PRIVATE', buyer_password: 'PRIVATE' };
    stub({ ...fixtureData(), cards: [{ key: 'html', label: '<script>alert(1)</script>', value: '<b>VALUE</b>', hint: '', tone: 'neutral' }], columns: [...fixtureData().columns, { key: 'buyer_password', label: 'private', format: 'text' }], rows: [row] });
    const wrapper = render(); await flushPromises(); expect(wrapper.find('img').exists()).toBe(false); expect(wrapper.find('script').exists()).toBe(false); expect(wrapper.find('b').exists()).toBe(false); expect(wrapper.get('.wb-table').text()).toContain('<img'); expect(wrapper.text()).toContain('待确认'); expect(wrapper.text()).not.toContain('PRIVATE'); expect(wrapper.text()).not.toContain('provider-secret-status');
  });
  it('renders genuine optional trend data and switches metrics, without inventing a chart for other pages', async () => {
    const trend = [{ day: '2026-01-01', orders: 2, sales: '10.50' }, { day: '2026-01-02', orders: 4, sales: '20.00' }]; stub({ ...fixtureData('overview'), trend }); const wrapper = render('overview'); await flushPromises();
    expect(wrapper.get('.wb-trend-summary').text()).toContain('30.50'); expect(wrapper.get('.wb-chart polyline').attributes('points')).toBe('65,116.25 950,45'); await wrapper.get('.wb-chart-tabs button:last-child').trigger('click'); expect(wrapper.get('.wb-trend-summary').text()).toContain('累计订单6'); expect(wrapper.get('.wb-trend-details').text()).toContain('2026-01-01');
  });
  it('keeps task UUID identifiers and renders feature flag labels in business Chinese', async () => {
    const uuid = '12345678-1234-1234-1234-123456789012'; stub({ ...fixtureData('runtime'), columns: [{ key: 'id', label: '任务编号', format: 'text' }], rows: [{ id: uuid, actions: [{ key: 'retry-job', label: '重试任务', enabled: true }] }], features: [{ key: 'stripe_reconciliation', label: 'stripe_reconciliation', installed: true, enabled: true }] }); const wrapper = render('runtime'); await flushPromises();
    expect(wrapper.get('.wb-table').text()).toContain(uuid); expect(wrapper.get('.wb-feature').text()).toContain('Stripe 自动对账'); expect(wrapper.get('.wb-feature').text()).not.toContain('stripe_reconciliation');
  });
});

describe('confirmed operations and private error handling', () => {
  it('confirms row retry, sends only action/id with CSRF, blocks reentry and returns success details', async () => {
    const pending = deferred<Response>(); const fetch = vi.fn().mockResolvedValueOnce(json(fixtureData())).mockImplementationOnce(() => pending.promise).mockResolvedValue(json(fixtureData())); vi.stubGlobal('fetch', fetch); const wrapper = render(); await flushPromises();
    await wrapper.get('.wb-row-action').trigger('click'); expect(fetch).toHaveBeenCalledTimes(1); expect(wrapper.get('[role=dialog]').text()).toContain('ORDER-001');
    const confirm = wrapper.get('.wb-dialog-actions button:last-child'); await confirm.trigger('click'); await confirm.trigger('click'); expect(fetch).toHaveBeenCalledTimes(2); expect(confirm.attributes('disabled')).toBeDefined();
    const options = fetch.mock.calls[1][1]; expect(JSON.parse(options.body)).toEqual({ action: 'retry', id: 7 }); expect(options.headers['X-CSRF-TOKEN']).toBe('fixture-csrf'); expect(options.method).toBe('POST');
    pending.resolve(json({ message: '已重新安排处理。', result: { retried: 1, payload: 'PRIVATE-RESULT' } })); await flushPromises();
    expect(wrapper.get('.wb-dialog').text()).toContain('已重试1'); expect(wrapper.get('.wb-dialog').text()).not.toContain('PRIVATE-RESULT'); expect(fetch).toHaveBeenCalledTimes(3); await wrapper.get('.wb-dialog button').trigger('click'); expect(wrapper.find('[role=dialog]').exists()).toBe(false);
  });
  it('requires explicit confirmation for dangerous bulk expiry and omits id', async () => {
    const data = { ...fixtureData('overview'), bulkActions: [{ key: 'expire', label: '关闭过期订单', enabled: true }] }; const fetch = vi.fn().mockResolvedValueOnce(json(data)).mockResolvedValueOnce(json({ message: '已执行。', result: { expired: 2, failed: 0 } })).mockResolvedValue(json(data)); vi.stubGlobal('fetch', fetch); const wrapper = render('overview'); await flushPromises();
    await wrapper.get('.wb-bulk-bar button').trigger('click'); expect(wrapper.find('.wb-dialog-danger').exists()).toBe(true); expect(fetch).toHaveBeenCalledTimes(1); await wrapper.get('.wb-dialog-actions button:last-child').trigger('click'); await flushPromises(); expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ action: 'expire' }); expect(wrapper.get('.wb-dialog').text()).toContain('已过期2');
  });
  it('maps POST permission errors without displaying merchant response bodies', async () => {
    const fetch = vi.fn().mockResolvedValueOnce(json(fixtureData())).mockResolvedValueOnce(json({ message: 'PRIVATE-MERCHANT-RESPONSE', payload: 'SECRET' }, 403)); vi.stubGlobal('fetch', fetch); const wrapper = render(); await flushPromises(); await wrapper.get('.wb-row-action').trigger('click'); await wrapper.get('.wb-dialog-actions button:last-child').trigger('click'); await flushPromises(); expect(wrapper.get('[role=alert]').text()).toContain('当前账号没有此操作的权限'); expect(wrapper.text()).not.toContain('PRIVATE-MERCHANT'); expect(wrapper.text()).not.toContain('SECRET');
  });
  it.each([401, 419])('exposes a login action for HTTP %s and never renders private error messages', async status => {
    const fetch = vi.fn().mockResolvedValue(json({ message: 'PRIVATE-ERROR' }, status)); vi.stubGlobal('fetch', fetch); const wrapper = render(); await flushPromises(); expect(wrapper.text()).toContain('登录状态已过期'); expect(wrapper.get('.wb-message a').attributes('href')).toBe('/admin/auth/login'); expect(wrapper.text()).not.toContain('PRIVATE-ERROR'); expect(wrapper.get('.wb-heading-side button').attributes('disabled')).toBeDefined();
  });
  it('recognizes a redirected HTML login page instead of reporting raw parsing errors', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>PRIVATE-LOGIN</html>', { headers: { 'content-type': 'text/html' } }))); const wrapper = render(); await flushPromises(); expect(wrapper.text()).toContain('重新登录'); expect(wrapper.text()).not.toContain('PRIVATE-LOGIN');
  });
  it('maps network failures and malformed successful responses to recovery states', async () => {
    const fetch = vi.fn().mockRejectedValueOnce(new Error('PRIVATE-CONNECTION')).mockResolvedValueOnce(json({ section: 'stripe', metadata: 'PRIVATE-DATA' })).mockResolvedValue(json(fixtureData())); vi.stubGlobal('fetch', fetch); const wrapper = render(); await flushPromises(); expect(wrapper.text()).toContain('连接暂时中断'); await wrapper.get('.wb-message button').trigger('click'); await flushPromises(); expect(wrapper.text()).toContain('数据暂未就绪'); expect(wrapper.text()).not.toContain('PRIVATE'); await wrapper.get('.wb-message button').trigger('click'); await flushPromises(); expect(wrapper.find('.wb-table').exists()).toBe(true);
  });
});

describe('request timing and lifecycle', () => {
  it('aborts timeout requests, leaves retry available and clears all timers on removal', async () => {
    vi.useFakeTimers(); const fetch = vi.fn().mockImplementation(() => new Promise(() => undefined)); vi.stubGlobal('fetch', fetch); const wrapper = render(); await flushPromises(); expect(wrapper.get('.wb-records').attributes('aria-busy')).toBe('true'); await vi.advanceTimersByTimeAsync(REQUEST_TIMEOUT); await flushPromises(); expect(wrapper.text()).toContain('请求等待时间较长'); expect(fetch.mock.calls[0][1].signal.aborted).toBe(true); expect(wrapper.get('.wb-message button').attributes('disabled')).toBeUndefined(); wrapper.unmount(); wrappers.pop(); expect(vi.getTimerCount()).toBe(0);
  });
  it('ignores out-of-order results after quick filter changes', async () => {
    const old = deferred<Response>(); const latest = deferred<Response>(); const fetch = vi.fn().mockResolvedValueOnce(json(fixtureData())).mockImplementationOnce(() => old.promise).mockImplementationOnce(() => latest.promise); vi.stubGlobal('fetch', fetch); const wrapper = render(); await flushPromises(); await wrapper.get('#wb-state').setValue('failed'); await wrapper.get('#wb-state').setValue('completed'); expect(fetch.mock.calls[1][1].signal.aborted).toBe(true);
    latest.resolve(json({ ...fixtureData(), rows: [{ ...fixtureData().rows[0], order_sn: 'NEWEST', status: 'completed' }] })); await flushPromises(); old.resolve(json({ ...fixtureData(), rows: [{ ...fixtureData().rows[0], order_sn: 'STALE' }] })); await flushPromises(); expect(wrapper.get('.wb-table').text()).toContain('NEWEST'); expect(wrapper.text()).not.toContain('STALE'); expect(wrapper.get('#wb-state').element).toHaveProperty('value', 'completed');
  });
  it('cancels a pending POST when the component leaves the page', async () => {
    const pending = deferred<Response>(); const fetch = vi.fn().mockResolvedValueOnce(json(fixtureData())).mockImplementationOnce(() => pending.promise); vi.stubGlobal('fetch', fetch); const wrapper = render(); await flushPromises(); await wrapper.get('.wb-row-action').trigger('click'); await wrapper.get('.wb-dialog-actions button:last-child').trigger('click'); wrapper.unmount(); wrappers.pop(); expect(fetch.mock.calls[1][1].signal.aborted).toBe(true); pending.resolve(json({ message: 'late', result: { retried: 1 } })); await flushPromises(); expect(fetch).toHaveBeenCalledTimes(2);
  });
  it('focuses confirmation controls and restores focus after Escape', async () => {
    stub(); const wrapper = render(); await flushPromises(); const button = wrapper.get('.wb-row-action'); await button.trigger('click'); await flushPromises(); expect(document.activeElement).toBe(wrapper.get('.wb-dialog-actions button:first-child').element); await wrapper.get('.wb-dialog').trigger('keydown', { key: 'Escape' }); await flushPromises(); expect(wrapper.find('[role=dialog]').exists()).toBe(false); expect(document.activeElement).toBe(button.element);
  });
});
