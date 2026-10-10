<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import QRCode from 'qrcode';
import type { Bootstrap } from '../types';
import { money, safeUrl } from '../utils';
const props = defineProps<{ qr: NonNullable<Bootstrap['qr']> }>();
const image = ref('');
const message = ref('等待支付，正在自动查询订单状态…');
const state = ref<'waiting'|'paid'|'expired'>('waiting');
const checking = ref(false);
const qrError = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let stopped = false;
let active: AbortController | undefined;
let failures = 0;
function visibilityChanged() {
  clearTimeout(timer);
  if (!document.hidden) void check();
}
async function check() {
  if (stopped || document.hidden || checking.value || state.value !== 'waiting') return;
  clearTimeout(timer); checking.value = true; active = new AbortController();
  const timeout = setTimeout(() => active?.abort(), 10000);
  try {
    const response = await fetch(props.qr.checkUrl, { credentials:'same-origin', cache:'no-store', signal: active.signal });
    if (!response.ok) throw new Error('response');
    const result = await response.json();
    if (stopped) return;
    failures = 0;
    if (Number(result.code) === 200) { state.value = 'paid'; message.value = '支付成功，正在前往订单详情…'; window.location.assign(safeUrl(props.qr.detailUrl)); }
    else if (Number(result.code) === 400001) { state.value = 'expired'; message.value = '订单已过期，请返回店铺重新下单。'; }
    else if (Number(result.code) === 400000) message.value = '等待支付，正在自动查询订单状态…';
    else message.value = '状态暂未确认，将自动重试。';
  } catch {
    failures = Math.min(failures + 1, 4);
    if (!stopped) message.value = '网络暂时异常，将自动重试。你也可以手动查询。';
  } finally {
    clearTimeout(timeout); checking.value = false;
    if (!stopped && !document.hidden && state.value === 'waiting') {
      const delay = Math.min(30000, 5000 * 2 ** Math.max(0, failures - 1));
      timer = setTimeout(check, delay);
    }
  }
}
onMounted(async () => { document.addEventListener('visibilitychange', visibilityChanged); check(); try { image.value = await QRCode.toDataURL(props.qr.value, { width:280, margin:2, errorCorrectionLevel:'M' }); } catch { qrError.value = true; } });
onBeforeUnmount(() => { document.removeEventListener('visibilitychange', visibilityChanged); stopped = true; clearTimeout(timer); active?.abort(); });
</script>
<template><section class="panel narrow qr-panel"><p class="eyebrow">安全支付 · 即刻开启</p><h1>扫码完成支付</h1><p class="muted">使用 {{ qr.name }} 扫码 · 下单后 {{ qr.minutes }} 分钟内有效</p><p class="price large">¥{{ money(qr.price) }}</p><div class="qr-code" :class="{expired:state === 'expired'}"><img v-if="image" :src="image" alt="订单支付二维码" width="280" height="280"><p v-else role="status">{{ qrError ? '二维码生成失败，请刷新重试' : '正在生成二维码…' }}</p></div><p class="small muted">订单编号 {{ qr.sn }}</p><p role="status" :class="{'error-text':state === 'expired'}">{{ message }}</p><a v-if="qr.jumpUrl && safeUrl(qr.jumpUrl,true) && state === 'waiting'" :href="safeUrl(qr.jumpUrl,true)" class="primary full">打开支付应用</a><button v-if="state === 'waiting'" class="secondary" :disabled="checking" @click="check">{{ checking ? '正在查询…' : '我已支付，查询状态' }}</button><a v-else :href="safeUrl(qr.detailUrl)" class="secondary">查看订单详情</a></section></template>
