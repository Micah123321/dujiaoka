import { sections } from './types';
import type { Section, WorkbenchConfig } from './types';
export function fixtureConfig(section: Section = 'stripe', canOperate = true): WorkbenchConfig {
  return { section, userName: '店铺管理员', csrf: 'fixture-csrf', canOperate,
    endpoints: { data: '/admin/operations/' + section + '/data', action: '/admin/operations/' + section + '/action' },
    navigation: sections.map(key => ({ key, label: { overview: '经营概览', stripe: 'Stripe 对账', paypal: 'PayPal 对账', notifications: '通知投递', quotes: '冻结报价', runtime: '运行状态' }[key], href: '/admin/operations/' + key })),
    management: [{ key: 'order', label: '订单管理', href: '/admin/order' }] };
}
export function fixtureData(section: Section = 'stripe') {
  return { section, module: { installed: true, enabled: true, message: '已启用' },
    cards: [{ key: 'today_sales', label: '今日销售额', value: '1280.50', hint: '以已收款订单为准', tone: 'good' }],
    columns: [{ key: 'order_sn', label: '订单号', format: 'text' }, { key: 'amount', label: '金额', format: 'money' }, { key: 'status', label: '状态', format: 'status' }, { key: 'created_at', label: '创建时间', format: 'time' }],
    rows: [{ id: 7, order_sn: 'ORDER-001', amount: '24.50', status: 'failed', created_at: '2026-01-01 09:00:00', actions: [{ key: 'retry', label: '重新排队', enabled: true }] }],
    pagination: { page: 1, per_page: 20, total: 43, last_page: 3 }, bulkActions: [{ key: 'reconcile', label: '执行一批对账', enabled: true }], features: [], runtime: [] };
}
export function json(value: unknown, status = 200) { return new Response(JSON.stringify(value), { status, headers: { 'content-type': 'application/json' } }); }
export function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(finish => { resolve = finish; }); return { promise, resolve }; }
