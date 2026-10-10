const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const {execFileSync} = require('node:child_process');
const {chromium} = require('./.runtime/browser/node_modules/playwright');

// Run only against an already initialized --extended fixture and its matching server.
const tree = process.argv.includes('--candidate') ? 'candidate' : 'root';
const base = 'http://127.0.0.1:8199';
const output = path.join(__dirname, '.runtime', 'admin-extended-' + tree);
const database = path.join(output, 'fixture.sqlite');
const option = name => {
  const inline = process.argv.find(value => value.startsWith(name + '='));
  if (inline) return inline.slice(name.length + 1);
  const index = process.argv.indexOf(name);
  return index < 0 ? undefined : process.argv[index + 1];
};
const php = option('--php') || process.env.ADMIN_EXTENDED_PHP || process.env.PHP_BINARY || 'php';
const ini = option('--php-ini') || process.env.ADMIN_EXTENDED_PHP_INI;
const phpArgs = ini ? ['-c', ini] : [];
const timeout = 12000;
const labels = {trash: '\u56de\u6536\u7ad9', submit: '\u63d0\u4ea4', login: '\u767b\u5f55'};
const report = {
  tree, base, startedAt: new Date().toISOString(), status: 'running',
  pages: [], actions: [], permissions: [], controls: [], screenshots: [],
  assets: {}, blockedExternal: [], expectedHTTP: [], unexpectedHTTP: [], errors: [], failedLocal: [],
};
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const stateHash = state => hash(JSON.stringify(state));
const goods = (state, id) => state.goods.find(row => Number(row.id) === id);
const active = rows => rows.filter(row => row.deleted_at === null);
const trashRoute = resource => '/admin/' + resource + '?_scope_=' + encodeURIComponent(labels.trash);

// PDO reads the fixture directly: no Laravel bootstrap, seeding, package cache, or session writes.
const inspectCode = [
  '$pdo = new PDO("sqlite:" . $argv[1]);',
  '$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);',
  '$pdo->exec("PRAGMA query_only = ON");',
  '$pdo->beginTransaction();',
  'if ($pdo->query("SELECT name FROM fixture_marker")->fetchColumn() !== "dujiaoka-admin-browser-v1") throw new RuntimeException("Fixture marker mismatch");',
  '$result = ["php" => PHP_VERSION, "binary" => PHP_BINARY, "ini" => php_ini_loaded_file(),',
  '"goods" => $pdo->query("SELECT * FROM goods ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),',
  '"carmis" => $pdo->query("SELECT * FROM carmis ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)];',
  '$pdo->rollBack(); echo json_encode($result, JSON_THROW_ON_ERROR);',
].join('\n');

function businessFiles() {
  const root = path.join(output, 'storage', 'app');
  const files = {};
  const walk = directory => {
    if (!fs.existsSync(directory)) return;
    for (const entry of fs.readdirSync(directory, {withFileTypes: true}).sort((a, b) => a.name.localeCompare(b.name))) {
      const file = path.join(directory, entry.name);
      assert.ok(!entry.isSymbolicLink(), 'Fixture upload tree contains a symbolic link');
      if (entry.isDirectory()) walk(file);
      else if (entry.isFile()) files[path.relative(root, file).split(path.sep).join('/')] = hash(fs.readFileSync(file));
    }
  };
  walk(root);
  return files;
}

function snapshot() {
  assert.ok(fs.existsSync(database), 'Parent must initialize the matching --extended fixture before running');
  assert.ok(fs.lstatSync(database).isFile(), 'Fixture database must be a regular file');
  const inspected = JSON.parse(execFileSync(php, [...phpArgs, '-r', inspectCode, database], {
    cwd: path.join(__dirname, '..'), encoding: 'utf8', timeout: 8000, maxBuffer: 4 * 1024 * 1024,
  }));
  report.php = {version: inspected.php, binary: inspected.binary, ini: inspected.ini};
  return {goods: inspected.goods, carmis: inspected.carmis, files: businessFiles()};
}

function diskFile(disk, value) {
  assert.ok(['admin', 'public'].includes(disk));
  assert.ok(typeof value === 'string' && value.length > 0 && !value.includes('://'), 'Expected a relative uploaded file path');
  const root = path.resolve(output, 'storage', 'app', disk);
  const file = path.resolve(root, value);
  assert.ok(file.startsWith(root + path.sep), 'Uploaded file path escaped the selected fixture disk');
  return file;
}

