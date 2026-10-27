'use strict';
// Hosted-runner dependency admission, not a substitute for application tests.
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
let browser;
async function main() {
  assert.equal(process.env.CI, 'true');
  assert.equal(process.env.GITHUB_ACTIONS, 'true');
  assert.equal(process.env.RUNNER_ENVIRONMENT, 'github-hosted');
  assert.equal(process.env.RUNNER_OS, 'Linux');
  assert(process.env.PLAYWRIGHT_BROWSERS_PATH, 'Ephemeral browser directory required');
  browser = await chromium.launch({ headless: true, timeout: 20000 });
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  page.setDefaultTimeout(5000);
  await page.setContent('<title>Runner preflight</title><button onclick="this.textContent=\'Runtime ready\'">Check runtime</button>');
  await page.getByRole('button', { name: 'Check runtime', exact: true }).click();
  assert.equal(await page.getByRole('button').innerText(), 'Runtime ready');
  assert.equal(await page.title(), 'Runner preflight');
  console.log(`PASS browser-preflight: Chromium ${browser.version()} launches, renders and responds on hosted Linux; application assertions still required`);
}
main().catch(error => {
  console.error(`FAIL browser-preflight: ${error.stack}`);
  process.exitCode = 1;
}).finally(async () => { if (browser) await browser.close(); });
