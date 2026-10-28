'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {SkillCatalog} = require('../../combat/data/SkillCatalog.cjs');
const {fixture} = require('../combat/fixture.cjs');
assert.equal(process.env.GITHUB_ACTIONS, 'true');
const dir = process.env.NL_COMBAT_DATA; assert(dir);
function read(name) { const file = path.join(dir, name); assert(fs.statSync(file).size <= 8 * 1024 * 1024); return JSON.parse(fs.readFileSync(file, 'utf8')); }
const skills = read('skills.json'), effects = read('skill-effect.json');
assert.equal(skills.length, 1158); assert.equal(effects.length, 835);
const c = new SkillCatalog(skills, effects, 'a53f074aaa0690398db947205956ffb97d5671aa');
console.log('SCOPED_CATALOG_REPORT', JSON.stringify(c.report()));
console.log('SOURCE_TYPE_VALUES', JSON.stringify([...new Set(skills.map(s => s.type))].sort()));
assert.equal(c.report().skill_records, c.report().admitted_to_scoped_evaluation + c.report().quarantined);
assert(c.report().quarantined > 0, 'Unresolved semantics must remain quarantined');
assert.equal(c.report().duplicated_effect_identities, 2);
for (const row of skills) {
  assert.deepEqual(c.inspect(row.id).metadata, row, 'Source metadata must not be rewritten');
  if (c.inspect(row.id).admitted) {
    assert.equal(c.getSkill(row.id).skill_cp_cost, row.cp_cost);
    assert.equal(c.getSkill(row.id).skill_cooldown, row.cooldown);
  } else assert.throws(() => c.getSkill(row.id));
}
const chosen = c.admittedIds().find(id => {
  const row = c.inspect(id); return row.mechanics.skill_cp_cost <= 60 + Math.max(10, row.metadata.level) * 40;
});
assert(chosen, 'No skill meets this deliberately restricted evaluation profile; do not relax checks silently');
const row = c.inspect(chosen), level = Math.max(10, row.metadata.level);
const character = id => ({id, name: 'Synthetic corpus participant', level, rank: 1,
  point_earth: 0, point_water: 0, point_wind: 0, point_lightning: 0, point_fire: 0, equipment_skills: chosen});
const f = fixture({skillData: c, characters: [character(1), character(2)]});
f.battle.start(); f.clock.advance(1000);
const actor = f.battle._activeParticipant, before = actor.stats.cp;
f.send(actor, 'skill', {skill_id: chosen});
assert.equal(before - actor.stats.cp, row.mechanics.skill_cp_cost);
assert.equal(actor.cooldowns[chosen], row.mechanics.skill_cooldown);
assert.equal(f.battle._actionLog.filter(x => x.type === 'skill').length, 1);
assert.notEqual(f.battle.phase, 'cancelled'); assert.equal(f.dbCalls.length, 0);
f.battle.dispose();
console.log('REAL_CORPUS_SKILL_PASS server source skill mechanics executed; exact CP/cooldown; synthetic characters/RNG; no DB/reward');
