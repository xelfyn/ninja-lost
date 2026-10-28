'use strict';
// Browser plugin unavailable on the hosted runner; reuse existing temporary pinned Playwright.
const {chromium} = require('playwright'), assert = require('node:assert/strict');
const {spawn, execFileSync} = require('node:child_process');
const {once} = require('node:events');
const crypto = require('node:crypto'), fs = require('node:fs'), path = require('node:path');
const ROOT = path.resolve(__dirname, '../..'), PUBLIC = path.join(ROOT, 'public'), BASE = 'http://127.0.0.1:8003', API = BASE + '/evaluation/v1', TRAIN = API + '/training';
assert.equal(process.env.CI, 'true'); assert.equal(process.env.DB_DATABASE, 'ninja_lost_eval');
const password = crypto.randomBytes(24).toString('hex');
const env = {...process.env, APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: BASE, NL_EVALUATION_GATEWAY: 'true', NL_TRAINING: 'true',
  SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'file', PHP_CLI_SERVER_WORKERS: '4',
  NL_TRAINING_QA_PASSWORD: password, NL_TRAINING_QA_STATE: path.join(path.dirname(process.env.NL_QA_STATE), 'training.json')};
const fixture = action => JSON.parse(execFileSync('php', ['ops/training/browser-fixture.php', action], {cwd: ROOT, env, timeout: 20000, stdio: ['ignore', 'pipe', 'pipe']}).toString());
let server, browser, prepared = false, page, intentionalDisconnect = false; const errors = [];
async function startServer() {
  server = spawn('php', ['-S', '127.0.0.1:8003', '-t', PUBLIC, path.join(ROOT, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {cwd: PUBLIC, env, detached: true, stdio: ['ignore', 'ignore', 'pipe']});
  let error = ''; server.stderr.on('data', d => { error = (error + d).slice(-1200); });
  for (let i = 0; i < 100; i++) {
    if (server.exitCode !== null) throw new Error('Training HTTP server exited: ' + error);
    try { if ((await fetch(BASE + '/up', {signal: AbortSignal.timeout(1000)})).ok) return; } catch {}
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error('Training server readiness deadline');
}
async function stopServer() {
  if (!server) return; const child = server; server = null;
  const exited = child.exitCode === null ? once(child, 'exit') : Promise.resolve();
  try { process.kill(-child.pid, 'SIGTERM'); } catch (e) { if (e.code !== 'ESRCH') throw e; }
  const timer = setTimeout(() => { try { process.kill(-child.pid, 'SIGKILL'); } catch {} }, 3000);
  try { await exited; } finally { clearTimeout(timer); }
}
function observe(p) {
  p.setDefaultTimeout(15000); p.setDefaultNavigationTimeout(20000);
  p.on('pageerror', e => errors.push(e.message));
  p.on('console', m => { if (['error', 'warning'].includes(m.type()) && !/401 \(Unauthorized\)/.test(m.text()) && !m.location().url.endsWith('/favicon.ico')
      && !(intentionalDisconnect && /net::ERR_FAILED/.test(m.text()))) errors.push(m.text()); });
}
async function health(p) {
  assert.equal(new URL(p.url()).pathname, '/evaluation/v1/training/client'); assert.equal(await p.title(), 'Ninja Lost — Training');
  assert((await p.locator('body').innerText()).includes('Battle latihan')); assert.equal(await p.locator('text=Internal Server Error').count(), 0);
  assert(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 4));
}
async function screenshot(p, name) {
  const file = path.join(process.env.NL_BROWSER_PROOF_DIR, name + '.png'); await p.screenshot({path: file, fullPage: false});
  console.log('PASS temporary-' + name + ': ' + crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex') + '; not visual acceptance');
}
async function login(p, username) {
  await p.goto(TRAIN + '/client', {waitUntil: 'networkidle'}); await p.locator('#username').fill(username); await p.locator('#password').fill(password);
  await p.getByRole('button', {name: 'Masuk', exact: true}).click(); await p.locator('#session').waitFor({state: 'visible'});
  await p.locator('#loadout-note').filter({hasText: 'Revision akun'}).waitFor();
}
const waitRevision = (p, n) => p.locator(`#run-status[data-revision="${n}"]`).waitFor();
async function main() {
  const f = fixture('prepare'); prepared = true; await startServer(); browser = await chromium.launch({headless: true});
  const desktop = await browser.newContext({viewport: {width: 1440, height: 1000}}); page = await desktop.newPage(); observe(page); await login(page, f.username);
  const csrf = (await (await desktop.request.get(API + '/bootstrap')).json()).csrf_token;
  const blocked = await desktop.request.post(TRAIN + '/runs', {data: {character_id: f.character, revision: 1, request_key: crypto.randomUUID()}}); assert.equal(blocked.status(), 419);
  await page.locator('#gear-weapon').selectOption(f.weapon); await page.locator('#gear-back').selectOption(f.back); await page.locator('#skills').selectOption([f.skill]);
  await page.getByRole('button', {name: 'Simpan loadout', exact: true}).click(); await page.locator('#loadout-note').filter({hasText: 'Revision akun 2.'}).waitFor();
  await page.getByRole('button', {name: 'Mulai latihan', exact: true}).click(); await waitRevision(page, 0);
  assert((await page.locator('#player-hp').innerText()).includes('560 / 560')); assert((await page.locator('#sets').innerText()).includes('2 bagian'));
  const id = await page.locator('#run-status').getAttribute('data-run'), cp = await page.locator('#player-cp').innerText();
  await page.locator(`[data-skill="${f.skill}"]`).click(); await waitRevision(page, 1); assert.notEqual(await page.locator('#player-cp').innerText(), cp);
  const command = {revision: 1, request_key: crypto.randomUUID(), action: 'charge'};
  const send = body => desktop.request.post(`${TRAIN}/runs/${id}/actions`, {data: body, headers: {'X-CSRF-TOKEN': csrf}});
  const replies = await Promise.all(Array.from({length: 8}, () => send(command))); for (const response of replies) assert.equal(response.status(), 200);
  const values = await Promise.all(replies.map(r => r.json())); assert.equal(values.filter(v => !v.replayed).length, 1); assert(values.every(v => v.run.revision === 2));
  const rivals = await Promise.all(Array.from({length: 8}, () => send({revision: 2, request_key: crypto.randomUUID(), action: 'dodge'})));
  assert.equal(rivals.filter(r => r.status() === 200).length, 1); assert.equal(rivals.filter(r => r.status() === 409).length, 7);
  assert.equal(fixture('verify').revision, 3);
  await page.getByRole('button', {name: 'Muat ulang state', exact: true}).click(); await waitRevision(page, 3);
  console.log('PASS training-http-races: real four-worker HTTP, eight identical retries -> one action; eight competing same-revision actions -> one acceptance and seven conflicts; CSRF enforced');
  const actionUrl = `${TRAIN}/runs/${id}/actions`; intentionalDisconnect = true;
  await page.route(actionUrl, async route => { const response = await route.fetch(); assert.equal(response.status(), 200); await route.abort('failed'); });
  await page.getByRole('button', {name: 'Dodge', exact: true}).click(); await page.locator('#notice').filter({hasText: 'Identitas request dipertahankan'}).waitFor();
  assert.equal(fixture('verify').revision, 4); await page.unroute(actionUrl); await page.reload({waitUntil: 'networkidle'});
  await waitRevision(page, 4); await page.getByRole('button', {name: 'Ulangi permintaan yang sama', exact: true}).click();
  await page.locator('#notice').filter({hasText: 'tidak ada aksi tambahan'}).waitFor(); assert.equal(fixture('verify').revision, 4); intentionalDisconnect = false;
  await health(page); await screenshot(page, 'training-desktop');
  await stopServer(); await startServer(); await page.reload({waitUntil: 'networkidle'}); await waitRevision(page, 4);
  const mobile = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true}), phone = await mobile.newPage(); observe(phone);
  await login(phone, f.username); await waitRevision(phone, 4); await health(phone); await screenshot(phone, 'training-mobile');
  console.log('PASS training-recovery: response lost after committed action -> reload -> retry original intent without duplicate; PHP process restart and independent mobile session resume persisted battle');
  for (let n = 0; n < 40; n++) {
    const status = await page.locator('#run-status').innerText(); if (status.startsWith('ended')) break;
    const revision = Number(await page.locator('#run-status').getAttribute('data-revision'));
    await page.getByRole('button', {name: 'Weapon', exact: true}).click(); await waitRevision(page, revision + 1);
  }
  assert((await page.locator('#run-status').innerText()).startsWith('ended')); assert.equal(fixture('verify').status, 'ended');
  const end = await page.locator('#run-status').innerText(); await page.reload({waitUntil: 'networkidle'}); await page.locator('#run-status').filter({hasText: 'ended'}).waitFor(); assert.equal(await page.locator('#run-status').innerText(), end);
  await page.getByRole('button', {name: 'Keluar', exact: true}).click(); await page.locator('#login-form').waitFor({state: 'visible'});
  assert.equal((await desktop.request.get(`${TRAIN}/runs/${id}`)).status(), 401); assert.deepEqual(errors, []);
  console.log('PASS training-playable-loop: player UI selects owned equipment/skill -> real engine HP/CP/cooldown/set bonus -> terminal result -> reload persistence -> logout; SQL inventory/receipts/wallet/XP unchanged');
}
main().catch(e => { console.error(e.stack); process.exitCode = 1; }).finally(async () => {
  try { if (browser) await browser.close(); } catch (e) { console.error(e.message); process.exitCode = 1; }
  try { await stopServer(); } catch (e) { console.error(e.message); process.exitCode = 1; }
  if (prepared) { try { assert.equal(fixture('cleanup').cleaned, true); console.log('PASS training-cleanup'); } catch (e) { console.error('Training cleanup failed: ' + e.message); process.exitCode = 1; } }
});
