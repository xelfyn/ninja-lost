'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const {fixture} = require('./fixture.cjs');
function ready(options) { const f = fixture(options); f.battle.start(); f.clock.advance(1000); return f; }
function state(b) { return JSON.stringify([b.phase, b.turnId, b.round, b.host.stats.hp, b.enemy.stats.hp, b.host.stats.cp, b.enemy.stats.cp, b._actionLog.length]); }
function turn(f, action = 'weapon', extra) { const p = f.battle._activeParticipant; f.send(p, action, extra); if (f.battle.phase === 'animation') f.send(p, 'finished'); f.clock.advance(0); }

test('upstream baseline reproduces null-packet crash (known defect, not safe behavior)', () => {
  const f = ready({safe: false}); assert.throws(() => f.host.socket.receive('Battle.action.weapon', null));
});
test('upstream baseline accepts opponent action during animation (known defect)', () => {
  const f = ready({safe: false}); f.host.socket.receive('Battle.action.weapon', {battle_id: f.battle.id});
  f.enemy.socket.receive('Battle.action.weapon', {battle_id: f.battle.id}); assert.equal(f.battle._actionLog.length, 2);
});
test('upstream baseline permits wrong participant animation ack (known defect)', () => {
  const f = ready({safe: false}); f.host.socket.receive('Battle.action.weapon', {battle_id: f.battle.id});
  const round = f.battle.round; f.enemy.socket.receive('Battle.action.finished', {battle_id: f.battle.id}); assert.equal(f.battle.round, round + 1);
});
test('upstream baseline heals arbitrary unowned scroll (known defect)', () => {
  const f = ready({safe: false, scrolls: true}); f.battle.host.stats.hp = 10;
  f.host.socket.receive('Battle.action.scroll', {battle_id: f.battle.id, scroll_id: 'not-owned'});
  assert.ok(f.battle.host.stats.hp > 10); assert.equal(f.battle.host.scrolls.length, 0);
});
test('upstream baseline emits success-shaped result after failed DB persistence (known defect)', async () => {
  const f = ready({safe: false}); await f.battle._endBattle(f.battle.host, 'battle');
  assert.ok(f.dbCalls.some(s => s.includes('INSERT'))); assert.ok(f.host.socket.sent.find(p => p.event === 'Battle.ended' && p.data.won));
});

