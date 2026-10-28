'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');
const createSafeBattle = require('../evaluation/TurnSafeBattle.cjs');
const {PublishedSkillCatalog} = require('../data/PublishedSkillCatalog.cjs');
const {EquipmentStats, wrapStats} = require('../data/EquipmentStats.cjs');
const sourceLock = require('../../ops/combat/upstream-lock.json');
const PROTOCOL = 'nl-training-v1';
const MAX_ACTIONS = 128;
function check(ok, message) { if (!ok) throw new Error(message); }

// Virtual time is intentional: this is a paused, single-player training session, not timed PvP.
class TrainingClock {
  constructor() { this.time = 0; this.sequence = 0; this.jobs = new Map(); }
  now = () => this.time;
  setTimeout = (fn, delay) => {
    check(Number.isFinite(delay) && delay >= 0, 'Invalid timer');
    const id = ++this.sequence; this.jobs.set(id, {at: this.time + delay, fn}); return id;
  };
  clearTimeout = id => this.jobs.delete(id);
  setImmediate = fn => this.setTimeout(fn, 0);
  next() {
    const first = [...this.jobs].sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
    check(first, 'Engine has no scheduled continuation');
    this.jobs.delete(first[0]); this.time = first[1].at; first[1].fn();
  }
}
class TrainingSocket {
  constructor(id) { this.id = `training-${id}`; this.charId = id; this.listeners = new Map(); }
  on(event, callback) { if (!this.listeners.has(event)) this.listeners.set(event, new Set()); this.listeners.get(event).add(callback); }
  off(event, callback) { this.listeners.get(event)?.delete(callback); }
  emit() {} // No client is allowed to inject transport callbacks or animation acknowledgements.
  receive(event, packet) { for (const callback of [...(this.listeners.get(event) || [])]) callback(packet); }
}
function loadPinned(name, dependencies, clock, random, directory) {
  check(typeof directory === 'string' && path.isAbsolute(directory), 'Absolute trusted combat source path required');
  const file = sourceLock.files.find(f => f.local === name);
  const bytes = fs.readFileSync(path.join(directory, name));
  check(file && bytes.length <= file.max_bytes && crypto.createHash('sha1').update(Buffer.from(`blob ${bytes.length}\0`)).update(bytes).digest('hex') === file.git_blob, 'Unpinned combat source');
  const module = {exports: {}}, math = Object.create(Math); math.random = random;
  // Dependency isolation for already trusted pinned code, NOT a security sandbox for uploaded scripts.
  vm.runInNewContext(bytes.toString('utf8'), {module, exports: module.exports, Math: math,
    console: {log() {}, warn() {}, error() {}}, setTimeout: clock.setTimeout, clearTimeout: clock.clearTimeout, setImmediate: clock.setImmediate,
    require: id => { check(Object.hasOwn(dependencies, id), 'Unexpected engine dependency'); return dependencies[id]; },
  }, {filename: name, timeout: 1000});
  return module.exports;
}

