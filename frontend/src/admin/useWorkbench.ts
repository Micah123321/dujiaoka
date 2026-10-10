import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { ApiError, loadData, performAction } from './api';
import type { Action, ActionResult, Query, Row, State, WorkbenchConfig, WorkbenchData } from './types';

export function useWorkbench(config: WorkbenchConfig) {
  const data = ref<WorkbenchData | null>(null);
  const query = reactive<Query>({ page: 1, state: 'all', search: '' });
  const searchDraft = ref(''); const loading = ref(false); const acting = ref(false);
  const error = ref(''); const actionError = ref(''); const updatedAt = ref('');
  const confirmation = ref<{ action: Action; id?: Row['id']; context: string } | null>(null);
  const result = ref<ActionResult | null>(null);
  let readController: AbortController | undefined; let actionController: AbortController | undefined;
  let generation = 0; let disposed = false;
  const expired = computed(() => error.value === 'expired' || actionError.value === 'expired');
  const moduleReady = computed(() => !!data.value?.module.installed && !!data.value?.module.enabled);
  const locked = computed(() => !config.canOperate || !moduleReady.value || loading.value || acting.value || !!error.value || expired.value);
  const canAct = (action: Action) => action.enabled && !locked.value;

  async function load() {
    if (disposed || expired.value) return;
    const current = ++generation;
    readController?.abort(); readController = new AbortController();
    loading.value = true; error.value = '';
    try {
      const response = await loadData(config, { ...query }, readController.signal);
      if (disposed || current !== generation) return;
      data.value = response; query.page = response.pagination.page;
      updatedAt.value = new Date().toLocaleTimeString('zh-CN', { hour12: false });
    } catch (cause) {
      if (disposed || current !== generation) return;
      const code = cause instanceof ApiError ? cause.code : 'server';
      if (code !== 'cancelled') error.value = code;
    } finally { if (!disposed && current === generation) loading.value = false; }
  }
  function filter(state?: State) {
    if (acting.value || expired.value) return;
    if (state !== undefined) query.state = state;
    query.search = searchDraft.value.trim().slice(0, 120); query.page = 1; void load();
  }
  function reset() {
    if (acting.value || expired.value) return;
    searchDraft.value = ''; query.search = ''; query.state = 'all'; query.page = 1; void load();
  }
  function page(number: number) {
    if (loading.value || acting.value || expired.value || !data.value || number < 1 || number > data.value.pagination.last_page) return;
    query.page = number; void load();
  }
  function prepare(action: Action, row?: Row) {
    if (!canAct(action)) return;
    actionError.value = ''; result.value = null;
    const context = row ? String(row.order_sn || row.event_id || row.provider_order_id || row.id) : '当前符合条件的记录';
    confirmation.value = { action, id: row?.id, context };
  }
  function close() {
    if (acting.value) return;
    confirmation.value = null; result.value = null; actionError.value = '';
  }
  async function execute() {
    const pending = confirmation.value;
    if (!pending || !canAct(pending.action)) return;
    acting.value = true; actionError.value = ''; actionController = new AbortController();
    try {
      const response = await performAction(config, pending.action, pending.id, actionController.signal);
      if (disposed) return;
      result.value = response;
      await load();
    } catch (cause) {
      if (disposed) return;
      const code = cause instanceof ApiError ? cause.code : 'server';
      if (code !== 'cancelled') actionError.value = code;
    } finally { if (!disposed) acting.value = false; }
  }
  onMounted(() => void load());
  onBeforeUnmount(() => { disposed = true; ++generation; readController?.abort(); actionController?.abort(); });
  return { data, query, searchDraft, loading, acting, error, actionError, updatedAt, confirmation, result, expired, moduleReady, locked,
    canAct, load, filter, reset, page, prepare, close, execute };
}
