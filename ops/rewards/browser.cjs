'use strict';
// Browser plugin is unavailable on GitHub runners; exercise real Chromium using Playwright.
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawn, execFileSync } = require('node:child_process');
const { once } = require('node:events');
const ROOT = path.resolve(__dirname, '../..'), PUBLIC = path.join(ROOT, 'public'), BASE = 'http://127.0.0.1:8000';
const email = `nlqa_browser_${crypto.randomBytes(8).toString('hex')}@example.invalid`;
const password = crypto.randomBytes(24).toString('hex'), code = `QA-${crypto.randomBytes(12).toString('hex').toUpperCase()}`;
const env = { ...process.env, APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: BASE, CACHE_STORE: 'file', SESSION_DRIVER: 'file', SESSION_SECURE_COOKIE: 'false', NL_QA_EMAIL: email, NL_QA_PASSWORD: password, NL_QA_CODE: code };
let server, browser;
const errors = [], httpErrors = [], results = [];
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
function pass(name) { console.log(`PASS ${name}`); results.push(name); }
function fixture(mode) { return execFileSync('php', ['ops/rewards/browser-fixture.php', mode], { cwd: ROOT, env, encoding: 'utf8', timeout: 30000, stdio: ['ignore', 'pipe', 'pipe'] }); }
async function start() {
  server = spawn('php', ['-S', '127.0.0.1:8000', '-t', PUBLIC, path.join(ROOT, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: PUBLIC, env, stdio: ['ignore', 'ignore', 'pipe'] });
  let stderr = ''; server.stderr.on('data', b => { stderr = (stderr + b.toString()).slice(-1500); });
  for(let i=0; i<100; i++) {
    if(server.exitCode !== null) throw Error(`Server stopped: ${stderr}`);
    try { if((await fetch(`${BASE}/up`, { signal: AbortSignal.timeout(1000) })).ok) return; } catch {}
    await sleep(200);
  }
  throw Error('Server readiness timed out');
}
async function stop() {
  if(!server || server.exitCode !== null) return;
  const child = server, done = once(child, 'exit'); child.kill('SIGTERM');
  const timer = setTimeout(() => child.kill('SIGKILL'), 3000);
  try { await done; } finally { clearTimeout(timer); server = null; }
}
function observe(page) {
  page.setDefaultTimeout(20000); page.setDefaultNavigationTimeout(20000);
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if(m.type()==='error' && !/Failed to load resource.*404/.test(m.text())) errors.push(m.text()); });
  page.on('response', r => { if(r.status()>=400 && r.url().startsWith(BASE) && !r.url().endsWith('/favicon.ico')) httpErrors.push(`${r.status()} ${new URL(r.url()).pathname}`); });
}
async function pageCheck(page, title) {
  assert((await page.title()).includes(title));
  await page.getByRole('heading', { name: title, exact: true }).first().waitFor();
  const text = await page.locator('body').innerText(); assert(text.length>150);
  assert(!/Internal Server Error|SQLSTATE\[|Ignition|Whoops!/.test(text));
  const dims = await page.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);
  assert(dims[0]<=dims[1]+4, `Document overflow ${dims}`);
}
async function shot(page, name) {
  fs.mkdirSync(process.env.NL_BROWSER_PROOF_DIR, { recursive: true });
  const file = path.join(process.env.NL_BROWSER_PROOF_DIR, name+'.png');
  const image = await page.screenshot({ path: file, fullPage: false });
  console.log(`PASS screenshot-${name}: SHA256=${crypto.createHash('sha256').update(image).digest('hex')}; temporary, not visually reviewed`);
}
async function login(page) {
  await page.goto(BASE+'/portal/login', { waitUntil: 'networkidle' });
  await page.locator('input[type=email]').fill(email); await page.locator('input[type=password]').fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await page.waitForLoadState('networkidle');
  console.log(`INFO portal after login submit: ${new URL(page.url()).pathname}`);
  await page.waitForURL(/\/portal\/home\/?$/); await page.waitForLoadState('networkidle');
}
async function main() {
  assert.equal(process.env.CI, 'true'); assert.equal(process.env.DB_DATABASE, 'ninja_lost_eval');
  const data = JSON.parse(fixture('prepare')); console.log('STEP browser: isolated fixtures prepared');
  await start(); browser = await chromium.launch({ headless: true, timeout: 20000 });
  const desktop = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  const page = await desktop.newPage(); observe(page);
  await page.goto(BASE+'/portal/home', { waitUntil: 'networkidle' }); assert.equal(new URL(page.url()).pathname, '/portal/login');
  await login(page); await pageCheck(page, 'Portal Pemain'); pass('portal-login-page-identity-and-session');
  assert.equal(await page.getByTestId('nl-tokens').innerText(), '0');
  await page.locator('#nl-campaign').selectOption(String(data.campaign));
  await page.locator('#nl-choice option[value="default"]').waitFor({ state: 'attached' });
  await page.locator('#nl-choice').selectOption('default');
  await page.getByRole('button', { name: 'Klaim starter pack', exact: true }).click();
  await page.getByText('Hadiah berhasil diterima', { exact: true }).first().waitFor();
  await page.reload({ waitUntil: 'networkidle' }); await pageCheck(page, 'Portal Pemain');
  assert.equal(await page.getByTestId('nl-tokens').innerText(), '5000'); assert.equal(await page.getByTestId('nl-gold').innerText(), '2500');
  fixture('verify-starter'); pass('starter-click-to-shared-balance-inventory-and-history');
  await stop(); await start(); await page.reload({ waitUntil: 'networkidle' }); await pageCheck(page, 'Portal Pemain');
  assert.equal(await page.getByTestId('nl-tokens').innerText(), '5000'); fixture('verify-starter'); pass('portal-session-and-reward-survive-process-restart');
  await shot(page, 'mb02-desktop');
  // Actual CSRF enforcement in APP_ENV=local; this deliberately rejected request is not a page resource.
  assert.equal((await desktop.request.post(BASE+'/portal/logout', { timeout: 10000 })).status(), 419); pass('logout-without-csrf-rejected');
  await page.goto(BASE+'/portal/catalog', { waitUntil: 'networkidle' }); await pageCheck(page, 'Katalog Konten');
  await page.locator('#nl-catalog-kind').selectOption('skill');
  await page.locator('#nl-catalog-search').fill('QA Synthetic skill');
  await page.locator('tbody tr').first().getByText('QA Synthetic skill', { exact: true }).waitFor(); pass('catalog-server-filter');
  const mobile = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, reducedMotion: 'reduce' });
  const phone = await mobile.newPage(); observe(phone); await login(phone); await pageCheck(phone, 'Portal Pemain');
  await phone.locator('#nl-code').fill(code); await phone.getByRole('button', { name: 'Redeem hadiah', exact: true }).click();
  await phone.getByText('Hadiah berhasil diterima', { exact: true }).first().waitFor(); await phone.reload({ waitUntil: 'networkidle' });
  await pageCheck(phone, 'Portal Pemain'); assert.equal(await phone.getByTestId('nl-tokens').innerText(), '5200');
  fixture('verify-redeem'); pass('mobile-redeem-refresh-and-database-receipt'); await shot(phone, 'mb02-mobile');
  await page.goto(BASE+'/portal/home', { waitUntil: 'networkidle' }); assert.equal(await page.getByTestId('nl-tokens').innerText(), '5200'); pass('two-browser-sessions-share-backend-state');
  // Submit Filament's actual logout form, including its CSRF token.
  await phone.locator('form[action$="/logout"]').first().evaluate(form => form.requestSubmit());
  await phone.waitForURL(/\/portal\/login\/?$/);
  await phone.goto(BASE+'/portal/home', { waitUntil: 'networkidle' }); assert.equal(new URL(phone.url()).pathname, '/portal/login'); pass('logout-invalidates-authenticated-session');
  assert.deepEqual(errors, []); assert.deepEqual(httpErrors, []); pass('portal-no-runtime-errors-and-no-document-overflow');
  await mobile.close(); await desktop.close();
  if(process.env.GITHUB_STEP_SUMMARY) fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, '\n## Portal browser proof\n\n'+results.map(x=>'- PASS '+x).join('\n')+'\n\nChromium desktop and 390x844 only, not real Android. Screenshot files are ephemeral and not human visually reviewed.\n');
}
main().catch(error => { console.error('FAIL portal browser: '+error.stack); process.exitCode = 1; })
.finally(async () => { if(browser) await browser.close(); await stop(); if(fs.existsSync(process.env.NL_QA_STATE || '')) { try { fixture('cleanup'); } catch { console.error('Fixture cleanup failed; isolated runner disposal required'); process.exitCode=1; } } });
