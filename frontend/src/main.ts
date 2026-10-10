import { createApp } from 'vue';
import App from './App.vue';
import './style.css';
import type { Bootstrap } from './types';
const source = document.getElementById('modern-data');
const root = document.getElementById('modern-app');
if (source && root) {
  try { const data: Bootstrap = JSON.parse(source.textContent || '{}'); createApp(App, { data }).mount(root); }
  catch { root.textContent = '页面加载失败，请刷新重试或联系店主。'; }
}
