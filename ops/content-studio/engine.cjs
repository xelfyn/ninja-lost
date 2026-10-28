'use strict';
const assert = require('node:assert/strict');
const {PublishedSkillCatalog} = require('../../combat/data/PublishedSkillCatalog.cjs');
const {fixture} = require('../combat/fixture.cjs');
assert.equal(process.env.GITHUB_ACTIONS, 'true');
let input = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { input += chunk; if (Buffer.byteLength(input) > 196608) throw new Error('Bounded CI input exceeded'); });
process.stdin.on('end', () => {
  try {
    const {bundles} = JSON.parse(input); assert.equal(bundles.length, 2);
    const catalog = new PublishedSkillCatalog(bundles.flatMap(b => b.releases));
    const characters = bundles.map(b => catalog.bindLoadout(b.snapshot));
    const f = fixture({skillData: catalog, characters}); f.battle.start(); f.clock.advance(1000);
    const actor = f.battle._activeParticipant, opponent = actor === f.battle.host ? f.battle.enemy : f.battle.host;
    assert.equal(actor.character.id, characters[0].id);
    actor.stats.hp = 100;
    actor.buffs.push({effect: 'poison', type: 'Debuff', duration: 3, amount: 1, calc_type: 'number', no_disperse: false});
    opponent.buffs.push({effect: 'regenHP', type: 'Buff', duration: 3, amount: 1, calc_type: 'number', no_disperse: false});
    const hp = opponent.stats.hp, cp = actor.stats.cp, skill = actor.skills[0];
    f.send(actor, 'skill', {skill_id: skill});
    assert.equal(f.battle.phase, 'animation');
    const direct = hp - opponent.stats.hp, healing = actor.stats.hp - 100;
    const cpSpent = cp - actor.stats.cp, cooldown = actor.cooldowns[skill];
    const purified = !actor.buffs.some(b => b.effect === 'poison');
    const dispersed = !opponent.buffs.some(b => b.effect === 'regenHP');
    assert(opponent.buffs.some(b => b.effect === 'stun')); assert(opponent.buffs.some(b => b.effect === 'burn'));
    const afterHit = opponent.stats.hp;
    f.send(actor, 'finished'); f.clock.advance(0);
    const stunSkip = opponent.socket.sent.some(e => e.event === 'Battle.action.skip' && e.data.id === opponent.character.id);
    const burned = afterHit - opponent.stats.hp;
    assert.equal(f.battle._activeParticipant.character.id, actor.character.id);
    assert.equal(f.dbCalls.length, 0);
    f.battle.running = false; f.clock.jobs.clear();
    console.log(JSON.stringify({direct_damage: direct, healing, burn_damage: burned, cp_spent: cpSpent, cooldown_after_action: cooldown,
      stun_skip: stunSkip, purified, dispersed, legacy_db_calls: f.dbCalls.length, native_client_verified: false,
      scope: 'Published PHP compiler payload and DB-owned snapshots consumed by pinned source Battle/StatsCalc; synthetic clock/RNG/sockets, no gameplay reward.'}));
  } catch (e) { console.error(e.stack); process.exitCode = 1; }
});
