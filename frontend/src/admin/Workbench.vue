<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { businessCell, errorText, featureLabels, formatCell, loginHref, pageMeta, sectionStates, stateLabels } from './presentation';
import type { Action, Card, Row, Scalar, WorkbenchConfig } from './types';
import { useWorkbench } from './useWorkbench';

const props = defineProps<{ config: WorkbenchConfig }>();
const { data, query, searchDraft, loading, acting, error, actionError, updatedAt, confirmation, result, expired,
  canAct, load, filter, reset, page, prepare, close, execute } = useWorkbench(props.config);
const meta = computed(() => pageMeta[props.config.section]);
const trendMode = ref<'sales' | 'orders'>('sales');
const trend = computed(() => data.value?.trend || []);
const trendMaximum = computed(() => {
  const maximum = Math.max(1, ...trend.value.map(point => point[trendMode.value]));
  return trendMode.value === 'orders' ? Math.ceil(maximum / 3) * 3 : maximum;
});
const trendTotal = computed(() => trend.value.reduce((sum, point) => sum + point[trendMode.value], 0));
const chartPoints = computed(() => trend.value.map((point, index) => ({
  x: 65 + index * 885 / Math.max(1, trend.value.length - 1),
  y: 195 - point[trendMode.value] * 150 / trendMaximum.value, label: point.day.slice(5).replace('-', '/'), value: point[trendMode.value],
})));
const chartLine = computed(() => chartPoints.value.map(point => point.x + ',' + point.y).join(' '));
const chartArea = computed(() => chartPoints.value.length ? 'M ' + chartPoints.value[0].x + ' 195 L ' + chartPoints.value.map(point => point.x + ' ' + point.y).join(' L ') + ' L ' + chartPoints.value[chartPoints.value.length - 1].x + ' 195 Z' : '');
const navigationOpen = ref(false); const dialog = ref<HTMLElement>(); let trigger: HTMLElement | null = null;
const hasRowActions = computed(() => !!data.value?.rows.some(row => row.actions.length));
const moduleLabel = computed(() => !data.value ? '正在确认' : !data.value.module.installed ? '待配置' : !data.value.module.enabled ? '已停用' : '已更新');
const emptyTitle = computed(() => !data.value?.module.installed ? '此功能尚未就绪' : !data.value.module.enabled ? '此功能已停用' : query.search || query.state !== 'all' ? '没有找到符合条件的记录' : '暂无记录');
const emptyHint = computed(() => !data.value?.module.installed || !data.value?.module.enabled ? '配置完成并启用后，这里将显示业务记录。' : query.search || query.state !== 'all' ? '试试其他订单号，或清除筛选条件。' : '新记录产生后会显示在这里，可稍后刷新查看。');
const visibleCards = computed<Card[]>(() => {
  if (!data.value) return [];
  if (data.value.cards.length) return data.value.cards;
  const available = data.value.module.installed;
  return [
    { key: 'filtered_total', label: meta.value.table + '总数', value: available || data.value.pagination.total > 0 ? data.value.pagination.total : null, hint: '符合当前筛选的记录', tone: 'neutral' },
    { key: 'page_records', label: '本页记录', value: data.value.rows.length, hint: '第 ' + data.value.pagination.page + ' / ' + data.value.pagination.last_page + ' 页', tone: 'neutral' },
    { key: 'module_state', label: '功能状态', value: !available ? '待配置' : data.value.module.enabled ? '已启用' : '已停用', hint: '以最近一次读取为准', tone: available && data.value.module.enabled ? 'good' : 'warn' },
    { key: 'operation_access', label: '处理权限', value: props.config.canOperate && props.config.section !== 'quotes' ? '可处理' : '仅查看', hint: '业务操作前需确认', tone: 'neutral' },
  ];
});
const displayCard = (card: Card) => card.value === null ? '—' : typeof card.value === 'number' ? card.value.toLocaleString('zh-CN', { maximumFractionDigits: 2 }) : card.value;
const cell = (row: Row, key: string) => row[key] as Scalar | undefined;
const statusClass = (value: unknown) => typeof value === 'string' && ['pending', 'failed', 'completed', 'reserved', 'manual', 'expired', 'open', 'closed'].includes(value) ? value : 'unknown';
function openAction(action: Action, event: Event, row?: Row) { trigger = event.currentTarget as HTMLElement; prepare(action, row); }
function closeDialog() { close(); if (!confirmation.value) void nextTick(() => trigger?.isConnected && trigger.focus()); }
function dialogKey(event: KeyboardEvent) {
  if (event.key === 'Escape') { event.preventDefault(); closeDialog(); }
  if (event.key !== 'Tab') return;
  const controls = Array.from(dialog.value?.querySelectorAll<HTMLElement>('button:not(:disabled), a[href]') || []);
  if (!controls.length) { event.preventDefault(); return; }
  const first = controls[0]; const last = controls[controls.length - 1];
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
}
watch([confirmation, result], () => { if (confirmation.value) void nextTick(() => dialog.value?.querySelector<HTMLElement>('button:not(:disabled)')?.focus()); });
</script>

