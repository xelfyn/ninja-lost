'use strict';
// CI bridge proof, not an HTTP endpoint, combat worker daemon or native client.
const assert = require('node:assert/strict');
const {SkillCatalog} = require('../../combat/data/SkillCatalog.cjs');
const {fixture} = require('../combat/fixture.cjs');
assert.equal(process.env.GITHUB_ACTIONS, 'true');
let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => {
  input += chunk;
  if (Buffer.byteLength(input) > 65536) { console.error('Bounded trusted fixture input exceeded'); process.exit(1); }
});
process.stdin.on('end', () => {
  try {
    const bundle = JSON.parse(input); assert.equal(bundle.snapshots.length, 2);
    assert.notEqual(bundle.snapshots[0].user_id, bundle.snapshots[1].user_id);
    const catalogue = new SkillCatalog(bundle.skills, bundle.effects, bundle.revision);
    const characters = bundle.snapshots.map(s => catalogue.bindLoadout(s));
    const f = fixture({skillData: catalogue, characters});
    f.battle.start(); f.clock.advance(1000);
    for (let attempts = 0; !f.battle.terminal && attempts < 100; attempts++) {
      if (f.battle.phase === 'input') {
        const actor = f.battle._activeParticipant;
        f.send(actor, 'skill', {skill_id: actor.skills[0]});
        if (f.battle.phase === 'animation') f.send(actor, 'finished');
      } else if (f.battle.phase === 'ending') f.clock.advance(2000);
      else f.clock.advance(0);
    }
    assert(f.battle.terminal, 'Damage-driven duel did not finish'); assert.equal(f.battle.terminal.reason, 'battle');
    assert.equal(f.host.socket.sent.filter(e => e.event === 'Battle.ended').length, 1);
    assert.equal(f.enemy.socket.sent.filter(e => e.event === 'Battle.ended').length, 1);
    assert.equal(f.dbCalls.length, 0);
    console.log(JSON.stringify({participant_ids: characters.map(c => c.id), winner_id: f.battle.terminal.winner_id,
      hp: [f.battle.host.stats.hp, f.battle.enemy.stats.hp], cp: [f.battle.host.stats.cp, f.battle.enemy.stats.cp],
      actions: f.battle._actionLog.length, terminals_per_participant: 1, rewards_issued: false, legacy_db_calls: 0,
      scope: 'Actual DB-owned snapshots and inherited engine; synthetic skills/RNG/clock/socket; no native game or persistent combat settlement.'}));
  } catch (error) { console.error(error.message); process.exitCode = 1; }
});
