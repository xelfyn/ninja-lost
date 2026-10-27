'use strict';
// Browser plugin not available on this CI runner. Use the existing pinned Playwright.
// Flow: staff login -> deliberate grant -> independent SQL -> existing player session refresh -> retry -> mobile read.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn, execFileSync } = require('node:child_process');
const { once } = require('node:events');
const ROOT = path.resolve(__dirname, '../..'), PUBLIC = path.join(ROOT, 'public'), BASE = 'http://127.0.0.1:8000';
assert.equal(process.env.CI, 'true'); assert.equal(process.env.DB_DATABASE, 'ninja_lost_eval');
const makeUser = () => ({ email: `g8_${crypto.randomBytes(8).toString('hex')}@example.invalid`, password: crypto.randomBytes(24).toString('hex') });
const admin = makeUser(), player = makeUser();
const env = { ...process.env, APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: BASE, SESSION_DRIVER: 'file', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'file',
  NL_ADMIN_EMAIL: admin.email, NL_ADMIN_PASSWORD: admin.password, NL_PLAYER_EMAIL: player.email, NL_PLAYER_PASSWORD: player.password };
const fixture = action => JSON.parse(execFileSync('php', ['ops/economy/browser-fixture.php', action], { cwd: ROOT, env, timeout: 20000, stdio: ['ignore', 'pipe', 'pipe'] }).toString());
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
  page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource.*404/.test(m.text())) errors.push(m.text()); });
  page.on('response', r => { if (r.status() >= 400 && r.url().startsWith(BASE) && !r.url().endsWith('/favicon.ico')) errors.push(`${r.status()} ${new URL(r.url()).pathname}`); });
}
async function login(page, prefix, user) {
  await page.goto(`${BASE}/${prefix}/login`, { waitUntil: 'networkidle' });
  await page.locator('input[type="email"]').fill(user.email); await page.locator('input[type="password"]').fill(user.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForURL(url => !url.pathname.endsWith('/login'));
}
async function check(page, pathname, heading) {
  assert.equal(new URL(page.url()).pathname, pathname); assert((await page.title()).length > 0);
  await page.getByRole('heading', { name: heading, exact: true }).first().waitFor();
  const body = await page.locator('body').innerText();
  assert(body.length > 150 && !/Internal Server Error|SQLSTATE\[|Ignition|Whoops!/.test(body));
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 4));
}
async function proof(page, name) {
  const folder = process.env.NL_BROWSER_PROOF_DIR; assert(folder); fs.mkdirSync(folder, { recursive: true });
  const file = path.join(folder, `${name}.png`); await page.screenshot({ path: file, fullPage: false });
  console.log(`PASS screenshot-${name}: sha256=${crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex')}; ephemeral, not visually reviewed`);
}
async function main() {
  const state = fixture('prepare'); prepared = true; await start(); browser = await chromium.launch({ headless: true });
  const staffContext = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const playerContext = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, reducedMotion: 'reduce' });
  const staff = await staffContext.newPage(), phone = await playerContext.newPage(); observe(staff); observe(phone);
  await login(phone, 'portal', player); await phone.goto(`${BASE}/portal/home`, { waitUntil: 'networkidle' });
  await check(phone, '/portal/home', 'Portal Pemain');
  await login(staff, 'admin', admin); await staff.goto(`${BASE}/admin/staff-grants`, { waitUntil: 'networkidle' });
  await check(staff, '/admin/staff-grants', 'Staff Grants');
  await staff.locator('#staff-character').fill(String(state.character)); await staff.locator('#staff-quantity').fill('37');
  await staff.locator('#staff-reason').fill('QA browser approved compensation');
  const requestKey = await staff.locator('#staff-request-key').innerText();
  await staff.getByRole('button', { name: 'Terbitkan grant', exact: true }).click();
  await staff.locator('#staff-receipt').waitFor();
  const sql = fixture('verify'); assert.equal(await staff.locator('#staff-receipt').innerText(), sql.receipt);
  assert.equal(await staff.locator('#staff-request-key').innerText(), requestKey);
  assert.equal(await staff.getByRole('button', { name: 'Terbitkan grant', exact: true }).count(), 0);
  // Exercise the real server action again on the same mounted signed Livewire snapshot.
  await staff.evaluate(async () => {
    const root = document.querySelector('[wire\\:id]');
    await window.Livewire.find(root.getAttribute('wire:id')).$call('grant');
  });
  fixture('verify'); console.log('PASS real-staff-form-and-server-action-retry: exactly one grant, ledger and revision');
  await proof(staff, 'g8-staff-desktop');
  await phone.reload({ waitUntil: 'networkidle' }); await check(phone, '/portal/home', 'Portal Pemain');
  await phone.getByText('QA browser approved compensation', { exact: false }).waitFor();
  assert((await phone.locator('body').innerText()).includes('37'));
  console.log('PASS already-open-player-session-refresh: staff grant visible from shared backend');
  await stop(); await start(); await phone.reload({ waitUntil: 'networkidle' });
  await check(phone, '/portal/home', 'Portal Pemain'); fixture('verify'); await proof(phone, 'g8-player-mobile');
  console.log('PASS staff-receipt-session-and-balance-survive-process-restart');
  await staff.setViewportSize({ width: 390, height: 844 }); await check(staff, '/admin/staff-grants', 'Staff Grants');
  await staff.getByRole('button', { name: 'Grant baru', exact: true }).click();
  await staff.getByRole('button', { name: 'Terbitkan grant', exact: true }).waitFor();
  assert.notEqual(await staff.locator('#staff-request-key').innerText(), requestKey); fixture('verify');
  await proof(staff, 'g8-staff-mobile'); console.log('PASS explicit-new-grant-mobile: new request identity without issuing reward');
  assert.deepEqual(errors, []); console.log('PASS g8-browser-health: identity, nonblank, no framework overlay, app errors or document overflow');
  await staffContext.close(); await playerContext.close();
}
main().catch(error => { console.error(error.stack); process.exitCode = 1; })
  .finally(async () => {
    if (browser) await browser.close(); await stop();
    if (prepared) execFileSync('php', ['ops/economy/browser-fixture.php', 'cleanup'], { cwd: ROOT, env, timeout: 20000, stdio: 'pipe' });
  });