function png() {
  const crc32 = data => {
    let crc = 0xffffffff;
    for (const byte of data) {
      crc ^= byte;
      for (let bit = 0; bit < 8; bit++) crc = (crc >>> 1) ^ ((crc & 1) ? 0xedb88320 : 0);
    }
    return (crc ^ 0xffffffff) >>> 0;
  };
  const chunk = (type, data) => {
    const header = Buffer.alloc(8);
    header.writeUInt32BE(data.length);
    header.write(type, 4, 'ascii');
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(Buffer.concat([Buffer.from(type), data])));
    return Buffer.concat([header, data, crc]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(32, 0);
  ihdr.writeUInt32BE(32, 4);
  ihdr[8] = 8;
  ihdr[9] = 2;
  const pixels = Buffer.alloc(32 * 97);
  for (let y = 0; y < 32; y++) for (let x = 0; x < 32; x++) {
    const offset = y * 97 + 1 + x * 3;
    pixels[offset] = x * 8;
    pixels[offset + 1] = y * 8;
    pixels[offset + 2] = 180;
  }
  return Buffer.concat([Buffer.from('89504e470d0a1a0a', 'hex'), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(pixels)), chunk('IEND', Buffer.alloc(0))]);
}

async function settled(page) {
  await page.waitForLoadState('networkidle', {timeout});
}

async function visit(actor, route, expected = 200) {
  report.currentStep = actor.name + ': visit ' + route;
  if (expected >= 400) actor.expected.set('GET ' + new URL(base + route).pathname, expected);
  const response = await actor.page.goto(base + route, {waitUntil: 'domcontentloaded'});
  assert.ok(response, route + ' returned no navigation response');
  assert.equal(response.status(), expected, actor.name + ' ' + route);
  assert.equal(response.headers()['x-fixture-tree'], tree);
  assert.equal(response.headers()['x-fixture-php'], report.php.version);
  assert.equal(response.headers()['x-fixture-framework'], tree === 'candidate' ? '13.35.0' : '6.20.42');
  report.httpRuntime = {tree, php: response.headers()['x-fixture-php'], framework: response.headers()['x-fixture-framework']};
  await settled(actor.page);
  if (expected === 200) {
    await actor.page.locator('.content-wrapper').waitFor();
    actor.csrf = await actor.page.evaluate(() => window.Dcat && Dcat.token);
    assert.ok(actor.csrf, actor.name + ' page did not expose Dcat.token');
  }
  report.pages.push({actor: actor.name, route, status: response.status(), expected});
  return response;
}

async function menu(actor, allowed) {
  const links = await actor.page.locator('.main-sidebar a[href]').evaluateAll(nodes => nodes.map(node => new URL(node.href).pathname));
  for (const resource of allowed) assert.ok(links.includes('/admin/' + resource), actor.name + ' missing menu ' + resource);
  for (const resource of ['goods', 'goods-group', 'carmis', 'coupon', 'pay', 'order', 'emailtpl', 'system-setting', 'email-test', 'import-carmis']) {
    if (!allowed.includes(resource)) assert.ok(!links.includes('/admin/' + resource), actor.name + ' leaked menu ' + resource);
  }
  report.actions.push({actor: actor.name, test: 'role-bound menu', allowed});
}

