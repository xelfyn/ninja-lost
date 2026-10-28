'use strict';
(() => {
  const base = '/evaluation/v1', apiRoot = base + '/trials', $ = id => document.getElementById(id);
  const uuid = /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/;
  let csrf = '', user = null, loadout = null, current = null, pending = null, busy = true, epoch = 0;
  const key = () => 'nl-trial-intent:' + user;
  const notice = text => { $('notice').textContent = text; };
  function controls() {
    $('selection').disabled = busy || !!pending;
    $('start').disabled = !loadout || !$('trial').value || current?.battle_status === 'started';
    $('actions').disabled = busy || !!pending || !current || current.battle_status !== 'started' || current.run.status !== 'active';
    $('finish').disabled = busy || !!pending || !current || current.battle_status !== 'started' || current.run.status !== 'ended';
    $('refresh').disabled = busy; $('retry').hidden = !pending; $('retry').disabled = busy;
  }
  function journal(value) {
    pending = value;
    try { if (value) sessionStorage.setItem(key(), JSON.stringify(value)); else sessionStorage.removeItem(key()); }
    catch { notice('Penyimpanan tab tidak tersedia; pertahankan tab untuk retry.'); }
    controls();
  }
  async function request(path, body) {
    if (!path.startsWith(base + '/') || /[\\?#%]/.test(path)) throw new Error('Path tidak valid.');
    const response = await fetch(path, {method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', headers: {Accept: 'application/json',
      ...(body === undefined ? {} : {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf})}, ...(body === undefined ? {} : {body: JSON.stringify(body)})});
    const result = await response.json();
    if (!response.ok) { const e = new Error(result.message || 'Permintaan ditolak.'); e.status = response.status; throw e; }
    return result;
  }
  function option(value, label) { const node = document.createElement('option'); node.value = value; node.textContent = label; return node; }
  function render(trial) {
    if (current && trial && current.battle_id === trial.battle_id && trial.run.revision < current.run.revision) return;
    current = trial; $('battle').hidden = !trial;
    if (!trial) { controls(); return; }
    const state = trial.run.state;
    $('title').textContent = trial.title; $('status').textContent = `${trial.battle_status} · ${trial.run.status} · aksi ${trial.run.revision}`;
    $('status').dataset.revision = String(trial.run.revision); $('status').dataset.battle = trial.battle_id; $('status').dataset.state = trial.battle_status;
    $('rewards').textContent = `Reward jika menang: ${trial.rewards.gold} gold + ${trial.rewards.xp} XP (cap rank tetap berlaku).`;
    for (const side of ['player', 'mirror']) {
      const p = state[side]; $(side + '-hp').textContent = `HP ${p.hp} / ${p.max_hp}`; $(side + '-bar').max = p.max_hp; $(side + '-bar').value = p.hp;
      $(side + '-cp').textContent = `CP ${p.cp} / ${p.max_cp}`;
    }
    $('skill-actions').replaceChildren();
    for (const skill of state.skills) {
      const b = document.createElement('button'); b.type = 'button'; b.textContent = `${skill.name} · CP ${skill.cp_cost} · CD ${skill.cooldown}`;
      b.dataset.skill = skill.id; b.disabled = skill.cooldown > 0 || state.player.cp < skill.cp_cost; b.title = skill.tooltip;
      b.addEventListener('click', () => action('skill', skill.id)); $('skill-actions').append(b);
    }
    $('receipt').textContent = trial.result ? (trial.result.outcome === 'won'
      ? `Menang. Receipt ${trial.result.claim_id}. ${trial.result.granted.map(g => `${g.quantity} ${g.type}`).join(' + ')} tersimpan.` : 'Kalah/menyerah. Tidak ada reward.') : '';
    controls();
  }
  async function refresh() {
    const selected = $('character').value, revision = ++epoch;
    if (!selected) return;
    const [gear, last] = await Promise.all([request(`${base}/training/characters/${selected}/loadout`), request(`${apiRoot}/characters/${selected}/latest`)]);
    if (revision !== epoch || selected !== $('character').value) return;
    loadout = gear; $('loadout').textContent = `Revision akun ${gear.revision} · ${gear.skills.length} skill terpasang. Ubah loadout pada halaman training sebelum mulai.`;
    render(last.trial); controls();
  }
  function pathFor(intent) {
    if (intent.kind === 'start') return apiRoot + '/runs';
    if (!uuid.test(intent.battle || '') || !['action', 'finish'].includes(intent.kind)) throw new Error('Identitas retry tidak valid.');
    return `${apiRoot}/runs/${intent.battle}/${intent.kind === 'action' ? 'actions' : 'finish'}`;
  }
  async function submit() {
    if (busy || !pending) return; busy = true; ++epoch; controls();
    try { const result = await request(pathFor(pending), pending.body); journal(null); render(result.trial); notice(result.replayed ? 'Receipt asli dipulihkan; tidak diproses dua kali.' : 'Tersimpan di server.'); }
    catch (e) {
      if ([409, 410, 422].includes(e.status)) { journal(null); await refresh(); }
      notice(e.message + (pending ? ' Identitas request dipertahankan.' : ''));
    } finally { busy = false; controls(); }
  }
  function send(kind, body) {
    if (busy || pending || !loadout) return;
    journal({kind, body, character: loadout.character_id, battle: current?.battle_id || null}); void submit();
  }
  function action(name, skill) {
    if (!current) return;
    send('action', {revision: current.run.revision, request_key: crypto.randomUUID(), action: name, ...(skill ? {skill_id: skill} : {})});
  }
  $('start').addEventListener('click', () => send('start', {character_id: loadout.character_id, trial_id: Number($('trial').value), revision: loadout.revision, request_key: crypto.randomUUID()}));
  $('finish').addEventListener('click', () => send('finish', {})); $('retry').addEventListener('click', () => void submit());
  $('refresh').addEventListener('click', () => void refresh().catch(e => notice(e.message)));
  $('character').addEventListener('change', () => { current = null; loadout = null; controls(); void refresh().catch(e => notice(e.message)); });
  for (const button of document.querySelectorAll('[data-action]')) button.addEventListener('click', () => action(button.dataset.action));
  (async () => {
    controls();
    try {
      csrf = (await request(base + '/bootstrap')).csrf_token;
      user = (await request(base + '/training/identity')).user_id;
      const [characters, trials] = await Promise.all([request(base + '/characters'), request(apiRoot + '/definitions')]);
      $('character').replaceChildren(...characters.data.map(c => option(c.id, c.name)));
      $('trial').replaceChildren(...trials.data.filter(t => t.wins < t.win_limit).map(t => option(t.id, `${t.title} · ${t.gold} gold / ${t.xp} XP · ${t.wins}/${t.win_limit}`)));
      let saved = null; try { saved = JSON.parse(sessionStorage.getItem(key()) || 'null'); } catch {}
      if (saved && characters.data.some(c => c.id === saved.character) && saved.body && typeof saved.body === 'object' && !Array.isArray(saved.body)
          && ['start', 'action', 'finish'].includes(saved.kind) && (saved.kind === 'start' || uuid.test(saved.battle || ''))
          && (saved.kind === 'finish' || uuid.test(saved.body.request_key || ''))) {
        pending = saved; $('character').value = String(saved.character);
      } else if (saved) journal(null);
      await refresh(); $('session').hidden = false; notice(pending ? 'Ada request belum terkonfirmasi. Ulangi identitas yang sama.' : 'Pilih aturan yang dibuat admin. Training biasa tetap tanpa hadiah.');
    } catch (e) { notice(e.status === 401 ? 'Masuk melalui halaman training terlebih dahulu.' : e.message); }
    finally { busy = false; controls(); }
  })();
})();
