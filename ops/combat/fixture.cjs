'use strict';
// Explicit fixture adapters: socket, clock, skill catalogue, UUID and DB. Real Battle/StatsCalc math.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const crypto = require('node:crypto');
const createSafe = require('../../combat/evaluation/TurnSafeBattle.cjs');
const lock = require('./upstream-lock.json');
class Clock {
  constructor() { this.time = 0; this.id = 0; this.jobs = new Map(); }
  now = () => this.time;
  setTimeout = (fn, delay) => { const id = ++this.id; this.jobs.set(id, {at: this.time + delay, fn}); return id; };
  clearTimeout = id => this.jobs.delete(id);
  setImmediate = fn => this.setTimeout(fn, 0);
  advance(ms) {
    const target = this.time + ms;
    for (let n = 0; ; n++) {
      if (n > 2000) throw new Error('Fixture scheduler runaway');
      const next = [...this.jobs.entries()].filter(([, j]) => j.at <= target).sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
      if (!next) break;
      this.jobs.delete(next[0]); this.time = next[1].at; next[1].fn();
    }
    this.time = target;
  }
}
class Socket {
  constructor(id) { this.id = 'socket-' + id; this.charId = id; this.listeners = new Map(); this.sent = []; }
  on(event, fn) { if (!this.listeners.has(event)) this.listeners.set(event, new Set()); this.listeners.get(event).add(fn); }
  off(event, fn) { this.listeners.get(event)?.delete(fn); }
  emit(event, data) { this.sent.push({event, data: structuredClone(data)}); }
  receive(event, data) { for (const fn of [...(this.listeners.get(event) || [])]) fn(data); }
  count() { return [...this.listeners.values()].reduce((n, set) => n + set.size, 0); }
}
function source(name) {
  const directory = process.env.NL_COMBAT_SOURCE;
  if (!directory) throw new Error('NL_COMBAT_SOURCE must name hash-verified temporary text intake');
  const bytes = fs.readFileSync(path.join(directory, name));
  const digest = crypto.createHash('sha1').update(Buffer.from(`blob ${bytes.length}\0`)).update(bytes).digest('hex');
  if (digest !== lock.files.find(f => f.local === name)?.git_blob) throw new Error('Unpinned combat source');
  return bytes.toString('utf8');
}
function load(name, dependencies, clock, random) {
  const module = {exports: {}};
  const math = Object.create(Math); math.random = random;
  // VM provides dependency seams, NOT a security sandbox for untrusted source.
  vm.runInNewContext(source(name), {module, exports: module.exports,
    require: id => { if (!Object.hasOwn(dependencies, id)) throw new Error('Unexpected dependency: ' + id); return dependencies[id]; },
    Math: math, console: {log() {}, warn() {}, error() {}},
    setTimeout: clock.setTimeout, clearTimeout: clock.clearTimeout, setImmediate: clock.setImmediate,
  }, {filename: name, timeout: 1000});
  return module.exports;
}
function fixture({safe = true, scrolls = false, onEnd, level = 10} = {}) {
  const clock = new Clock();
  const skills = {
    skill_eval_hit: {skill_cp_cost: 20, skill_cooldown: 3, skill_damage: 70, skill_type: 2},
    skill_eval_stun: {skill_cp_cost: 30, skill_cooldown: 4, skill_damage: 10, skill_type: 1},
  };
  const SkillData = {getSkill: id => { if (!skills[id]) throw new Error('Synthetic skill not found'); return skills[id]; },
    getSkillEffects: id => id === 'skill_eval_stun' ? [{effect: 'stun', type: 'Debuff', target: 'enemy', duration: 1, amount: 0, chance: 100, effect_name: 'Synthetic Stun', calc_type: 'number'}] : []};
  const random = () => 0.5;
  const stats = load('StatsCalc.js', {'../SkillData': SkillData}, clock, random);
  const dbCalls = [];
  const db = {getGameConfig: async () => { dbCalls.push('config'); return {}; }, getPool: () => ({execute: async sql => { dbCalls.push(sql); throw new Error('Fixture DB intentionally unavailable'); }})};
  const Base = load('Battle.js', {'uuid': {v4: crypto.randomUUID}, '../config': {turnDuration: 30, maxRounds: 50, pvpPointsWin: 1, pvpPointsLose: 1},
    './StatsCalc': stats, './TrophyCalc': {}, '../SkillData': SkillData, '../characterInfo': {buildCharacterInfoPayload: c => ({character: c})}, '../db': db}, clock, random);
  const make = id => ({socket: new Socket(id), character: {id, name: 'Synthetic-' + id, level, rank: 1, point_earth: 0, point_water: 0, point_wind: 0, point_lightning: 0, point_fire: 0, equipment_skills: 'skill_eval_hit,skill_eval_stun'}});
  const host = make(1), enemy = make(2);
  const options = {host, enemy, room: {mode: 'evaluation', stage: 'synthetic', allowScrolls: scrolls}, evaluation: true, clock, onEnd};
  const Type = safe ? createSafe(Base) : Base;
  const battle = new Type(options);
  const packet = extra => ({battle_id: battle.id, turn_id: battle.turnId, ...extra});
  const send = (p, action, extra) => p.socket.receive('Battle.action.' + action, packet(extra));
  return {battle, clock, host, enemy, options, Type, Base, dbCalls, send, packet, stats};
}
module.exports = {fixture, Clock, Socket};
