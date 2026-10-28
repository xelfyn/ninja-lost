'use strict';
const crypto = require('node:crypto');
const {SkillCatalog} = require('./SkillCatalog.cjs');

/** Trusted server bundles only, never unsigned browser input. Reuses the existing mechanics reader. */
class PublishedSkillCatalog {
  #catalogs = new Map();
  #digests = new Map();
  constructor(releases) {
    if (!Array.isArray(releases) || releases.length > 16) throw new TypeError('Bounded release list required');
    for (const release of releases) {
      if (!release || typeof release.payload !== 'string' || Buffer.byteLength(release.payload) > 65536
          || typeof release.id !== 'string' || !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(release.id)
          || crypto.createHash('sha256').update(release.payload).digest('hex') !== release.sha256) throw new Error('Release checksum or identity mismatch');
      const data = JSON.parse(release.payload);
      if (data.schema !== 1 || data.profile !== 'nl-studio-skill-v1' || data.effect_registry !== 'nl-studio-effects-v1'
          || data.revision !== 'nl-studio:' + release.id || data.client_ready !== false
          || !Array.isArray(data.skills) || data.skills.length !== 1 || !Array.isArray(data.effects) || data.effects.length !== 1) throw new Error('Unsupported content release');
      const catalog = new SkillCatalog(data.skills, data.effects, data.revision);
      const id = data.skills[0].id;
      if (catalog.admittedIds().length !== 1 || data.definition?.id !== id || data.effects[0].skill_id !== id) throw new Error('Unadmitted release');
      const old = this.#catalogs.get(id);
      if (old && (old.revision !== data.revision || this.#digests.get(id) !== release.sha256)) throw new Error('Conflicting revisions of one skill in ruleset');
      this.#catalogs.set(id, catalog); this.#digests.set(id, release.sha256);
    }
    Object.freeze(this);
  }
  #get(id) {
    const catalog = this.#catalogs.get(id);
    if (!catalog) throw new Error('Skill not in pinned release bundle');
    return catalog;
  }
  getSkill(id) { return this.#get(id).getSkill(id); }
  getSkillEffects(id) { return this.#get(id).getSkillEffects(id); }
  bindLoadout(snapshot) {
    if (!snapshot || !Array.isArray(snapshot.skills) || snapshot.skills.length > 8 || !snapshot.character
        || snapshot.character.equipment_skills !== snapshot.skills.map(s => s.id).join(',')
        || new Set(snapshot.skills.map(s => s.id)).size !== snapshot.skills.length) throw new Error('Invalid loadout');
    if (!snapshot.skills.length) return new SkillCatalog([], [], 'empty').bindLoadout(snapshot);
    let bound;
    for (const ref of snapshot.skills) {
      bound = this.#get(ref.id).bindLoadout({...snapshot, character: {...snapshot.character, equipment_skills: ref.id}, skills: [ref]});
    }
    return Object.freeze({...bound, equipment_skills: snapshot.character.equipment_skills});
  }
}
module.exports = {PublishedSkillCatalog};
