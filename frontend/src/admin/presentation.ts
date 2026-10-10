import type { Column, Section, State, Scalar } from './types';

export const pageMeta: Record<Section, { title: string; intro: string; table: string; search: string; mark: string }> = {
  overview: { title: '经营概览', intro: '看清订单与收款，及时处理需要关注的事项。', table: '最近订单', search: '搜索订单号', mark: '01' },
  stripe: { title: 'Stripe 收款', intro: '查看支付事件的处理进度，跟进异常收款。', table: '支付事件', search: '搜索订单号或事件编号', mark: '02' },
  paypal: { title: 'PayPal 收款', intro: '核对支付记录与订单状态，让每笔收款有据可查。', table: '收款记录', search: '搜索订单号或支付编号', mark: '03' },
  notifications: { title: '订单通知', intro: '跟进通知发送结果，及时补发待处理消息。', table: '通知记录', search: '搜索订单号', mark: '04' },
  quotes: { title: '支付报价', intro: '核对支付币种、换算金额与汇率，查看订单的报价快照。', table: '报价记录', search: '搜索订单号', mark: '05' },
  runtime: { title: '运行状态', intro: '查看服务状态与任务积压，掌握店铺运行情况。', table: '任务记录', search: '搜索任务编号', mark: '06' },
};
export const stateLabels: Record<State | 'unknown', string> = {
  all: '全部状态', pending: '待处理', failed: '处理失败', completed: '已完成', reserved: '处理中',
  manual: '待人工处理', expired: '已过期', open: '有效', closed: '已关闭', unknown: '待确认',
};
export const sectionStates: Record<Section, State[]> = {
  overview: ['all', 'pending', 'open', 'completed', 'failed', 'expired'],
  stripe: ['all', 'pending', 'reserved', 'failed', 'completed', 'closed'],
  paypal: ['all', 'pending', 'reserved', 'failed', 'completed', 'closed'],
  notifications: ['all', 'pending', 'reserved', 'failed', 'completed'],
  quotes: ['all', 'completed'],
  runtime: ['all', 'pending', 'reserved', 'failed', 'completed'],
};

// Only these public business fields may become table cells; metadata is never traversed.
export const publicColumns: Record<string, Column['format']> = {
  id: 'text', kind: 'text', error: 'text', order_sn: 'text', title: 'text', goods_name: 'text', quantity: 'number', buy_amount: 'number',
  cny_amount: 'money', usd_amount: 'money', base_currency: 'text', rate_date: 'time', amount: 'money', total_price: 'money', actual_price: 'money', price: 'money', currency: 'text', status: 'status',
  provider_id: 'text', provider_order_id: 'text', payment_id: 'text', payment_intent_id: 'text', event_id: 'text',
  event_type: 'text', type: 'text', capture_id: 'text', paypal_order_id: 'text', stripe_event_id: 'text',
  quote_id: 'text', quote_sn: 'text', source: 'text', channel: 'text', notification_type: 'text',
  attempts: 'number', retry_count: 'number', queue: 'text', connection: 'text', uuid: 'text', job_id: 'text',
  created_at: 'time', updated_at: 'time',
  paid_at: 'time', processed_at: 'time', sent_at: 'time', failed_at: 'time', next_retry_at: 'time',
  available_at: 'time', reserved_at: 'time', expires_at: 'time', published_at: 'time', closed_at: 'time',
};
const knownErrors: Record<string, string> = {
  network: '连接暂时中断，请稍后重新加载。', timeout: '请求等待时间较长，请重试。',
  forbidden: '当前账号没有此操作的权限。', expired: '登录状态已过期，请重新登录后继续。',
  conflict: '记录状态已变化，请刷新后重新确认。', invalid: '操作信息有误，请刷新后重试。',
  unavailable: '此功能暂未就绪，请稍后再试。', server: '服务暂时繁忙，请稍后重试。',
  response: '数据暂未就绪，请重新加载。',
};
export function errorText(code: string) { return knownErrors[code] || knownErrors.server; }
export function formatCell(value: Scalar | undefined, format: Column['format']): string {
  if (value === null || value === undefined || value === '') return '—';
  if (format === 'status') return stateLabels[value as State] || stateLabels.unknown;
  if (format === 'money') {
    const number = Number(value);
    return Number.isFinite(number) ? number.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—';
  }
  if (format === 'number') {
    const number = Number(value);
    return Number.isFinite(number) ? number.toLocaleString('zh-CN') : String(value);
  }
  if (format === 'time') {
    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('zh-CN', { hour12: false });
  }
  return String(value);
}
export function safeHref(value: unknown): string {
  if (typeof value !== 'string' || !value) return '';
  try {
    const url = new URL(value, location.href);
    return ['http:', 'https:'].includes(url.protocol) && url.origin === location.origin ? url.pathname + url.search + url.hash : '';
  } catch { return ''; }
}
export function loginHref(dataEndpoint: string): string {
  const path = safeHref(dataEndpoint).replace(/\/operations\/[^/]+\/data(?:\?.*)?$/, '');
  return path + '/auth/login';
}
export const featureLabels: Record<string, string> = {
  stripe: 'Stripe 收款', paypal: 'PayPal 收款', notifications: '订单通知', quotes: '支付报价',
  stripe_payment_intents: 'Stripe 在线收款', stripe_reconciliation: 'Stripe 自动对账',
  paypal_orders_v2: 'PayPal 在线收款', paypal_reconciliation: 'PayPal 自动对账',
  payment_quote_freeze: '订单报价快照', api_hook_outbox: '店铺回调通知',
  payment_mail_outbox: '支付邮件通知', manual_status_mail_outbox: '人工订单邮件通知',
};
export function businessCell(key: string, value: Scalar | undefined, format: Column['format']): string {
  const labels: Record<string, Record<string, string>> = {
    kind: { binding: '收款记录', webhook: '支付通知' },
    channel: { api_hook: '店铺回调', payment_mail: '支付邮件', manual_status_mail: '人工订单邮件', mail: '邮件', email: '邮件' },
  };
  return typeof value === 'string' && labels[key]?.[value] ? labels[key][value] : formatCell(value, format);
}
