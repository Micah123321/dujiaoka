const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('./.runtime/browser/node_modules/playwright');
const base = 'http://127.0.0.1:8202';
const out = path.join(__dirname, '.runtime/theme58/browser');
fs.mkdirSync(out, { recursive:true });
const report = { checks:[], errors:[], screenshots:[] };
function check(value, label) { assert.ok(value, label); report.checks.push(label); }
function rgb(hex) { return 'rgb(' + hex.slice(1).match(/../g).map(n=>parseInt(n,16)).join(', ') + ')'; }
function contrast(a,b) {
  const light = s=>{ const c=s.match(/[0-9.]+/g).slice(0,3).map(Number).map(n=>n/255).map(n=>n<=.04045?n/12.92:((n+.055)/1.055)**2.4); return c[0]*.2126+c[1]*.7152+c[2]*.0722; };
  const x=light(a),y=light(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05);
}
(async()=>{
  const browser=await chromium.launch({channel:'chrome',headless:true});
  const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
  const page=await context.newPage(); page.setDefaultTimeout(12000);
  page.on('pageerror',e=>report.errors.push(e.message));
  await page.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
  async function visit(slug){ const response=await page.goto(base+'/admin/'+slug); check(response.status()===200,slug+' renders');await page.waitForLoadState('networkidle'); }
  async function palette(){ return page.evaluate(()=>Object.fromEntries(['primary','primary-hover','on-primary','link','surface','surface-soft','input','ink','muted','line','sidebar-selected'].map(k=>[k,getComputedStyle(document.body).getPropertyValue('--admin-'+k).trim()]))); }
  async function colors(locator){return locator.evaluate(e=>({color:getComputedStyle(e).color,bg:getComputedStyle(e).backgroundColor,border:getComputedStyle(e).borderColor}));}
  async function shot(name){await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:path.join(out,name+'.png'),fullPage:true});report.screenshots.push(name);}
  try{
    await page.goto(base+'/admin/auth/login');await page.locator('[name=username]').fill('fixture-admin');await page.locator('[name=password]').fill('fixture-password');await page.locator('[type=submit]').click();await page.waitForURL('**/admin');
    await visit('pay');let theme=await palette();const create=page.locator('a[href$="/pay/create"]').first();
    let state=await colors(create);check(state.bg===rgb(theme.primary),'Create uses the native theme primary');check(state.color===rgb(theme['on-primary']),'Create text is readable white');
    check((await colors(create.locator('i'))).color===state.color,'Create icon inherits white');check(contrast(state.color,state.bg)>=4.5,'Create text and icon contrast exceeds 4.5:1');
    await create.hover();await page.waitForFunction(expected=>getComputedStyle(document.querySelector('a[href$="/pay/create"]')).backgroundColor===expected,rgb(theme['primary-hover']));state=await colors(create);check(state.bg===rgb(theme['primary-hover'])&&state.color===rgb(theme['on-primary']),'Create hover keeps a readable theme state');await page.mouse.move(400,80);
    check((await colors(page.locator('.pagination .active .page-link'))).bg===rgb(theme.primary),'Pagination selected page shares the primary');
    const sidebar=page.locator('.main-sidebar .nav-link.active').first();check((await colors(sidebar)).color===rgb(theme['sidebar-selected']),'Selected menu foreground is readable');check((await colors(sidebar.locator('i'))).color===rgb(theme['sidebar-selected']),'Selected menu icon inherits its foreground');
    const enabled=page.locator('input[type=checkbox]:checked + .switchery').first();const disabled=page.locator('input[type=checkbox]:not(:checked) + .switchery').first();
    check((await colors(enabled)).bg===rgb(theme.primary),'Enabled switch uses the shared primary');check((await colors(disabled)).bg===rgb(theme['surface-soft']),'Disabled switch differs from enabled');
    await page.locator('.grid-select-all').check();check(await page.locator('.admin-selection-toolbar').count()===1,'Exactly one selection toolbar is mounted');check((await page.locator('.admin-selection-toolbar').innerText()).includes('20'),'Selection count matches the current page');
    check((await page.evaluate(()=>Dcat.grid.selected())).length===20,'Native batch source holds selected row IDs');
    await page.getByRole('button',{name:'取消全部选择',exact:true}).click();check((await page.evaluate(()=>Dcat.grid.selected())).length===0,'Cancel selection clears native Dcat action keys');check(!(await page.locator('.grid-select-all').isChecked()),'Cancel selection clears header checkbox');
    await shot('payments-light');
    await page.locator('.dark-mode-switcher').click();check(await page.locator('body').evaluate(e=>e.classList.contains('dark-mode')),'Actual theme switch enters dark mode');theme=await palette();await page.waitForFunction(()=>document.getAnimations().every(animation=>animation.playState!=='running'));
    check((await colors(enabled)).bg===rgb(theme.primary),'Dark enabled switch retains the active state');check((await colors(disabled)).bg===rgb(theme['surface-soft']),'Dark disabled switch retains the inactive state');
    check((await colors(page.locator('.table').first())).bg===rgb(theme.surface),'Native table follows the dark surface');
    check((await colors(page.locator('.table tbody td').nth(1))).bg===rgb(theme.surface),'Dark table cells retain the shared surface');
    check((await colors(page.locator('.table tbody td').nth(1))).color===rgb(theme.ink),'Dark table cell text shares the readable foreground');
    check((await colors(page.locator('.dcat-box').first())).bg===rgb(theme.surface),'Dark list header and footer share the same surface');
    check((await colors(create)).color===rgb(theme['on-primary']),'Dark create foreground remains readable');
    await page.evaluate(()=>Dcat.confirm('主题检查','核对当前选中的记录',()=>{}));
    check((await colors(page.locator('.swal2-popup'))).bg===rgb(theme.surface),'Native confirm dialog shares the dark surface');
    check((await colors(page.locator('.swal2-confirm'))).color===rgb(theme['on-primary']),'Native dialog action shares the readable primary foreground');
    await page.locator('.swal2-cancel').click();await page.locator('.swal2-container').waitFor({state:'detached'});
    await shot('payments-dark');
    await visit('goods/create');theme=await palette();check((await colors(page.locator('[name=gd_name]'))).bg===rgb(theme.input),'Native text field follows dark input surface');
    check((await colors(page.locator('form .nav-tabs .active'))).color===rgb(theme.link),'Active native tab follows theme link color');
    await page.waitForFunction(()=>Array.from(document.querySelectorAll('iframe.tox-edit-area__iframe')).length===2&&Array.from(document.querySelectorAll('iframe.tox-edit-area__iframe')).every(f=>f.contentDocument?.querySelector('[data-admin-editor-theme]')));
    const editor=await page.locator('iframe.tox-edit-area__iframe').first().evaluate(f=>({color:getComputedStyle(f.contentDocument.body).color,bg:getComputedStyle(f.contentDocument.body).backgroundColor}));
    check(editor.bg===rgb(theme.input)&&editor.color===rgb(theme.ink),'Rich text content follows the same dark palette');
    await page.locator('form .nav-tabs .nav-link').filter({hasText:'优惠与买家资料'}).click();check((await colors(page.locator('.fg-card').first())).bg===rgb(theme.surface),'Vue form editor shares native dark surface');await shot('goods-dark');
    await visit('operations/overview');theme=await palette();await page.locator('[data-admin-ready]').waitFor({state:'attached'}).catch(()=>{});check((await colors(page.locator('.wb-panel').first())).bg===rgb(theme.surface),'Vue workbench shares native dark surface');await shot('workbench-dark');
    await page.locator('.dark-mode-switcher').click();check(!(await page.locator('body').evaluate(e=>e.classList.contains('dark-mode'))),'Theme switches back to light');
    await page.setViewportSize({width:390,height:844});await visit('pay');
    const layout=await page.evaluate(()=>({content:document.querySelector('.content-wrapper').getBoundingClientRect().right,sidebar:document.querySelector('.main-sidebar').getBoundingClientRect().right,viewport:innerWidth}));check(layout.sidebar<=1&&layout.content<=layout.viewport+1,'Mobile table shell stays clear of the closed sidebar');
    check(contrast((await colors(page.locator('a[href$="/pay/create"]').first())).color,(await colors(page.locator('a[href$="/pay/create"]').first())).bg)>=4.5,'Mobile icon-only create action remains readable');await shot('payments-mobile');
    check(report.errors.length===0,'No browser runtime errors');fs.writeFileSync(path.join(out,'result.json'),JSON.stringify({...report,status:'PASS'},null,2));console.log(JSON.stringify({status:'PASS',checks:report.checks.length,screenshots:out}));
  }catch(error){await page.screenshot({path:path.join(out,'failed.png'),fullPage:true}).catch(()=>{});fs.writeFileSync(path.join(out,'failed.json'),JSON.stringify({...report,message:error.message,url:page.url()},null,2));throw error;}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
