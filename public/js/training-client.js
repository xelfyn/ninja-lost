'use strict';
(() => {
  const root = '/evaluation/v1', training = root + '/training', $ = id => document.getElementById(id);
  let csrf = '', userId = null, loadout = null, run = null, pending = null, busy = false, generation = 0;
  const notice = text => { $('notice').textContent = text; };
  const storageKey = () => 'nl-training-intent:' + userId;
  function remember(value) {
    // Only a command/revision/request UUID is retained. Never credentials, cookies, seed or server stats.
    pending = value;
    try { if (value) sessionStorage.setItem(storageKey(), JSON.stringify(value)); else sessionStorage.removeItem(storageKey()); }
    catch { notice('Penyimpanan tab tidak tersedia; jangan tutup tab saat permintaan belum terkonfirmasi.'); }
    controls();
  }
  function controls() {
    $('configuration').disabled = busy || Boolean(pending);
    $('actions').disabled = busy || Boolean(pending) || !run || run.status !== 'active';
    $('start').disabled = !loadout || run?.status === 'active';
    $('logout').disabled = busy; $('refresh').disabled = busy; $('retry').hidden = !pending; $('retry').disabled = busy;
  }
  function localPath(value) {
    if (typeof value !== 'string' || !value.startsWith(root + '/') || /[\\?#%]/.test(value)) throw new Error('Path permintaan tidak valid.');
    const url = new URL(value, location.origin);
    if (url.origin !== location.origin || url.pathname !== value) throw new Error('Origin permintaan tidak valid.');
    return value;
  }
  async function request(path, body) {
    const response = await fetch(localPath(path), {method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin',
      headers: {Accept: 'application/json', ...(body === undefined ? {} : {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf})},
      ...(body === undefined ? {} : {body: JSON.stringify(body)})});
    let result; try { result = await response.json(); } catch { throw new Error('Respons tidak terbaca; ulangi request yang sama.'); }
    if (!response.ok) { const error = new Error(result.message || 'Permintaan ditolak.'); error.status = response.status; throw error; }
    return result;
  }
  function validIntent(value, characters) {
    if (!value || !characters.some(c => c.id === value.characterId) || !value.body || Array.isArray(value.body)
        || !Number.isInteger(value.body.revision) || value.body.revision < 0) return false;
    if (value.kind === 'loadout') return value.path === `${training}/characters/${value.characterId}/loadout`;
    if (!/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/.test(value.body.request_key || '')) return false;
    if (value.kind === 'start') return value.path === training + '/runs' && value.body.character_id === value.characterId;
    return value.kind === 'action' && new RegExp('^' + training + '/runs/[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}/actions$').test(value.path);
  }
  function option(value, name, selected = false) { const o = document.createElement('option'); o.value = value; o.textContent = name; o.selected = selected; return o; }
  function renderLoadout(data) {
    loadout = data; $('gear').replaceChildren(); $('skills').replaceChildren();
    for (const slot of ['weapon', 'back', 'clothing', 'accessory']) {
      const label = document.createElement('label'), select = document.createElement('select'); label.textContent = slot + ' '; select.id = 'gear-' + slot;
      select.append(option('', 'Tidak dipasang', !data.equipment[slot]));
      for (const item of data.items.filter(i => i.slot === slot)) select.append(option(item.id, item.name, item.id === data.equipment[slot]));
      if (data.equipment[slot] && !data.items.some(i => i.id === data.equipment[slot])) select.append(option(data.equipment[slot], 'Tidak tersedia: ' + data.equipment[slot], true));
      label.append(select); $('gear').append(label);
    }
    for (const skill of data.available_skills) $('skills').append(option(skill.id, skill.name, data.skills.includes(skill.id)));
    for (const id of data.skills) if (!data.available_skills.some(s => s.id === id)) $('skills').append(option(id, 'Tidak tersedia: ' + id, true));
    $('loadout-note').textContent = `Revision akun ${data.revision}. Persyaratan level, elemen, akun, dan kepemilikan tetap diperiksa server.` + (data.truncated ? ' Daftar lebih dari 100; sebagian opsi tidak ditampilkan.' : '');
    controls();
  }
  function renderRun(value) {
    if (run && value && run.id === value.id && value.revision < run.revision) return;
    run = value; $('battle').hidden = !run;
    if (!run) { controls(); return; }
    const s = run.state;
    $('run-status').textContent = `${run.status} · aksi tersimpan ${run.revision} · giliran ${s.turn}` + (s.outcome ? ` · ${s.outcome.won ? 'Menang' : 'Kalah'} (${s.outcome.reason}) · tanpa hadiah` : '');
    $('run-status').dataset.revision = String(run.revision); $('run-status').dataset.run = run.id;
    for (const side of ['player', 'mirror']) {
      const p = s[side]; $(side + '-hp').textContent = `HP ${p.hp} / ${p.max_hp}`; $(side + '-bar').max = p.max_hp; $(side + '-bar').value = p.hp;
      $(side + '-cp').textContent = `CP ${p.cp} / ${p.max_cp}`;
      $(side + '-effects').textContent = 'Efek: ' + (p.effects.map(e => `${e.code} (${e.duration})`).join(', ') || 'tidak ada');
    }
    $('stats').replaceChildren();
    for (const [label, key] of [['Agility', 'agility'], ['Attack', 'attack'], ['Defense', 'defense']]) {
      const row = document.createElement('tr'); for (const text of [label, s.player[key]]) { const cell = document.createElement('td'); cell.textContent = String(text); row.append(cell); } $('stats').append(row);
    }
    $('sets').textContent = 'Bonus set aktif: ' + (s.active_sets.map(t => `${t.id}: ${t.required} bagian`).join('; ') || 'tidak ada');
    $('skill-actions').replaceChildren();
    for (const skill of s.skills) {
      const b = document.createElement('button'); b.type = 'button'; b.textContent = `${skill.name} · CP ${skill.cp_cost} · CD ${skill.cooldown}`; b.title = skill.tooltip;
      b.dataset.skill = skill.id; b.disabled = skill.cooldown > 0 || s.player.cp < skill.cp_cost;
      b.addEventListener('click', () => act('skill', skill.id)); $('skill-actions').append(b);
    }
    controls();
  }
  async function refresh() {
    const id = $('character').value, epoch = ++generation;
    if (!id) { renderRun(null); loadout = null; controls(); return; }
    const [data, latest] = await Promise.all([request(`${training}/characters/${id}/loadout`), request(`${training}/characters/${id}/latest`)]);
    if (epoch !== generation || $('character').value !== id) return;
    renderLoadout(data); renderRun(latest.run);
  }
  async function authenticated(id) {
    ++generation; userId = id; run = null; loadout = null;
    let saved = null; try { saved = JSON.parse(sessionStorage.getItem(storageKey()) || 'null'); } catch {}
    const list = await request(root + '/characters'); $('character').replaceChildren();
    for (const c of list.data) $('character').append(option(c.id, `${c.name} · level ${c.level}`));
    pending = validIntent(saved, list.data) ? saved : null;
    if (saved && !pending) remember(null);
    if (pending) $('character').value = String(pending.characterId);
    await refresh(); $('login-form').hidden = true; $('session').hidden = false;
    controls(); notice(pending ? 'Ada permintaan belum terkonfirmasi. Ulangi dengan identitas yang sama.' : 'Sesi siap. Simpan loadout sebelum mulai latihan.');
  }
  async function submitPending() {
    if (!pending || busy) return; busy = true; ++generation; controls();
    const intent = pending;
    try {
      const result = await request(intent.path, intent.body);
      remember(null);
      if (intent.kind === 'loadout') renderLoadout(result); else renderRun(result.run);
      notice(result.replayed ? 'Permintaan sudah diproses sebelumnya; tidak ada aksi tambahan.' : 'State server tersimpan.');
    } catch (error) {
      if ([409, 410, 422].includes(error.status)) { remember(null); await refresh(); }
      if ([401, 419].includes(error.status)) { ++generation; $('login-form').hidden = false; $('session').hidden = true; }
      notice(error.message + (pending ? ' Identitas request dipertahankan.' : ''));
    } finally { busy = false; controls(); }
  }
  function intent(kind, path, body) {
    if (busy || pending || !loadout) return;
    remember({kind, path, body, characterId: loadout.character_id}); void submitPending();
  }
  function act(action, skill) {
    if (!run || run.status !== 'active') return;
    intent('action', `${training}/runs/${run.id}/actions`, {revision: run.revision, request_key: crypto.randomUUID(), action, ...(skill ? {skill_id: skill} : {})});
  }
  $('login-form').addEventListener('submit', async event => {
    event.preventDefault(); if (busy) return; busy = true; ++generation;
    const password = $('password').value; $('password').value = '';
    try {
      csrf = (await request(root + '/bootstrap')).csrf_token;
      const data = await request(root + '/login', {username: $('username').value, password}); csrf = data.csrf_token; await authenticated(data.user_id);
    } catch (error) { notice(error.message); } finally { busy = false; controls(); }
  });
  $('save').addEventListener('click', () => intent('loadout', `${training}/characters/${loadout.character_id}/loadout`, {
    revision: loadout.revision, equipment: Object.fromEntries(['weapon', 'back', 'clothing', 'accessory'].map(slot => [slot, $('gear-' + slot).value])), skills: [...$('skills').selectedOptions].map(o => o.value)}));
  $('start').addEventListener('click', () => intent('start', training + '/runs', {character_id: loadout.character_id, revision: loadout.revision, request_key: crypto.randomUUID()}));
  $('retry').addEventListener('click', () => void submitPending());
  $('refresh').addEventListener('click', () => { void refresh().catch(e => notice(e.message)); });
  $('character').addEventListener('change', () => { run = null; loadout = null; controls(); void refresh().catch(e => notice(e.message)); });
  for (const button of document.querySelectorAll('[data-action]')) button.addEventListener('click', () => act(button.dataset.action));
  $('logout').addEventListener('click', async () => {
    if (busy) return; busy = true; ++generation; controls();
    try { await request(root + '/logout', {}); $('session').hidden = true; $('login-form').hidden = false; run = null; loadout = null; userId = null; pending = null; notice('Sesi berakhir.'); }
    catch (e) { notice(e.message); } finally { busy = false; controls(); }
  });
  (async () => {
    busy = true; controls();
    try { csrf = (await request(root + '/bootstrap')).csrf_token; const identity = await request(training + '/identity'); await authenticated(identity.user_id); }
    catch (error) { $('login-form').hidden = false; notice(error.status === 401 ? 'Masuk dengan akun pemain evaluasi.' : error.message); }
    finally { busy = false; controls(); }
  })();
})();