/** Input comes only from the trusted PHP service, stored immutable bundles and stored action receipts. */
function replay(input, directory = process.env.NL_COMBAT_SOURCE) {
  check(input && input.protocol === PROTOCOL && typeof input.id === 'string' && /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/.test(input.id), 'Invalid training identity');
  check(typeof input.seed === 'string' && /^[0-9a-f]{64}$/.test(input.seed), 'Invalid server seed');
  check(Array.isArray(input.actions) && input.actions.length <= MAX_ACTIONS, 'Training action limit');
  const bundle = input.bundle;
  check(bundle && bundle.snapshot?.equipment && Array.isArray(bundle.releases), 'Published equipped snapshot required');
  const catalog = new PublishedSkillCatalog(bundle.releases);
  const character = structuredClone(catalog.bindLoadout(bundle.snapshot));
  // This ID is an engine-local mirror handle, NOT another account or DB character.
  const mirror = {...character, id: character.id === 1 ? 2 : 1, name: 'Training mirror'};
  const clock = new TrainingClock(); let draws = 0;
  const random = () => crypto.createHmac('sha256', Buffer.from(input.seed, 'hex')).update(String(draws++)).digest().readUInt32BE(0) / 4294967296;
  const upstreamStats = loadPinned('StatsCalc.js', {'../SkillData': catalog}, clock, random, directory);
  const equipment = bundle.snapshot.equipment;
  const stats = wrapStats(upstreamStats, [character, mirror].map(c => ({character_id: c.id, equipment})));
  const disabledDatabase = new Proxy({}, {get() { throw new Error('Legacy database access forbidden in training'); }});
  const Legacy = loadPinned('Battle.js', {'uuid': {v4: () => input.id}, '../config': {turnDuration: 30, maxRounds: 50},
    './StatsCalc': stats, './TrophyCalc': {}, '../SkillData': catalog,
    '../characterInfo': {buildCharacterInfoPayload: c => ({character: c})}, '../db': disabledDatabase,
  }, clock, random, directory);
  const Battle = createSafeBattle(Legacy);
  const battle = new Battle({evaluation: true, clock, room: {mode: 'evaluation', stage: 'training', allowScrolls: false},
    host: {character, socket: new TrainingSocket(character.id)}, enemy: {character: mirror, socket: new TrainingSocket(mirror.id)}});
  const send = (actor, action, extra = {}) => actor.socket.receive('Battle.action.' + action, {battle_id: battle.id, turn_id: battle.turnId, ...extra});
  function settle() {
    for (let transitions = 0; transitions < 512; transitions++) {
      check(battle.phase !== 'cancelled', 'Combat engine cancelled');
      if (battle.terminal || (battle.phase === 'input' && battle._activeParticipant === battle.host)) return;
      if (battle.phase === 'input') {
        const turn = battle.turnId; send(battle.enemy, 'weapon');
        check(battle.phase !== 'input' || turn !== battle.turnId, 'Mirror action rejected');
      } else if (battle.phase === 'animation') send(battle.animationActor, 'finished');
      else clock.next();
    }
    throw new Error('Training transition limit');
  }
  const derived = new EquipmentStats(equipment).derive(upstreamStats.buildStats(character));
  const descriptions = new Map(bundle.releases.map(r => { const d = JSON.parse(r.payload); return [d.definition.id, d]; }));
  const participant = p => ({hp: p.stats.hp, max_hp: p.stats.maxHp, cp: p.stats.cp, max_cp: p.stats.maxCp,
    agility: p.stats.agility, attack: p.stats.atk, defense: p.stats.def,
    effects: p.buffs.map(b => ({code: b.effect, duration: b.duration, amount: b.amount ?? 0}))});
  const state = () => ({protocol: PROTOCOL, turn: battle.turnId, phase: battle.terminal ? 'ended' : 'input',
    player: participant(battle.host), mirror: participant(battle.enemy), active_sets: derived.active_sets,
    contributions: derived.contributions,
    skills: character.equipment_skills.split(',').filter(Boolean).map(id => {
      const skill = catalog.getSkill(id); return {id, name: descriptions.get(id).definition.name, tooltip: descriptions.get(id).tooltip,
        cp_cost: skill.skill_cp_cost, cooldown: battle.host.cooldowns[id] || 0};
    }),
    outcome: battle.terminal ? {won: battle.terminal.winner_id === character.id, reason: battle.terminal.reason} : null,
    rewards_issued: false, native_client_verified: false});
  let previous = null;
  try {
    battle.start(); settle();
    for (const command of input.actions) {
      check(!battle.terminal && battle.phase === 'input', 'Training already ended');
      check(command && !Array.isArray(command) && Object.keys(command).every(k => ['action', 'skill_id'].includes(k))
        && ['weapon', 'skill', 'charge', 'dodge', 'run'].includes(command.action), 'Invalid training action');
      if (command.action === 'skill') {
        check(typeof command.skill_id === 'string' && battle.host.skills.includes(command.skill_id), 'Skill is not equipped');
        const skill = catalog.getSkill(command.skill_id);
        check(!(battle.host.cooldowns[command.skill_id] > 0) && battle.host.stats.cp >= skill.skill_cp_cost, 'Skill cost or cooldown');
      } else check(!Object.hasOwn(command, 'skill_id'), 'Unexpected skill identity');
      previous = state(); const turn = battle.turnId;
      send(battle.host, command.action, command.action === 'skill' ? {skill_id: command.skill_id} : {});
      check(battle.phase !== 'input' || battle.turnId !== turn, 'Action unavailable'); settle();
    }
    // Detach VM-realm arrays/objects at the adapter boundary; retain strict replay comparisons.
    return structuredClone({state: state(), previous});
  } finally { battle.dispose('adapter_finished'); clock.jobs.clear(); }
}
module.exports = {replay, PROTOCOL, MAX_ACTIONS};
