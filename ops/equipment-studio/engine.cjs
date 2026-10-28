'use strict';
const assert = require('node:assert/strict');
const {PublishedSkillCatalog} = require('../../combat/data/PublishedSkillCatalog.cjs');
const {EquipmentStats} = require('../../combat/data/EquipmentStats.cjs');
const {fixture} = require('../combat/fixture.cjs');
assert.equal(process.env.GITHUB_ACTIONS, 'true');
let text = '';
process.stdin.setEncoding('utf8');
process.stdin.on('data', part => { text += part; if (Buffer.byteLength(text) > 1048576) throw new Error('Equipment CI input exceeded'); });
process.stdin.on('end', () => {
  try {
    const {bundles} = JSON.parse(text); assert.equal(bundles.length, 2);
    const catalog = new PublishedSkillCatalog(bundles.flatMap(b => b.releases));
    const characters = bundles.map(b => catalog.bindLoadout(b.snapshot));
    const equipmentBundles = bundles.map(b => ({character_id: b.snapshot.character.id, equipment: b.snapshot.equipment}));
    const f = fixture({skillData: catalog, characters, equipmentBundles}); f.battle.start(); f.clock.advance(1000);
    const host = f.battle.host, enemy = f.battle.enemy;
    const base = f.upstreamStats.buildStats(characters[0]);
    const proof = new EquipmentStats(bundles[0].snapshot.equipment).derive(base);
    const initial = {hp: host.stats.maxHp, cp: host.stats.maxCp, agility: host.stats.agility, atk: host.stats.atk, def: host.stats.def};
    assert.equal(initial.atk, proof.stats.atk); assert.equal(initial.agility, proof.stats.agility);
    const first = f.battle._activeParticipant.character.id;
    // Obtain a real host weapon action even if the unequipped host loses initiative.
    if (f.battle._activeParticipant !== host) { f.send(enemy, 'charge'); f.send(enemy, 'finished'); f.clock.advance(0); }
    assert.equal(f.battle._activeParticipant, host);
    const hp = enemy.stats.hp; f.send(host, 'weapon'); const damage = hp - enemy.stats.hp;
    assert(damage > 0); assert.equal(f.battle.phase, 'animation');
    assert.equal(damage, f.upstreamStats.calcWeaponDamage(host.stats, enemy.stats).damage);
    let actions = 1;
    f.send(host, 'finished'); f.clock.advance(0);
    for (let n = 0; n < 200 && f.battle.running; n++) {
      if (f.battle.phase === 'input') { const actor = f.battle._activeParticipant; f.send(actor, 'weapon'); actions++; f.send(actor, 'finished'); }
      f.clock.advance(2500);
    }
    assert.equal(f.battle.running, false); assert.equal(Math.min(host.stats.hp, enemy.stats.hp), 0);
    assert.equal(f.dbCalls.length, 0);
    f.clock.jobs.clear();
    console.log(JSON.stringify({initial, first_actor: first, direct_damage: damage, active_sets: proof.active_sets, contributions: proof.contributions,
      actions, hp_end: [host.stats.hp, enemy.stats.hp], legacy_db_calls: 0, rewards_issued: false, native_client_verified: false}));
  } catch (error) { console.error(error.stack); process.exitCode = 1; }
});
