'use strict';
// Browser plugin unavailable on hosted CI. Reuse the existing temporary pinned Playwright installation.
const {chromium} = require('playwright'), assert = require('node:assert/strict');
const {spawn, execFileSync} = require('node:child_process'), {once} = require('node:events');
const path = require('node:path'), crypto = require('node:crypto');
const ROOT = path.resolve(__dirname, '../..'), PUBLIC = path.join(ROOT, 'public'), BASE = 'http://127.0.0.1:8004', API = BASE + '/evaluation/v1', TRIAL = API + '/trials';
assert.equal(process.env.CI, 'true'); assert.equal(process.env.DB_DATABASE, 'ninja_lost_eval');
const password = crypto.randomBytes(24).toString('hex');
const env = {...process.env, APP_ENV: 'local', APP_URL: BASE, APP_DEBUG: 'false', SESSION_DRIVER: 'database', SESSION_SECURE_COOKIE: 'false', CACHE_STORE: 'file',
  NL_EVALUATION_GATEWAY: 'true', NL_TRAINING: 'true', NL_MIRROR_TRIALS: 'true', PHP_CLI_SERVER_WORKERS: '4',
  NL_TRIAL_QA_PASSWORD: password, NL_TRIAL_QA_STATE: path.join(path.dirname(process.env.NL_QA_STATE), 'trials.json')};
