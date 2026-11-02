// Screenshots of the module page for the README and the Addons listing, on a test shop.
// The shop talks to the local test server, so the page shows a "Test mode" banner and that server under
// Advanced: both are hidden in these pictures, which then show the page as a shop on smstunnel.io sees it.
//   SMSTUNNEL_TEST_KEY_FILE=... node listing-shots.mjs --project smst-int-ps9 --port 8472
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';
import { REPO, backOfficeTokens, docker, loginBackOffice, sql, testServer } from './lib.mjs';

const args = Object.fromEntries(
  process.argv.slice(2).reduce((pairs, value, i, all) => (value.startsWith('--') ? [...pairs, [value.slice(2), all[i + 1]]] : pairs), []),
);
const project = args.project || 'smst-int-ps9';
const base = `http://localhost:${args.port || 8472}`;
const out = path.join(REPO, 'docs', 'screenshots');
fs.mkdirSync(out, { recursive: true });
const server = testServer();
const container = `${project}-prestashop-1`;

// Install the built module with PrestaShop's console.
docker(['exec', container, 'rm', '-rf', '/var/www/html/modules/smstunnel']);
docker(['cp', path.join(REPO, 'build', 'smstunnel'), `${container}:/var/www/html/modules/smstunnel`]);
docker(['exec', container, 'chown', '-R', 'www-data:www-data', '/var/www/html/modules/smstunnel']);
docker(['exec', '-u', 'www-data', container, 'php', '/var/www/html/bin/console', 'prestashop:module', 'install', 'smstunnel']);
sql(project, 'UPDATE ps_employee SET id_lang = 1 WHERE id_employee = 1');

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await (await browser.newContext({ viewport: { width: 1400, height: 1000 }, deviceScaleFactor: 1 })).newPage();
await loginBackOffice(page, base);
const tokens = await backOfficeTokens(page);
await page.goto(`${base}/admin-dev/index.php/improve/modules/manage/action/configure/smstunnel?_token=${tokens.symfonyToken}`);
await page.waitForLoadState('networkidle').catch(() => {});
await page.screenshot({ path: path.join(out, 'first-visit.png') });

await page.fill('#SMSTUNNEL_API_KEY', server.apiKey);
await page.fill('#SMSTUNNEL_DEVICE_ID', server.deviceId);
await page.fill('#SMSTUNNEL_OWNER_NUMBERS', '+33 6 12 34 56 78');
await page.fill('#SMSTUNNEL_API_URL', server.fromDocker);
await Promise.all([page.waitForLoadState('load'), page.locator('button[name="submitSmstunnelSettings"]').first().click()]);
await page.goto(page.url().replace(/#.*$/, ''));
await page.waitForLoadState('networkidle').catch(() => {});

// Show the page as on smstunnel.io: no test banner, the default server.
const asOnSmstunnel = () =>
  page.evaluate(() => {
    document.querySelectorAll('#smstunnel-status .alert-warning').forEach((el) => el.remove());
    const field = document.getElementById('SMSTUNNEL_API_URL');
    if (field) field.value = 'https://smstunnel.io';
  });
await asOnSmstunnel();
await page.locator('#smstunnel-status').screenshot({ path: path.join(out, 'phones-and-test-text.png') });

// The whole page in one picture, in a window as tall as the page: a full-page capture would leave the fixed
// side menu one window high.
for (let i = 0; i < 3; i++) {
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  if (height === page.viewportSize().height) break;
  await page.setViewportSize({ width: 1400, height });
  await page.waitForTimeout(300);
}
await page.screenshot({ path: path.join(out, 'configuration.png') });
await page.setViewportSize({ width: 1400, height: 1000 });

// The same page with the back office in French, when the shop has French.
const french = sql(project, "SELECT id_lang FROM ps_lang WHERE iso_code = 'fr' AND active = 1");
if (french.length) {
  sql(project, `UPDATE ps_employee SET id_lang = ${french[0][0]} WHERE id_employee = 1`);
  await page.reload();
  await page.waitForLoadState('networkidle').catch(() => {});
  await asOnSmstunnel();
  await page.screenshot({ path: path.join(out, 'back-office-in-french.png') });
  sql(project, 'UPDATE ps_employee SET id_lang = 1 WHERE id_employee = 1');
}

docker(['exec', '-u', 'www-data', container, 'php', '/var/www/html/bin/console', 'prestashop:module', 'uninstall', 'smstunnel']);
await browser.close();
console.log(`Screenshots in ${out}`);
