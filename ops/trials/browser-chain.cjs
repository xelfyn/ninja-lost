'use strict';
const assert = require('node:assert/strict'), crypto = require('node:crypto');
module.exports = async function chain({staff, page, trialRoot, f, fixture}) {
  await staff.getByRole('button', {name: 'Aturan baru', exact: true}).click();
  await staff.locator('#trial-slug').fill('qa-trial-chain-' + crypto.randomBytes(6).toString('hex'));
  await staff.locator('#trial-title').fill('QA Repeatable Trial'); await staff.locator('#trial-gold').fill('25');
  await staff.locator('#trial-xp').fill('120'); await staff.locator('#trial-limit').fill('2');
  await staff.getByRole('button', {name: 'Simpan nonaktif', exact: true}).click(); await staff.locator('#trial-created').waitFor();
  const rule = await staff.locator('#trial-created').innerText();
  staff.once('dialog', d => d.accept()); await staff.getByTestId('trial-row-' + rule).getByRole('button', {name: 'Aktifkan', exact: true}).click();
  await staff.getByTestId('trial-row-' + rule).getByRole('button', {name: 'Nonaktifkan', exact: true}).waitFor();
  await page.getByRole('button', {name: 'Muat ulang', exact: true}).click();
  await page.waitForFunction(id => [...document.getElementById('trial').options].some(o => o.value === id), rule);
  await page.locator('#trial').selectOption(rule); await page.getByRole('button', {name: 'Mulai tantangan', exact: true}).click();
  await page.locator('#status[data-revision="0"][data-state="started"]').waitFor(); fixture('ready-latest');
  await page.reload({waitUntil: 'networkidle'}); await page.locator('#status[data-revision="0"][data-state="started"]').waitFor();
  for (let i = 0; i < 16; i++) {
    if ((await page.locator('#status').innerText()).includes('· ended ·')) break;
    const revision = Number(await page.locator('#status').getAttribute('data-revision'));
    await page.locator(`[data-skill="${f.skill}"]`).click(); await page.locator(`#status[data-revision="${revision + 1}"]`).waitFor();
  }
  assert((await page.locator('#status').innerText()).includes('· ended ·'));
  const second = await page.locator('#status').getAttribute('data-battle');
  await page.getByRole('button', {name: 'Verifikasi hasil dan selesaikan', exact: true}).click();
  await page.locator('#status[data-state="won"]').waitFor();
  await page.locator('#loadout').filter({hasText: 'Revision akun 4 '}).waitFor();
  assert((await page.locator('#trial').innerText()).includes('1/2'));
  // No reload or manual refresh between the successful finish and the next start.
  const response = page.waitForResponse(r => r.url() === trialRoot + '/runs' && r.request().method() === 'POST');
  await page.getByRole('button', {name: 'Mulai tantangan', exact: true}).click();
  assert.equal((await response).status(), 200);
  await page.locator('#status[data-revision="0"][data-state="started"]').waitFor();
  assert.notEqual(await page.locator('#status').getAttribute('data-battle'), second);
  assert.equal(fixture('verify-chain').wins, 2);
  console.log('PASS trial-consecutive-start: successful reward refreshes account revision and quota before next UI start; no reload/manual refresh/409 retry required');
};