async function actor(browser, name, landing) {
  const context = await browser.newContext({viewport: {width: 1440, height: 1000}, serviceWorkers: 'block'});
  context.setDefaultTimeout(timeout);
  context.setDefaultNavigationTimeout(15000);
  const page = await context.newPage();
  const result = {name, context, page, csrf: null, expected: new Map()};
  if (name !== 'admin') result.expected.set('GET /admin', 403);
  page.on('pageerror', error => report.errors.push({actor: name, error: error.message}));
  page.on('requestfailed', request => {
    if (new URL(request.url()).origin === base) report.failedLocal.push({actor: name, url: request.url(), error: request.failure()?.errorText});
  });
  page.on('response', response => {
    const url = new URL(response.url());
    if (url.origin !== base) return;
    if (url.pathname.startsWith('/vendor/') || url.pathname.startsWith('/fixture-uploads/')) {
      const header = response.headers()['x-fixture-asset-tree'];
      report.assets[url.pathname] = {status: response.status(), tree: header};
      if (header !== tree || response.status() !== 200) report.errors.push({actor: name, asset: url.pathname, status: response.status(), tree: header});
    }
    if (response.status() >= 400) {
      const entry = {actor: name, path: url.pathname, method: response.request().method(), status: response.status()};
      const expected = result.expected.get(entry.method + ' ' + entry.path);
      (expected === entry.status ? report.expectedHTTP : report.unexpectedHTTP).push(entry);
    }
  });
  await context.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.origin === base) return route.continue();
    report.blockedExternal.push(url.origin + url.pathname);
    return route.abort();
  });
  await page.goto(base + '/admin/auth/login');
  await settled(page);
  await page.locator('[name=username]').fill('fixture-' + name);
  await page.locator('[name=password]').fill('fixture-password');
  const waiting = page.waitForResponse(response => new URL(response.url()).pathname === '/admin/auth/login' && response.request().method() === 'POST');
  waiting.catch(() => {});
  await page.getByRole('button', {name: labels.login, exact: false}).click();
  await success(await waiting, name + ' login');
  await page.waitForURL(url => url.pathname !== '/admin/auth/login');
  await settled(page);
  await visit(result, landing);
  report.actions.push({actor: name, test: 'real browser login with independent cookie/CSRF context'});
  return result;
}

async function success(response, label) {
  assert.equal(response.status(), 200, label);
  const data = await response.json();
  assert.equal(data.status, true, label + ': ' + JSON.stringify(data));
  if (data.data && data.data.type) assert.equal(data.data.type, 'success', label + ': ' + JSON.stringify(data));
  return data;
}

function fieldsBody(fields) {
  const body = new URLSearchParams();
  for (const [name, value] of Object.entries(fields)) {
    if (Array.isArray(value)) value.forEach(item => body.append(name + '[]', String(item)));
    else if (value !== undefined && value !== null) body.append(name, String(value));
  }
  return body.toString();
}

async function request(actor, method, route, fields = {}, file) {
  const url = new URL(route, base);
  assert.equal(url.origin, base, 'API request left the fixture origin');
  assert.ok(url.pathname.startsWith('/admin/') || url.pathname.startsWith('/fixture-uploads/'));
  const headers = {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'};
  const options = {method, headers, maxRedirects: 0, timeout, failOnStatusCode: false};
  if (method !== 'GET') {
    assert.ok(actor.csrf, actor.name + ' missing its own CSRF token');
    const ownFields = {...fields, _token: actor.csrf};
    headers['X-CSRF-TOKEN'] = actor.csrf;
    if (file) options.multipart = {...Object.fromEntries(Object.entries(ownFields).map(([key, value]) => [key, String(value)])), _file_: file};
    else {
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
      options.data = fieldsBody(ownFields);
    }
  }
  return actor.context.request.fetch(url.href, options);
}

async function unchanged(actor, label, method, route, fields = {}, file, expected = 403) {
  report.currentStep = actor.name + ': ' + label;
  const before = snapshot();
  const response = await request(actor, method, route, fields, file);
  const after = snapshot();
  const entry = {actor: actor.name, test: label, method, route: new URL(route, base).pathname, expected, status: response.status(), before: stateHash(before), after: stateHash(after), unchanged: stateHash(before) === stateHash(after)};
  report.permissions.push(entry);
  assert.equal(entry.status, expected, report.currentStep);
  assert.deepEqual(after, before, report.currentStep + ' changed goods, carmis, or local business files');
}

// Extract server-rendered action values without recomputing or weakening class signatures.
async function restoreControls(actor, resource, id) {
  await visit(actor, trashRoute(resource));
  const scripts = await actor.page.locator('script').allTextContents();
  const controls = [];
  for (const source of scripts) for (const match of source.matchAll(/Dcat[.]Action\(\{([\s\S]*?)calledClass:\s*'([^']+)'/g)) {
    const prefix = match[1];
    const selector = prefix.match(/selector:\s*'([^']+)'/)?.[1];
    const url = prefix.match(/url:\s*'([^']+)'/)?.[1];
    const key = prefix.match(/\bkey:\s*'([^']*)'/)?.[1];
    const data = prefix.match(/\bdata:\s*(\{[^\r\n]*\})\s*,/);
    if (!selector || !url || !data) continue;
    const parameters = JSON.parse(data[1]);
    if (parameters.model !== 'App\\Models\\' + (resource === 'goods' ? 'Goods' : 'Carmis')) continue;
    assert.equal(new URL(url, base).origin, base);
    assert.equal(await actor.page.locator(selector).count(), 1, 'Rendered action selector must exist');
    controls.push({selector, url, key, parameters, calledClass: match[2], batch: match[2].split('|', 1)[0].endsWith('BatchRestore')});
  }
  const single = controls.find(control => !control.batch && control.key === String(id));
  const batch = controls.find(control => control.batch);
  assert.ok(single && batch, resource + ' missing real single/batch restore controls');
  report.controls.push({actor: actor.name, resource, source: actor.page.url(), singleClassHash: hash(single.calledClass), batchClassHash: hash(batch.calledClass)});
  return {single, batch};
}

