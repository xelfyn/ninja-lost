'use strict';
(() => {
  const root = '/evaluation/v1', training = root + '/training', $ = id => document.getElementById(id);
  let csrf = '', userId = null, loadout = null, run = null, pending = null, busy = false;
  const notice = text => { $('notice').textContent = text; };
  const storageKey = () => 'nl-training-intent:' + userId;
  function remember(value) {
    // Only a command/revision/request UUID is retained. Never credentials, cookies, seed or server stats.
    pending = value;
    if (value) sessionStorage.setItem(storageKey(), JSON.stringify(value)); else sessionStorage.removeItem(storageKey());
    controls();
  }
  function controls() {
    $('configuration').disabled = busy || Boolean(pending);
    $('actions').disabled = busy || Boolean(pending) || !run || run.status !== 'active';
    $('start').disabled = !loadout || run?.status === 'active';
    $('refresh').disabled = busy; $('retry').hidden = !pending; $('retry').disabled = busy;
  }
  async function request(path, body) {
    const response = await fetch(path, {method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin',
      headers: {Accept: 'application/json', ...(body === undefined ? {} : {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf})},
      ...(body === undefined ? {} : {body: JSON.stringify(body)})});
    let result; try { result = await response.json(); } catch { throw new Error('Respons tidak terbaca; ulangi request yang sama.'); }
    if (!response.ok) { const error = new Error(result.message || 'Permintaan ditolak.'); error.status = response.status; throw error; }
    return result;
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
    $('loadout-note').textContent = `Revision akun ${data.revision}. Persyaratan level, elemen, akun, dan kepemilikan tetap diperiksa server.` + (data.truncated ? ' Daftar lebih dari 100; sebagian opsi tidak ditampilkan.' : '');
    controls();
  }
  function renderRun(value) {
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
    if (!$('character').value) return;
    const id = $('character').value;
    renderLoadout(await request(`${training}/characters/${id}/loadout`));
    renderRun((await request(`${training}/characters/${id}/latest`)).run);
  }
  async function authenticated(id) {
    userId = id; $('login-form').hidden = true; $('session').hidden = false;
    try { pending = JSON.parse(sessionStorage.getItem(storageKey()) || 'null'); } catch { pending = null; }
    const list = await request(root + '/characters'); $('character').replaceChildren();
    for (const c of list.data) $('character').append(option(c.id, `${c.name} · level ${c.level}`));
    if (pending?.characterId && list.data.some(c => c.id === pending.characterId)) $('character').value = String(pending.characterId);
    await refresh(); controls(); notice(pending ? 'Ada permintaan belum terkonfirmasi. Ulangi dengan identitas yang sama.' : 'Sesi siap. Simpan loadout sebelum mulai latihan.');
  }
  async function submitPending() {
    if (!pending || busy) return; busy = true; controls();
    const intent = pending;
    try {
      const result = await request(intent.path, intent.body);
      remember(null);
      if (intent.kind === 'loadout') renderLoadout(result); else renderRun(result.run);
      notice(result.replayed ? 'Permintaan sudah diproses sebelumnya; tidak ada aksi tambahan.' : 'State server tersimpan.');
    } catch (error) {
      if ([409, 410, 422].includes(error.status)) { remember(null); await refresh(); }
      if ([401, 419].includes(error.status)) { $('login-form').hidden = false; $('session').hidden = true; }
      notice(error.message + (pending ? ' Identitas request dipertahankan.' : ''));
    } finally { busy = false; controls(); }
  }
  function intent(kind, path, body) {
    if (busy || pending) return;
    remember({kind, path, body, characterId: loadout.character_id}); void submitPending();
  }
  function act(action, skill) {
    if (!run || run.status !== 'active') return;
    intent('action', `${training}/runs/${run.id}/actions`, {revision: run.revision, request_key: crypto.randomUUID(), action, ...(skill ? {skill_id: skill} : {})});
  }
  $('login-form').addEventListener('submit', async event => {
    event.preventDefault(); if (busy) return; busy = true;
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
  $('character').addEventListener('change', () => { void refresh().catch(e => notice(e.message)); });
  for (const button of document.querySelectorAll('[data-action]')) button.addEventListener('click', () => act(button.dataset.action));
  $('logout').addEventListener('click', async () => {
    try { await request(root + '/logout', {}); $('session').hidden = true; $('login-form').hidden = false; run = null; loadout = null; userId = null; pending = null; notice('Sesi berakhir.'); }
    catch (e) { notice(e.message); }
  });
  (async () => {
    try { csrf = (await request(root + '/bootstrap')).csrf_token; const identity = await request(training + '/identity'); await authenticated(identity.user_id); }
    catch (error) { $('login-form').hidden = false; notice(error.status === 401 ? 'Masuk dengan akun pemain evaluasi.' : error.message); }
  })();
})();
