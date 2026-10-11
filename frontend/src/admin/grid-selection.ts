export interface GridSelectionBridge { refresh: () => void; dispose: () => void }

type JQuery = (element: Element) => { on: (event: string, handler: () => void) => unknown; off: (event: string, handler: () => void) => unknown; trigger: (event: string) => unknown };
const rowSelector = '.checkbox-grid-column input[type="checkbox"][data-id]';

// Dcat reads the original checkboxes when a signed batch action is submitted.
export function createGridSelectionBridge(): GridSelectionBridge {
  const entries = new Map<HTMLTableElement, { toolbar: HTMLElement; refresh: () => void; dispose: () => void }>();
  let disposed = false;
  function refresh() {
    if (disposed) return;
    for (const [table, entry] of entries) {
      if (!table.isConnected) { entry.dispose(); entries.delete(table); }
    }
    for (const table of document.querySelectorAll<HTMLTableElement>('table')) {
      if (!table.querySelector(rowSelector)) continue;
      if (entries.has(table)) { entries.get(table)!.refresh(); continue; }
      const jquery = (window as Window & { jQuery?: JQuery }).jQuery;
      const header = table.querySelector<HTMLInputElement>('.checkbox-grid-header input[type="checkbox"]');
      const selectAllClass = Array.from(header?.classList || []).find(name => name.endsWith('-select-all'));
      const batch = selectAllClass ? document.getElementsByClassName(selectAllClass + '-btn')[0]?.querySelector<HTMLButtonElement>('button') : null;
      const batchLabel = document.createElement('span'); batchLabel.className = 'admin-batch-label'; batchLabel.textContent = '批量操作';
      if (batch && !batch.querySelector('.admin-batch-label')) batch.prepend(batchLabel);
      const toolbar = document.createElement('div');
      toolbar.className = 'admin-selection-toolbar'; toolbar.hidden = true;
      const count = document.createElement('span'); count.setAttribute('role', 'status'); count.setAttribute('aria-live', 'polite');
      const clear = document.createElement('button'); clear.type = 'button'; clear.className = 'btn btn-outline-primary'; clear.textContent = '取消全部选择';
      toolbar.append(count, clear);
      const rows = () => Array.from(table.querySelectorAll<HTMLInputElement>(rowSelector));
      const update = () => {
        const selected = rows().filter(input => input.checked).length;
        toolbar.hidden = selected === 0;
        if (batch?.parentElement) batch.parentElement.style.display = selected ? 'inline-flex' : 'none';
        const message = '已选择 ' + selected + ' 项';
        if (count.textContent !== message) count.textContent = message;
      };
      const notify = (input: HTMLInputElement) => {
        input.dispatchEvent(new Event('change', { bubbles: true }));
        jquery?.(input).trigger('change');
      };
      const reset = () => {
        for (const input of table.querySelectorAll<HTMLInputElement>('.checkbox-grid-header input[type="checkbox"]')) {
          input.checked = false; input.indeterminate = false; notify(input);
        }
        for (const input of rows()) {
          if (!input.checked) continue;
          input.checked = false; input.indeterminate = false; notify(input);
        }
        update();
      };
      clear.addEventListener('click', reset); table.addEventListener('change', update);
      jquery?.(table).on('change.dujiaokaSelection', update);
      entries.set(table, { toolbar, refresh: update, dispose: () => {
        clear.removeEventListener('click', reset); table.removeEventListener('change', update);
        jquery?.(table).off('change.dujiaokaSelection', update); batchLabel.remove(); toolbar.remove();
      } });
      (table.closest('.table-responsive') || table).insertAdjacentElement('beforebegin', toolbar);
      update();
    }
  }
  return { refresh, dispose: () => { disposed = true; for (const entry of entries.values()) entry.dispose(); entries.clear(); } };
}
