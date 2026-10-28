'use strict';
const crypto = require('node:crypto');
const rules = require('./equipment-stats-v1.json');
const PREFIX = Object.freeze({weapon: 'wpn', back: 'back', clothing: 'set', accessory: 'accessory'});
const integer = (n, min, max) => Number.isSafeInteger(n) && n >= min && n <= max;
function check(value, message) { if (!value) throw new TypeError(message); }
function object(value) { return value !== null && typeof value === 'object' && !Array.isArray(value); }
function modifiers(lines) {
  check(Array.isArray(lines) && lines.length <= 14, 'Bounded modifier list required');
  const seen = new Set();
  for (const m of lines) {
    check(object(m) && Object.keys(m).length === 3 && Object.keys(m).every(k => ['stat', 'unit', 'amount'].includes(k)), 'Unknown modifier fields');
    check(typeof m.stat === 'string' && Object.hasOwn(rules.stats, m.stat) && typeof m.unit === 'string'
      && Object.hasOwn(rules.stats[m.stat].units, m.unit) && integer(m.amount, 0, rules.stats[m.stat].units[m.unit]), 'Invalid stat/unit/amount');
    const key = m.stat + ':' + m.unit; check(!seen.has(key), 'Duplicate stat/unit'); seen.add(key);
  }
  return lines;
}
function decode(release) {
  check(object(release) && typeof release.id === 'string' && /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/.test(release.id)
    && typeof release.payload === 'string' && Buffer.byteLength(release.payload) <= 131072
    && crypto.createHash('sha256').update(release.payload).digest('hex') === release.sha256, 'Equipment release checksum');
  const p = JSON.parse(release.payload), d = p.definition;
  check(p.schema === 1 && p.profile === 'nl-studio-equipment-v1' && p.stat_registry === rules.version && p.client_ready === false
    && p.revision === 'nl-equipment:' + release.id && p.visual_status === 'reference_only_not_client_verified', 'Unsupported equipment contract');
  check(object(d) && typeof d.id === 'string' && /^gear_nl_[a-z0-9_]{1,60}$/.test(d.id)
    && Array.isArray(d.pieces) && d.pieces.length >= 1 && d.pieces.length <= 4 && Array.isArray(d.bonuses) && d.bonuses.length <= 3, 'Invalid equipment bundle');
  const ids = new Set(), slots = new Set(), tiers = new Set();
  for (const item of d.pieces) {
    check(object(item) && Object.hasOwn(PREFIX, item.slot) && typeof item.id === 'string'
      && new RegExp('^' + PREFIX[item.slot] + '_nl_[a-z0-9_]{1,60}$').test(item.id)
      && !ids.has(item.id) && !slots.has(item.slot) && integer(item.level, 1, 200) && typeof item.premium === 'boolean', 'Invalid piece identity or requirement');
    ids.add(item.id); slots.add(item.slot); modifiers(item.modifiers);
  }
  for (const tier of d.bonuses) {
    check(object(tier) && Object.keys(tier).length === 2 && integer(tier.required, 2, d.pieces.length)
      && !tiers.has(tier.required) && Array.isArray(tier.modifiers) && tier.modifiers.length > 0, 'Invalid set threshold');
    tiers.add(tier.required); modifiers(tier.modifiers);
  }
  return p;
}

/** Pinned, trusted server input. Hashes detect corruption, not a hostile server operator. */
class EquipmentStats {
  #sources = [];
  #activeSets = [];
  constructor(bundle) {
    check(object(bundle) && bundle.profile === 'nl-owned-equipment-v1' && object(bundle.equipped)
      && Object.keys(bundle.equipped).length <= 4 && Array.isArray(bundle.releases) && bundle.releases.length <= 4, 'Invalid owned equipment');
    const equipped = structuredClone(bundle.equipped), used = new Set(), bundles = new Set(), allItems = new Set();
    for (const [slot, id] of Object.entries(equipped)) check(Object.hasOwn(PREFIX, slot) && typeof id === 'string', 'Invalid equipped slot');
    check(new Set(Object.values(equipped)).size === Object.keys(equipped).length, 'Duplicate equipped identity');
    for (const release of bundle.releases) {
      const p = decode(release), d = p.definition; check(!bundles.has(d.id), 'Duplicate/conflicting bundle'); bundles.add(d.id);
      let count = 0;
      for (const item of d.pieces) {
        check(!allItems.has(item.id), 'Ambiguous bundle membership'); allItems.add(item.id);
        if (equipped[item.slot] === item.id) {
          count++; used.add(item.id); this.#sources.push({source: item.id, revision: p.revision, modifiers: structuredClone(item.modifiers)});
        }
      }
      check(count > 0, 'Extraneous unequipped bundle');
      for (const tier of [...d.bonuses].sort((a, b) => a.required - b.required)) {
        if (count >= tier.required) {
          const source = d.id + ':' + tier.required;
          this.#activeSets.push({id: d.id, required: tier.required, equipped: count, revision: p.revision});
          this.#sources.push({source, revision: p.revision, modifiers: structuredClone(tier.modifiers)});
        }
      }
    }
    check(used.size === Object.keys(equipped).length, 'Equipped item has no published mechanics');
  }
  /** Always start from fresh upstream buildStats. Never reapply to a previously derived result. */
  derive(base) {
    check(object(base) && !Object.hasOwn(base, 'equipmentProfile'), 'Fresh upstream base stats required');
    const result = {...base}, contributions = [];
    for (const [stat, spec] of Object.entries(rules.stats)) {
      check(Number.isFinite(base[stat]) && base[stat] >= 0 && base[stat] <= rules.max_final, 'Invalid base stat');
      let flat = 0, percent = 0, points = 0;
      for (const source of this.#sources) for (const m of source.modifiers) if (m.stat === stat) {
        if (m.unit === 'flat') flat += m.amount; else if (m.unit === 'percent') percent += m.amount; else points += m.amount;
        contributions.push({source: source.source, revision: source.revision, ...m});
      }
      const value = Object.hasOwn(spec, 'cap') ? Math.min(spec.cap, Math.round((base[stat] + points) * 10) / 10)
        : Math.floor((base[stat] + flat) * (100 + percent) / 100);
      check(Number.isFinite(value) && value >= 0 && value <= rules.max_final, 'Derived stat exceeds limit'); result[stat] = value;
    }
    check(result.maxHp > 0 && result.maxCp > 0 && result.agility > 0, 'Invalid final survival stats');
    result.hp = result.maxHp; result.cp = result.maxCp; result.equipmentProfile = rules.version;
    return {stats: result, contributions, active_sets: structuredClone(this.#activeSets)};
  }
}

/** Decorate only initial stat construction; existing damage, charge and turn code are retained. */
function wrapStats(upstream, bundles) {
  check(upstream && typeof upstream.buildStats === 'function' && Array.isArray(bundles) && bundles.length === 2, 'Two server participants required');
  const map = new Map();
  for (const entry of bundles) {
    check(integer(entry.character_id, 1, 2147483647) && !map.has(entry.character_id), 'Duplicate participant');
    map.set(entry.character_id, new EquipmentStats(entry.equipment));
  }
  return {...upstream, buildStats(character) {
    const gear = map.get(character.id); check(gear, 'Participant equipment missing');
    return gear.derive(upstream.buildStats(character)).stats;
  }};
}
module.exports = {EquipmentStats, wrapStats};