const fixture = action => JSON.parse(execFileSync('php', ['ops/trials/browser-fixture.php', action], {cwd: ROOT, env, timeout: 20000, stdio: ['ignore', 'pipe', 'pipe']}).toString());
let server, browser, prepared = false, intentionalDrop = false; const errors = [];
async function startServer() {
  server = spawn('php', ['-S', '127.0.0.1:8004', '-t', PUBLIC, path.join(ROOT, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], {cwd: PUBLIC, env, detached: true, stdio: ['ignore', 'ignore', 'pipe']});
  let stderr = ''; server.stderr.on('data', d => { stderr = (stderr + d).slice(-1500); });
  for (let i = 0; i < 100; i++) {
    if (server.exitCode !== null) throw new Error('Server exited: ' + stderr);
    try { if ((await fetch(BASE + '/up', {signal: AbortSignal.timeout(1000)})).ok) return; } catch {}
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error('Trial server readiness deadline');
}
async function stopServer() {
  if (!server) return; const child = server; server = null; const exit = child.exitCode === null ? once(child, 'exit') : Promise.resolve();
  try { process.kill(-child.pid, 'SIGTERM'); } catch (e) { if (e.code !== 'ESRCH') throw e; }
  const timer = setTimeout(() => { try { process.kill(-child.pid, 'SIGKILL'); } catch {} }, 3000);
  try { await exit; } finally { clearTimeout(timer); }
}
function observe(page) {
  page.setDefaultTimeout(15000); page.setDefaultNavigationTimeout(20000);
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => { if (['error', 'warning'].includes(m.type()) && !/401 \(Unauthorized\)/.test(m.text()) && !m.location().url.endsWith('/favicon.ico')
    && !(intentionalDrop && /net::ERR_FAILED/.test(m.text()))) errors.push(m.text()); });
}
async function health(page, route, title) {
  assert.equal(new URL(page.url()).pathname, route); assert((await page.title()).includes(title));
  const text = await page.locator('body').innerText(); assert(text.length > 200 && !/SQLSTATE\[|Internal Server Error|Ignition|Whoops!/.test(text));
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 4));
}
async function main() {
  const f = fixture('prepare'); prepared = true; await startServer(); browser = await chromium.launch({headless: true});
  const staffContext = await browser.newContext({viewport: {width: 1440, height: 1000}}), playerContext = await browser.newContext({viewport: {width: 1440, height: 1000}});
  const staff = await staffContext.newPage(), page = await playerContext.newPage(); observe(staff); observe(page);
  await staff.goto(BASE + '/admin/login', {waitUntil: 'networkidle'}); await staff.locator('input[type="email"]').fill(f.staff_email); await staff.locator('input[type="password"]').fill(password);
  await staff.getByRole('button', {name: 'Sign in', exact: true}).click(); await staff.waitForURL(url => !url.pathname.endsWith('/login'));
  await staff.goto(BASE + '/admin/mirror-trials', {waitUntil: 'networkidle'});
  await staff.locator('#trial-slug').fill('qa-trial-browser-' + crypto.randomBytes(6).toString('hex')); await staff.locator('#trial-title').fill('QA Browser Trial');
  await staff.locator('#trial-gold').fill('25'); await staff.locator('#trial-xp').fill('120'); await staff.getByRole('button', {name: 'Simpan nonaktif', exact: true}).click();
  await staff.locator('#trial-created').waitFor(); const rule = Number(await staff.locator('#trial-created').innerText());
  await page.goto(API + '/training/client', {waitUntil: 'networkidle'}); await page.locator('#username').fill(f.username); await page.locator('#password').fill(password);
  await page.getByRole('button', {name: 'Masuk', exact: true}).click(); await page.locator('#session').waitFor({state: 'visible'});
  await page.locator('#gear-weapon').selectOption(f.weapon); await page.locator('#gear-back').selectOption(f.back); await page.locator('#skills').selectOption([f.skill]);
  await page.getByRole('button', {name: 'Simpan loadout', exact: true}).click(); await page.locator('#loadout-note').filter({hasText: 'Revision akun 2.'}).waitFor();
  assert.equal((await (await playerContext.request.get(TRIAL + '/definitions')).json()).data.length, 0);
  staff.once('dialog', d => d.accept()); await staff.getByTestId(`trial-row-${rule}`).getByRole('button', {name: 'Aktifkan', exact: true}).click();
  await staff.getByTestId(`trial-row-${rule}`).getByRole('button', {name: 'Nonaktifkan', exact: true}).waitFor();
  await health(staff, '/admin/mirror-trials', 'Tantangan Cermin');
  await page.goto(TRIAL + '/client', {waitUntil: 'networkidle'}); await page.locator('#session').waitFor({state: 'visible'});
  await page.locator('#trial').selectOption(String(rule)); await page.getByRole('button', {name: 'Mulai tantangan', exact: true}).click();
  await page.locator('#status[data-revision="0"]').waitFor(); fixture('ready'); await page.reload({waitUntil: 'networkidle'}); await page.locator('#status[data-revision="0"]').waitFor();
  const id = await page.locator('#status').getAttribute('data-battle'); assert((await page.locator('#player-hp').innerText()).includes('560'));
  await staff.getByTestId(`trial-row-${rule}`).getByRole('button', {name: 'Nonaktifkan', exact: true}).click();
  await staff.getByTestId(`trial-row-${rule}`).getByRole('button', {name: 'Aktifkan', exact: true}).waitFor();
  for (let i = 0; i < 16; i++) {
    if ((await page.locator('#status').innerText()).includes('· ended ·')) break;
    const revision = Number(await page.locator('#status').getAttribute('data-revision'));
    await page.locator(`[data-skill="${f.skill}"]`).click(); await page.locator(`#status[data-revision="${revision + 1}"]`).waitFor();
  }
  assert((await page.locator('#status').innerText()).includes('· ended ·')); assert.equal(fixture('verify').status, 'started');
  const csrf = (await (await playerContext.request.get(API + '/bootstrap')).json()).csrf_token;
  const finishUrl = `${TRIAL}/runs/${id}/finish`;
  assert.equal((await playerContext.request.post(finishUrl, {data: {}})).status(), 419);
  assert.equal((await playerContext.request.post(finishUrl, {data: {won: true, gold: 9999}, headers: {'X-CSRF-TOKEN': csrf}})).status(), 422);
  assert.equal(fixture('verify').status, 'started');
  intentionalDrop = true; let handlerDone, handlerError = null;
  const complete = new Promise(resolve => { handlerDone = resolve; });
  await page.route(finishUrl, async route => {
    let handled = false;
    try {
      const responses = await Promise.all([route.fetch(), ...Array.from({length: 7}, () => playerContext.request.post(finishUrl, {data: {}, headers: {'X-CSRF-TOKEN': csrf}}))]);
      for (const response of responses) assert.equal(response.status(), 200);
      const values = await Promise.all(responses.map(r => r.json())); assert.equal(values.filter(r => !r.replayed).length, 1);
      assert.equal(new Set(values.map(v => v.trial.result.claim_id)).size, 1); handled = true; await route.abort('failed');
    } catch (e) { handlerError = e; if (!handled) { try { await route.abort('failed'); } catch {} } }
    finally { handlerDone(); }
  }, {times: 1});
  await page.getByRole('button', {name: 'Verifikasi hasil dan selesaikan', exact: true}).click();
  await page.locator('#notice').filter({hasText: 'Identitas request dipertahankan'}).waitFor(); await complete; if (handlerError) throw handlerError;
  await page.unroute(finishUrl); const sql = fixture('verify'); assert.equal(sql.status, 'won'); assert.equal(sql.gold, 25); assert.equal(sql.xp, 120);
  await page.reload({waitUntil: 'networkidle'}); await page.locator('#status[data-state="won"]').waitFor();
  await page.getByRole('button', {name: 'Ulangi permintaan yang sama', exact: true}).click(); await page.locator('#notice').filter({hasText: 'Receipt asli dipulihkan'}).waitFor();
  assert.equal(fixture('verify').claim_id, sql.claim_id); intentionalDrop = false;
  await health(page, '/evaluation/v1/trials/client', 'Tantangan Cermin');
  const gear = await (await playerContext.request.get(API + `/training/characters/${f.character}/loadout`)).json();
  const over = await playerContext.request.post(TRIAL + '/runs', {data: {character_id: f.character, trial_id: rule, revision: gear.revision, request_key: crypto.randomUUID()}, headers: {'X-CSRF-TOKEN': csrf}});
  assert.equal(over.status(), 409); assert((await over.json()).message.includes('Kuota')); fixture('verify');
  console.log('PASS mirror-trial-browser: real admin form -> inactive rule -> activation -> owned player loadout -> engine KO -> eight simultaneous finishes -> one gold/XP receipt; pause preserves promised terms; CSRF/tampering/quota reject');
  await stopServer(); await startServer(); await page.reload({waitUntil: 'networkidle'}); await page.locator('#status[data-state="won"]').waitFor();
  assert((await page.locator('#receipt').innerText()).includes(sql.claim_id)); assert.equal(fixture('verify').claim_id, sql.claim_id);
  const mobile = await browser.newContext({viewport: {width: 390, height: 844}, isMobile: true, hasTouch: true, storageState: await playerContext.storageState()});
  const phone = await mobile.newPage(); observe(phone); await phone.goto(TRIAL + '/client', {waitUntil: 'networkidle'}); await phone.locator('#status[data-state="won"]').waitFor();
  await health(phone, '/evaluation/v1/trials/client', 'Tantangan Cermin');
  assert((await phone.locator('#receipt').innerText()).includes(sql.claim_id)); assert.deepEqual(errors, []);
  console.log('PASS mirror-trial-recovery: committed reward response loss -> retained finish intent -> original receipt; PHP restart and mobile-sized session preserve result, gold, XP and ownership; functional checks only, no visual approval');
}
main().catch(e => { console.error(e.stack); process.exitCode = 1; }).finally(async () => {
  try { if (browser) await browser.close(); } catch (e) { console.error(e.message); process.exitCode = 1; }
  try { await stopServer(); } catch (e) { console.error(e.message); process.exitCode = 1; }
  if (prepared) { try { assert.equal(fixture('cleanup').cleaned, true); console.log('PASS mirror-trial-cleanup'); } catch (e) { console.error(e.message); process.exitCode = 1; } }
});
