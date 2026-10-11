interface EditorThemeBridge { refresh: () => void; dispose: () => void }
type JQuery = (element: Document) => { on: (events: string, handler: () => void) => unknown; off: (events: string, handler: () => void) => unknown };

// TinyMCE content lives in a separate document, beyond the shell's CSS scope.
export function createEditorThemeBridge(): EditorThemeBridge {
  const frames = new Map<HTMLIFrameElement, () => void>();
  const jquery = (window as Window & { jQuery?: JQuery }).jQuery;
  let disposed = false;
  function refresh() {
    if (disposed) return;
    for (const [frame, handler] of frames) if (!frame.isConnected) { frame.removeEventListener('load', handler); frames.delete(frame); }
    const palette = getComputedStyle(document.body);
    const background = palette.getPropertyValue('--admin-input').trim();
    const ink = palette.getPropertyValue('--admin-ink').trim();
    if (!background || !ink) return;
    for (const frame of document.querySelectorAll<HTMLIFrameElement>('iframe.tox-edit-area__iframe')) {
      if (!frames.has(frame)) { const handler = () => refresh(); frame.addEventListener('load', handler); frames.set(frame, handler); }
      try {
        const doc = frame.contentDocument;
        if (!doc?.head) continue;
        let style = doc.querySelector<HTMLStyleElement>('style[data-admin-editor-theme]');
        if (!style) { style = doc.createElement('style'); style.setAttribute('data-admin-editor-theme', ''); doc.head.append(style); }
        const text = 'body{background:' + background + ';color:' + ink + ';}';
        if (style.textContent !== text) style.textContent = text;
      } catch { /* Only editors in this page's origin can receive the palette. */ }
    }
  }
  const events = 'dark-mode.shown.dujiaokaEditor dark-mode.hide.dujiaokaEditor';
  jquery?.(document).on(events, refresh);
  return { refresh, dispose: () => { disposed = true; jquery?.(document).off(events, refresh); for (const [frame, handler] of frames) frame.removeEventListener('load', handler); frames.clear(); } };
}
