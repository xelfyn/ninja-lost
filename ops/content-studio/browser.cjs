'use strict';
// Browser plugin is not available on hosted CI. Use the project's pinned Playwright.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const {spawn, execFileSync} = require('node:child_process');
const {once} = require('node:events');
const ROOT = path.resolve(__dirname, '../..'), PUBLIC = path.join(ROOT, 'public'), BASE = 'http://127.0.0.1:8000';
assert.equal(process.env.CI, 'true'); assert.equal(process.env.DB_DATABASE, 'ninja_lost_eval');
const credential = () => ({email: `studio_${crypto.randomBytes(8).toString('hex')}@example.invalid`, password: crypto.randomBytes(24).toString('hex')});
const admin = credential(), player = credential();
const env = {...process.env, APP_ENV: 'local', APP_URL: BASE, APP_DEBUG: 'false', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'file',
  NL_ADMIN_EMAIL: admin.email, NL_ADMIN_PASSWORD: admin.password, NL_PLAYER_EMAIL: player.email, NL_PLAYER_PASSWORD: player.password};
const fixture = action => JSON.parse(execFileSync('php', ['ops/content-studio/browser-fixture.php', action], {cwd: ROOT, env, timeout: 20000, stdio: ['ignore', 'pipe', 'pipe']}).toString());
const errors = []; let browser, server, prepared = false, lastPage;
async function start() {
  server = spawn('php', ['-S', '127.0.0.1:8000', '-t', PUBLIC, path.join(ROOT, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {cwd: PUBLIC, env, stdio: ['ignore', 'ignore', 'pipe']});
  let stderr = ''; server.stderr.on('data', d => { stderr = (stderr + d).slice(-1500); });
  for (let i = 0; i < 120; i++) {
    if (server.exitCode !== null) throw new Error('Server exited: ' + stderr);
    try { if ((await fetch(BASE + '/up', {signal: AbortSignal.timeout(1000)})).ok) return; } catch {}
    await new Promise(r => setTimeout(r, 250));
  }
  throw new Error('Server readiness deadline');
}
async function stop() {
  if (!server || server.exitCode !== null) return;
  const child = server, exit = once(child, 'exit'); child.kill('SIGTERM'); const timer = setTimeout(() => child.kill('SIGKILL'), 4000);
  try { await exit; } finally { clearTimeout(timer); server = null; }
}
function observe(page) {
  page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(20000); lastPage = page;
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (['warning', 'error'].includes(m.type()) && !/Failed to load resource.*404/.test(m.text())) errors.push(m.text()); });
  page.on('response', r => { if (r.status() >= 400 && r.url().startsWith(BASE) && !r.url().endsWith('/favicon.ico')) errors.push(`${r.status()} ${new URL(r.url()).pathname}`); });
}
async function login(page, prefix, user) {
  await page.goto(`${BASE}/${prefix}/login`, {waitUntil: 'networkidle'});
  await page.locator('input[type="email"]').fill(user.email); await page.locator('input[type="password"]').fill(user.password);
  await page.getByRole('button', {name: 'Sign in', exact: true}).click(); await page.waitForURL(u => !u.pathname.endsWith('/login'));
}
async function health(page, pathname, heading) {
  assert.equal(new URL(page.url()).pathname, pathname); assert((await page.title()).length > 0);
  await page.getByRole('heading', {name: heading, exact: true}).first().waitFor();
  const body = await page.locator('body').innerText(); assert(body.length > 150 && !/Internal Server Error|SQLSTATE\[|Ignition|Whoops!/.test(body));
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 4));
}
async function screenshot(page, name) {
  const dir = process.env.NL_BROWSER_PROOF_DIR; assert(dir); fs.mkdirSync(dir, {recursive: true});
  const file = path.join(dir, name + '.png'); await page.screenshot({path: file, fullPage: false});
  console.log(`PASS functional-screenshot-${name}: ${crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex')}; temporary, not visual approval`);
}
function engine(expected) {
  const out = JSON.parse(execFileSync('node', ['ops/content-studio/engine.cjs'], {cwd: ROOT, env, input: JSON.stringify(fixture('bundle')), timeout: 10000, stdio: ['pipe', 'pipe', 'pipe']}).toString());
  assert.equal(out.direct_damage, expected); assert.equal(out.burn_damage, 46); assert.equal(out.cp_spent, 12); assert.equal(out.cooldown_after_action, 3);
  assert(out.stun_skip && out.purified && out.dispersed); assert.equal(out.healing, 46); assert.equal(out.legacy_db_calls, 0);
}
async function activate(page, id) {
  page.once('dialog', d => d.accept());
  await page.getByTestId('studio-release-' + id).getByRole('button', {name: 'Aktifkan evaluasi', exact: true}).click();
  await page.locator('#studio-active').filter({hasText: id}).waitFor();
}
async function main() {
  const state = fixture('prepare'); prepared = true; await start(); browser = await chromium.launch({headless: true});
  const desktop = await browser.newContext({viewport: {width: 1440, height: 1000}, reducedMotion: 'reduce'});
  const mobile = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true, reducedMotion: 'reduce'});
  const page = await desktop.newPage(), phone = await mobile.newPage(); observe(page); observe(phone);
  await login(phone, 'portal', player); await phone.goto(BASE + '/portal/catalog', {waitUntil: 'networkidle'});
  assert.equal(await phone.getByText(state.definition.name, {exact: true}).count(), 0);
  await login(page, 'admin', admin); await page.goto(BASE + '/admin/content-studio', {waitUntil: 'networkidle'});
  lastPage = page; await health(page, '/admin/content-studio', 'Content Studio — Skill');
  const d = state.definition;
  for (const [key, value] of Object.entries({id: d.id, name: d.name, lore: d.lore, damage: d.damage, cp_cost: d.cp_cost, cooldown: d.cooldown, source_url: d.source_url, rights_evidence: d.rights_evidence, supply_limit: d.supply_limit})) {
    await page.locator(`[id="form.${key}"]`).fill(String(value));
  }
  for (let i = 0; i < d.effects.length; i++) {
    await page.getByRole('button', {name: 'Tambah efek', exact: true}).click();
    const select = page.locator('select[id^="form.effects."][id$=".code"]').nth(i); await select.waitFor(); await select.selectOption(d.effects[i].code);
    for (const key of ['duration', 'amount', 'chance']) await page.locator(`input[id^="form.effects."][id$=".${key}"]`).nth(i).fill(String(d.effects[i][key]));
  }
  await page.getByRole('button', {name: 'Validasi dan tooltip', exact: true}).click();
  await page.locator('#studio-preview').filter({hasText: 'Damage dasar 80'}).waitFor(); fixture('empty');
  assert((await page.locator('#studio-preview').innerText()).includes('Stun'));
  await page.getByRole('button', {name: 'Simpan draft', exact: true}).click();
  await page.locator('#studio-draft').filter({hasText: /^\d+$/}).waitFor(); const {draft_id} = fixture('draft');
  await page.getByRole('button', {name: 'Terbitkan versi tersimpan', exact: true}).click(); await page.locator('#studio-published').waitFor();
  const first = fixture('published').release; assert.equal(await page.locator('#studio-published').innerText(), first);
  await phone.reload({waitUntil: 'networkidle'}); assert.equal(await phone.getByText(d.name, {exact: true}).count(), 0);
  await activate(page, first); fixture('active'); fixture('grant'); engine(74);
  await phone.reload({waitUntil: 'networkidle'}); await phone.getByText(d.name, {exact: true}).waitFor(); await health(phone, '/portal/catalog', 'Katalog Konten');
  console.log('PASS browser-authoring: real form -> draft -> release -> explicit activation -> common registry/grant -> existing source engine executes five effects');
  await page.locator('[id="form.damage"]').fill('90'); await page.getByRole('button', {name: 'Simpan draft', exact: true}).click();
  await page.locator('#studio-revision').filter({hasText: /^2$/}).waitFor(); fixture('unchanged-active'); engine(74);
  await page.getByRole('button', {name: 'Terbitkan versi tersimpan', exact: true}).click(); await page.locator('#studio-published').waitFor();
  const second = await page.locator('#studio-published').innerText(); assert.notEqual(first, second); await activate(page, second); engine(84);
  await activate(page, first); engine(74);
  console.log('PASS browser-versioning: draft edit does not mutate active rules; new version and rollback preserve issued ownership/receipts');
  await stop(); await start(); await page.reload({waitUntil: 'networkidle'});
  await health(page, '/admin/content-studio', 'Content Studio — Skill');
  await page.getByRole('button', {name: `Muat #${draft_id}`, exact: true}).click(); await page.locator('#studio-active').filter({hasText: first}).waitFor(); engine(74);
  await page.evaluate(() => window.scrollTo(0, 0)); await screenshot(page, 'studio-desktop');
  const phoneStaff = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true, reducedMotion: 'reduce'});
  const staffPage = await phoneStaff.newPage(); observe(staffPage); await login(staffPage, 'admin', admin);
  await staffPage.goto(BASE + '/admin/content-studio', {waitUntil: 'networkidle'}); await health(staffPage, '/admin/content-studio', 'Content Studio — Skill');
  await staffPage.getByRole('button', {name: `Muat #${draft_id}`, exact: true}).click(); await staffPage.locator('#studio-active').filter({hasText: first}).waitFor();
  await staffPage.getByRole('button', {name: 'Validasi dan tooltip', exact: true}).click(); await staffPage.locator('#studio-preview').filter({hasText: 'Damage dasar 90'}).waitFor();
  await staffPage.evaluate(() => window.scrollTo(0, 0)); await screenshot(staffPage, 'studio-mobile');
  assert.deepEqual(errors, []); console.log('PASS browser-restart-mobile: session/release persist, current draft and active version remain distinct, identities/nonblank/overlays/console/overflow clean');
  await phoneStaff.close(); await desktop.close(); await mobile.close();
}
main().catch(async error => {
  console.error(error.stack);
  if (lastPage && !lastPage.isClosed()) console.error('Control identities:', await lastPage.locator('input,select,textarea').evaluateAll(nodes => nodes.map(n => ({id: n.id, type: n.type}))).catch(() => []));
  process.exitCode = 1;
}).finally(async () => {
  if (browser) await browser.close(); await stop();
  if (prepared) execFileSync('php', ['ops/content-studio/browser-fixture.php', 'cleanup'], {cwd: ROOT, env, timeout: 20000, stdio: 'pipe'});
});
