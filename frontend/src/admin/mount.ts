import { createApp, type App } from 'vue';
import Workbench from './Workbench.vue';
import { parseConfig } from './api';
import { createFormGuideBridge } from './form-guide';
import { createGridSelectionBridge } from './grid-selection';
import { createEditorThemeBridge } from './editor-theme';

export interface WorkbenchBridge { refresh: () => void; dispose: () => void }
declare global {
  interface Window {
    Dcat?: { ready?: (callback: () => void) => unknown; onPjaxLoaded?: (callback: () => void, once?: boolean) => unknown };
    __dujiaokaAdminBridge?: WorkbenchBridge;
  }
}
export function createWorkbenchBridge(): WorkbenchBridge {
  let app: App | undefined; let root: HTMLElement | null = null; let payload = ''; let disposed = false;
  const forms = createFormGuideBridge();
  const grids = createGridSelectionBridge();
  const editors = createEditorThemeBridge();
  function unmount() { app?.unmount(); app = undefined; root = null; payload = ''; }
  function refresh() {
    if (disposed) return;
    document.body?.classList.add('dujiaoka-admin');
    forms.refresh();
    grids.refresh();
    editors.refresh();
    const nextRoot = document.getElementById('admin-workbench');
    document.body?.classList.toggle('dujiaoka-workbench', !!nextRoot);
    const nextPayload = document.getElementById('admin-workbench-config')?.textContent || '';
    if (nextRoot === root && nextPayload === payload) return;
    unmount();
    if (!nextRoot) return;
    root = nextRoot; payload = nextPayload;
    try {
      const config = parseConfig(JSON.parse(nextPayload));
      app = createApp(Workbench, { config }); app.mount(nextRoot);
    } catch {
      const message = document.createElement('p'); message.setAttribute('role', 'alert');
      message.textContent = '页面信息暂未就绪，请刷新页面重试。'; nextRoot.replaceChildren(message);
    }
  }
  // Dcat replaces the content node during PJAX. Observe replacement, not only the initial DOM-ready event.
  const observer = new MutationObserver(refresh);
  observer.observe(document.body || document.documentElement, { childList: true, subtree: true });
  document.addEventListener('DOMContentLoaded', refresh);
  window.Dcat?.ready?.(refresh);
  window.Dcat?.onPjaxLoaded?.(refresh, false);
  refresh();
  return { refresh, dispose: () => { disposed = true; forms.dispose(); grids.dispose(); editors.dispose(); observer.disconnect(); document.removeEventListener('DOMContentLoaded', refresh); document.body?.classList.remove('dujiaoka-workbench'); unmount(); } };
}
