<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Bootstrap } from '../types';
const props = defineProps<{ search: NonNullable<Bootstrap['search']>; csrf: string }>();
const mode = ref<'sn'|'email'|'browser'>('sn');
const busy = ref(false);
const modes = [{id:'sn' as const,label:'订单编号'},{id:'email' as const,label:'邮箱查询'},{id:'browser' as const,label:'浏览器记录'}];
function submit(event: Event) { if (busy.value) event.preventDefault(); else busy.value = true; }
function reset() { busy.value = false; }
onMounted(() => window.addEventListener('pageshow',reset));
onBeforeUnmount(() => window.removeEventListener('pageshow',reset));
</script>
<template><section class="panel narrow"><p class="eyebrow">随时掌握订单进度</p><h1>找回你的订单</h1><p class="muted">选择一种方式，查看订单与交付内容。</p><div class="categories"><button v-for="item in modes" :key="item.id" :class="{active:mode === item.id}" :aria-pressed="mode === item.id" @click="mode = item.id; busy = false">{{ item.label }}</button></div><form :key="mode" :action="props.search[mode]" method="post" @submit="submit"><input type="hidden" name="_token" :value="csrf"><label v-if="mode === 'sn'">订单编号<input name="order_sn" type="text" required autocomplete="off" placeholder="输入下单时生成的订单编号"></label><template v-if="mode === 'email'"><label>下单邮箱<input name="email" type="email" autocomplete="email" required placeholder="输入购买时填写的邮箱"></label><label v-if="search.password">查询密码<input name="search_pwd" type="password" required autocomplete="current-password"></label></template><p v-if="mode === 'browser'" class="notice">查询本浏览器保存的订单记录。清理 Cookie 或更换浏览器后，记录可能不再显示。</p><button class="primary full" type="submit" :disabled="busy" :aria-busy="busy">{{ busy ? '正在查询…' : '查询订单 →' }}</button></form></section></template>