const actionFields = (control, key) => ({...control.parameters, _action: control.calledClass, _key: key});

async function widgetControl(actor) {
  await visit(actor, '/admin/import-carmis');
  const value = await actor.page.locator('input[type=hidden][name="_form_"]').inputValue();
  assert.ok(value, 'Import widget did not render its real class value');
  report.controls.push({actor: actor.name, widget: 'ImportCarmis', source: actor.page.url(), classHash: hash(value)});
  return value;
}

const importFields = form => ({_form_: form, goods_id: '1', carmis_list: 'DENIED-IMPORT\r\n', carmis_txt: '', remove_duplication: '1'});
const uploadFields = form => ({_form_: form, upload_column: 'carmis_txt', _column: 'carmis_txt', _id: 'extended-denied-upload', primary_key: ''});
const deniedFile = {name: 'denied.txt', mimeType: 'text/plain', buffer: Buffer.from('DENIED-UPLOAD\r\n')};

async function capture(actor, filename) {
  await actor.page.screenshot({path: path.join(output, filename), fullPage: false});
  report.screenshots.push(filename);
}

async function submit(actor, endpoint, confirmation = false) {
  report.currentStep = actor.name + ': submit ' + endpoint;
  const waiting = actor.page.waitForResponse(response => new URL(response.url()).pathname === endpoint && response.request().method() === 'POST');
  waiting.catch(() => {});
  await actor.page.getByRole('button', {name: labels.submit, exact: false}).click();
  if (confirmation) await actor.page.locator('.swal2-confirm').click();
  return success(await waiting, actor.name + ' submit ' + endpoint);
}

function fileField(actor, name) {
  return actor.page.locator('input.file-input[name="' + name + '"]').locator('..');
}

async function uploadedBytes(actor, disk, relative, expected) {
  const file = diskFile(disk, relative);
  assert.ok(fs.existsSync(file), disk + ' upload missing from the selected fixture disk');
  const stored = fs.readFileSync(file);
  const route = '/fixture-uploads/' + disk + '/' + relative.split('/').map(encodeURIComponent).join('/');
  const response = await request(actor, 'GET', route);
  assert.equal(response.status(), 200, route);
  assert.equal(response.headers()['x-fixture-asset-tree'], tree);
  const served = await response.body();
  assert.equal(hash(stored), hash(expected), 'Uploaded source bytes differ from disk bytes');
  assert.equal(hash(served), hash(stored), 'Served upload bytes differ from the selected disk');
  return {disk, relative, bytes: served.length, sha256: hash(served), status: response.status(), tree: response.headers()['x-fixture-asset-tree']};
}

