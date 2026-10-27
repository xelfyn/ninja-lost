'use strict';
// Browser plugin is not available on the disposable CI runner. Reuse existing pinned Playwright.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { spawn, execFileSync } = require('node:child_process');
const { once } = require('node:events');
const ROOT = path.resolve(__dirname, '../..'), PUBLIC = path.join(ROOT, 'public'), BASE = 'http://127.0.0.1:8000';
assert.equal(process.env.CI, 'true'); assert.equal(process.env.DB_DATABASE, 'ninja_lost_eval');
const credentials = () => ({ email: `shop_${crypto.randomBytes(8).toString('hex')}@example.invalid`, password: crypto.randomBytes(24).toString('hex') });
const admin = credentials(), player = credentials();
const env = { ...process.env, APP_ENV: 'local', APP_URL: BASE, APP_DEBUG: 'false', SESSION_DRIVER: 'file', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'file',
  NL_ADMIN_EMAIL: admin.email, NL_ADMIN_PASSWORD: admin.password, NL_PLAYER_EMAIL: player.email, NL_PLAYER_PASSWORD: player.password };
const fixture = action => JSON.parse(execFileSync('php', ['ops/shop/browser-fixture.php', action], { cwd: ROOT, env, timeout: 20000, stdio: ['ignore', 'pipe', 'pipe'] }).toString());
const errors = []; let browser, server, prepared = false;
async function start() {
  server = spawn('php', ['-S', '127.0.0.1:8000', '-t', PUBLIC, path.join(ROOT, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: PUBLIC, env, stdio: ['ignore', 'ignore', 'pipe'] });
  let stderr = ''; server.stderr.on('data', d => { stderr = (stderr + d.toString()).slice(-1500); });
  for (let i = 0; i < 120; i++) {
    if (server.exitCode !== null) throw new Error(`Server exited: ${stderr}`);
    try { if ((await fetch(`${BASE}/up`, { signal: AbortSignal.timeout(1000) })).ok) return; } catch {}
    await new Promise(r => setTimeout(r, 250));
  }
  throw new Error('Server readiness deadline');
}
async function stop() {
  if (!server || server.exitCode !== null) return;
  const child = server, exit = once(child, 'exit'); child.kill('SIGTERM');
  const timer = setTimeout(() => child.kill('SIGKILL'), 4000);
  try { await exit; } finally { clearTimeout(timer); server = null; }
}
function observe(page) {
  page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(20000);
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (['error', 'warning'].includes(m.type()) && !/Failed to load resource.*404/.test(m.text())) errors.push(m.text()); });
  page.on('response', r => { if (r.status() >= 400 && r.url().startsWith(BASE) && !r.url().endsWith('/favicon.ico')) errors.push(`${r.status()} ${new URL(r.url()).pathname}`); });
}
async function login(page, prefix, user) {
  await page.goto(`${BASE}/${prefix}/login`, { waitUntil: 'networkidle' });
  await page.locator('input[type="email"]').fill(user.email); await page.locator('input[type="password"]').fill(user.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForURL(url => !url.pathname.endsWith('/login'));
}
async function health(page, pathname, heading) {
  assert.equal(new URL(page.url()).pathname, pathname); assert((await page.title()).length > 0);
  await page.getByRole('heading', { name: heading, exact: true }).first().waitFor();
  const body = await page.locator('body').innerText();
  assert(body.length > 150 && !/Internal Server Error|SQLSTATE\[|Ignition|Whoops!/.test(body));
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 4));
}
async function proof(page, name) {
  const folder = process.env.NL_BROWSER_PROOF_DIR; assert(folder); fs.mkdirSync(folder, { recursive: true });
  const file = path.join(folder, name + '.png'); await page.screenshot({ path: file, fullPage: false });
  console.log(`PASS screenshot-${name}: sha256=${crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex')}; ephemeral, review only after exported`);
}
async function main() {
  const state = fixture('prepare'); prepared = true; await start(); browser = await chromium.launch({ headless: true });
  const staffContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const playerContext = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, reducedMotion: 'reduce' });
  const staff = await staffContext.newPage(), phone = await playerContext.newPage(); observe(staff); observe(phone);
  await login(phone, 'portal', player); await phone.goto(`${BASE}/portal/shop`, { waitUntil: 'networkidle' });
  await health(phone, '/portal/shop', 'Toko Evaluasi'); assert.equal(await phone.getByTestId('shop-tokens').innerText(), '100');
  await login(staff, 'admin', admin); await staff.goto(`${BASE}/admin/shop-offers`, { waitUntil: 'networkidle' });
  await health(staff, '/admin/shop-offers', 'Penawaran Toko');
  await staff.locator('#offer-slug').fill(state.slug); await staff.locator('#offer-title').fill('QA Browser Shop Offer');
  await staff.locator('#offer-catalog').fill(String(state.catalog)); await staff.locator('#offer-tokens').fill('10'); await staff.locator('#offer-gold').fill('5'); await staff.locator('#offer-max').fill('10');
  await staff.getByRole('button', { name: 'Simpan nonaktif', exact: true }).click(); await staff.locator('#offer-created').waitFor();
  const { offer } = fixture('offer'); assert.equal(await staff.locator('#offer-created').innerText(), String(offer));
  await phone.reload({ waitUntil: 'networkidle' }); assert.equal(await phone.locator(`#shop-offer option[value="${offer}"]`).count(), 0);
  staff.once('dialog', d => d.accept());
  await staff.getByTestId(`offer-row-${offer}`).getByRole('button', { name: 'Aktifkan', exact: true }).click();
  await staff.getByTestId(`offer-row-${offer}`).getByRole('button', { name: 'Nonaktifkan', exact: true }).waitFor();
  assert.equal(await staff.getByTestId(`offer-card-${offer}`).isVisible(), false);
  console.log('PASS shop-admin-create-review-activate: inactive offer not visible to player until explicit activation');
  await staff.evaluate(() => window.scrollTo(0, 0));
  await proof(staff, 'shop-admin-desktop');
  await phone.reload({ waitUntil: 'networkidle' }); await phone.locator('#shop-offer').selectOption(String(offer));
  await phone.locator('#shop-quantity').fill('2'); await phone.getByTestId('shop-total').filter({ hasText: 'Total: 20 token + 10 gold.' }).waitFor();
  const key = await phone.locator('#shop-request-key').innerText();
  await phone.getByRole('button', { name: 'Beli dengan saldo game', exact: true }).click(); await phone.locator('#shop-receipt').waitFor();
  const sql = fixture('verify'); assert.equal(await phone.locator('#shop-receipt').innerText(), sql.receipt);
  assert.equal(await phone.getByTestId('shop-tokens').innerText(), '80'); assert.equal(await phone.getByTestId('shop-gold').innerText(), '90');
  await phone.evaluate(async () => {
    const root = document.querySelector('#shop-request-key').closest('[wire\\:id]');
    await window.Livewire.find(root.getAttribute('wire:id')).$call('buy');
  });
  fixture('verify'); assert.equal(await phone.locator('#shop-request-key').innerText(), key);
  await health(phone, '/portal/shop', 'Toko Evaluasi'); await proof(phone, 'shop-player-mobile');
  console.log('PASS shop-real-purchase-and-retry: one charge, exact costs, owned inventory, signed ledger, supply and revision');
  await phone.getByRole('button', { name: 'Pembelian baru', exact: true }).click();
  await phone.locator('#shop-offer').waitFor(); assert.notEqual(await phone.locator('#shop-request-key').innerText(), key); fixture('verify');
  await phone.locator('#shop-offer').selectOption(String(offer)); await phone.locator('#shop-quantity').fill('10');
  await phone.getByTestId('shop-total').filter({ hasText: 'Total: 100 token + 50 gold.' }).waitFor();
  await phone.getByRole('button', { name: 'Beli dengan saldo game', exact: true }).click();
  await phone.getByRole('alert').filter({ hasText: 'Saldo tidak cukup' }).waitFor(); fixture('verify');
  console.log('PASS shop-visible-insufficient-funds: validation shown and original purchase remains the only debit');
  await stop(); await start(); await phone.reload({ waitUntil: 'networkidle' });
  await health(phone, '/portal/shop', 'Toko Evaluasi'); fixture('verify'); assert.equal(await phone.getByTestId('shop-tokens').innerText(), '80');
  await phone.setViewportSize({ width: 1440, height: 1000 });
  await phone.reload({ waitUntil: 'networkidle' }); await phone.evaluate(() => window.scrollTo(0, 0));
  await health(phone, '/portal/shop', 'Toko Evaluasi'); await proof(phone, 'shop-player-desktop');
  // Independent mobile context: do not mistake a desktop-sidebar resize transition for phone layout.
  const mobileContext = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, reducedMotion: 'reduce' });
  const mobile = await mobileContext.newPage(); observe(mobile); await login(mobile, 'admin', admin);
  await mobile.goto(`${BASE}/admin/shop-offers`, { waitUntil: 'networkidle' });
  await health(mobile, '/admin/shop-offers', 'Penawaran Toko');
  const card = mobile.getByTestId(`offer-card-${offer}`);
  await card.waitFor(); assert.equal(await mobile.getByTestId(`offer-row-${offer}`).isVisible(), false);
  await card.getByRole('button', { name: 'Nonaktifkan', exact: true }).click();
  await card.getByRole('button', { name: 'Aktifkan', exact: true }).waitFor();
  mobile.once('dialog', d => d.accept());
  await card.getByRole('button', { name: 'Aktifkan', exact: true }).click();
  await card.getByRole('button', { name: 'Nonaktifkan', exact: true }).waitFor();
  fixture('verify');
  await mobile.getByRole('heading', { name: 'Penawaran tersimpan', exact: true }).scrollIntoViewIfNeeded();
  await health(mobile, '/admin/shop-offers', 'Penawaran Toko'); await proof(mobile, 'shop-admin-mobile');
  console.log('PASS shop-mobile-card-controls: fresh phone context, visible card, pause and confirmed activate preserve original purchase');
  assert.deepEqual(errors, []); console.log('PASS shop-browser-health-and-restart: identities, nonblank, no overlays, no app console errors, no document overflow, balances and session persist');
  await staffContext.close(); await playerContext.close(); await mobileContext.close();
}
main().catch(error => { console.error(error.stack); process.exitCode = 1; }).finally(async () => {
  if (browser) await browser.close(); await stop();
  if (prepared) execFileSync('php', ['ops/shop/browser-fixture.php', 'cleanup'], { cwd: ROOT, env, timeout: 20000, stdio: 'pipe' });
});
