'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const {PublishedSkillCatalog} = require('../../combat/data/PublishedSkillCatalog.cjs');
function release(id = 'skill_nl_unit', changes = {}, releaseId = crypto.randomUUID()) {
  const data = {schema: 1, profile: 'nl-studio-skill-v1', effect_registry: 'nl-studio-effects-v1', revision: 'nl-studio:' + releaseId, client_ready: false,
    definition: {id}, skills: [{id, type: '2', level: 1, premium: false, damage: 70, cp_cost: 0, cooldown: 0}], effects: [{skill_id: id, skill_effect: []}], ...changes};
  const payload = JSON.stringify(data); return {id: releaseId, payload, sha256: crypto.createHash('sha256').update(payload).digest('hex')};
}
function snapshot(releases) {
  return {profile: 'nl-owned-basic-combat-v1', user_id: 1, revision: 0,
    character: {id: 1, name: 'QA', rank: 1, level: 10, point_wind: 0, point_fire: 0, point_lightning: 0, point_water: 0, point_earth: 0,
      equipment_skills: releases.map(r => JSON.parse(r.payload).skills[0].id).join(',')},
    skills: releases.map(r => ({id: JSON.parse(r.payload).skills[0].id, source_revision: 'nl-studio:' + r.id, level: 1, element: 2, premium: false}))};
}
test('per-skill versions compose without requiring identical release UUIDs', () => {
  const a = release('skill_nl_a'), b = release('skill_nl_b'), c = new PublishedSkillCatalog([a, b]);
  assert.equal(c.bindLoadout(snapshot([a, b])).equipment_skills, 'skill_nl_a,skill_nl_b');
  assert.equal(c.getSkill('skill_nl_a').skill_cp_cost, 0); assert.equal(c.getSkill('skill_nl_a').skill_cooldown, 0);
});
test('identical shared skill bundle is allowed for two players', () => {
  const a = release(); const c = new PublishedSkillCatalog([a, a]); assert.equal(c.getSkill('skill_nl_unit').skill_damage, 70);
});
test('checksum corruption rejected', () => {
  const a = release(); assert.throws(() => new PublishedSkillCatalog([{...a, payload: a.payload + ' '}]));
});
test('conflicting revisions or contents of one skill rejected', () => {
  const a = release(); assert.throws(() => new PublishedSkillCatalog([a, release()]));
  const b = release('skill_nl_unit', {skills: [{id: 'skill_nl_unit', type: '2', level: 1, premium: false, damage: 71, cp_cost: 0, cooldown: 0}]}, a.id);
  assert.throws(() => new PublishedSkillCatalog([a, b]));
});
test('foreign schema registry and claimed live readiness rejected', () => {
  for (const change of [{schema: 99}, {profile: 'arbitrary'}, {effect_registry: 'unknown'}, {client_ready: true}, {revision: 'changed'}]) assert.throws(() => new PublishedSkillCatalog([release('skill_nl_unit', change)]));
});
test('mechanics quarantined by existing reader are rejected', () => {
  assert.throws(() => new PublishedSkillCatalog([release('skill_nl_unit', {skills: [{id: 'skill_nl_unit', type: '2', level: 1, premium: false, damage: 0, cp_cost: 0, cooldown: 0}]})]));
});
test('snapshot revision and equipped list must agree with pinned releases', () => {
  const a = release(), c = new PublishedSkillCatalog([a]), s = snapshot([a]);
  s.skills[0].source_revision = 'old'; assert.throws(() => c.bindLoadout(s));
  s.skills[0].source_revision = 'nl-studio:' + a.id; s.character.equipment_skills = 'skill_nl_foreign'; assert.throws(() => c.bindLoadout(s));
});
test('bounds and unknown skill reject without fallback', () => {
  const a = release(); assert.throws(() => new PublishedSkillCatalog(Array(17).fill(a)));
  assert.throws(() => new PublishedSkillCatalog([a]).getSkill('skill_nl_missing'));
});
test('mutating input after construction cannot change mechanics', () => {
  const a = release(), c = new PublishedSkillCatalog([a]); a.payload = '{}';
  assert.equal(c.getSkill('skill_nl_unit').skill_damage, 70); assert(Object.isFrozen(c.getSkill('skill_nl_unit')));
});
test('empty owned loadout still validates character stats', () => {
  const c = new PublishedSkillCatalog([]), s = snapshot([]); assert.equal(c.bindLoadout(s).equipment_skills, '');
  s.character.level = 0; assert.throws(() => c.bindLoadout(s));
});
