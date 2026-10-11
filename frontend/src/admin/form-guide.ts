import { createApp, type App } from 'vue';
import FormGuide from './FormGuide.vue';
import './form-guide.css';

export type MerchantField = 'merchant_id' | 'merchant_key' | 'merchant_pem';
export interface Profile {
  label: string;
  note: string;
  checks: { value: string; label: string; method: number; client: number }[];
  fields: Record<MerchantField, { label: string; help: string; placeholder: string; required: boolean }>;
  notify: string;
}
export interface FormGuideConfig { kind: 'payment' | 'goods'; editing: boolean; catalog?: Record<string, Profile> }
export interface FormGuideBridge { refresh: () => void; dispose: () => void }

const merchantFields: MerchantField[] = ['merchant_id', 'merchant_key', 'merchant_pem'];
function record(value: unknown): value is Record<string, unknown> {
  return !!value && typeof value === 'object' && !Array.isArray(value);
}
export function parseFormGuideConfig(value: unknown): FormGuideConfig {
  if (!record(value) || (value.kind !== 'payment' && value.kind !== 'goods') || typeof value.editing !== 'boolean') {
    throw new Error('填写指导配置未就绪');
  }
  const catalog: Record<string, Profile> = Object.create(null);
  if (value.catalog !== undefined) {
    if (!record(value.catalog)) throw new Error('渠道信息未就绪');
    for (const [route, item] of Object.entries(value.catalog)) {
      if (!record(item) || typeof item.label !== 'string' || typeof item.note !== 'string' || typeof item.notify !== 'string' || !Array.isArray(item.checks) || !record(item.fields)) {
        throw new Error('渠道信息未就绪');
      }
      const checks: Profile['checks'] = item.checks.map(check => {
        if (!record(check) || typeof check.value !== 'string' || typeof check.label !== 'string' || !Number.isInteger(check.method) || !Number.isInteger(check.client)) {
          throw new Error('支付方式未就绪');
        }
        return { value: check.value, label: check.label, method: check.method as number, client: check.client as number };
      });
      if (new Set(checks.map(check => check.value)).size !== checks.length) throw new Error('支付标识重复');
      const fields = {} as Profile['fields'];
      for (const key of merchantFields) {
        const field = item.fields[key];
        if (!record(field) || typeof field.label !== 'string' || typeof field.help !== 'string' || typeof field.placeholder !== 'string' || typeof field.required !== 'boolean') {
          throw new Error('字段说明未就绪');
        }
        fields[key] = { label: field.label, help: field.help, placeholder: field.placeholder, required: field.required };
      }
      catalog[route] = { label: item.label, note: item.note, notify: item.notify, checks, fields };
    }
  }
  return { kind: value.kind, editing: value.editing, catalog };
}

// The main bridge owns ready/PJAX observation. Repeated refreshes keep each live root intact.
export function createFormGuideBridge(): FormGuideBridge {
  const mounted = new Map<HTMLElement, { app?: App; form: HTMLFormElement }>();
  let disposed = false;
  function refresh() {
    if (disposed) return;
    for (const [root, entry] of mounted) {
      if (!root.isConnected || root.closest('form') !== entry.form) {
        entry.app?.unmount();
        mounted.delete(root);
      }
    }
    for (const root of document.querySelectorAll<HTMLElement>('[data-admin-form-guide]')) {
      if (mounted.has(root)) continue;
      const form = root.closest('form');
      const script = form?.querySelector<HTMLScriptElement>('script[type="application/json"][data-form-guide-config]');
      if (!form || !script) continue;
      let app: App | undefined;
      try {
        const config = parseFormGuideConfig(JSON.parse(script.textContent || ''));
        app = createApp(FormGuide, { config, form });
        app.mount(root);
        mounted.set(root, { app, form });
      } catch {
        app?.unmount();
        const message = document.createElement('p');
        message.setAttribute('role', 'alert');
        message.className = 'fg-error';
        message.textContent = '填写指导暂未就绪，原生表单仍可使用。';
        root.replaceChildren(message);
        mounted.set(root, { form });
      }
    }
  }
  return { refresh, dispose: () => {
    disposed = true;
    for (const entry of mounted.values()) entry.app?.unmount();
    mounted.clear();
  } };
}
