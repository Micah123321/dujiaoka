import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import HomePage from './HomePage.vue';
import BuyPage from './BuyPage.vue';
import SearchPage from './SearchPage.vue';
import OrderCard from './OrderCard.vue';
import QrPayPage from './QrPayPage.vue';
import GeetestField from './GeetestField.vue';
import type { BuyData, OrderData } from '../types';
import { unitPrice } from '../utils';
vi.mock('qrcode', () => ({ default: { toDataURL: vi.fn().mockResolvedValue('data:image/png;base64,test') } }));
const product = { id:1, name:'数字商品', picture:'/product.png', price:'10.00', stock:8, type:1, url:'/buy/1' };
const buy: BuyData = { ...product, limit:5, wholesale:[{number:3,price:'8.00'}], fields:[{field:'account',desc:'代充账号',placeholder:'填写账号',required:true}],payways:[{id:2,name:'支付宝'}],coupon:true,searchPassword:true,captcha:'/captcha/buy?abc',geetest:false,geetestUrl:'/check-geetest',language:'zh_CN',action:'/create-order',description:'<p>商品介绍</p>',prompt:'' };
const order: OrderData = { sn:'ORDER-001',title:'数字商品',quantity:2,price:'20.00',goodsPrice:'10.00',email:'buyer@example.test',type:1,status:4,created:'2026-01-01',payName:'支付宝',info:'KEY-123\nKEY-456',coupon:'',couponDiscount:'0',wholesaleDiscount:'0',payUrl:'/bill/ORDER-001' };
const wrappers: {unmount: () => void}[] = [];
function tracked<T extends {unmount: () => void}>(wrapper:T) { wrappers.push(wrapper); return wrapper; }
afterEach(() => { wrappers.splice(0).forEach(w => w.unmount()); vi.unstubAllGlobals(); vi.restoreAllMocks(); vi.useRealTimers(); delete window.initGeetest; });
describe('modern shop component behavior', () => {
 it('matches backend tier order and non-positive tier fallback', () => {
  expect(unitPrice('10', [{number:2,price:'9'}, {number:5,price:'7'}], 5)).toBe(7);
  expect(unitPrice('10', [{number:2,price:'0'}], 3)).toBe(10);
 });
 it('combines category and case insensitive search, then clears the empty state', async () => {
  const w = tracked(mount(HomePage,{props:{notice:'',groups:[{id:1,name:'软件',goods:[product]},{id:2,name:'工具',goods:[{...product,id:2,name:'Cloud Tool'}]}]}}));
  expect(w.findAll('.product-card')).toHaveLength(2);
  await w.get('input').setValue('CLOUD'); expect(w.findAll('.product-card')).toHaveLength(1);
  await w.findAll('.categories button')[1].trigger('click'); expect(w.findAll('.product-card')).toHaveLength(0);
  await w.get('.empty button').trigger('click'); expect(w.findAll('.product-card')).toHaveLength(2);
 });
 it('sanitizes announcement and product HTML instead of running markup', () => {
  const w = tracked(mount(HomePage,{props:{groups:[],notice:'<img src=x onerror="alert(1)"><script>alert(1)</script><b>公告</b>'}}));
  expect(w.find('script').exists()).toBe(false); expect(w.find('img').attributes('onerror')).toBeUndefined(); expect(w.find('.notice b').text()).toBe('公告');
 });
 it('keeps native POST fields, dynamic fields, CSRF and bulk pricing', async () => {
  const w = tracked(mount(BuyPage,{props:{buy,csrf:'csrf-test'}}));
  expect(w.get('form').attributes('method')).toBe('post'); expect(w.get('form').attributes('action')).toBe('/create-order');
  const values = new FormData(w.get('form').element as HTMLFormElement);
  expect(values.get('_token')).toBe('csrf-test'); expect(values.get('gid')).toBe('1'); expect(values.get('payway')).toBe('2');
  expect(w.get('[name=account]').attributes('required')).toBeDefined();
  expect(w.find('[name=coupon_code]').exists()).toBe(true); expect(w.find('[name=search_pwd]').exists()).toBe(true);
  await w.get('[name=by_amount]').setValue(3); expect(w.get('.total').text()).toContain('24.00');
  const before = w.get('.captcha-refresh img').attributes('src'); await w.get('.captcha-refresh').trigger('click'); expect(w.get('.captcha-refresh img').attributes('src')).not.toBe(before);
 });
 it('blocks stock/limit overflow and suppresses duplicate submit', async () => {
  const w = tracked(mount(BuyPage,{props:{buy,csrf:'token'}}));
  await w.get('[name=by_amount]').setValue(6);
  let event = new Event('submit',{cancelable:true}); w.get('form').element.dispatchEvent(event); expect(event.defaultPrevented).toBe(true);
  await w.get('[name=by_amount]').setValue(2);
  event = new Event('submit',{cancelable:true}); w.get('form').element.dispatchEvent(event); expect(event.defaultPrevented).toBe(false);
  event = new Event('submit',{cancelable:true}); w.get('form').element.dispatchEvent(event); expect(event.defaultPrevented).toBe(true);
 });
 it('blocks submitting before Geetest succeeds and handles no payment methods', () => {
  const w = tracked(mount(BuyPage,{props:{buy:{...buy,geetest:true},csrf:'token'},global:{stubs:{GeetestField:true}}}));
  const event = new Event('submit',{cancelable:true}); w.get('form').element.dispatchEvent(event); expect(event.defaultPrevented).toBe(true);
  const empty = tracked(mount(BuyPage,{props:{buy:{...buy,payways:[]},csrf:'token'}})); expect(empty.get('button[type=submit]').attributes('disabled')).toBeDefined();
 });
 it('submits explicit Geetest validation fields', async () => {
  const success: {callback?: () => void} = {};
  window.initGeetest = (_options, callback) => callback({appendTo:vi.fn(),onReady:cb => cb(),onSuccess:cb => {success.callback = cb;},getValidate:() => ({geetest_challenge:'challenge',geetest_validate:'validate',geetest_seccode:'seccode'}),destroy:vi.fn()});
  vi.stubGlobal('fetch',vi.fn().mockResolvedValue({ok:true,json:async () => ({gt:'public-id',challenge:'challenge',success:1})}));
  const w = tracked(mount(GeetestField,{props:{url:'/check-geetest',language:'zh_CN'}})); await flushPromises(); success.callback?.(); await flushPromises();
  expect(w.get('[name=geetest_challenge]').attributes('value')).toBe('challenge'); expect(w.get('[name=geetest_seccode]').attributes('value')).toBe('seccode'); expect(w.emitted('valid')?.[0]).toEqual([true]);
 });
 it('switches search modes with separate original POST routes and required password', async () => {
  const w = tracked(mount(SearchPage,{props:{csrf:'token',search:{password:true,sn:'/search-order-by-sn',email:'/search-order-by-email',browser:'/search-order-by-browser'}}}));
  expect(w.get('form').attributes('action')).toBe('/search-order-by-sn');
  await w.findAll('.categories button')[1].trigger('click'); expect(w.get('form').attributes('action')).toBe('/search-order-by-email'); expect(w.get('[name=search_pwd]').attributes('required')).toBeDefined();
  await w.findAll('.categories button')[2].trigger('click'); expect(w.get('form').attributes('action')).toBe('/search-order-by-browser'); expect(w.find('[name=email]').exists()).toBe(false);
 });
 it('copies literal delivery content and hides payment on completed orders', async () => {
  const writeText = vi.fn().mockResolvedValue(undefined); Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText}});
  const w = tracked(mount(OrderCard,{props:{order}})); await w.get('button').trigger('click'); await flushPromises();
  expect(writeText).toHaveBeenCalledWith(order.info); expect(w.text()).toContain('已复制到剪贴板'); expect(w.find('a.primary').exists()).toBe(false);
 });
 it('backs off failures and resets after recovery', async () => {
   vi.useFakeTimers();
   const fetch = vi.fn().mockRejectedValue(new Error('offline')); vi.stubGlobal('fetch',fetch);
   tracked(mount(QrPayPage,{props:{qr:{value:'fixture',price:'10',name:'Pay',sn:'SN',checkUrl:'/status',detailUrl:'/detail',jumpUrl:'',minutes:5}}}));
   await flushPromises(); expect(fetch).toHaveBeenCalledTimes(1);
   for (const [delay,count] of [[5000,2],[10000,3],[20000,4],[30000,5]]) {
    await vi.advanceTimersByTimeAsync(delay - 1); expect(fetch).toHaveBeenCalledTimes(count - 1);
    await vi.advanceTimersByTimeAsync(1); expect(fetch).toHaveBeenCalledTimes(count);
   }
   fetch.mockResolvedValue({ok:true,json:async () => ({code:400000})});
   await vi.advanceTimersByTimeAsync(30000); expect(fetch).toHaveBeenCalledTimes(6);
   await vi.advanceTimersByTimeAsync(5000); expect(fetch).toHaveBeenCalledTimes(7);
 });
 it('pauses hidden pages and checks immediately when visible', async () => {
   vi.useFakeTimers(); let hidden = true; vi.spyOn(document,'hidden','get').mockImplementation(() => hidden);
   const fetch = vi.fn().mockResolvedValue({ok:true,json:async () => ({code:400000})}); vi.stubGlobal('fetch',fetch);
   tracked(mount(QrPayPage,{props:{qr:{value:'fixture',price:'10',name:'Pay',sn:'SN',checkUrl:'/status',detailUrl:'/detail',jumpUrl:'',minutes:5}}}));
   await flushPromises(); await vi.advanceTimersByTimeAsync(60000); expect(fetch).not.toHaveBeenCalled();
   hidden = false; document.dispatchEvent(new Event('visibilitychange')); await flushPromises(); expect(fetch).toHaveBeenCalledTimes(1);
   hidden = true; document.dispatchEvent(new Event('visibilitychange')); await vi.advanceTimersByTimeAsync(60000); expect(fetch).toHaveBeenCalledTimes(1);
   hidden = false; document.dispatchEvent(new Event('visibilitychange')); await flushPromises(); expect(fetch).toHaveBeenCalledTimes(2);
 });
 it('allows manual retry during backoff without overlapping requests', async () => {
   vi.useFakeTimers(); let finish: ((response: unknown) => void) | undefined;
   const fetch = vi.fn().mockRejectedValueOnce(new Error('offline')).mockImplementation(() => new Promise(resolve => { finish = resolve; })); vi.stubGlobal('fetch',fetch);
   const w = tracked(mount(QrPayPage,{props:{qr:{value:'fixture',price:'10',name:'Pay',sn:'SN',checkUrl:'/status',detailUrl:'/detail',jumpUrl:'',minutes:5}}}));
   await flushPromises(); await w.get('button').trigger('click'); expect(fetch).toHaveBeenCalledTimes(2);
   document.dispatchEvent(new Event('visibilitychange')); await vi.advanceTimersByTimeAsync(5000); expect(fetch).toHaveBeenCalledTimes(2);
   finish?.({ok:true,json:async () => ({code:400001})}); await flushPromises();
   await vi.advanceTimersByTimeAsync(60000); expect(fetch).toHaveBeenCalledTimes(2);
 });
 it('stops polling on expiry and cleans timers on unmount', async () => {
  vi.useFakeTimers(); const fetch = vi.fn().mockResolvedValue({ok:true,json:async () => ({code:400001})}); vi.stubGlobal('fetch',fetch);
  const w = tracked(mount(QrPayPage,{props:{qr:{value:'weixin://pay',price:'10',name:'微信',sn:'SN',checkUrl:'/check-order-status/SN',detailUrl:'/detail-order-sn/SN',jumpUrl:'',minutes:5}}}));
  await flushPromises(); expect(w.text()).toContain('订单已过期'); await vi.advanceTimersByTimeAsync(20000); expect(fetch).toHaveBeenCalledTimes(1);
 });
 it('retries network errors without overlapping requests and stops when removed', async () => {
  vi.useFakeTimers(); const fetch = vi.fn().mockRejectedValue(new Error('network')); vi.stubGlobal('fetch',fetch);
  const w = mount(QrPayPage,{props:{qr:{value:'weixin://pay',price:'10',name:'微信',sn:'SN',checkUrl:'/check-order-status/SN',detailUrl:'/detail-order-sn/SN',jumpUrl:'',minutes:5}}});
  await flushPromises(); expect(w.text()).toContain('网络暂时异常'); await vi.advanceTimersByTimeAsync(5000); expect(fetch).toHaveBeenCalledTimes(2); w.unmount(); await vi.advanceTimersByTimeAsync(15000); expect(fetch).toHaveBeenCalledTimes(2);
 });
});