async function picture(admin) {
  report.currentStep = 'admin: real picture autoUpload, save, and reload';
  await visit(admin, '/admin/goods/1/edit');
  const bytes = png();
  const field = fileField(admin, 'picture');
  const waiting = admin.page.waitForResponse(response => new URL(response.url()).pathname === '/admin/goods/1' && response.request().method() === 'POST' && (response.request().headers()['content-type'] || '').includes('multipart/form-data'));
  waiting.catch(() => {});
  await field.locator('input[type=file]').first().setInputFiles({name: 'extended-picture.png', mimeType: 'image/png', buffer: bytes});
  await success(await waiting, 'picture autoUpload');
  await admin.page.waitForFunction(() => !!document.querySelector('input[name="picture"]').value);
  const relative = await admin.page.locator('input[name="picture"]').inputValue();
  await settled(admin.page);
  await admin.page.waitForFunction(() => typeof tinymce !== 'undefined' && tinymce.editors.length >= 2 && tinymce.editors.every(editor => editor.initialized));
  await submit(admin, '/admin/goods/1');
  await admin.page.waitForURL(url => url.pathname === '/admin/goods');
  await settled(admin.page);
  assert.equal(goods(snapshot(), 1).picture, relative);
  await visit(admin, '/admin/goods/1/edit');
  assert.equal(await admin.page.locator('input[name="picture"]').inputValue(), relative);
  await admin.page.waitForFunction(() => Array.from(document.querySelectorAll('.web-uploader img')).some(image => image.complete && image.naturalWidth > 0));
  report.picture = await uploadedBytes(admin, 'admin', relative, bytes);
  await capture(admin, 'goods-picture-editor-36.png');
  report.actions.push({actor: 'admin', test: 'picture setInputFiles autoUpload, normal form save, reloaded preview, disk/HTTP/source byte equality'});
}

async function chooseRows(actor, ids) {
  for (const id of ids) {
    const checkbox = actor.page.locator('table tbody input[type=checkbox][data-id="' + id + '"]').first();
    await checkbox.check({force: true});
    assert.equal(await checkbox.isChecked(), true);
  }
}

async function openBatch(actor, selector) {
  const item = actor.page.locator(selector);
  const group = item.locator('xpath=ancestor::*[contains(concat(" ",normalize-space(@class)," ")," btn-group ")][1]');
  await group.locator('[data-toggle=dropdown]').click();
  await item.waitFor({state: 'visible'});
  return item;
}

async function batchGoods(admin) {
  report.currentStep = 'admin: real checkbox batch soft-delete and batch restore';
  await visit(admin, '/admin/goods');
  await chooseRows(admin, [1, 2]);
  const deleting = admin.page.waitForResponse(response => /^\/admin\/goods\/(?:1,2|2,1)$/.test(decodeURIComponent(new URL(response.url()).pathname)) && ['POST', 'DELETE'].includes(response.request().method()));
  deleting.catch(() => {});
  await (await openBatch(admin, '[data-action="batch-delete"]')).click();
  await admin.page.locator('.swal2-confirm').click();
  await success(await deleting, 'UI batch soft-delete');
  await settled(admin.page);
  let state = snapshot();
  assert.ok(goods(state, 1).deleted_at && goods(state, 2).deleted_at);
  assert.equal(state.goods.length, 4, 'Batch deletion must be soft deletion');
  assert.ok(state.carmis.find(row => Number(row.id) === 1).deleted_at, 'Production GoodsDeleted listener must soft-delete its cards');
  const controls = await restoreControls(admin, 'goods', 1);
  await capture(admin, 'goods-recycle-bin-36.png');
  await chooseRows(admin, [1, 2]);
  const restoring = admin.page.waitForResponse(response => new URL(response.url()).pathname === '/admin/dcat-api/action' && response.request().method() === 'POST');
  restoring.catch(() => {});
  await (await openBatch(admin, controls.batch.selector)).click();
  await admin.page.locator('.swal2-confirm').click();
  const response = await restoring;
  await success(response, 'UI batch restore');
  const posted = new URLSearchParams(response.request().postData());
  assert.deepEqual(posted.getAll('_key[]').sort(), ['1', '2']);
  assert.equal(posted.get('_action'), controls.batch.calledClass);
  await settled(admin.page);
  state = snapshot();
  assert.equal(goods(state, 1).deleted_at, null);
  assert.equal(goods(state, 2).deleted_at, null);
  assert.ok(goods(state, 4).deleted_at, 'Unselected trash record was restored');
  await visit(admin, '/admin/goods');
  for (const id of [1, 2]) assert.equal(await admin.page.locator('table tbody input[data-id="' + id + '"]').count(), 1);
  assert.ok(state.carmis.find(row => Number(row.id) === 1).deleted_at, 'Goods restore alone retains the existing separate carmis recycle behavior');
  const cardControls = await restoreControls(admin, 'carmis', 1);
  const cardRestoring = admin.page.waitForResponse(response => new URL(response.url()).pathname === '/admin/dcat-api/action' && response.request().method() === 'POST');
  cardRestoring.catch(() => {});
  const cardRow = admin.page.locator('table tbody tr').filter({has: admin.page.locator('input[data-id="1"]')});
  await cardRow.locator('.grid-dropdown-actions [data-toggle=dropdown]').click();
  await admin.page.locator(cardControls.single.selector).click();
  await admin.page.locator('.swal2-confirm').click();
  await success(await cardRestoring, 'UI restore card after goods cascade');
  await settled(admin.page);
  assert.equal(snapshot().carmis.find(row => Number(row.id) === 1).deleted_at, null);
  report.actions.push({actor: 'admin', test: 'real row checkboxes, batch menu and confirmations, soft-delete/reload/batch-restore/reload, original card cascade and separate confirmed card restore', ids: [1, 2]});
}

