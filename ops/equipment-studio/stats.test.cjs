'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const {EquipmentStats, wrapStats} = require('../../combat/data/EquipmentStats.cjs');
const id = '11111111-1111-4111-8111-111111111111';
function payload() { return {schema: 1, profile: 'nl-studio-equipment-v1', stat_registry: 'nl-equipment-stats-v1', revision: 'nl-equipment:' + id,
  client_ready: false, visual_status: 'reference_only_not_client_verified', definition: {id: 'gear_nl_test', pieces: [
    {id: 'wpn_nl_test', slot: 'weapon', level: 1, premium: false, modifiers: [{stat: 'agility', unit: 'flat', amount: 15}, {stat: 'atk', unit: 'flat', amount: 20}]},
    {id: 'back_nl_test', slot: 'back', level: 1, premium: false, modifiers: [{stat: 'maxHp', unit: 'flat', amount: 100}]},
  ], bonuses: [{required: 2, modifiers: [{stat: 'agility', unit: 'flat', amount: 10}, {stat: 'atk', unit: 'percent', amount: 20}]}]}}; }
function release(p = payload()) { const text = JSON.stringify(p); return {id, payload: text, sha256: crypto.createHash('sha256').update(text).digest('hex')}; }
function bundle(p = payload(), equipped = {weapon: 'wpn_nl_test', back: 'back_nl_test'}) { return {profile: 'nl-owned-equipment-v1', equipped, releases: [release(p)]}; }
const base = () => ({maxHp: 460, maxCp: 460, hp: 460, cp: 460, agility: 19, atk: 50, def: 20, critPct: 5, dodgePct: 5, lightning: 0});
test('flat plus additive percent applies once and reports each contribution', () => {
  const out = new EquipmentStats(bundle()).derive(base()); assert.equal(out.stats.maxHp, 560); assert.equal(out.stats.hp, 560);
  assert.equal(out.stats.agility, 44); assert.equal(out.stats.atk, 84); assert.equal(out.active_sets.length, 1); assert.equal(out.contributions.length, 5);
});
test('unequip removes threshold but does not remove remaining item modifier', () => {
  const out = new EquipmentStats(bundle(payload(), {weapon: 'wpn_nl_test'})).derive(base());
  assert.equal(out.stats.agility, 34); assert.equal(out.stats.maxHp, 460); assert.equal(out.stats.atk, 70); assert.deepEqual(out.active_sets, []);
});
test('no equipment leaves upstream stats unchanged apart from profile marker', () => {
  const out = new EquipmentStats({profile: 'nl-owned-equipment-v1', equipped: {}, releases: []}).derive(base()).stats;
  delete out.equipmentProfile; assert.deepEqual(out, base());
});
test('fresh recomputation is idempotent; already derived stats reject double application', () => {
  const gear = new EquipmentStats(bundle()); assert.deepEqual(gear.derive(base()), gear.derive(base()));
  assert.throws(() => gear.derive(gear.derive(base()).stats), /Fresh/);
});
test('piece and modifier order do not change final stats', () => {
  const a = new EquipmentStats(bundle()).derive(base()).stats, p = payload(); p.definition.pieces.reverse();
  p.definition.bonuses[0].modifiers.reverse(); assert.deepEqual(new EquipmentStats(bundle(p)).derive(base()).stats, a);
});
test('relative percentages add and rounding happens after flat total', () => {
  const p = payload(); p.definition.pieces[0].modifiers = [{stat: 'def', unit: 'flat', amount: 1}, {stat: 'def', unit: 'percent', amount: 20}];
  p.definition.pieces[1].modifiers = [{stat: 'def', unit: 'percent', amount: 30}]; p.definition.bonuses = [];
  assert.equal(new EquipmentStats(bundle(p)).derive(base()).stats.def, 31);
});
test('probability modifiers use points and explicit caps, not relative percentage', () => {
  const p = payload(); p.definition.pieces[0].modifiers = [{stat: 'critPct', unit: 'points', amount: 100}, {stat: 'dodgePct', unit: 'points', amount: 95}];
  const stats = new EquipmentStats(bundle(p)).derive(base()).stats; assert.equal(stats.critPct, 100); assert.equal(stats.dodgePct, 95);
  p.definition.pieces[0].modifiers[0].unit = 'percent'; assert.throws(() => new EquipmentStats(bundle(p)), /stat\/unit/);
});
test('unknown stat, unit, fraction, negative and duplicate modifier reject', () => {
  for (const m of [{stat: 'accuracy', unit: 'flat', amount: 5}, {stat: 'atk', unit: 'relative', amount: 5},
    {stat: 'atk', unit: 'flat', amount: 0.1}, {stat: 'atk', unit: 'flat', amount: -1}, {stat: 'atk', unit: 'flat', amount: true}]) {
    const p = payload(); p.definition.pieces[0].modifiers = [m]; assert.throws(() => new EquipmentStats(bundle(p)));
  }
  const p = payload(); p.definition.pieces[0].modifiers.push(p.definition.pieces[0].modifiers[0]); assert.throws(() => new EquipmentStats(bundle(p)), /Duplicate/);
});
test('duplicate slots, unknown IDs and extraneous release cannot contribute', () => {
  const p = payload(); p.definition.pieces[1].slot = 'weapon'; assert.throws(() => new EquipmentStats(bundle(p)));
  assert.throws(() => new EquipmentStats(bundle(payload(), {weapon: 'wpn_nl_missing'})), /Extraneous|no published/);
  assert.throws(() => new EquipmentStats(bundle(payload(), {})), /Extraneous/);
});
test('same bundle cannot contribute twice or smuggle another revision', () => {
  const b = bundle(); b.releases.push(b.releases[0]); assert.throws(() => new EquipmentStats(b), /Duplicate/);
});
test('bad checksum, registry, profile and fake client readiness reject', () => {
  const b = bundle(); b.releases[0].sha256 = '0'.repeat(64); assert.throws(() => new EquipmentStats(b), /checksum/);
  for (const field of ['profile', 'stat_registry', 'client_ready']) { const p = payload(); p[field] = field === 'client_ready' ? true : 'unsupported'; assert.throws(() => new EquipmentStats(bundle(p)), /contract/); }
});
test('unreachable and duplicate thresholds reject; cumulative thresholds apply once', () => {
  const p = payload(); p.definition.bonuses[0].required = 3; assert.throws(() => new EquipmentStats(bundle(p)));
  p.definition.bonuses[0].required = 2; p.definition.bonuses.push(p.definition.bonuses[0]); assert.throws(() => new EquipmentStats(bundle(p)));
  const q = payload(); q.definition.pieces.push({id: 'set_nl_test', slot: 'clothing', level: 1, premium: false, modifiers: []});
  q.definition.bonuses.push({required: 3, modifiers: [{stat: 'agility', unit: 'flat', amount: 5}]});
  const out = new EquipmentStats(bundle(q, {weapon: 'wpn_nl_test', back: 'back_nl_test', clothing: 'set_nl_test'})).derive(base());
  assert.equal(out.stats.agility, 49); assert.equal(out.active_sets.length, 2);
});
test('input mutation after binding cannot alter frozen semantics', () => {
  const b = bundle(), gear = new EquipmentStats(b), expected = gear.derive(base()); b.releases[0].payload = '{}'; b.equipped = {};
  const out = gear.derive(base()); assert.deepEqual(out, expected); out.contributions[0].amount = 999; assert.deepEqual(gear.derive(base()), expected);
});
test('bad base stats and excessive final values reject without partial mutation', () => {
  const gear = new EquipmentStats(bundle()); for (const bad of [NaN, Infinity, -1, 10000001]) { const b = base(); b.atk = bad; assert.throws(() => gear.derive(b)); }
  const b = base(); b.atk = 10000000; assert.throws(() => gear.derive(b), /limit/); assert.equal(b.atk, 10000000);
});
test('wrapper changes only initial buildStats; damage function identity remains upstream', () => {
  const fn = () => 'original'; const upstream = {buildStats: base, calcWeaponDamage: fn};
  const wrapped = wrapStats(upstream, [{character_id: 1, equipment: bundle()}, {character_id: 2, equipment: {profile: 'nl-owned-equipment-v1', equipped: {}, releases: []}}]);
  assert.equal(wrapped.calcWeaponDamage, fn); assert.equal(wrapped.buildStats({id: 1}).atk, 84); assert.equal(wrapped.buildStats({id: 2}).atk, 50);
  assert.throws(() => wrapped.buildStats({id: 3}));
});
if (process.env.NL_COMBAT_SOURCE) test('actual upstream initiative changes only when owned gear is included', () => {
  const {fixture} = require('../combat/fixture.cjs');
  const chars = [1, 2].map(id => ({id, name: 'QA', level: 10, rank: 1, point_wind: id === 2 ? 10 : 0,
    point_fire: 0, point_water: 0, point_earth: 0, point_lightning: 0, equipment_skills: ''}));
  const naked = fixture({characters: chars}); naked.battle.start(); naked.clock.advance(1000); assert.equal(naked.battle._activeParticipant.character.id, 2);
  naked.battle.running = false; naked.clock.jobs.clear();
  const f = fixture({characters: chars, equipmentBundles: [{character_id: 1, equipment: bundle()}, {character_id: 2, equipment: {profile: 'nl-owned-equipment-v1', equipped: {}, releases: []}}]});
  f.battle.start(); f.clock.advance(1000); assert.equal(f.battle._activeParticipant.character.id, 1); assert.equal(f.dbCalls.length, 0);
  f.battle.running = false; f.clock.jobs.clear();
});
