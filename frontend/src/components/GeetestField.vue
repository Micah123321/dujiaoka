<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue';
interface Validation { geetest_challenge: string; geetest_validate: string; geetest_seccode: string }
interface Captcha { appendTo: (node: HTMLElement) => void; onReady: (callback: () => void) => void; onSuccess: (callback: () => void) => void; onError?: (callback: () => void) => void; getValidate: () => Validation | false; destroy?: () => void }
declare global { interface Window { initGeetest?: (options: Record<string, unknown>, callback: (captcha: Captcha) => void) => void } }
const props = defineProps<{ url: string; language: string }>();
const emit = defineEmits<{ valid: [value: boolean] }>();
const container = ref<HTMLElement>();
const status = ref('正在加载行为验证…');
const validation = ref<Validation>();
let captcha: Captcha | undefined;
let stopped = false;
const controller = new AbortController();
let script: HTMLScriptElement | undefined;
async function load() {
  try {
    if (!window.initGeetest) await new Promise<void>((resolve,reject) => {
      script = document.createElement('script'); script.src = 'https://static.geetest.com/static/tools/gt.js'; script.async = true;
      script.onload = () => resolve(); script.onerror = () => reject(new Error('load')); document.head.appendChild(script);
    });
    const response = await fetch(props.url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
    if (!response.ok) throw new Error('response');
    const data = await response.json();
    if (stopped || !window.initGeetest) return;
    window.initGeetest({ gt: data.gt, challenge: data.challenge, offline: !data.success, new_captcha: data.new_captcha, product: 'popup', width: '100%', lang: props.language }, instance => {
      if (stopped || !container.value) { instance.destroy?.(); return; }
      captcha = instance; instance.appendTo(container.value);
      instance.onReady(() => { status.value = '请完成行为验证'; });
      instance.onSuccess(() => { const result = instance.getValidate(); validation.value = result || undefined; emit('valid', !!result); status.value = result ? '验证成功' : '请重新验证'; });
      instance.onError?.(() => { validation.value = undefined; emit('valid', false); status.value = '验证出错，请刷新页面重试'; });
    });
  } catch { if (!stopped) status.value = '行为验证加载失败，请刷新页面重试'; }
}
onMounted(load);
onBeforeUnmount(() => { stopped = true; controller.abort(); captcha?.destroy?.(); script?.remove(); });
</script>
<template><div class="verification"><div ref="container"></div><p class="muted small" role="status">{{ status }}</p><template v-if="validation"><input type="hidden" name="geetest_challenge" :value="validation.geetest_challenge"><input type="hidden" name="geetest_validate" :value="validation.geetest_validate"><input type="hidden" name="geetest_seccode" :value="validation.geetest_seccode"></template></div></template>
