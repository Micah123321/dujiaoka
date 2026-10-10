<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Group } from '../types';
import { money, safeUrl } from '../utils';
import DOMPurify from 'dompurify';
const props = defineProps<{ groups: Group[]; notice: string }>();
const category = ref<number | null>(null);
const query = ref('');
const goods = computed(() => props.groups.filter(g => category.value === null || g.id === category.value).flatMap(g => g.goods).filter(g => g.name.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())));
</script>
<template>
  <section class="hero"><div><p class="eyebrow">精选好物 · 即刻拥有</p><h1>让每一次选择，<br>都简单一点。</h1><p class="muted">挑选所需，轻松下单。你的数字生活，从这里开始。</p></div><div class="hero-art" aria-hidden="true"><div class="art-card"><span>✦</span><b>好物，触手可及</b><small>简洁 · 便捷 · 随心</small></div><i class="orb orb-one"></i><i class="orb orb-two"></i></div></section>
  <aside v-if="notice" class="notice"><strong>店铺公告</strong><div class="rich" v-html="DOMPurify.sanitize(notice)"></div></aside>
  <section aria-labelledby="catalog-title"><div class="section-head"><div><p class="eyebrow">发现你的下一件好物</p><h2 id="catalog-title">全部商品 <span class="count">{{ goods.length }}</span></h2></div><label class="search-box"><span class="sr-only">搜索商品</span><span aria-hidden="true">⌕</span><input v-model="query" type="search" placeholder="搜索商品名称…"></label></div>
  <div class="categories" aria-label="商品分类"><button :class="{active: category === null}" :aria-pressed="category === null" @click="category = null">全部商品</button><button v-for="group in groups" :key="group.id" :class="{active: category === group.id}" :aria-pressed="category === group.id" @click="category = group.id">{{ group.name }}</button></div>
  <div v-if="goods.length" class="product-grid"><a v-for="good in goods" :key="good.id" class="product-card" :href="safeUrl(good.url)"><div class="product-image"><img :src="safeUrl(good.picture)" :alt="good.name" loading="lazy"><span class="badge">{{ good.type === 1 ? '自动发货' : '人工处理' }}</span></div><div class="product-body"><h3>{{ good.name }}</h3><p class="muted small">{{ good.stock > 0 ? '库存 ' + good.stock : '暂时售罄' }}</p><div class="product-bottom"><strong class="price"><small>¥</small>{{ money(good.price) }}</strong><span class="round-arrow" aria-hidden="true">↗</span></div></div></a></div>
  <div v-else class="empty"><span aria-hidden="true">⌕</span><h3>暂时没有找到商品</h3><p>试试其他关键词或分类。</p><button class="secondary" @click="query = ''; category = null">查看全部商品</button></div></section>
</template>
