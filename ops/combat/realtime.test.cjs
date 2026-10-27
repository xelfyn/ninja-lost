'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const crypto = require('node:crypto');
const {setTimeout: sleep} = require('node:timers/promises');
const {fixture} = require('./fixture.cjs');

// Separate from accelerated deterministic scheduler cases: use real Node event loop/time.
const nativeClock = {now: Date.now, setTimeout, clearTimeout, setImmediate};
async function until(predicate, ms) {
  const end = Date.now() + ms;
  while (!predicate()) { if (Date.now() >= end) throw new Error('Real-time probe deadline'); await sleep(10); }
}
test('real HTTP and native timers: duplicate burst then complete damage-driven duel', {timeout: 10000}, async () => {
  let endings = 0;
  const f = fixture({clock: nativeClock, onEnd: () => endings++});
  const tokens = [crypto.randomUUID(), crypto.randomUUID()];
  const people = new Map([[tokens[0], f.host], [tokens[1], f.enemy]]);
  const server = http.createServer((req, res) => {
    const actor = people.get(req.headers.authorization);
    if (!actor || req.method !== 'POST' || req.url !== '/action') { res.writeHead(403).end(); return; }
    const chunks = []; let size = 0;
    req.on('data', b => { size += b.length; if (size > 2048) req.destroy(); else chunks.push(b); });
    req.on('end', () => {
      try {
        const msg = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        if (!['weapon', 'finished'].includes(msg.action)) throw new Error('Unsupported fixture action');
        actor.socket.receive('Battle.action.' + msg.action, msg.data);
        res.writeHead(200, {'content-type': 'application/json'}).end(JSON.stringify({phase: f.battle.phase, turn_id: f.battle.turnId}));
      } catch { res.writeHead(400).end(); }
    });
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const url = `http://127.0.0.1:${server.address().port}/action`;
  async function post(actor, action, data = f.packet()) {
    const res = await fetch(url, {method: 'POST', headers: {authorization: tokens[actor], 'content-type': 'application/json'}, body: JSON.stringify({action, data}), signal: AbortSignal.timeout(2000)});
    assert.equal(res.status, 200); return res.json();
  }
  try {
    f.battle.start(); await until(() => f.battle.phase === 'input', 2500);
    const old = f.packet(); await Promise.all(Array.from({length: 8}, () => post(0, 'weapon', old)));
    assert.equal(f.battle._actionLog.length, 1); assert.equal(f.battle.enemy.stats.hp, 420);
    await post(1, 'weapon', old); await post(1, 'finished', old);
    assert.equal(f.battle._actionLog.length, 1); assert.equal(f.battle.turnId, 1);
    await post(0, 'finished', old); assert.equal(f.battle.turnId, 2);
    for (let attempts = 0; attempts < 60 && !f.battle.terminal; attempts++) {
      if (f.battle.phase === 'ending') { await until(() => f.battle.terminal !== null, 3000); break; }
      assert.equal(f.battle.phase, 'input');
      const actor = f.battle._activeParticipant === f.battle.host ? 0 : 1;
      const data = f.packet(); await post(actor, 'weapon', data); await post(actor, 'finished', data);
    }
    assert.equal(f.battle.terminal?.winner_id, 1); assert.equal(f.battle.terminal?.reason, 'battle');
    assert.equal(f.battle.host.stats.hp, 20); assert.equal(f.battle.enemy.stats.hp, 0);
    assert.equal(f.battle._actionLog.length, 23); assert.equal(endings, 1); assert.deepEqual(f.dbCalls, []);
    assert.equal(f.host.socket.count(), 0); assert.equal(f.enemy.socket.count(), 0);
    console.log('NATIVE_TIMER_HTTP_PASS source combat: 23 actions; host HP20; enemy HP0; burst8 applied once; one terminal; DB calls0; no rewards; synthetic identities/catalogue and deterministic RNG');
  } finally { f.battle.dispose(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});
