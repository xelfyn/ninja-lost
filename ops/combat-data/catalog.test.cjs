'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const {SkillCatalog} = require('../../combat/data/SkillCatalog.cjs');
const skill = extra => ({id: 'skill_self_hit', damage: 70, cp_cost: 0, cooldown: 0, type: '2', level: 1, premium: false, ...extra});
const effect = extra => ({target: 'enemy', type: 'Debuff', effect: 'stun', effect_name: 'Self-authored stun', duration: 1, amount: 0, calc_type: '', chance: 100, ...extra});
const catalog = (row = skill(), list = [], extra = []) => new SkillCatalog([row], [{skill_id: row.id, skill_effect: list}, ...extra], 'self-v1');
test('zero CP and cooldown remain zero rather than inherited fallback 50/3', () => {
  const c = catalog(); assert.equal(c.getSkill('skill_self_hit').skill_cp_cost, 0); assert.equal(c.getSkill('skill_self_hit').skill_cooldown, 0);
  assert.equal(parseInt(0, 10) || 50, 50); assert.equal(parseInt(0, 10) || 3, 3);
});
test('zero damage preserved in metadata but blocked because legacy engine invents damage', () => {
  const c = catalog(skill({damage: 0})); assert.equal(c.inspect('skill_self_hit').metadata.damage, 0);
  assert.throws(() => c.getSkill('skill_self_hit')); assert(c.report().reasons.zero_damage_engine_fallback_unverified);
});
test('fractional damage or CP is quarantined, never truncated', () => {
  for (const change of [{damage: 0.5}, {cp_cost: 0.4}, {cooldown: 0.5}]) {
    const c = catalog(skill(change)); assert.equal(c.report().quarantined, 1); assert.deepEqual(c.inspect('skill_self_hit').metadata, skill(change));
  }
});
test('unknown or malformed numeric types cannot become fallback rules', () => {
  for (const v of [null, undefined, true, '50junk', '50', NaN, Infinity, -1]) assert.equal(catalog(skill({cp_cost: v})).report().quarantined, 1);
});
test('missing chance is blocked instead of NaN accidentally always applying', () => {
  const e = effect(); delete e.chance; const c = catalog(skill(), [e]);
  assert(Number.isNaN(parseInt(undefined, 10) ?? 100)); assert.equal(50 >= (parseInt(undefined, 10) ?? 100), false);
  assert(c.report().reasons.missing_or_invalid_chance); assert.throws(() => c.getSkillEffects('skill_self_hit'));
});
test('zero and 100 percent chance stay exact', () => {
  for (const chance of [0, 100]) assert.equal(catalog(skill(), [effect({chance})]).getSkillEffects('skill_self_hit')[0].chance, chance);
});
test('duplicate effect records quarantine only the ambiguous skill', () => {
  const c = catalog(skill(), [], [{skill_id: 'skill_self_hit', skill_effect: [effect()]}]);
  assert.equal(c.report().duplicated_effect_identities, 1); assert.throws(() => c.getSkill('skill_self_hit'));
});
test('duplicate skill identity fails the source, never last-write-wins', () => assert.throws(() => new SkillCatalog([skill(), skill({damage: 99})], [], 'self-v1')));
test('missing effect record and explicit empty effect list are distinct', () => {
  assert.throws(() => new SkillCatalog([skill()], [], 'self-v1').getSkill('skill_self_hit'));
  assert.deepEqual(catalog().getSkillEffects('skill_self_hit'), []);
});
test('orphan effects are counted without becoming skills', () => {
  const c = catalog(skill(), [], [{skill_id: 'skill_orphan', skill_effect: []}]); assert.equal(c.report().orphan_effect_identities, 1); assert.equal(c.inspect('skill_orphan'), null);
});
test('unknown, passive or conditional effects are not silently dropped', () => {
  for (const e of [effect({effect: 'unsupported_effect'}), effect({passive: true}), effect({conditional_flag: true}), effect({calc_type: 'added_percent'})]) {
    assert.throws(() => catalog(skill(), [e]).getSkill('skill_self_hit'));
  }
});
test('truthy strings are not accepted as booleans', () => {
  for (const extra of [{passive: 'false'}, {no_disperse: 'true'}]) assert.throws(() => catalog(skill(), [effect(extra)]).getSkillEffects('skill_self_hit'));
});
test('effect payload bounds and required labels are enforced', () => {
  for (const extra of [{chance: -1}, {chance: 101}, {duration: -1}, {duration: 0}, {amount: NaN}, {target: 'all'}, {type: 'buff'}, {effect_name: null}]) assert.throws(() => catalog(skill(), [effect(extra)]).getSkillEffects('skill_self_hit'));
});
test('source records and outputs cannot mutate accepted mechanics', () => {
  const input = skill(); const e = effect(); const c = catalog(input, [e]); input.cp_cost = 99; e.chance = 3;
  assert.equal(c.getSkill(input.id).skill_cp_cost, 0); assert.equal(c.getSkillEffects(input.id)[0].chance, 100);
  assert.throws(() => { c.getSkill(input.id).skill_cp_cost = 999; });
  assert.throws(() => { c.getSkillEffects(input.id)[0].chance = 999; });
});
function snapshot(extra = {}) {
  return {profile: 'nl-owned-basic-combat-v1', user_id: 10, revision: 0,
    character: {id: 20, level: 10, point_wind: 0, point_fire: 0, point_lightning: 0, point_earth: 0, point_water: 0, equipment_skills: 'skill_self_hit'},
    skills: [{id: 'skill_self_hit', source_revision: 'self-v1', level: 1, element: 2, premium: false}], ...extra};
}
test('owned snapshot maps explicit matching revision and requirements without new stats', () => {
  const result = catalog().bindLoadout(snapshot()); assert.equal(result.id, 20); assert.equal(result.level, 10); assert(Object.isFrozen(result));
});
test('snapshot cannot silently bind a different catalogue version or requirement', () => {
  for (const change of [{source_revision: 'other'}, {level: 2}, {element: 3}, {premium: true}]) {
    const s = snapshot(); Object.assign(s.skills[0], change); assert.throws(() => catalog().bindLoadout(s));
  }
});
test('snapshot rejects missing identity, forged shape, duplicate IDs and oversized stats', () => {
  for (const s of [snapshot({user_id: 0}), snapshot({revision: -1}), snapshot({profile: 'player-chosen'}), snapshot({skills: []})]) assert.throws(() => catalog().bindLoadout(s));
  const a = snapshot(); a.skills.push({...a.skills[0]}); assert.throws(() => catalog().bindLoadout(a));
  const b = snapshot(); b.character.point_fire = 10001; assert.throws(() => catalog().bindLoadout(b));
});
test('unknown ID fails closed, never generic damage with default cost', () => assert.throws(() => catalog().getSkill('skill_missing')));