test('evaluation constructor rejects ranked mode, scrolls, duplicate IDs and invalid stats', () => {
  const f = fixture();
  assert.throws(() => new f.Type({...f.options, room: {mode: 'ranked'}}));
  assert.throws(() => fixture({scrolls: true}));
  assert.throws(() => new f.Type({...f.options, enemy: {...f.options.enemy, character: {...f.options.enemy.character, id: 1}}}));
  assert.throws(() => fixture({level: -1})); f.battle.dispose();
});
test('start is once only and pre-input packets do nothing', () => {
  const f = fixture(); assert.equal(f.battle.start(), true); assert.equal(f.battle.start(), false);
  const before = state(f.battle); f.send(f.host, 'weapon'); assert.equal(state(f.battle), before);
  f.clock.advance(1000); assert.equal(f.battle.phase, 'input'); assert.equal(f.battle.turnId, 1); f.battle.dispose();
});
test('null, arrays, unknown fields, spoofed victory, battle and turn IDs do not mutate', () => {
  const f = ready(); const before = state(f.battle);
  for (const data of [null, [], 'x', {}, f.packet({won: true}), f.packet({reward: 100}), f.packet({turn_id: 0}), f.packet({battle_id: 'foreign'}), f.packet({turn_id: '1'})]) {
    assert.doesNotThrow(() => f.host.socket.receive('Battle.action.weapon', data)); assert.equal(state(f.battle), before);
  }
  f.send(f.enemy, 'weapon'); assert.equal(state(f.battle), before); f.battle.dispose();
});
test('single action per input window, no opponent pre-animation action', () => {
  const f = ready(); f.send(f.host, 'weapon'); const after = state(f.battle);
  f.send(f.host, 'weapon'); f.send(f.enemy, 'weapon'); assert.equal(state(f.battle), after); assert.equal(f.battle._actionLog.length, 1); f.battle.dispose();
});
test('only acting participant may ack once; stale action rejected when their turn returns', () => {
  const f = ready(); const old = f.packet(); f.send(f.host, 'weapon');
  f.send(f.enemy, 'finished'); assert.equal(f.battle.turnId, 1);
  f.send(f.host, 'finished'); assert.equal(f.battle.turnId, 2); const before = state(f.battle);
  f.send(f.host, 'finished'); assert.equal(state(f.battle), before);
  turn(f); assert.equal(f.battle.turnId, 3); const later = state(f.battle);
  f.host.socket.receive('Battle.action.weapon', old); assert.equal(state(f.battle), later); f.battle.dispose();
});
test('real inherited damage, CP cost and per-owner cooldown are executed', () => {
  const f = ready(); f.send(f.host, 'skill', {skill_id: 'skill_eval_hit'});
  assert.equal(f.battle.enemy.stats.hp, 396); assert.equal(f.battle.host.stats.cp, 440); assert.equal(f.battle.host.cooldowns.skill_eval_hit, 3);
  f.send(f.host, 'finished'); assert.equal(f.battle.host.cooldowns.skill_eval_hit, 3);
  turn(f, 'charge'); assert.equal(f.battle.host.cooldowns.skill_eval_hit, 2);
  const hp = f.battle.enemy.stats.hp, cp = f.battle.host.stats.cp;
  f.send(f.host, 'skill', {skill_id: 'skill_eval_hit'}); assert.equal(f.battle.enemy.stats.hp, hp); assert.equal(f.battle.host.stats.cp, cp); f.battle.dispose();
});
test('invalid skill cannot indefinitely renew turn deadline', () => {
  const f = ready(); const deadline = f.battle.turnDeadline; f.clock.advance(10000);
  f.send(f.host, 'skill', {skill_id: 'skill_unavailable'}); assert.equal(f.battle.turnDeadline, deadline);
  f.clock.advance(22000); assert.equal(f.battle.turnId, 2); f.battle.dispose();
});
test('one-turn stun from inherited skill effect skips exactly one opponent turn', () => {
  const f = ready(); f.send(f.host, 'skill', {skill_id: 'skill_eval_stun'}); assert.equal(f.battle.enemy.buffs.length, 1);
  f.send(f.host, 'finished'); f.clock.advance(0);
  assert.equal(f.battle.turnId, 3); assert.equal(f.battle._activeParticipant, f.battle.host); assert.equal(f.battle.enemy.buffs.length, 0); f.battle.dispose();
});
test('charge uses inherited 20 percent CP formula and clamps to maximum', () => {
  const f = ready(); f.battle.host.stats.cp = 450; f.send(f.host, 'charge'); assert.equal(f.battle.host.stats.cp, 460); f.battle.dispose();
});
test('client timeout and scroll have no bindings or mutation authority', () => {
  const f = ready(); const before = state(f.battle); f.send(f.host, 'timeout'); f.send(f.host, 'scroll', {scroll_id: 'anything'});
  assert.equal(state(f.battle), before); f.battle.dispose();
});
test('lethal damage locks winner during death animation and never calls legacy database', () => {
  let ended = 0; const f = ready({onEnd: () => ended++}); f.battle.enemy.stats.hp = 1; f.send(f.host, 'weapon');
  assert.equal(f.battle.phase, 'ending'); const before = state(f.battle);
  f.send(f.host, 'finished'); f.send(f.enemy, 'weapon'); assert.equal(state(f.battle), before);
  f.battle.handleDisconnect(f.host.socket); f.clock.advance(100000);
  assert.equal(f.battle.terminal.winner_id, 1); assert.equal(f.battle.terminal.rewards_issued, false); assert.equal(ended, 1); assert.deepEqual(f.dbCalls, []);
});
test('missing animation ack and server timeout progress instead of hanging', () => {
  const f = ready(); f.send(f.host, 'weapon'); f.clock.advance(40000);
  assert.equal(f.battle.turnId, 2); assert.equal(f.battle.phase, 'input'); f.clock.advance(32000);
  assert.equal(f.battle.turnId, 3); assert.equal(f.battle.phase, 'input'); f.battle.dispose();
});
test('dispose clears owned timers/listeners, preserves unrelated socket listeners, no result', () => {
  const f = fixture(); const keep = () => {}; f.host.socket.on('unrelated', keep); f.battle.start(); f.battle.dispose(); f.clock.advance(100000);
  assert.equal(f.clock.jobs.size, 0); assert.equal(f.host.socket.count(), 1); assert.equal(f.enemy.socket.count(), 0);
  assert.equal(f.battle.terminal, null); assert.equal(f.battle.start(), false);
});
test('disconnect identity uses bound socket, not attacker-supplied character ID', () => {
  const f = ready(); f.battle.handleDisconnect({charId: 1}); assert.equal(f.battle.terminal, null);
  f.battle.handleDisconnect(f.host.socket); assert.equal(f.battle.terminal.winner_id, 2); assert.deepEqual(f.dbCalls, []);
});
test('a complete duel determines the winner from inherited damage, not a client win flag', () => {
  const f = ready(); let turns = 0;
  while (!f.battle.terminal && turns++ < 60) { if (f.battle.phase === 'ending') f.clock.advance(2000); else turn(f); }
  assert.ok(f.battle.terminal); assert.equal(f.battle.enemy.stats.hp, 0); assert.equal(f.battle.terminal.winner_id, 1);
  assert.equal(f.battle.terminal.reason, 'battle'); assert.equal(f.battle.terminal.rewards_issued, false); assert.deepEqual(f.dbCalls, []); assert.equal(f.clock.jobs.size, 0);
});
test('client descriptor is a template, not a completed Android build', () => {
  const xml = fs.readFileSync(path.join(process.env.NL_COMBAT_SOURCE, 'descriptor.xml'), 'utf8');
  assert.match(xml, /\[This value will be overwritten by Moonshine/); assert.match(xml, /application\/10\.0/);
  const report = JSON.parse(fs.readFileSync(path.join(process.env.NL_COMBAT_SOURCE, 'tree-audit.json'), 'utf8'));
  assert.equal(report.tree_complete, true); assert.equal(report.commit, require('./upstream-lock.json').commit);
});
test('real loopback HTTP transports a full source-engine duel; synthetic identities, no economy', async () => {
  const f = ready(); const identities = new Map([[crypto.randomUUID(), f.host], [crypto.randomUUID(), f.enemy]]);
  const tokens = [...identities.keys()];
  const server = http.createServer((req, res) => {
    const p = identities.get(req.headers.authorization);
    if (!p || req.method !== 'POST' || req.url !== '/action') { res.writeHead(403).end(); return; }
    let body = '', size = 0;
    req.on('data', chunk => { size += chunk.length; if (size > 2048) req.destroy(); else body += chunk; });
    req.on('end', () => {
      try { const input = JSON.parse(body); if (!['weapon', 'finished'].includes(input.action)) throw new Error();
        p.socket.receive('Battle.action.' + input.action, input.data); f.clock.advance(0); res.writeHead(200, {'content-type': 'application/json'}).end(JSON.stringify({phase: f.battle.phase, turn: f.battle.turnId}));
      } catch { res.writeHead(400).end(); }
    });
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const url = `http://127.0.0.1:${server.address().port}/action`;
  try {
    const bad = await fetch(url, {method: 'POST', body: '{}'}); assert.equal(bad.status, 403); await bad.text();
    let steps = 0;
    while (!f.battle.terminal && steps++ < 60) {
      if (f.battle.phase === 'ending') { f.clock.advance(2000); continue; }
      const actor = f.battle._activeParticipant === f.battle.host ? 0 : 1;
      for (const action of ['weapon', 'finished']) {
        const response = await fetch(url, {method: 'POST', headers: {authorization: tokens[actor], 'content-type': 'application/json'}, body: JSON.stringify({action, data: f.packet()})});
        assert.equal(response.status, 200); await response.json();
      }
    }
    assert.equal(f.battle.terminal?.winner_id, 1); assert.equal(f.battle.enemy.stats.hp, 0); assert.deepEqual(f.dbCalls, []);
    console.log('COMBAT_HTTP_PASS actual Battle.js + StatsCalc.js; terminal from HP; no reward/DB write; synthetic catalogue/clock/identities');
  } finally { f.battle.dispose(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
});
