import { publicColumns, safeHref } from './presentation';
import { sections } from './types';
import type { Action, ActionKey, ActionResult, Column, Query, Row, Scalar, WorkbenchConfig, WorkbenchData } from './types';

export class ApiError extends Error {
  constructor(public code: string) { super(code); this.name = 'ApiError'; }
}
export const REQUEST_TIMEOUT = 15000;
const object = (value: unknown): Record<string, unknown> => value !== null && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {};
const text = (value: unknown, max = 300): string => typeof value === 'string' ? value.slice(0, max) : '';
const scalar = (value: unknown): Scalar => typeof value === 'number' && Number.isFinite(value) ? value : typeof value === 'string' ? value.slice(0, 500) : null;
const positive = (value: unknown, fallback: number) => typeof value === 'number' && Number.isSafeInteger(value) && value > 0 ? value : fallback;
const items = (value: unknown): unknown[] => Array.isArray(value) ? value : [];
const rowActions = new Set<ActionKey>(['retry', 'retry-event', 'retry-job']);
const bulkActions = new Set<ActionKey>(['reconcile', 'publish', 'expire']);
function actions(value: unknown, allowed: Set<ActionKey>): Action[] {
  return items(value).flatMap(raw => {
    const action = object(raw); const key = action.key as ActionKey;
    return allowed.has(key) ? [{ key, label: text(action.label) || '处理', enabled: action.enabled === true }] : [];
  });
}
export function parseConfig(raw: unknown): WorkbenchConfig {
  const data = object(raw); const endpoints = object(data.endpoints);
  if (!sections.includes(data.section as WorkbenchConfig['section']) || !safeHref(endpoints.data) || !safeHref(endpoints.action)) throw new ApiError('response');
  const links = (value: unknown) => items(value).flatMap(rawLink => {
    const link = object(rawLink); const href = safeHref(link.href);
    return href && text(link.label) ? [{ key: text(link.key), label: text(link.label), href }] : [];
  });
  return { section: data.section as WorkbenchConfig['section'], userName: text(data.userName, 80), csrf: text(data.csrf, 2048),
    endpoints: { data: safeHref(endpoints.data), action: safeHref(endpoints.action) },
    navigation: links(data.navigation).filter(link => sections.includes(link.key as WorkbenchConfig['section'])),
    management: links(data.management), canOperate: data.canOperate === true };
}
export function parseData(raw: unknown, expected: WorkbenchConfig['section']): WorkbenchData {
  const data = object(raw); const module = object(data.module); const pagination = object(data.pagination);
  if (data.section !== expected || typeof module.installed !== 'boolean' || typeof module.enabled !== 'boolean'
    || !['cards', 'columns', 'rows', 'bulkActions', 'features', 'runtime'].every(key => Array.isArray(data[key])) || !data.pagination) throw new ApiError('response');
  const columns: Column[] = []; const seen = new Set<string>();
  for (const rawColumn of items(data.columns)) {
    const col = object(rawColumn); const key = text(col.key);
    if (!Object.prototype.hasOwnProperty.call(publicColumns, key) || seen.has(key)) continue;
    seen.add(key); columns.push({ key, label: text(col.label) || key, format: publicColumns[key] });
  }
  const rows: Row[] = items(data.rows).flatMap(rawRow => {
    const row = object(rawRow); const id = row.id;
    const integer = typeof id === 'number' && Number.isSafeInteger(id) && id > 0 || typeof id === 'string' && /^[1-9][0-9]{0,17}$/.test(id);
    const uuid = expected === 'runtime' && typeof id === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(id);
    if (!integer && !uuid) return [];
    const output: Row = { id, actions: actions(row.actions, rowActions) };
    for (const col of columns) output[col.key] = scalar(row[col.key]);
    return [output];
  });
  const installed = module.installed === true; const enabled = module.enabled === true;
  return {
    section: expected, module: { installed, enabled, message: text(module.message) },
    cards: items(data.cards).map(rawCard => { const card = object(rawCard); return { key: text(card.key), label: text(card.label), value: scalar(card.value), hint: text(card.hint), tone: (['good', 'warn'].includes(String(card.tone)) ? card.tone : 'neutral') as 'neutral' | 'good' | 'warn' }; }),
    columns, rows,
    trend: items(data.trend).flatMap(rawPoint => {
      const point = object(rawPoint); const sales = Number(point.sales);
      return typeof point.day === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(point.day) && typeof point.orders === 'number' && Number.isFinite(point.orders) && point.orders >= 0 && Number.isFinite(sales) && sales >= 0
        ? [{ day: point.day, orders: point.orders, sales }] : [];
    }).slice(0, 31),
    pagination: { page: positive(pagination.page, 1), per_page: Math.min(50, positive(pagination.per_page, 20)), total: typeof pagination.total === 'number' && Number.isSafeInteger(pagination.total) && pagination.total >= 0 ? pagination.total : 0, last_page: positive(pagination.last_page, 1) },
    bulkActions: actions(data.bulkActions, bulkActions),
    features: items(data.features).map(rawFeature => { const feature = object(rawFeature); return { key: text(feature.key), label: text(feature.label), installed: feature.installed === true, enabled: feature.enabled === true }; }),
    runtime: items(data.runtime).map(rawItem => { const item = object(rawItem); return { label: text(item.label), value: text(item.value), hint: text(item.hint) }; }),
  };
}
const resultLabels: Record<string, string> = { checked: '已检查', completed: '已完成', retry: '待重试', manual: '待人工处理', ignored: '已忽略', processed: '已处理', retried: '已重试', queued: '已安排', published: '已发布', expired: '已过期', skipped: '已跳过', failed: '处理失败', succeeded: '处理成功', count: '处理数量', total: '记录总数', status: '处理状态', id: '记录编号' };
export function parseResult(raw: unknown): ActionResult {
  const response = object(raw); const result = object(response.result);
  return { message: text(response.message) || '操作已完成。', details: Object.entries(resultLabels).flatMap(([key, label]) => {
    const value = scalar(result[key]); return value === null ? [] : [{ label, value: String(value) }];
  }) };
}
async function request(url: string, options: RequestInit, signal: AbortSignal): Promise<unknown> {
  const href = safeHref(url); if (!href) throw new ApiError('response');
  const controller = new AbortController(); let timedOut = false;
  const cancel = () => controller.abort();
  signal.addEventListener('abort', cancel, { once: true });
  if (signal.aborted) controller.abort();
  const timer = setTimeout(() => { timedOut = true; controller.abort(); }, REQUEST_TIMEOUT);
  const aborted = new Promise<never>((_, reject) => {
    const fail = () => reject(new ApiError(timedOut ? 'timeout' : 'cancelled'));
    if (controller.signal.aborted) fail(); else controller.signal.addEventListener('abort', fail, { once: true });
  });
  try {
    return await Promise.race([aborted, (async () => {
      const response = await fetch(href, { ...options, credentials: 'same-origin', signal: controller.signal,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...options.headers } });
      if ([401, 419].includes(response.status) || response.redirected || response.headers.get('content-type')?.includes('text/html')) throw new ApiError('expired');
      if (!response.ok) {
        const codes: Record<number, string> = { 403: 'forbidden', 404: 'unavailable', 409: 'conflict', 422: 'invalid', 429: 'unavailable', 503: 'unavailable' };
        throw new ApiError(codes[response.status] || 'server');
      }
      try { return await response.json(); } catch { throw new ApiError('response'); }
    })()]);
  } catch (error) {
    if (error instanceof ApiError) throw error;
    throw new ApiError(timedOut ? 'timeout' : signal.aborted ? 'cancelled' : 'network');
  } finally { clearTimeout(timer); signal.removeEventListener('abort', cancel); }
}
export async function loadData(config: WorkbenchConfig, query: Query, signal: AbortSignal) {
  const url = new URL(config.endpoints.data, location.href);
  url.searchParams.set('page', String(query.page)); url.searchParams.set('state', query.state); url.searchParams.set('search', query.search.trim());
  return parseData(await request(url.href, { method: 'GET' }, signal), config.section);
}
export async function performAction(config: WorkbenchConfig, action: Action, id: Row['id'] | undefined, signal: AbortSignal) {
  const body = id === undefined ? { action: action.key } : { action: action.key, id };
  return parseResult(await request(config.endpoints.action, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': config.csrf }, body: JSON.stringify(body) }, signal));
}
