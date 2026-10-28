'use strict';
const assert = require('node:assert/strict');

async function deadline(promise, label) {
  let timer;
  try {
    return await Promise.race([promise, new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error(label + ' deadline')), 15000);
    })]);
  } finally { clearTimeout(timer); }
}

// Transfer handler errors to the awaited test and drain fulfillment before removing the route.
// Otherwise an assertion failure can close the page while its held response is still in flight.
async function withPausedResponse(page, url, inspect, exercise) {
  let release, arrived, finished, started = false;
  const gate = new Promise(resolve => { release = resolve; });
  const captured = new Promise(resolve => { arrived = resolve; });
  const complete = new Promise(resolve => { finished = resolve; });
  const errors = [];
  const handler = async route => {
    started = true; let settling = false;
    try {
      const response = await route.fetch({timeout: 15000});
      assert.equal(response.status(), 200);
      inspect(await response.json()); arrived();
      await gate; settling = true; await route.fulfill({response});
    } catch (error) {
      errors.push(error); arrived();
      if (!settling) {
        try { await route.abort('failed'); } catch (abortError) { errors.push(abortError); }
      }
    }
    finally { finished(); }
  };
  await page.route(url, handler, {times: 1});
  try {
    await exercise({release, captured: async () => {
      await deadline(captured, 'Paused-response capture');
      if (errors.length) throw errors[0];
    }});
  } catch (error) { if (!errors.includes(error)) errors.unshift(error); }
  finally {
    release();
    try { if (started) await deadline(complete, 'Paused-response completion'); }
    catch (error) { errors.push(error); }
    try { await page.unroute(url, handler); }
    catch (error) { errors.push(error); }
  }
  if (errors.length === 1) throw errors[0];
  if (errors.length > 1) throw new AggregateError(errors, 'Paused-response probe failed');
}

module.exports = async function clientProbes(page, training, fixture) {
  const latest = `${training}/characters/${fixture.character}/latest`;
  await withPausedResponse(page, latest, body => assert.equal(body.run.revision, 4), async hold => {
    await page.getByRole('button', {name: 'Muat ulang state', exact: true}).click();
    await hold.captured();
    await page.getByRole('button', {name: 'Dodge', exact: true}).click();
    await page.locator('#run-status[data-revision="5"]').waitFor();
    const delivered = page.waitForResponse(latest); hold.release(); await delivered;
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    assert.equal(await page.locator('#run-status').getAttribute('data-revision'), '5');
    assert.equal(await page.locator('#actions').evaluate(el => el.disabled), false);
    assert.equal(await page.getByRole('button', {name: 'Weapon', exact: true}).isDisabled(), false);
  });
  console.log('PASS training-stale-read: delayed revision4 refresh delivered after revision5 action cannot rewind player UI');

  const loadout = `${training}/characters/${fixture.character}/loadout`;
  await withPausedResponse(page, loadout, body => assert.equal(body.character_id, fixture.character), async hold => {
    await page.locator('#character').selectOption(String(fixture.character));
    await hold.captured();
    assert.equal(await page.locator('#save').isDisabled(), true);
    assert.equal(await page.locator('#start').isDisabled(), true);
    // Playwright's isDisabled applies to actionable descendants, not the fieldset itself.
    assert.equal(await page.locator('#actions').evaluate(el => el.disabled), true);
    for (const action of ['weapon', 'charge', 'dodge', 'run']) {
      assert.equal(await page.locator(`[data-action="${action}"]`).isDisabled(), true);
    }
    assert.equal(await page.locator(`[data-skill="${fixture.skill}"]`).isDisabled(), true);
    hold.release();
    await page.waitForFunction(() => !document.getElementById('save').disabled);
    assert.equal(await page.locator('#run-status').getAttribute('data-revision'), '5');
    assert.equal(await page.getByRole('button', {name: 'Weapon', exact: true}).isDisabled(), false);
  });
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