async function importFile(importer, admin, reader, editor, form) {
  report.currentStep = 'importer: real file upload, duplicate removal, and widget confirmation';
  const ownForm = await widgetControl(importer);
  const bytes = Buffer.from('BROWSER-CRLF-A\r\nBROWSER-CRLF-B\r\nBROWSER-CRLF-A\r\n0\r\n');
  const field = fileField(importer, 'carmis_txt');
  await field.locator('input[type=file]').first().setInputFiles({name: 'import.txt', mimeType: 'text/plain', buffer: bytes});
  await field.locator('.upload-btn').waitFor({state: 'visible'});
  assert.equal(await importer.page.locator('input[name="carmis_txt"]').inputValue(), '', 'Import file must wait for the actual upload button');
  const uploading = importer.page.waitForResponse(response => new URL(response.url()).pathname === '/admin/dcat-api/form/upload' && response.request().method() === 'POST');
  uploading.catch(() => {});
  await field.locator('.upload-btn').click();
  const uploadResponse = await uploading;
  await success(uploadResponse, 'importer file upload');
  assert.ok(uploadResponse.request().postData().includes('_form_'), 'Actual upload omitted its widget class value');
  await importer.page.waitForFunction(() => !!document.querySelector('input[name="carmis_txt"]').value);
  const relative = await importer.page.locator('input[name="carmis_txt"]').inputValue();
  report.importUpload = await uploadedBytes(importer, 'public', relative, bytes);
  const file = diskFile('public', relative);
  for (const deniedActor of [reader, editor]) {
    await unchanged(deniedActor, 'widget delete denied for a real existing upload', 'POST', '/admin/dcat-api/form/destroy-file', {_form_: form, _column: 'carmis_txt', key: relative});
    assert.ok(fs.existsSync(file), 'Denied delete removed the importer upload');
  }
  await importer.page.locator('select[name=goods_id]').selectOption('1');
  const toggle = importer.page.locator('input[type=checkbox][name=remove_duplication]');
  if (!await toggle.isChecked()) await toggle.locator('..').locator('.switchery').click();
  assert.equal(await toggle.isChecked(), true);
  const before = snapshot();
  assert.equal(active(before.carmis).length, 1);
  importer.expected.set('GET /admin/carmis', 403);
  const submission = await submit(importer, '/admin/dcat-api/form', true);
  assert.ok(ownForm);
  await importer.page.waitForURL(url => url.pathname === '/admin/carmis');
  await settled(importer.page);
  const after = snapshot();
  const added = after.carmis.filter(row => !before.carmis.some(previous => Number(previous.id) === Number(row.id)));
  assert.equal(added.length, 3);
  assert.deepEqual(added.map(row => row.carmi).sort(), ['0', 'BROWSER-CRLF-A', 'BROWSER-CRLF-B']);
  assert.ok(added.every(row => Number(row.goods_id) === 1 && Number(row.status) === 1 && row.deleted_at === null));
  assert.equal(active(after.carmis).length, 4);
  assert.deepEqual(after.carmis.filter(row => Number(row.id) <= 3), before.carmis);
  assert.deepEqual(after.goods, before.goods);
  assert.ok(!fs.existsSync(file), 'Imported source upload was not deleted from the public fixture disk');
  assert.ok(!Object.hasOwn(after.files, 'public/' + relative));
  await visit(admin, '/admin/carmis');
  for (const value of ['BROWSER-CRLF-A', 'BROWSER-CRLF-B', 'FIXTURE-ADMIN-CARD']) assert.equal(await admin.page.locator('table tbody tr').filter({hasText: value}).count(), 1);
  assert.equal(await admin.page.locator('table tbody input[type=checkbox][data-id]').count(), 4);
  report.import = {added: added.map(row => ({id: row.id, carmi: row.carmi})), active: 4, originalPublicUploadDeleted: true, sourceHash: hash(bytes), response: submission};
  report.actions.push({actor: 'importer', test: 'setInputFiles import.txt, explicit upload button, real signed widget confirm, CRLF/dedup/string0, persisted three cards plus existing card, deleted source file'});
}

