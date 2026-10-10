<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import DOMPurify from 'dompurify';
import GeetestField from './GeetestField.vue';
import type { BuyData } from '../types';
import { money, safeUrl, unitPrice } from '../utils';
const props = defineProps<{ buy: BuyData; csrf: string }>();
const quantity = ref(1);
const busy = ref(false);
const verified = ref(false);
const warning = ref('');
const captchaUrl = ref(props.buy.captcha);
const max = computed(() => Math.max(0, Math.min(props.buy.stock, props.buy.limit > 0 ? props.buy.limit : props.buy.stock)));
const price = computed(() => unitPrice(props.buy.price, props.buy.wholesale, Number(quantity.value)));
function refreshCaptcha() { const url = new URL(props.buy.captcha, window.location.origin); url.searchParams.set('_modern', String(Date.now())); captchaUrl.value = url.href; }
function submit(event: Event) {
  warning.value = '';
  if (busy.value) { event.preventDefault(); return; }
  if (!Number.isInteger(Number(quantity.value)) || quantity.value < 1 || quantity.value > max.value) warning.value = '请填写库存和限购范围内的整数数量。';
  else if (props.buy.geetest && !verified.value) warning.value = '请先完成行为验证。';
  else if (!props.buy.payways.length) warning.value = '暂时没有可用的支付方式，请联系店主。';
  if (warning.value) { event.preventDefault(); return; }
  busy.value = true;
}
function reset() { busy.value = false; }
onMounted(() => window.addEventListener('pageshow', reset));
onBeforeUnmount(() => window.removeEventListener('pageshow', reset));
</script>
<template><div class="purchase-grid"><aside class="panel product-summary"><div class="detail-image"><img :src="safeUrl(buy.picture)" :alt="buy.name"></div><span class="badge static">{{ buy.type === 1 ? '自动发货' : '人工处理' }}</span><h1>{{ buy.name }}</h1><p class="muted">库存 {{ buy.stock }} <span v-if="buy.limit > 0"> · 每单限购 {{ buy.limit }}</span></p><p class="price large"><small>¥</small>{{ money(buy.price) }}</p><div v-if="buy.wholesale.length" class="tiers"><strong>批量购买更优惠</strong><p v-for="tier in buy.wholesale" :key="tier.number">{{ tier.number }} 件起 <b>¥{{ money(tier.price) }} / 件</b></p></div><div v-if="buy.prompt" class="notice rich" v-html="DOMPurify.sanitize(buy.prompt)"></div></aside>
<section class="panel"><p class="eyebrow">只需几步，即可拥有</p><h2>填写购买信息</h2><form :action="buy.action" method="post" @submit="submit"><input type="hidden" name="_token" :value="csrf"><input type="hidden" name="gid" :value="buy.id"><div class="form-grid"><label>接收邮箱<input name="email" type="email" autocomplete="email" required placeholder="用于接收商品与查询订单"></label><label>购买数量<input v-model.number="quantity" name="by_amount" type="number" required min="1" :max="max" step="1"></label><label v-if="buy.coupon">优惠码 <span class="muted">（选填）</span><input name="coupon_code" autocomplete="off" placeholder="输入优惠码"></label><label v-if="buy.searchPassword">订单查询密码<input name="search_pwd" type="password" autocomplete="new-password" required placeholder="请妥善保存查询密码"></label><label v-for="(field,index) in buy.fields" :key="field.field" :for="'extra-' + index">{{ field.desc }}<input :id="'extra-' + index" :name="field.field" :placeholder="field.placeholder" :required="field.required" type="text"></label></div><label v-if="buy.captcha" class="captcha-row">图形验证码<div><input name="img_verify_code" required autocomplete="off"><button type="button" class="captcha-refresh" aria-label="刷新图形验证码" @click="refreshCaptcha"><img :src="captchaUrl" alt="图形验证码，点击刷新"></button></div></label><GeetestField v-if="buy.geetest" :url="buy.geetestUrl" :language="buy.language" @valid="verified = $event"/><fieldset><legend>支付方式</legend><div class="payment-options"><label v-for="(way,index) in buy.payways" :key="way.id"><input type="radio" name="payway" :value="way.id" :checked="index === 0" required><span>{{ way.name }}</span></label></div><p v-if="!buy.payways.length" class="error-text">暂时没有可用的支付方式</p></fieldset><div class="total"><span>预计合计<small>优惠以结账页面为准</small></span><strong class="price">¥{{ money(price * Math.max(0, Number(quantity) || 0)) }}</strong></div><p v-if="warning" class="error-text" role="alert">{{ warning }}</p><button class="primary full" type="submit" :disabled="busy || max === 0 || !buy.payways.length" :aria-busy="busy">{{ busy ? '正在创建订单…' : max === 0 ? '商品暂时售罄' : '提交订单 →' }}</button><p class="small muted">提交前请核对邮箱和商品信息。</p></form></section></div><section class="panel description"><h2>商品详情</h2><div v-if="buy.description" class="rich" v-html="DOMPurify.sanitize(buy.description)"></div><p v-else class="muted">店主暂未添加更多介绍。</p></section></template>
