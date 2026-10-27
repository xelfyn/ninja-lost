'use strict';
// Browser plugin not available in this CI runner; use Playwright Chromium.
// Flow: /admin/login -> real login -> operations search -> setting save -> restart -> reread.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn, execFileSync } = require('node:child_process');
const { once } = require('node:events');
const ROOT = path.resolve(__dirname, '../..');
const BASE = 'http://127.0.0.1:8000';
const proof = process.env.NL_BROWSER_PROOF_DIR;
assert(proof, 'Ephemeral proof directory required');
assert.equal(process.env.CI, 'true');
assert.equal(process.env.DB_DATABASE, 'ninja_lost_eval');
const credentials = {
  email: `browser_${crypto.randomBytes(8).toString('hex')}@example.invalid`,
  password: crypto.randomBytes(24).toString('hex'),
};
const appEnv = { ...process.env, APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: BASE,
  SESSION_DRIVER: 'file', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'array',
  NL_QA_EMAIL: credentials.email, NL_QA_PASSWORD: credentials.password };
let server;
let browser;
const results = [];
const pageErrors = [];
const consoleErrors = [];
const httpErrors = [];
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
function pass(name, detail) {
  results.push({ name, status: 'PASS', detail });
  console.log(`PASS ${name}: ${detail}`);
}
async function startServer() {
  server = spawn('php', ['-S', '127.0.0.1:8000', '-t', 'public',
    'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'],
    { cwd: ROOT, env: appEnv, stdio: ['ignore', 'ignore', 'pipe'] });
  let lastError = '';
  server.stderr.on('data', bytes => { lastError = (lastError + bytes.toString()).slice(-2000); });
  for (let i = 0; i < 120; i++) {
    if (server.exitCode !== null) throw new Error(`PHP server exited: ${lastError}`);
    try { if ((await fetch(`${BASE}/up`, { signal: AbortSignal.timeout(1000) })).ok) return; } catch {}
    await sleep(250);
  }
  throw new Error('HTTP server readiness timed out');
}
async function stopServer() {
  if (!server || server.exitCode !== null) return;
  const child = server;
  const closed = once(child, 'exit');
  child.kill('SIGTERM');
  const timer = setTimeout(() => child.kill('SIGKILL'), 5000);
  try { await closed; } finally { clearTimeout(timer); }
  server = null;
}
function observe(page) {
  page.on('pageerror', error => pageErrors.push(error.message));
  page.on('console', message => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('response', response => {
    if (response.status() >= 400 && response.url().startsWith(BASE) && !response.url().endsWith('/favicon.ico')) {
      httpErrors.push({ status: response.status(), path: new URL(response.url()).pathname });
    }
  });
}
async function screenshot(page, name) {
  const file = path.join(proof, `${name}.png`);
  await page.screenshot({ path: file, fullPage: false });
  const data = fs.readFileSync(file);
  assert.equal(data.subarray(1, 4).toString(), 'PNG');
  pass(`screenshot-${name}`, `captured ephemeral PNG, sha256=${crypto.createHash('sha256').update(data).digest('hex')}; not visually reviewed or retained`);
}
async function assertPage(page, heading) {
  assert((await page.title()).length > 0, 'Missing document title');
  await page.getByRole('heading', { name: heading, exact: true }).first().waitFor();
  assert((await page.locator('body').innerText()).length > 150, 'Blank/empty page');
  const text = await page.locator('body').innerText();
  assert(!/Internal Server Error|Whoops! Something went wrong|SQLSTATE\[|Ignition/.test(text), 'Framework error page');
  const dimensions = await page.evaluate(() => ({ width: window.innerWidth, scroll: document.documentElement.scrollWidth }));
  assert(dimensions.scroll <= dimensions.width + 4, `Horizontal overflow: ${JSON.stringify(dimensions)}`);
}
async function login(page) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.locator('input[type="email"]').fill(credentials.email);
  await page.locator('input[type="password"]').fill(credentials.password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForURL(/\/admin\/?$/);
  await page.waitForLoadState('networkidle');
}
async function main() {
  fs.mkdirSync(proof, { recursive: true });
  execFileSync('php', ['ops/browser/fixture.php', 'prepare'], { cwd: ROOT, env: appEnv, stdio: 'pipe' });
  await startServer();
  browser = await chromium.launch({ headless: true });
  const desktop = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const page = await desktop.newPage(); observe(page);
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  assert.equal(new URL(page.url()).pathname, '/admin/login');
  pass('guest-redirect', 'HTTP browser redirected to real login');
  await page.locator('input[type="email"]').fill(credentials.email);
  await page.locator('input[type="password"]').fill('deliberately-invalid-password');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.getByText('These credentials do not match our records.', { exact: false }).first().waitFor();
  assert.equal(new URL(page.url()).pathname, '/admin/login');
  pass('invalid-login', 'Wrong password rejected without entering panel');
  await login(page);
  pass('real-login', 'Form, Livewire request and session cookie accepted');
  await page.goto(`${BASE}/admin/nsx-operations`, { waitUntil: 'networkidle' });
  await assertPage(page, 'Pusat Operasi Ninja Lost');
  await screenshot(page, 'desktop-operations');
  const search = page.locator('#nsx-module-search');
  await search.fill('Game Settings');
  await page.waitForFunction(() => [...document.querySelectorAll('.nsx-module')].filter(el => el.getClientRects().length).length === 1);
  await page.locator('.nsx-module:visible').click();
  await assertPage(page, 'Game Settings');
  pass('module-search-navigation', 'Filter selects Game Settings and opens real settings page');
  const toggle = page.getByRole('switch', { name: 'Special Deals icon', exact: true });
  assert.equal(await toggle.getAttribute('aria-checked'), 'false');
  await toggle.click();
  await page.getByRole('button', { name: 'Save Settings', exact: true }).click();
  await page.getByText('Game settings saved', { exact: true }).waitFor();
  await page.reload({ waitUntil: 'networkidle' });
  assert.equal(await toggle.getAttribute('aria-checked'), 'true');
  execFileSync('php', ['ops/browser/fixture.php', 'verify'], { cwd: ROOT, env: appEnv, stdio: 'pipe' });
  pass('database-save', 'Saved through UI and confirmed by independent PHP/MySQL read');
  await stopServer(); await startServer();
  await page.goto(`${BASE}/admin/game-settings`, { waitUntil: 'networkidle' });
  await assertPage(page, 'Game Settings');
  assert.equal(await toggle.getAttribute('aria-checked'), 'true');
  execFileSync('php', ['ops/browser/fixture.php', 'verify'], { cwd: ROOT, env: appEnv, stdio: 'pipe' });
  pass('process-restart', 'New PHP HTTP process reads saved setting; file session survives');
  await screenshot(page, 'settings-after-restart');
  const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 1, reducedMotion: 'reduce' });
  const phone = await mobile.newPage(); observe(phone);
  await login(phone);
  await phone.goto(`${BASE}/admin/nsx-operations`, { waitUntil: 'networkidle' });
  await assertPage(phone, 'Pusat Operasi Ninja Lost');
  await phone.locator('#nsx-module-search').fill('zz-no-matching-module');
  await phone.waitForFunction(() => [...document.querySelectorAll('.nsx-module')].filter(el => el.getClientRects().length).length === 0);
  await phone.locator('#nsx-module-search').fill('');
  await phone.getByRole('button', { name: 'Periksa ulang', exact: true }).click();
  await phone.waitForLoadState('networkidle');
  await assertPage(phone, 'Pusat Operasi Ninja Lost');
  await screenshot(phone, 'mobile-operations');
  pass('mobile-sized-viewport', '390x844: real login, filter, refresh, meaningful content, no document overflow');
  // favicon is not supplied by imported source; it is excluded only as a known non-app icon request.
  assert.deepEqual(pageErrors, [], 'JavaScript runtime exception');
  assert.deepEqual(httpErrors, [], 'Unexpected failed application HTTP request');
  const relevantConsole = consoleErrors.filter(text => !/Failed to load resource.*404/.test(text));
  assert.deepEqual(relevantConsole, [], 'Unexpected browser console error');
  pass('browser-health', `No runtime JS/application HTTP errors; ${consoleErrors.length} console errors checked, favicon-only 404 allowed`);
  for (const route of ['/amf', '/gateway.php', '/clan/auth/login', '/crew/auth/login']) {
    const response = await desktop.request.post(`${BASE}${route}`);
    assert.equal(response.status(), 404, `Legacy route exposed: ${route}`);
  }
  pass('legacy-routes-closed', 'Game mutation endpoints remain unavailable');
  await mobile.close(); await desktop.close();
  if (process.env.GITHUB_STEP_SUMMARY) fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY,
    '\n## Browser and restart proof\n\n' + results.map(r => `- ${r.status}: ${r.name} — ${r.detail}`).join('\n') +
    '\n\nChromium desktop and mobile-sized viewport only. Screenshots captured on runner, not uploaded and not manually visually reviewed. Not a real phone/Codespaces/database-container-restart/gameplay test.\n');
}
main().catch(error => { console.error(`FAIL browser proof: ${error.stack}`); process.exitCode = 1; })
  .finally(async () => { if (browser) await browser.close(); await stopServer(); });
