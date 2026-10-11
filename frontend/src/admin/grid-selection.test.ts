import { afterEach, describe, expect, it } from 'vitest';
import { createGridSelectionBridge, type GridSelectionBridge } from './grid-selection';

const bridges: GridSelectionBridge[] = [];
function table(id: string) {
  const root = document.createElement('div');
  root.innerHTML = '<table id="' + id + '"><thead><tr><th class="checkbox-grid-header"><input type="checkbox" checked></th></tr></thead><tbody>' +
    [1, 2].map(key => '<tr><td class="checkbox-grid-column"><input type="checkbox" data-id="' + key + '" checked></td></tr>').join('') + '</tbody></table>';
  document.body.append(root); return root;
}
afterEach(() => { bridges.splice(0).forEach(bridge => bridge.dispose()); document.body.replaceChildren(); });

describe('原生列表选择增强', () => {
  it('取消选择清空原始行与表头，同时发出原生状态事件', () => {
    const root = table('goods'); const bridge = createGridSelectionBridge(); bridges.push(bridge); bridge.refresh();
    let events = 0; root.addEventListener('change', () => events++);
    expect(root.querySelector('[role=status]')?.textContent).toBe('已选择 2 项');
    root.querySelector<HTMLButtonElement>('button')!.click();
    expect(root.querySelectorAll('input:checked')).toHaveLength(0); expect(events).toBe(3);
    expect(root.querySelector<HTMLElement>('.admin-selection-toolbar')!.hidden).toBe(true);
  });

  it('PJAX保留checked状态时同步批量菜单，而不依赖再次触发change', () => {
    const root = table('goods');
    root.querySelector('thead input')!.classList.add('grid-select-all');
    const menu = document.createElement('div'); menu.className = 'grid-select-all-btn'; menu.style.display = 'none';
    menu.innerHTML = '<button><span class="selected"></span></button>'; root.prepend(menu);
    const bridge = createGridSelectionBridge(); bridges.push(bridge); bridge.refresh();
    expect(menu.style.display).toBe('inline-flex'); expect(menu.textContent).toBe('批量操作');
    root.querySelector<HTMLButtonElement>('.admin-selection-toolbar button')!.click();
    expect(menu.style.display).toBe('none');
  });

  it('多个列表选择独立，重复PJAX刷新不增加工具条，替换旧表会清理监听', () => {
    const goods = table('goods'), pays = table('pays'); const bridge = createGridSelectionBridge(); bridges.push(bridge);
    bridge.refresh(); bridge.refresh(); expect(document.querySelectorAll('.admin-selection-toolbar')).toHaveLength(2);
    goods.querySelector<HTMLButtonElement>('button')!.click(); expect(pays.querySelectorAll('input:checked')).toHaveLength(3);
    goods.querySelector('table')!.remove(); bridge.refresh(); expect(goods.querySelector('button')).toBeNull();
    bridge.dispose(); expect(document.querySelectorAll('.admin-selection-toolbar')).toHaveLength(0);
  });
});
