import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { createWorkbenchBridge, type WorkbenchBridge } from './mount';
import { fixtureConfig, fixtureData, json } from './fixtures';
import type { Section } from './types';
let bridge: WorkbenchBridge | undefined;
const callbacks: (() => void)[] = [];
function content(section: Section) {
  const host = document.createElement('div'); const config = document.createElement('script'); config.id = 'admin-workbench-config'; config.type = 'application/json'; config.textContent = JSON.stringify(fixtureConfig(section));
  const root = document.createElement('div'); root.id = 'admin-workbench'; host.append(config, root); return host;
}
afterEach(() => { bridge?.dispose(); bridge = undefined; window.__dujiaokaAdminBridge?.dispose(); delete window.__dujiaokaAdminBridge; delete window.Dcat; document.body.replaceChildren(); callbacks.splice(0); vi.unstubAllGlobals(); vi.restoreAllMocks(); });

describe('Dcat-ready and PJAX workbench lifecycle', () => {
  it('mounts once, unmounts on native CRUD navigation, and remounts after PJAX replacement', async () => {
    const fetch = vi.fn().mockImplementation((url: string) => Promise.resolve(json(fixtureData(url.includes('paypal') ? 'paypal' : 'overview')))); vi.stubGlobal('fetch', fetch);
    window.Dcat = { ready: callback => callback(), onPjaxLoaded: (callback, once) => { expect(once).toBe(false); callbacks.push(callback); } };
    const nativeMenu = document.createElement('nav'); nativeMenu.id = 'legacy-menu'; const container = document.createElement('div'); container.id = 'pjax-container'; container.append(content('overview')); document.body.append(nativeMenu, container);
    bridge = createWorkbenchBridge(); bridge.refresh(); callbacks.forEach(callback => callback()); await flushPromises(); expect(fetch).toHaveBeenCalledTimes(1); expect(container.querySelectorAll('.wb')).toHaveLength(1);
    const oldRoot = container.querySelector('#admin-workbench')!; container.replaceChildren(document.createElement('form')); await flushPromises(); expect(oldRoot.querySelector('.wb')).toBeNull(); expect(document.getElementById('legacy-menu')).toBe(nativeMenu);
    container.replaceChildren(content('paypal')); callbacks.forEach(callback => callback()); await flushPromises(); bridge.refresh(); expect(fetch).toHaveBeenCalledTimes(2); expect(container.querySelector('h1')?.textContent).toContain('PayPal 收款'); expect(container.querySelectorAll('.wb')).toHaveLength(1);
  });
  it('handles same-node configuration replacement and aborts the detached page request', async () => {
    const fetch = vi.fn().mockImplementation(() => new Promise(() => undefined)); vi.stubGlobal('fetch', fetch); const host = content('stripe'); document.body.append(host); bridge = createWorkbenchBridge(); await flushPromises();
    host.querySelector('#admin-workbench-config')!.textContent = JSON.stringify(fixtureConfig('runtime')); await flushPromises(); expect(fetch).toHaveBeenCalledTimes(2); expect(fetch.mock.calls[0][1].signal.aborted).toBe(true); expect(host.querySelector('h1')?.textContent).toContain('运行状态'); bridge.dispose(); expect(fetch.mock.calls[1][1].signal.aborted).toBe(true);
  });
  it('keeps malformed JSON inert and recovers when a valid page arrives', async () => {
    const fetch = vi.fn().mockResolvedValue(json(fixtureData('stripe'))); vi.stubGlobal('fetch', fetch); const host = content('stripe'); host.querySelector('script')!.textContent = '{bad'; document.body.append(host); bridge = createWorkbenchBridge(); await flushPromises(); expect(host.querySelector('[role=alert]')?.textContent).toContain('页面信息暂未就绪'); expect(fetch).not.toHaveBeenCalled(); host.querySelector('script')!.textContent = JSON.stringify(fixtureConfig()); await flushPromises(); expect(fetch).toHaveBeenCalledTimes(1);
  });
  it('does not duplicate observers or apps when the global IIFE entry executes again', async () => {
    const fetch = vi.fn().mockResolvedValue(json(fixtureData('stripe'))); vi.stubGlobal('fetch', fetch); document.body.append(content('stripe')); await import('./main'); await flushPromises(); const first = window.__dujiaokaAdminBridge;
    vi.resetModules(); await import('./main'); await flushPromises(); expect(window.__dujiaokaAdminBridge).toBe(first); expect(document.querySelectorAll('.wb')).toHaveLength(1); expect(fetch).toHaveBeenCalledTimes(1);
  });
});
