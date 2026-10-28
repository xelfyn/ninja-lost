'use strict';

const crypto = require('node:crypto');
const ID = /^skill_[A-Za-z0-9_-]{1,80}$/;
const STATUS = new Set(['stun', 'sleep', 'frozen', 'restriction', 'meridian_seal', 'dismantle', 'charge_disable', 'internal_injury']);
const SUPPORTED = new Set([...STATUS, 'burn', 'bleeding', 'poison', 'heal', 'regenHP', 'purify', 'disperse']);
const EFFECT_KEYS = new Set(['target', 'type', 'effect', 'effect_name', 'duration', 'amount', 'amount_hp', 'amount_cp', 'chance', 'passive', 'no_disperse', 'calc_type', 'reduce_type']);
function freeze(value) {
  if (value && typeof value === 'object' && !Object.isFrozen(value)) {
    Object.values(value).forEach(freeze); Object.freeze(value);
  }
  return value;
}
function integer(value, max = 1000000) { return Number.isSafeInteger(value) && value >= 0 && value <= max; }
function decimal(value) { return typeof value === 'number' && Number.isFinite(value) && value >= 0 && value <= 1000000; }
function skillType(value) {
  if (typeof value === 'string' && /^(0|[1-9][0-9]?)$/.test(value)) value = Number(value);
  return integer(value, 9) ? value : null;
}