(async () => {
  let browser;
  let deadline;
  try {
    report.currentStep = 'clean extended fixture preflight';
    const initial = snapshot();
    assert.deepEqual(initial.goods.map(row => Number(row.id)), [1, 2, 3, 4]);
    assert.deepEqual(active(initial.goods).map(row => Number(row.id)), [1, 2], 'Goods fixture is not clean');
    assert.deepEqual(initial.carmis.map(row => Number(row.id)), [1, 2, 3]);
    assert.deepEqual(active(initial.carmis).map(row => Number(row.id)), [1], 'Carmis fixture is not clean');
    assert.ok(initial.goods.every(row => !row.picture), 'Goods fixture already contains an uploaded picture');
    assert.deepEqual(initial.files, {}, 'Fixture business upload tree must start clean');
    report.initial = initial;
    browser = await chromium.launch({channel: 'chrome', headless: true, timeout: 15000});
    report.browser = browser.version();
    deadline = setTimeout(() => {
      report.errors.push({error: 'Extended browser run exceeded its six-minute deadline'});
      browser.close().catch(() => {});
    }, 6 * 60 * 1000);
    deadline.unref();
    const admin = await actor(browser, 'admin', '/admin/goods');
    const goodsControls = await restoreControls(admin, 'goods', 3);
    const carmisControls = await restoreControls(admin, 'carmis', 2);
    const form = await widgetControl(admin);

    const reader = await actor(browser, 'reader', '/admin/goods');
    await menu(reader, ['goods', 'import-carmis']);
    for (const route of ['/admin/goods/1', '/admin/goods/2', '/admin/import-carmis']) await visit(reader, route);
    await unchanged(reader, 'resource POST denied', 'POST', '/admin/goods', {gd_name: 'DENIED-CREATE', gd_description: 'denied', gd_keywords: 'denied', group_id: 1, type: 1, actual_price: 9});
    await unchanged(reader, 'inline PUT denied', 'PUT', '/admin/goods/1', {_editable: 1, name: 'is_open', value: 0});
    await unchanged(reader, 'inline PATCH denied', 'PATCH', '/admin/goods/1', {_editable: 1, name: 'is_open', value: 0});
    await unchanged(reader, 'resource DELETE denied', 'DELETE', '/admin/goods/1,2');
    await unchanged(reader, 'method-spoofed DELETE denied', 'POST', '/admin/goods/1,2', {_method: 'DELETE'});
    for (const controls of [goodsControls, carmisControls]) {
      await unchanged(reader, 'single Restore denied: ' + controls.single.parameters.model, 'POST', controls.single.url, actionFields(controls.single, controls.single.key));
      await unchanged(reader, 'BatchRestore denied: ' + controls.batch.parameters.model, 'POST', controls.batch.url, actionFields(controls.batch, controls === goodsControls ? [3, 4] : [2, 3]));
    }
    await unchanged(reader, 'signed widget POST denied', 'POST', '/admin/dcat-api/form', importFields(form));
    await unchanged(reader, 'signed widget upload denied', 'POST', '/admin/dcat-api/form/upload', uploadFields(form), deniedFile);
    await unchanged(reader, 'carmis GET denied', 'GET', '/admin/carmis');
    await visit(reader, '/admin/carmis', 403);
    await capture(reader, 'reader-denied-36.png');

    const editor = await actor(browser, 'goods-editor', '/admin/goods');
    await menu(editor, ['goods']);
    await visit(editor, '/admin/goods/1/edit');
    for (const [method, value] of [['PUT', 0], ['PATCH', 1]]) {
      report.currentStep = 'goods-editor: allowed inline ' + method;
      await success(await request(editor, method, '/admin/goods/1', {_editable: 1, name: 'is_open', value}), report.currentStep);
      assert.equal(Number(goods(snapshot(), 1).is_open), value);
      report.actions.push({actor: editor.name, test: 'allowed inline ' + method, id: 1, value});
    }
    await unchanged(editor, 'goods POST denied', 'POST', '/admin/goods', {gd_name: 'DENIED-CREATE'});
    await unchanged(editor, 'goods DELETE denied', 'DELETE', '/admin/goods/1');
    await unchanged(editor, 'carmis GET denied', 'GET', '/admin/carmis');
    await unchanged(editor, 'carmis PUT denied', 'PUT', '/admin/carmis/1', {_editable: 1, name: 'status', value: 2});
    await unchanged(editor, 'single Restore carmis denied', 'POST', carmisControls.single.url, actionFields(carmisControls.single, 2));
    await unchanged(editor, 'BatchRestore carmis denied', 'POST', carmisControls.batch.url, actionFields(carmisControls.batch, [2, 3]));
    await unchanged(editor, 'import page GET denied', 'GET', '/admin/import-carmis');
    await unchanged(editor, 'signed widget POST denied', 'POST', '/admin/dcat-api/form', importFields(form));
    await unchanged(editor, 'signed widget upload denied', 'POST', '/admin/dcat-api/form/upload', uploadFields(form), deniedFile);
    const editorControls = await restoreControls(editor, 'goods', 3);
    await unchanged(editor, 'atomic BatchRestore with a missing member', 'POST', editorControls.batch.url, actionFields(editorControls.batch, [4, 999999]), undefined, 404);
    await success(await request(editor, 'POST', editorControls.single.url, actionFields(editorControls.single, 3)), 'goods-editor allowed Restore Goods');
    assert.equal(goods(snapshot(), 3).deleted_at, null);
    assert.ok(goods(snapshot(), 4).deleted_at);
    await visit(editor, '/admin/goods');
    assert.equal(await editor.page.locator('table tbody input[data-id="3"]').count(), 1);
    report.actions.push({actor: editor.name, test: 'allowed Restore Goods through its page control, reloaded grid, missing-key batch atomicity'});

    await picture(admin);
    await batchGoods(admin);
    const importer = await actor(browser, 'importer', '/admin/import-carmis');
    await menu(importer, ['import-carmis']);
    await unchanged(importer, 'goods GET denied', 'GET', '/admin/goods');
    await unchanged(importer, 'goods PUT denied', 'PUT', '/admin/goods/1', {_editable: 1, name: 'is_open', value: 0});
    await unchanged(importer, 'goods DELETE denied', 'DELETE', '/admin/goods/1');
    await unchanged(importer, 'Restore Goods denied', 'POST', goodsControls.single.url, actionFields(goodsControls.single, 4));
    await importFile(importer, admin, reader, editor, form);
    report.final = snapshot();
    assert.deepEqual(report.errors, [], 'Unhandled browser or asset errors');
    assert.deepEqual(report.unexpectedHTTP, [], 'Unexpected browser HTTP errors');
    assert.deepEqual(report.failedLocal, [], 'Failed local browser requests');
    assert.ok(Object.keys(report.assets).length > 0, 'No real local admin assets were observed');
    report.status = 'passed';
  } catch (error) {
    report.status = 'failed';
    report.failure = {step: report.currentStep, message: error.message, stack: error.stack};
    try { report.final = snapshot(); } catch (inspectionError) { report.inspectionFailure = inspectionError.message; }
    process.exitCode = 1;
  } finally {
    clearTimeout(deadline);
    if (browser) await browser.close().catch(error => {
      report.status = 'failed';
      report.errors.push({error: 'browser.close: ' + error.message});
      process.exitCode = 1;
    });
    report.finishedAt = new Date().toISOString();
    report.blockedExternal = [...new Set(report.blockedExternal)];
    if (fs.existsSync(output)) {
      const filename = report.status === 'passed' ? 'browser-summary-36.json' : 'browser-failure-36.json';
      fs.writeFileSync(path.join(output, filename), JSON.stringify(report, null, 2) + '\n');
      const stale = report.status === 'passed' ? 'browser-failure-36.json' : 'browser-summary-36.json';
      fs.rmSync(path.join(output, stale), {force: true});
    }
    console.log(JSON.stringify({tree, status: report.status, pages: report.pages.length, permissions: report.permissions.length, actions: report.actions.length, failure: report.failure, report: path.join(output, report.status === 'passed' ? 'browser-summary-36.json' : 'browser-failure-36.json')}));
  }
})().catch(error => {console.error(error); process.exitCode = 1;});