<template>
  <main class="wb" aria-label="店铺运营中心">
    <header class="wb-heading">
      <div>
        <p class="wb-eyebrow"><span class="wb-dot"></span> 独角数卡 <span class="wb-slash">/</span> 运营中心</p>
        <h1>{{ meta.title }}<span class="wb-section-number">{{ meta.mark }}</span></h1>
        <p class="wb-intro">{{ meta.intro }}</p>
      </div>
      <div class="wb-heading-side">
        <span class="wb-account">{{ config.userName || '管理员' }}<span v-if="!config.canOperate" class="wb-readonly">只读账号</span></span>
        <button class="wb-button wb-button-light" type="button" :disabled="loading || acting || expired" @click="load">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M5.2 8a7.5 7.5 0 0 1 12.4-3L20 8M4 16l2.4 3A7.5 7.5 0 0 0 18.8 16"/></svg>
          {{ loading ? '刷新中' : '刷新数据' }}
        </button>
      </div>
    </header>

    <nav v-if="config.navigation.length" class="wb-navigation" aria-label="运营页面">
      <button class="wb-mobile-nav" type="button" :aria-expanded="navigationOpen" aria-controls="wb-navigation-links" @click="navigationOpen = !navigationOpen">{{ meta.title }} <span>{{ navigationOpen ? '收起导航 −' : '切换页面 +' }}</span></button>
      <div id="wb-navigation-links" class="wb-navigation-links" :class="{ 'is-open': navigationOpen }">
        <a v-for="link in config.navigation" :key="link.key" :href="link.href" :aria-current="link.key === config.section ? 'page' : undefined" :class="{ 'is-active': link.key === config.section }"><span class="wb-nav-dot"></span>{{ link.label }}</a>
      </div>
    </nav>

    <div v-if="error" class="wb-message wb-message-error" role="alert">
      <div><strong>{{ expired ? '请重新登录' : '数据加载未完成' }}</strong><p>{{ errorText(error) }}</p></div>
      <a v-if="expired" class="wb-button wb-button-primary" :href="loginHref(config.endpoints.data)" data-pjax="0">重新登录</a>
      <button v-else type="button" class="wb-button wb-button-light" :disabled="loading" @click="load">重新加载</button>
    </div>
    <div v-if="data && (!data.module.installed || !data.module.enabled)" class="wb-message wb-message-warn" role="status"><span class="wb-message-icon">!</span><div><strong>{{ data.module.installed ? '此功能已停用' : '此功能尚未就绪' }}</strong><p>{{ data.module.message || emptyHint }}</p></div></div>
    <div v-if="!config.canOperate || config.section === 'quotes'" class="wb-readonly-note"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 11h12v10H6zM8 11V7a4 4 0 0 1 8 0v4"/></svg>{{ config.section === 'quotes' ? '报价快照保留订单创建时的金额与汇率，仅供查看。' : '当前账号可以查看记录，业务操作由有权限的管理员处理。' }}</div>

    <section v-if="loading && !data" class="wb-metrics" aria-label="正在加载经营指标" aria-busy="true"><div v-for="n in 4" :key="n" class="wb-metric wb-skeleton"><span></span><span></span><span></span></div></section>
    <section v-else-if="visibleCards.length" class="wb-metrics" aria-label="经营指标">
      <article v-for="(card, index) in visibleCards" :key="card.key" class="wb-metric" :class="[{ 'wb-metric-featured': index === 0 }, 'wb-tone-' + card.tone]">
        <div class="wb-metric-top"><h2>{{ card.label }}</h2><span class="wb-metric-mark" aria-hidden="true">{{ index === 0 ? '↗' : '·' }}</span></div>
        <p class="wb-metric-value">{{ displayCard(card) }}</p><p class="wb-metric-hint"><span class="wb-dot"></span>{{ card.hint || '暂无补充信息' }}</p>
      </article>
    </section>

    <section v-if="config.section === 'overview' && trend.length" class="wb-panel wb-trend" aria-labelledby="wb-trend-title">
      <div class="wb-panel-heading"><div><p class="wb-eyebrow">经营变化</p><h2 id="wb-trend-title">近 {{ trend.length }} 日趋势</h2></div><div class="wb-chart-tabs" aria-label="趋势指标"><button type="button" :aria-pressed="trendMode === 'sales'" :class="{ 'is-active': trendMode === 'sales' }" @click="trendMode = 'sales'">销售额</button><button type="button" :aria-pressed="trendMode === 'orders'" :class="{ 'is-active': trendMode === 'orders' }" @click="trendMode = 'orders'">订单数</button></div></div>
      <div class="wb-trend-summary"><span>{{ trendMode === 'sales' ? '累计销售额' : '累计订单' }}</span><strong>{{ formatCell(trendTotal, trendMode === 'sales' ? 'money' : 'number') }}</strong><small>{{ trend[0].day }} — {{ trend[trend.length - 1].day }}</small></div>
      <div class="wb-chart"><svg viewBox="0 0 1000 250" role="img" :aria-label="'近 ' + trend.length + ' 日' + (trendMode === 'sales' ? '销售额' : '订单数') + '趋势，完整数值见下方数据表'"><g v-for="level in [0, 1, 2, 3]" :key="level"><line x1="65" x2="950" :y1="195 - level * 50" :y2="195 - level * 50" class="wb-chart-grid"/><text x="48" :y="200 - level * 50" text-anchor="end" class="wb-chart-label">{{ formatCell(trendMaximum * level / 3, trendMode === 'sales' ? 'money' : 'number') }}</text></g><path :d="chartArea" class="wb-chart-area"/><polyline :points="chartLine" class="wb-chart-line"/><g v-for="(point, index) in chartPoints" :key="index"><circle :cx="point.x" :cy="point.y" r="4" class="wb-chart-point"><title>{{ trend[index].day }}：{{ formatCell(point.value, trendMode === 'sales' ? 'money' : 'number') }}</title></circle><text :x="point.x" y="228" text-anchor="middle" class="wb-chart-label">{{ point.label }}</text></g></svg></div>
      <details class="wb-trend-details"><summary>查看每日数据</summary><div class="wb-table-scroll"><table class="wb-table"><thead><tr><th scope="col">日期</th><th scope="col">销售额</th><th scope="col">订单数</th></tr></thead><tbody><tr v-for="point in trend" :key="point.day"><td>{{ point.day }}</td><td>{{ formatCell(point.sales, 'money') }}</td><td>{{ formatCell(point.orders, 'number') }}</td></tr></tbody></table></div></details>
    </section>

    <div v-if="data && (data.features.length || data.runtime.length)" class="wb-status-layout" :class="{ 'wb-status-single': !data.features.length || !data.runtime.length }">
      <section v-if="data.features.length" class="wb-panel wb-feature-panel" aria-labelledby="wb-feature-title"><div class="wb-panel-heading"><div><p class="wb-eyebrow">店铺服务</p><h2 id="wb-feature-title">功能状态</h2></div><span class="wb-caption">{{ data.features.length }} 项服务</span></div>
        <div class="wb-feature-list"><article v-for="feature in data.features" :key="feature.key" class="wb-feature"><span class="wb-feature-indicator" :class="{ 'is-on': feature.installed && feature.enabled }"></span><strong>{{ featureLabels[feature.key] || feature.label }}</strong><span class="wb-chip" :class="feature.installed && feature.enabled ? 'wb-chip-good' : 'wb-chip-muted'">{{ !feature.installed ? '待配置' : feature.enabled ? '已启用' : '已停用' }}</span></article></div>
      </section>
      <section v-if="data.runtime.length" class="wb-panel" aria-labelledby="wb-runtime-title"><div class="wb-panel-heading"><div><p class="wb-eyebrow">运行情况</p><h2 id="wb-runtime-title">服务信息</h2></div><span class="wb-caption">最近一次读取</span></div>
        <dl class="wb-runtime-list"><div v-for="(item, index) in data.runtime" :key="index"><dt>{{ item.label }}</dt><dd>{{ item.value || '待确认' }}<small v-if="item.hint">{{ item.hint }}</small></dd></div></dl>
      </section>
    </div>

    <section class="wb-panel wb-records" aria-labelledby="wb-records-title" :aria-busy="loading">
      <div class="wb-panel-heading wb-records-heading"><div><p class="wb-eyebrow">业务明细</p><h2 id="wb-records-title">{{ meta.table }}<span v-if="data" class="wb-total">{{ data.pagination.total.toLocaleString('zh-CN') }}</span></h2></div><span class="wb-chip" :class="data?.module.installed && data?.module.enabled ? 'wb-chip-good' : 'wb-chip-muted'"><span class="wb-dot"></span>{{ moduleLabel }}</span></div>
      <div class="wb-filters">
        <form class="wb-search" role="search" @submit.prevent="filter()"><label for="wb-search-input" class="wb-sr-only">{{ meta.search }}</label><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><input id="wb-search-input" v-model="searchDraft" type="search" maxlength="120" :placeholder="meta.search" :disabled="acting || expired" autocomplete="off"><button class="wb-button wb-button-primary" type="submit" :disabled="acting || expired">搜索</button></form>
        <div class="wb-filter-controls"><label for="wb-state" class="wb-sr-only">记录状态</label><select id="wb-state" :value="query.state" :disabled="acting || expired" @change="filter(($event.target as HTMLSelectElement).value as typeof query.state)"><option v-for="state in sectionStates[config.section]" :key="state" :value="state">{{ stateLabels[state] }}</option></select><button v-if="query.search || query.state !== 'all'" class="wb-button wb-button-plain" type="button" :disabled="acting || expired" @click="reset">清除筛选</button></div>
      </div>
      <div v-if="data?.bulkActions.length" class="wb-bulk-bar"><p>批量处理符合条件的记录</p><button v-for="action in data.bulkActions" :key="action.key" type="button" class="wb-button wb-button-small" :class="action.key === 'expire' ? 'wb-button-danger' : 'wb-button-light'" :disabled="!canAct(action)" :title="!config.canOperate ? '当前账号为只读账号' : !action.enabled ? '当前操作暂未开放' : undefined" @click="openAction(action, $event)">{{ action.label }}</button></div>
      <div v-if="loading" class="wb-table-loading" role="status"><span class="wb-spinner"></span><p>正在读取{{ meta.table }}…</p><div v-for="n in 3" :key="n" class="wb-skeleton-line"></div></div>
      <div v-else-if="error" class="wb-empty"><div class="wb-empty-symbol" aria-hidden="true">!</div><h3>{{ expired ? '登录后继续查看' : '记录暂未加载' }}</h3><p>{{ errorText(error) }}</p></div>
      <div v-else-if="!data?.rows.length || !data.columns.length" class="wb-empty"><div class="wb-empty-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3h10l3 4v14H4V7zM4 8h16M8 13h8M8 17h5"/></svg></div><h3>{{ data?.rows.length && !data.columns.length ? '明细字段待确认' : emptyTitle }}</h3><p>{{ data?.rows.length && !data.columns.length ? '请稍后重新加载查看。' : emptyHint }}</p><button v-if="query.search || query.state !== 'all'" type="button" class="wb-button wb-button-light" @click="reset">清除筛选</button></div>
      <div v-else class="wb-table-scroll" tabindex="0" :aria-label="meta.table + '，可横向滚动'"><table class="wb-table"><thead><tr><th v-for="column in data.columns" :key="column.key" scope="col" :class="{ 'wb-align-right': ['money', 'number'].includes(column.format) }">{{ column.label }}</th><th v-if="hasRowActions" scope="col" class="wb-actions-column">操作</th></tr></thead><tbody><tr v-for="row in data.rows" :key="String(row.kind || 'record') + ':' + row.id"><td v-for="column in data.columns" :key="column.key" :class="[{ 'wb-align-right': ['money', 'number'].includes(column.format), 'wb-cell-identifier': ['order_sn', 'event_id', 'provider_order_id', 'uuid'].includes(column.key), 'wb-cell-time': column.format === 'time' }]"><span v-if="column.format === 'status'" class="wb-row-status" :class="'wb-status-' + statusClass(cell(row, column.key))"><span class="wb-dot"></span>{{ formatCell(cell(row, column.key), column.format) }}</span><span v-else>{{ businessCell(column.key, cell(row, column.key), column.format) }}</span></td><td v-if="hasRowActions" class="wb-actions-column"><button v-for="action in row.actions" :key="action.key" class="wb-row-action" type="button" :disabled="!canAct(action)" :aria-label="action.label + '，记录 ' + (row.order_sn || row.id)" @click="openAction(action, $event, row)">{{ action.label }}<span aria-hidden="true"> ↗</span></button><span v-if="!row.actions.length" class="wb-caption">—</span></td></tr></tbody></table></div>
      <footer v-if="data" class="wb-table-footer"><p>{{ updatedAt ? '更新于 ' + updatedAt : '等待更新' }} <span>·</span> 每页 {{ data.pagination.per_page }} 条</p><nav class="wb-pagination" aria-label="明细分页"><button class="wb-button wb-button-light wb-button-small" type="button" :disabled="loading || acting || expired || data.pagination.page <= 1" @click="page(data.pagination.page - 1)">上一页</button><span aria-live="polite">{{ data.pagination.page }} <span>/</span> {{ data.pagination.last_page }}</span><button class="wb-button wb-button-light wb-button-small" type="button" :disabled="loading || acting || expired || data.pagination.page >= data.pagination.last_page" @click="page(data.pagination.page + 1)">下一页</button></nav></footer>
    </section>

    <aside v-if="config.management.length" class="wb-management" aria-label="日常管理"><p>日常管理<span>商品、订单与店铺设置</span></p><div><a v-for="link in config.management" :key="link.key" :href="link.href">{{ link.label }} <span aria-hidden="true">↗</span></a></div></aside>
    <footer class="wb-page-footer"><span>独角数卡 · 运营中心</span><span>数据以最近一次读取为准</span></footer>

    <div v-if="confirmation" class="wb-modal-backdrop" @click.self="closeDialog"><section ref="dialog" class="wb-dialog" role="dialog" aria-modal="true" aria-labelledby="wb-dialog-title" aria-describedby="wb-dialog-description" :aria-busy="acting" @keydown="dialogKey">
      <template v-if="result"><span class="wb-dialog-symbol wb-dialog-success" aria-hidden="true">✓</span><p class="wb-eyebrow">处理结果</p><h2 id="wb-dialog-title">操作已完成</h2><p id="wb-dialog-description">{{ result.message }}</p><dl v-if="result.details.length" class="wb-result-details"><div v-for="detail in result.details" :key="detail.label"><dt>{{ detail.label }}</dt><dd>{{ detail.value }}</dd></div></dl><button type="button" class="wb-button wb-button-primary" :disabled="acting" @click="closeDialog">返回明细</button></template>
      <template v-else><span class="wb-dialog-symbol" :class="{ 'wb-dialog-danger': confirmation.action.key === 'expire' }" aria-hidden="true">{{ confirmation.action.key === 'expire' ? '!' : '↗' }}</span><p class="wb-eyebrow">确认业务操作</p><h2 id="wb-dialog-title">{{ confirmation.action.label }}？</h2><p id="wb-dialog-description">{{ confirmation.action.key === 'expire' ? '此操作将改变到期记录的状态，请确认后继续。' : '此操作将处理符合条件的记录，请确认后继续。' }}</p><div class="wb-confirm-context">{{ confirmation.context }}</div><p v-if="actionError" class="wb-action-error" role="alert">{{ errorText(actionError) }}</p><div class="wb-dialog-actions"><button type="button" class="wb-button wb-button-light" :disabled="acting" @click="closeDialog">取消</button><a v-if="expired" class="wb-button wb-button-primary" :href="loginHref(config.endpoints.data)" data-pjax="0">重新登录</a><button v-else type="button" class="wb-button" :class="confirmation.action.key === 'expire' ? 'wb-button-danger-solid' : 'wb-button-primary'" :disabled="acting || loading || !canAct(confirmation.action)" @click="execute">{{ acting ? '正在处理…' : '确认' + confirmation.action.label }}</button></div></template>
    </section></div>
  </main>
</template>
