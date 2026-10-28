'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const fs = require('node:fs'), os = require('node:os'), path = require('node:path');
const {replay} = require('../../combat/training/TrainingEngine.cjs');
const base = () => ({protocol: 'nl-training-v1', id: '12345678-1234-4234-8234-123456789012', seed: 'ab'.repeat(32), actions: [], bundle: {
  releases: [], snapshot: {profile: 'nl-owned-basic-combat-v1', user_id: 42, revision: 0, skills: [],
    character: {id: 7, name: 'Owned fixture', rank: 1, level: 10, point_wind: 0, point_fire: 0, point_lightning: 0, point_earth: 0, point_water: 0, equipment_skills: ''},
    equipment: {profile: 'nl-owned-equipment-v1', equipped: {}, releases: []}}}});
test('real pinned engine starts a bounded mirror battle without fixture runtime imports', () => {
  const state = replay(base()).state; assert.equal(state.phase, 'input'); assert.equal(state.player.max_hp, 460);
  assert.equal(state.player.attack, 50); assert.equal(state.rewards_issued, false); assert.equal(state.native_client_verified, false);
});
test('seeded replay is deterministic across fresh engine instances and resumes exact previous state', () => {
  const input = base(), before = replay(input).state; input.actions.push({action: 'weapon'});
  const a = replay(input), b = replay(structuredClone(input)); assert.deepEqual(a, b); assert.deepEqual(a.previous, before);
  assert(a.state.player.hp < before.player.hp || a.state.mirror.hp < before.mirror.hp);
});
test('complete weapon battle uses real terminal path without legacy DB or reward access', () => {
  const input = base(); let state = replay(input).state;
  while (state.phase !== 'ended' && input.actions.length < 128) { input.actions.push({action: 'weapon'}); state = replay(input).state; }
  assert.equal(state.phase, 'ended'); assert(state.player.hp <= 0 || state.mirror.hp <= 0);
  assert.equal(typeof state.outcome.won, 'boolean'); assert.equal(state.rewards_issued, false);
  input.actions.push({action: 'weapon'}); assert.throws(() => replay(input), /ended/);
});
test('run is a server-calculated terminal action, not a supplied winner', () => {
  const input = base(); input.actions.push({action: 'run'}); const state = replay(input).state;
  assert.equal(state.phase, 'ended'); assert.equal(state.outcome.won, false); assert.equal(state.rewards_issued, false);
});
test('unknown actions, animation callbacks, skill forgery and extra outcome fields reject', () => {
  for (const command of [{action: 'finished'}, {action: 'timeout'}, {action: 'skill', skill_id: 'skill_nl_unowned'}, {action: 'weapon', winner: true}, {action: 'weapon', skill_id: 'x'}]) {
    const input = base(); input.actions = [command]; assert.throws(() => replay(input));
  }
});
test('identity seed action and metadata bounds fail closed', () => {
  for (const changes of [{seed: 'predictable'}, {id: '../file'}, {protocol: 'live'}, {actions: Array(129).fill({action: 'weapon'})}]) assert.throws(() => replay({...base(), ...changes}));
  const input = base(); input.bundle.snapshot.character.level = -1; assert.throws(() => replay(input));
});
test('charge and dodge execute through inherited engine actions', () => {
  for (const action of ['charge', 'dodge']) { const input = base(); const before = replay(input).state; input.actions.push({action}); const result = replay(input); assert.deepEqual(result.previous, before); assert(result.state.turn > before.turn); }
});
test('source hash drift is rejected before game execution', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'nl-training-pin-'));
  try { for (const name of ['StatsCalc.js', 'Battle.js']) fs.copyFileSync(path.join(process.env.NL_COMBAT_SOURCE, name), path.join(dir, name));
    fs.appendFileSync(path.join(dir, 'StatsCalc.js'), '\n// altered'); assert.throws(() => replay(base(), dir), /Unpinned/);
  } finally { fs.rmSync(dir, {recursive: true, force: true}); }
});
