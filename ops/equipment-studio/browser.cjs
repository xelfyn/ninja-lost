'use strict';
// Browser plugin absent on hosted runner; reuse pinned Playwright, no new browser dependency.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const {spawn, execFileSync} = require('node:child_process');
const {once} = require('node:events');
const ROOT = path.resolve(__dirname, '../..'), BASE = 'http://127.0.0.1:8000', PUBLIC = path.join(ROOT, 'public');
assert.equal(process.env.CI, 'true'); assert.equal(process.env.DB_DATABASE, 'ninja_lost_eval');
const admin = {email: 'gear_' + crypto.randomBytes(8).toString('hex') + '@example.invalid', password: crypto.randomBytes(24).toString('hex')};
const env = {...process.env, APP_ENV: 'local', APP_URL: BASE, APP_DEBUG: 'false', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'file',
  NL_ADMIN_EMAIL: admin.email, NL_ADMIN_PASSWORD: admin.password};
const fixture = action => JSON.parse(execFileSync('php', ['ops/equipment-studio/browser-fixture.php', action], {cwd: ROOT, env, timeout: 20000, stdio: ['ignore', 'pipe', 'pipe']}).toString());
let server, browser, prepared = false; const errors = [];
async function start() {
  server = spawn('php', ['-S', '127.0.0.1:8000', '-t', PUBLIC, path.join(ROOT, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {cwd: PUBLIC, env, stdio: ['ignore', 'ignore', 'pipe']});
  let stderr = ''; server.stderr.on('data', d => { stderr = (stderr + d).slice(-1500); });
  for (let i = 0; i < 120; i++) {
    if (server.exitCode !== null) throw new Error('Server exited: ' + stderr);
    try { if ((await fetch(BASE + '/up', {signal: AbortSignal.timeout(1000)})).ok) return; } catch {}
    await new Promise(r => setTimeout(r, 250));
  }
  throw new Error('Server not ready');
}
async function stop() {
  if (!server || server.exitCode !== null) return;
  const child = server, exited = once(child, 'exit'); child.kill('SIGTERM'); const timer = setTimeout(() => child.kill('SIGKILL'), 4000);
  try { await exited; } finally { clearTimeout(timer); server = null; }
}
function observe(page) {
  page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(20000);
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (['error', 'warning'].includes(m.type()) && !/Failed to load resource.*404/.test(m.text())) errors.push(m.text()); });
  page.on('response', r => { if (r.url().startsWith(BASE) && r.status() >= 400 && !r.url().endsWith('/favicon.ico')) errors.push(r.status() + ' ' + new URL(r.url()).pathname); });
}
async function login(page) {
  await page.goto(BASE + '/admin/login', {waitUntil: 'networkidle'}); await page.locator('input[type=email]').fill(admin.email); await page.locator('input[type=password]').fill(admin.password);
  await page.getByRole('button', {name: 'Sign in', exact: true}).click(); await page.waitForURL(u => !u.pathname.endsWith('/login'));
}
async function health(page) {
  assert.equal(new URL(page.url()).pathname, '/admin/equipment-studio'); assert((await page.title()).length > 0);
  await page.getByRole('heading', {name: 'Content Studio — Equipment dan Set', exact: true}).first().waitFor();
  const body = await page.locator('body').innerText(); assert(body.length > 200 && !/Internal Server Error|SQLSTATE\[|Ignition|Whoops!/.test(body));
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 4));
}
async function screenshot(page, name) {
  const directory = process.env.NL_BROWSER_PROOF_DIR; assert(directory); fs.mkdirSync(directory, {recursive: true});
  const file = path.join(directory, name + '.png'); await page.screenshot({path: file, fullPage: false});
  console.log('PASS temporary-' + name + ': ' + crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex') + '; not visual acceptance');
}
function engine(damage, agility, hp, sets) {
  const result = JSON.parse(execFileSync('node', ['ops/equipment-studio/engine.cjs'], {cwd: ROOT, env, input: JSON.stringify(fixture('bundle')), timeout: 15000, stdio: ['pipe', 'pipe', 'pipe']}).toString());
  assert.equal(result.direct_damage, damage); assert.equal(result.initial.agility, agility); assert.equal(result.initial.hp, hp); assert.equal(result.active_sets.length, sets);
  assert.equal(result.legacy_db_calls, 0); assert.equal(result.rewards_issued, false); assert.equal(Math.min(...result.hp_end), 0);
}
async function activate(page, id) {
  page.once('dialog', dialog => dialog.accept()); await page.getByTestId('gear-release-' + id).getByRole('button', {name: 'Aktifkan evaluasi', exact: true}).click();
  await page.locator('#gear-active').filter({hasText: id}).waitFor();
}
async function main() {
  const state = fixture('prepare'); prepared = true; await start(); browser = await chromium.launch({headless: true});
  const desktop = await browser.newContext({viewport: {width: 1440, height: 1000}, reducedMotion: 'reduce'}), page = await desktop.newPage(); observe(page);
  await login(page); await page.goto(BASE + '/admin/equipment-studio', {waitUntil: 'networkidle'}); await health(page);
  const d = state.definition; await page.getByTestId('gear-id').fill(d.id); await page.getByTestId('gear-name').fill(d.name);
  await page.locator('textarea[wire\\:model="definition.lore"]').fill(d.lore);
  await page.getByTestId('gear-source').fill(d.source_url); await page.getByTestId('gear-rights').fill(d.rights_evidence);
  for (let i = 0; i < d.pieces.length; i++) {
    const item = d.pieces[i]; if (i > 0) await page.getByTestId('add-piece-' + item.slot).click();
    await page.getByTestId('piece-id-' + i).fill(item.id); await page.getByTestId('piece-name-' + i).fill(item.name);
    for (let j = 0; j < item.modifiers.length; j++) {
      await page.getByTestId('add-modifier-' + i).click(); const m = item.modifiers[j];
      await page.getByTestId(`pieces-${i}-stat-${j}`).selectOption(m.stat); await page.getByTestId(`pieces-${i}-unit-${j}`).selectOption(m.unit); await page.getByTestId(`pieces-${i}-amount-${j}`).fill(String(m.amount));
    }
  }
  await page.getByTestId('add-tier').click();
  for (let j = 0; j < d.bonuses[0].modifiers.length; j++) {
    if (j) await page.getByRole('button', {name: 'Tambah stat set', exact: true}).click(); const m = d.bonuses[0].modifiers[j];
    await page.getByTestId(`bonuses-0-stat-${j}`).selectOption(m.stat); await page.getByTestId(`bonuses-0-unit-${j}`).selectOption(m.unit); await page.getByTestId(`bonuses-0-amount-${j}`).fill(String(m.amount));
  }
  await page.getByRole('button', {name: 'Validasi dan tooltip', exact: true}).click(); await page.locator('#gear-preview').filter({hasText: '2 bagian'}).waitFor(); fixture('empty');
  await page.getByRole('button', {name: 'Simpan draft', exact: true}).click(); await page.locator('#gear-draft').filter({hasText: /^\d+$/}).waitFor(); fixture('draft');
  await page.getByRole('button', {name: 'Terbitkan versi tersimpan', exact: true}).click(); await page.locator('#gear-published').waitFor();
  const first = fixture('published'); assert.equal(first.head, null); await activate(page, first.release); fixture('grant'); engine(74, 44, 560, 1);
  console.log('PASS gear-browser-authoring: actual two-piece form and set threshold -> version -> activation -> shared grant/equip -> source engine');
  await page.getByTestId('pieces-0-amount-1').fill('30'); await page.getByRole('button', {name: 'Simpan draft', exact: true}).click();
  await page.locator('#gear-revision').filter({hasText: '2'}).waitFor(); engine(74, 44, 560, 1);
  await page.getByRole('button', {name: 'Terbitkan versi tersimpan', exact: true}).click(); await page.locator('#gear-published').waitFor();
  const second = fixture('published'); assert.notEqual(second.release, first.release); await activate(page, second.release); engine(86, 44, 560, 1);
  await activate(page, first.release); engine(74, 44, 560, 1); fixture('verify');
  fixture('unequip'); engine(60, 34, 460, 0); fixture('reequip'); engine(74, 44, 560, 1); fixture('verify');
  console.log('PASS gear-browser-versioning: draft isolation, 74->86->74 damage, unequip->60, re-equip->74, ownership/supply unchanged');
  await page.evaluate(() => window.scrollTo(0, 0)); await health(page); await screenshot(page, 'gear-desktop');
  await stop(); await start(); await page.reload({waitUntil: 'networkidle'}); await page.getByRole('button', {name: 'Muat', exact: true}).first().click();
  await page.locator('#gear-active').filter({hasText: first.release}).waitFor(); engine(74, 44, 560, 1);
  const mobile = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true, reducedMotion: 'reduce'}); const phone = await mobile.newPage(); observe(phone);
  await login(phone); await phone.goto(BASE + '/admin/equipment-studio', {waitUntil: 'networkidle'});
  await phone.getByRole('button', {name: 'Muat', exact: true}).first().click(); await phone.locator('#gear-active').filter({hasText: first.release}).waitFor();
  await phone.getByRole('button', {name: 'Validasi dan tooltip', exact: true}).click(); await phone.locator('#gear-preview').filter({hasText: 'Attack +30'}).waitFor();
  await phone.evaluate(() => window.scrollTo(0, 0)); await health(phone); await screenshot(phone, 'gear-mobile'); fixture('verify'); assert.deepEqual(errors, []);
  console.log('PASS gear-browser-restart: DB session, immutable active bundle and separately edited draft survive; desktop/mobile identity, content, console, overflow and controls verified');
}
main().catch(e => { console.error(e.stack); process.exitCode = 1; }).finally(async () => {
  try { if (browser) await browser.close(); await stop(); if (prepared) fixture('cleanup'); }
  catch (error) { console.error('Cleanup failed: ' + error.message); process.exitCode = 1; }
});