/** Mechanics catalogue only. Structural admission is NOT asset rights or full client/engine parity. */
class SkillCatalog {
  #records = new Map();
  #report;
  constructor(skills, effects, revision) {
    if (!Array.isArray(skills) || !Array.isArray(effects) || skills.length > 20000 || effects.length > 20000
        || typeof revision !== 'string' || revision.length < 1 || revision.length > 160) throw new TypeError('Bounded source arrays and explicit revision required');
    this.revision = revision;
    const effectMap = new Map(), duplicateEffects = new Set();
    for (const row of effects) {
      if (!row || typeof row !== 'object' || Array.isArray(row) || typeof row.skill_id !== 'string' || !ID.test(row.skill_id)) throw new TypeError('Invalid effect record identity');
      if (effectMap.has(row.skill_id)) duplicateEffects.add(row.skill_id);
      else effectMap.set(row.skill_id, row);
    }
    const reasons = {};
    for (const row of skills) {
      if (!row || typeof row !== 'object' || Array.isArray(row) || typeof row.id !== 'string' || !ID.test(row.id) || this.#records.has(row.id)) throw new TypeError('Invalid or duplicate skill identity');
      const issues = [];
      for (const key of ['damage', 'cp_cost', 'cooldown']) {
        if (!decimal(row[key])) issues.push('invalid_' + key);
        else if (!integer(row[key])) issues.push('fractional_' + key + '_semantics_unverified');
      }
      const type = skillType(row.type);
      if (type === null) issues.push('unknown_skill_type');
      if (!integer(row.level, 10000) || row.level < 1) issues.push('invalid_level');
      if (typeof row.premium !== 'boolean') issues.push('invalid_premium');
      // Source StatsCalc invents an ATK-based hit when damage is zero. Preserve metadata,
      // but do not silently certify effect-only skills until that behavior is reconciled.
      if (row.damage === 0) issues.push('zero_damage_engine_fallback_unverified');
      const record = effectMap.get(row.id);
      if (!record) issues.push('missing_effect_record');
      if (duplicateEffects.has(row.id)) issues.push('duplicate_effect_record');
      const normalized = [];
      if (record) {
        if (!Array.isArray(record.skill_effect) || record.skill_effect.length > 64) issues.push('invalid_effect_list');
        else for (const effect of record.skill_effect) {
          const item = this.#effect(effect, issues);
          if (item) normalized.push(item);
        }
      }
      const unique = [...new Set(issues)].sort();
      unique.forEach(reason => { reasons[reason] = (reasons[reason] || 0) + 1; });
      const metadata = structuredClone(row);
      const mechanics = {skill_id: row.id, skill_damage: row.damage, skill_cp_cost: row.cp_cost, skill_cooldown: row.cooldown, skill_type: type};
      const digest = crypto.createHash('sha256').update(JSON.stringify([revision, metadata, record?.skill_effect ?? null])).digest('hex');
      this.#records.set(row.id, freeze({id: row.id, admitted: unique.length === 0, issues: unique, metadata, mechanics, effects: normalized, digest}));
    }
    this.#report = freeze({revision, skill_records: this.#records.size, effect_records: effects.length,
      duplicated_effect_identities: duplicateEffects.size, orphan_effect_identities: [...effectMap.keys()].filter(id => !this.#records.has(id)).length,
      admitted_to_scoped_evaluation: [...this.#records.values()].filter(row => row.admitted).length,
      quarantined: [...this.#records.values()].filter(row => !row.admitted).length,
      reasons: Object.fromEntries(Object.entries(reasons).sort()),
      scope: 'Admission covers declared limited mechanics only; not client parity, ownership, rights or live activation.'});
    Object.freeze(this);
  }
  #effect(raw, issues) {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) { issues.push('invalid_effect_object'); return null; }
    if (Object.keys(raw).some(key => !EFFECT_KEYS.has(key))) issues.push('unhandled_effect_fields');
    if (!SUPPORTED.has(raw.effect)) issues.push('effect_not_admitted:' + String(raw.effect).slice(0, 80));
    if (!['self', 'enemy'].includes(raw.target)) issues.push('invalid_effect_target');
    if (!['Buff', 'Debuff'].includes(raw.type)) issues.push('invalid_effect_type');
    if (!integer(raw.duration, 1000)) issues.push('invalid_effect_duration');
    if (!integer(raw.chance, 100)) issues.push('missing_or_invalid_chance');
    for (const key of ['passive', 'no_disperse']) if (raw[key] !== undefined && typeof raw[key] !== 'boolean') issues.push('invalid_' + key);
    if (raw.passive === true) issues.push('passive_engine_parity_unverified');
    const amountless = STATUS.has(raw.effect) || ['purify', 'disperse'].includes(raw.effect);
    const amount = raw.amount === undefined && amountless ? 0 : raw.amount;
    const calc = (raw.calc_type === '' || raw.calc_type === undefined) && amountless ? 'number' : raw.calc_type;
    if (!decimal(amount)) issues.push('invalid_effect_amount');
    if (!['number', 'percent'].includes(calc)) issues.push('unhandled_calculation_type');
    for (const key of ['amount_hp', 'amount_cp']) if (raw[key] !== undefined && !decimal(raw[key])) issues.push('invalid_' + key);
    if (raw.reduce_type !== undefined && !['CURRENT', 'MAX'].includes(raw.reduce_type)) issues.push('unhandled_reduce_type');
    if (typeof raw.effect_name !== 'string' || raw.effect_name.length > 500) issues.push('invalid_effect_label');
    if (STATUS.has(raw.effect) && raw.duration === 0) issues.push('instant_status_not_supported');
    return {effect: raw.effect, type: raw.type, target: raw.target, duration: raw.duration, amount,
      amount_hp: raw.amount_hp ?? 0, amount_cp: raw.amount_cp ?? 0, calc_type: calc,
      reduce_type: raw.reduce_type ?? 'CURRENT', chance: raw.chance, passive: raw.passive ?? false,
      no_disperse: raw.no_disperse ?? false, effect_name: raw.effect_name};
  }
  inspect(id) { return this.#records.get(id) ?? null; }
  report() { return this.#report; }
  admittedIds() { return [...this.#records.values()].filter(row => row.admitted).map(row => row.id); }
  #required(id) {
    const row = this.#records.get(id);
    if (!row || !row.admitted) throw new Error('Skill unavailable or quarantined: ' + String(id).slice(0, 90));
    return row;
  }
  getSkill(id) { return this.#required(id).mechanics; }
  getSkillEffects(id) { return this.#required(id).effects; }
  /** Only consume a trusted server-produced snapshot, never a player-submitted stat object. */
  bindLoadout(snapshot) {
    if (!snapshot || snapshot.profile !== 'nl-owned-basic-combat-v1' || !snapshot.character
        || !integer(snapshot.user_id, 2147483647) || snapshot.user_id < 1
        || !integer(snapshot.revision, 4294967295) || !Array.isArray(snapshot.skills) || snapshot.skills.length > 8) throw new TypeError('Invalid server loadout contract');
    const character = structuredClone(snapshot.character);
    if (!integer(character.id, 2147483647) || character.id < 1 || typeof character.equipment_skills !== 'string') throw new TypeError('Invalid character');
    for (const field of ['level', 'point_wind', 'point_fire', 'point_lightning', 'point_earth', 'point_water']) {
      if (!integer(character[field], 10000) || (field === 'level' && character[field] < 1)) throw new TypeError('Invalid server stat');
    }
    const ids = snapshot.skills.map(ref => ref.id);
    if (new Set(ids).size !== ids.length || character.equipment_skills !== ids.join(',')) throw new TypeError('Ambiguous equipped skill set');
    for (const ref of snapshot.skills) {
      const row = this.#required(ref.id);
      if (ref.source_revision !== this.revision || row.metadata.level !== ref.level || row.mechanics.skill_type !== ref.element || row.metadata.premium !== ref.premium) throw new Error('Metadata revision or requirement mismatch');
      if (row.metadata.level > character.level) throw new Error('Skill exceeds character level');
    }
    return freeze(character);
  }
}
module.exports = {SkillCatalog};
