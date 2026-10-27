import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { readFileSync, openSync, closeSync } from 'node:fs';
import { spawn, spawnSync } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import { EvaluationClient } from '../../clients/evaluation/client.mjs';

if (process.env.CI !== 'true' || process.env.DB_DATABASE !== 'ninja_lost_eval' || process.env.APP_ENV !== 'local' || process.env.SESSION_DRIVER !== 'database' || process.env.CACHE_STORE !== 'file') throw new Error('Isolated persistent-session HTTP runtime required.');
const state = JSON.parse(readFileSync(process.env.NL_GATEWAY_FIXTURE, 'utf8'));
const origin = 'http://127.0.0.1:8000';
const client = new EvaluationClient(origin);
let server;
const fd = openSync(process.env.NL_GATEWAY_SERVER_LOG, 'a', 0o600);
const fixture = mode => {
  const result = spawnSync('php', ['ops/gateway/http-fixture.php', mode], { encoding: 'utf8', timeout: 15000 });
  if (result.status !== 0) throw new Error(`Isolated fixture failed: ${mode}; credentials/output not printed.`);
  if (mode === 'verify') process.stdout.write(result.stdout);
};
function stop() {
  if (server) { try { process.kill(-server.pid, 'SIGTERM'); } catch (error) { if (error.code !== 'ESRCH') throw error; } server = undefined; }
}
async function start() {
  server = spawn('php', ['-S', '127.0.0.1:8000', '-t', 'public', 'public/index.php'], { detached: true, env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4' }, stdio: ['ignore', fd, fd] });
  for (let i = 0; i < 50; i++) {
    if (server.exitCode !== null) throw new Error('HTTP server exited before readiness.');
    try { const r = await fetch(`${origin}/up`, { signal: AbortSignal.timeout(500) }); if (r.status === 200) return; } catch {}
    await delay(100);
  }
  throw new Error('HTTP readiness timeout.');
}
function expect(response, status) { assert.equal(response.status, status, `HTTP status ${response.status}, expected ${status}`); return response.data; }
async function snapshot() { return expect(await client.exchange('GET', `/characters/${state.character}/state`), 200); }
try {
  await start();
  expect(await client.exchange('GET', '/characters'), 401);
  expect(await client.login(state.username, 'wrong-password'), 401);
  const login = expect(await client.login(state.username, state.password), 200); assert.equal(login.user_id, state.player);
  const intended = EvaluationClient.purchaseIntent(state.character, state.offer, 2);
  expect(await client.exchange('POST', '/purchases', intended, { csrf: false }), 419);
  const list = expect(await client.exchange('GET', '/characters'), 200); assert.equal(list.data.length, 1); assert.equal(list.data[0].id, state.character);
  expect(await client.exchange('GET', `/characters/${state.foreign_character}/inventory`), 404);
  const offers = expect(await client.exchange('GET', '/offers'), 200); assert(offers.data.some(x => x.id === state.offer)); assert(!offers.data.some(x => x.id === state.paused_offer));
  const campaigns = expect(await client.exchange('GET', '/campaigns'), 200); assert(campaigns.data.some(x => x.id === state.starter)); assert(!JSON.stringify(campaigns).includes(state.code));
  console.log('PASS actual HTTP login, session, CSRF419, ownership404 and bounded discovery');

  const starter = { character_id: state.character, campaign_id: state.starter, choice: 'default', request_key: randomUUID() };
  assert.equal(expect(await client.exchange('POST', '/starter-claims', starter), 200).replayed, false);
  assert.equal(expect(await client.exchange('POST', '/starter-claims', starter), 200).replayed, true);
  const redeem = { character_id: state.character, code: state.code, choice: 'default', request_key: randomUUID() };
  assert.equal(expect(await client.exchange('POST', '/redeem-claims', redeem), 200).replayed, false);
  assert.equal(expect(await client.exchange('POST', '/redeem-claims', redeem), 200).replayed, true);
  let current = await snapshot(); assert.equal(current.tokens, 107); assert.equal(current.gold, 102);
  expect(await client.purchase({ ...intended, price_tokens: 0, actor_id: state.admin }), 422);
  // Eight simultaneous HTTP requests, served by four PHP workers, one stable intent.
  const purchases = (await Promise.all(Array.from({ length: 8 }, () => client.purchase(intended)))).map(r => expect(r, 200));
  assert.equal(purchases.filter(r => !r.replayed).length, 1); assert.equal(new Set(purchases.map(r => r.id)).size, 1);
  current = await snapshot(); assert.equal(current.tokens, 87); assert.equal(current.gold, 92); assert.equal(current.revision, 3);
  const items = expect(await client.exchange('GET', `/characters/${state.character}/inventory`), 200).items;
  assert.equal(items.data[0].item_id, state.content.key); assert.equal(items.data[0].quantity, 2);
  expect(await client.purchase({ ...intended, quantity: 3 }), 422);
  expect(await client.exchange('POST', `/characters/${state.character}/equipment`, { revision: 0, equipment: { weapon: '' } }), 409);
  console.log('PASS actual HTTP starter/redeem retry, eight concurrent purchase requests, single debit/issuance, tamper422 and stale-save409');

  fixture('grant'); current = await snapshot(); assert.equal(current.tokens, 100); assert.equal(current.gold, 95); assert.equal(current.revision, 4);
  stop(); await delay(250); await start();
  current = await snapshot(); assert.equal(current.tokens, 100); assert.equal(current.gold, 95); assert.equal(current.revision, 4);
  assert.equal(expect(await client.purchase(intended), 200).replayed, true);
  assert.equal(expect(await client.exchange('POST', `/characters/${state.character}/equipment`, { revision: 4, equipment: { weapon: '' } }), 200).revision, 5);
  console.log('PASS real process restart: existing database session, shared staff grant, original receipt retry and current revision survive');

  const oldSession = client.fork(); expect(await client.exchange('POST', '/logout', {}), 200);
  expect(await client.exchange('GET', '/characters'), 401); expect(await oldSession.exchange('GET', '/characters'), 401);
  console.log('PASS actual HTTP logout: both updated client and captured pre-logout cookie lose access');
  fixture('verify');
} finally { stop(); closeSync(fd); }
