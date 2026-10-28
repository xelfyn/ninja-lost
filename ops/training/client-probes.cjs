'use strict';
const assert = require('node:assert/strict');

module.exports = async function clientProbes(page, training, fixture) {
  const latest = `${training}/characters/${fixture.character}/latest`;
  let release, capturedResolve;
  const gate = new Promise(resolve => { release = resolve; });
  const captured = new Promise(resolve => { capturedResolve = resolve; });
  await page.route(latest, async route => {
    const response = await route.fetch(); const body = await response.json();
    assert.equal(body.run.revision, 4); capturedResolve(); await gate; await route.fulfill({response});
  });
  try {
    await page.getByRole('button', {name: 'Muat ulang state', exact: true}).click();
    let timer;
    try { await Promise.race([captured, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('Stale-response probe capture deadline')), 15000); })]); }
    finally { clearTimeout(timer); }
    await page.getByRole('button', {name: 'Dodge', exact: true}).click();
    await page.locator('#run-status[data-revision="5"]').waitFor();
    const delivered = page.waitForResponse(latest); release(); await delivered;
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    assert.equal(await page.locator('#run-status').getAttribute('data-revision'), '5');
    assert.equal(await page.locator('#actions').isDisabled(), false);
  } finally { release(); await page.unroute(latest); }
  console.log('PASS training-stale-read: delayed revision4 refresh delivered after revision5 action cannot rewind player UI');

  const loadout = `${training}/characters/${fixture.character}/loadout`;
  let unlock, capturedLoadout;
  const waiting = new Promise(resolve => { unlock = resolve; });
  const arrived = new Promise(resolve => { capturedLoadout = resolve; });
  await page.route(loadout, async route => { const response = await route.fetch(); capturedLoadout(); await waiting; await route.fulfill({response}); });
  try {
    await page.locator('#character').selectOption(String(fixture.character));
    let timer;
    try { await Promise.race([arrived, new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('Loadout probe deadline')), 15000); })]); }
    finally { clearTimeout(timer); }
    assert.equal(await page.locator('#save').isDisabled(), true);
    assert.equal(await page.locator('#start').isDisabled(), true);
    assert.equal(await page.locator('#actions').isDisabled(), true);
    unlock();
    await page.waitForFunction(() => !document.getElementById('save').disabled);
    assert.equal(await page.locator('#run-status').getAttribute('data-revision'), '5');
  } finally { unlock(); await page.unroute(loadout); }
  console.log('PASS training-loadout-read: mutation controls stay disabled until selected-character state arrives');

  for (const path of ['/evaluation/v1/purchases', 'https://example.invalid/collect']) {
    await page.evaluate(({user, character, path}) => {
      sessionStorage.setItem('nl-training-intent:' + user, JSON.stringify({kind: 'action', characterId: character, path,
        body: {revision: 5, request_key: crypto.randomUUID(), action: 'weapon'}}));
    }, {user: fixture.player, character: fixture.character, path});
    const posts = [], record = request => { if (request.method() === 'POST') posts.push(request.url()); };
    page.on('request', record);
    try {
      await page.reload({waitUntil: 'networkidle'}); await page.locator('#run-status[data-revision="5"]').waitFor();
      assert.equal(await page.locator('#retry').isVisible(), false);
      assert.equal(await page.evaluate(user => sessionStorage.getItem('nl-training-intent:' + user), fixture.player), null);
      assert.deepEqual(posts, []);
    } finally { page.off('request', record); }
  }
  console.log('PASS training-intent-scope: malformed restored paths are discarded without requests or CSRF token transmission');
};
