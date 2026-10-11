const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./.runtime/browser/node_modules/playwright');
const base='http://127.0.0.1:8202',out=path.join(__dirname,'.runtime/theme58/bulk-browser');
fs.mkdirSync(out,{recursive:true});const report={checks:[],errors:[]};
function check(value,label){assert.ok(value,label);report.checks.push(label);}
(async()=>{
 const browser=await chromium.launch({channel:'chrome',headless:true}); const page=await browser.newPage({viewport:{width:1440,height:1000},reducedMotion:'reduce'}); page.setDefaultTimeout(12000);page.on('pageerror',e=>report.errors.push(e.message));
 await page.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
 const row=id=>page.locator('.grid-row-checkbox[data-id="'+id+'"]').locator('xpath=ancestor::tr');
 async function visit(resource){await page.goto(base+'/admin/'+resource);await page.waitForLoadState('networkidle');}
 async function selected(ids){for(const id of ids)await page.locator('.grid-row-checkbox[data-id="'+id+'"]').check();}
 async function status(ids,state){await page.waitForFunction(({ids,state})=>ids.every(id=>{const r=document.querySelector('.grid-row-checkbox[data-id="'+id+'"]').closest('tr');return r.querySelector('input[type=checkbox]:not([data-id])')?.checked===state;}),{ids,state});}
 async function batch(resource,ids,state){
   await selected(ids); await page.locator('.grid-select-all-btn > [data-toggle=dropdown]').click();
   await page.locator('.admin-batch-status-'+resource+'-'+state).click();
   await page.locator('.swal2-confirm').waitFor();
   const response=page.waitForResponse(r=>r.url()===base+'/admin/dcat-api/action'&&r.request().method()==='POST');
   await page.locator('.swal2-confirm').click(); const result=await (await response).json();check(result.status===true,resource+' batch '+state+' signed action succeeds');
   await status(ids,state===1); await page.waitForLoadState('networkidle');check(await page.locator('.admin-selection-toolbar').count()===1,resource+' keeps one selection toolbar after PJAX refresh');
 }
 async function clone(resource,id){await row(id).locator('[data-toggle=dropdown]').click();const response=page.waitForResponse(r=>r.url()===base+'/admin/dcat-api/action'&&r.request().method()==='POST');await row(id).locator('.admin-clone-resource').click();const result=await (await response).json();check(result.status===true,resource+' clone signed action succeeds');await page.waitForURL('**/admin/'+resource+'/*/edit');await page.waitForLoadState('networkidle');return Number(page.url().match(/\/(\d+)\/edit$/)[1]);}
 try{
   await page.goto(base+'/admin/auth/login');await page.locator('[name=username]').fill('fixture-admin');await page.locator('[name=password]').fill('fixture-password');await page.locator('[type=submit]').click();await page.waitForURL('**/admin');
   await visit('pay');await batch('pay',[2,3],0);await batch('pay',[2,3],1);
   await selected([2,3]);await page.locator('.grid-select-all-btn > [data-toggle=dropdown]').click();await page.screenshot({path:path.join(out,'payment-batch-menu.png'),fullPage:true});await page.evaluate(()=>scrollTo(0,0));await page.getByRole('button',{name:'取消全部选择',exact:true}).click();check((await page.evaluate(()=>Dcat.grid.selected())).length===0,'Cancel all after batch refresh removes original action IDs');
   await visit('goods');await batch('goods',[1,2],0);await batch('goods',[1,2],1);
   const product=await clone('goods',2);check(product>3,'Goods clone creates a new row');check((await page.locator('[name=gd_name]').inputValue()).includes('副本'),'Goods clone keeps a clear Chinese copy name');
   check(!(await page.locator('input[type=checkbox][name=is_open]').isChecked()),'Goods clone is unlisted');check(await page.locator('[name=in_stock]').inputValue()==='0','Manual goods clone has zero stock');
   await page.screenshot({path:path.join(out,'goods-copy-edit.png'),fullPage:true});await visit('pay');
   const payment=await clone('pay',2);check(payment>31,'Payment clone creates a new row');check((await page.locator('select[name=pay_check]').inputValue()).startsWith('draft-'),'Payment clone has an isolated temporary check');
   check(!(await page.locator('input[type=checkbox][name=is_open]').isChecked()),'Payment clone remains disabled');
   check(await page.locator('body').innerText().then(s=>s.includes('待选实际付款方式')),'Payment copy form explains activation requirement');
   await page.screenshot({path:path.join(out,'payment-copy-edit.png'),fullPage:true});
   await page.locator('form .nav-tabs a').filter({hasText:'保存与启用'}).click();await page.locator('input[type=checkbox][name=is_open] + .switchery').click();
   const [invalidResponse]=await Promise.all([page.waitForResponse(r=>r.url().includes('/admin/pay/'+payment)&&r.request().method()==='POST'),page.getByRole('button',{name:'提交',exact:false}).click()]);const invalid=await invalidResponse.json();check(invalid.status===false,'Native form blocks activation of a copied draft');
   await page.goto(base+'/admin/pay/'+payment+'/edit');await page.waitForLoadState('networkidle');check(!(await page.locator('input[type=checkbox][name=is_open]').isChecked()),'Blocked payment draft activation persists no change');
   // Give this extensible provider a real unique method using its original Select2 input.
   await page.locator('select[name=pay_check]').evaluate((e,value)=>{const option=new Option('USDT Test',value,true,true);e.append(option);window.jQuery(e).trigger('change');},'tokenpay-browser-copy-'+payment);
   await page.locator('form .nav-tabs a').filter({hasText:'保存与启用'}).click();await page.locator('input[type=checkbox][name=is_open] + .switchery').click();
   const [validResponse]=await Promise.all([page.waitForResponse(r=>r.url().includes('/admin/pay/'+payment)&&r.request().method()==='POST'),page.getByRole('button',{name:'提交',exact:false}).click()]);const valid=await validResponse.json();check(valid.status===true,'Copy with real unique TokenPay method can activate through native form');
   await visit('pay?page=2');await status([payment],true);check((await row(payment).innerText()).includes('tokenpay-browser-copy'),'Activated copy appears with the real method');
   await visit('goods-group');await batch('goods-group',[1],0);await batch('goods-group',[1],1);await visit('coupon');await batch('coupon',[1],0);await batch('coupon',[1],1);
   await page.setViewportSize({width:390,height:844});await visit('pay');await selected([2]);await page.locator('.grid-select-all-btn > [data-toggle=dropdown]').click();check(await page.locator('.admin-batch-status-pay-0').isVisible(),'Mobile payment list exposes bulk disable');await page.screenshot({path:path.join(out,'payment-mobile-batch.png'),fullPage:true});
   check(report.errors.length===0,'No runtime errors during real bulk/clone flow');fs.writeFileSync(path.join(out,'result.json'),JSON.stringify({...report,status:'PASS',product,payment},null,2));console.log(JSON.stringify({status:'PASS',checks:report.checks.length,out}));
 }catch(error){await page.screenshot({path:path.join(out,'failed.png'),fullPage:true}).catch(()=>{});fs.writeFileSync(path.join(out,'failed.json'),JSON.stringify({...report,error:error.message,url:page.url()},null,2));throw error;}finally{await browser.close()}
})().catch(error=>{console.error(error);process.exitCode=1});
