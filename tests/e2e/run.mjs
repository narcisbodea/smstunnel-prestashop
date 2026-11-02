// End-to-end test of SMSTunnel for PrestaShop on a throwaway Docker shop (tests/docker/docker-compose.yml)
// against the LOCAL SMSTunnel test server. Never run it against a real shop or smstunnel.io.
//
//   SMSTUNNEL_TEST_KEY_FILE=/path/local-test-key.json node run.mjs --project smst-int-ps8 --port 8471 \
//       --label "PrestaShop 8.1.7 / PHP 7.4" --shots ps8 [--offlinePhone <id of an offline phone>]
//
// What it does: uploads build/smstunnel.zip in Module Manager, configures the module, sends a test text,
// places real orders in the front office, changes their status in the back office, and checks the texts
// in the test server's database. Also: duplicate hooks, invalid and premium-rate numbers, an offline phone,
// SMSTunnel down or unreachable during checkout, "Send again", French, GDPR export and erasure, uninstall.
//
// The test server allows 30 sends a minute per API key, and the test key may be shared: a text refused with
// HTTP 429 is sent again with the module's own "Send again" button after a pause, as a merchant would.
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';
import { REPO, HERE, Report, backOfficeTokens, docker, loginBackOffice, psHelper, sql, testServer, waitForTexts, textsTo, sleep } from './lib.mjs';

const args = Object.fromEntries(
  process.argv.slice(2).reduce((pairs, value, i, all) => (value.startsWith('--') ? [...pairs, [value.slice(2), all[i + 1]]] : pairs), []),
);
const project = args.project || 'smst-int-ps8';
const base = `http://localhost:${args.port || 8471}`;
const shots = args.shots || project;
const screensDir = args.screens || path.resolve(REPO, '..', 'prestashop-screens');
const resultsDir = path.join(HERE, 'results');
fs.mkdirSync(screensDir, { recursive: true });
fs.mkdirSync(resultsDir, { recursive: true });

const server = testServer();
const report = new Report(args.label || project);
const started = new Date(Date.now() - 2000);

const OWNER = '+14155550100';
const CUSTOMER = '+14155550123';
const TEST_NUMBER = '+14155550199';
const OFFLINE_PHONE = args.offlinePhone || '';
const THROTTLE_PAUSE = 30000;

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const bo = await (await browser.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
bo.on('dialog', (dialog) => dialog.accept());
// The customer gets a browser of their own: clearing its cookies must not log the back office out.
const shop = await (await browser.newContext({ viewport: { width: 1300, height: 1000 } })).newPage();

let tokens = { adminModulesToken: '', symfonyToken: '' };
let configureUrl = '';
let throttled = 0;

try {
  // Shop setup for the test: payments for US addresses, a tracking link on the carriers.
  sql(project, `INSERT IGNORE INTO ps_module_country (id_module, id_shop, id_country)
    SELECT m.id_module, 1, c.id_country FROM ps_module m JOIN ps_country c ON c.iso_code = 'US'
    WHERE m.name IN ('ps_wirepayment', 'ps_checkpayment', 'ps_cashondelivery')`);
  sql(project, `UPDATE ps_country SET active = 1 WHERE iso_code = 'US'`);
  sql(project, `UPDATE ps_carrier SET url = 'https://tracking.example.com/track?n=@' WHERE deleted = 0 AND active = 1`);
  sql(project, 'UPDATE ps_employee SET id_lang = 1 WHERE id_employee = 1');
  const SHIPPED = psHelper(project, 'state-id', 'PS_OS_SHIPPING').id;
  const DELIVERED = psHelper(project, 'state-id', 'PS_OS_DELIVERED').id;
  const PAYMENT = psHelper(project, 'state-id', 'PS_OS_PAYMENT').id;

  // 1. Install from the zip, like a merchant.
  tokens = await loginBackOffice(bo, base);
  const installed = await uploadModule();
  report.ok('module zip installs from Module Manager', installed.ok, installed.detail);
  report.ok('log table created', sql(project, "SHOW TABLES LIKE 'ps_smstunnel_log'").length === 1);
  tokens = await backOfficeTokens(bo);
  // Module Manager's configure route redirects to the module page with a valid token: keep that URL.
  await bo.goto(`${base}/admin-dev/index.php/improve/modules/manage/action/configure/smstunnel?_token=${tokens.symfonyToken}`);
  await bo.waitForLoadState('networkidle').catch(() => {});
  configureUrl = bo.url().replace(/#.*$/, '');

  // 2. Configure.
  report.ok('configuration page opens', await bo.isVisible('#smstunnel-status'));
  await bo.screenshot({ path: path.join(screensDir, `${shots}-1-first-visit.png`), fullPage: false });
  await saveSettings({ key: server.apiKey, deviceId: server.deviceId, owner: '+1 415 555 0100', url: server.fromDocker });
  const savedAlert = await alertText();
  report.ok('settings saved', /saved/i.test(savedAlert), savedAlert);
  report.ok('API key never shown back', (await bo.inputValue('#SMSTUNNEL_API_KEY')) === '' && !(await bo.content()).includes(server.apiKey));
  const savedOwner = sql(project, "SELECT value FROM ps_configuration WHERE name = 'SMSTUNNEL_OWNER_NUMBERS'")[0];
  report.ok('owner number saved in international format', savedOwner && savedOwner[0] === OWNER, savedOwner && savedOwner[0]);
  report.ok('phones listed from SMSTunnel', (await bo.locator('.smstunnel-phones tbody tr').count()) > 0);
  await bo.screenshot({ path: path.join(screensDir, `${shots}-2-configuration.png`), fullPage: true });

  // 3. Test text.
  const testAlert = await sendTestText(TEST_NUMBER);
  report.ok('test text accepted', /sent to \+14155550199/i.test(testAlert), testAlert);
  const testTexts = await waitForTexts(TEST_NUMBER, started, 1, (t) => /test text from/i.test(t.message));
  report.ok('test text reached SMSTunnel', testTexts.length >= 1, testTexts[0] && testTexts[0].message);

  // 4. Offline phone: the text waits on the account.
  if (OFFLINE_PHONE) {
    await bo.goto(configureUrl);
    await saveSettings({ deviceId: OFFLINE_PHONE });
    const offlineAlert = await sendTestText(TEST_NUMBER);
    report.ok('offline phone: the merchant is told the text waits', /offline/i.test(offlineAlert), offlineAlert);
    await bo.goto(configureUrl);
    await saveSettings({ deviceId: server.deviceId });
  }

  // 5. A real order in the front office: the owner gets a text.
  const order1 = await placeOrder({ phone: '(415) 555-0123', firstname: 'John', lastname: 'Doe' });
  report.ok('front-office order placed', Boolean(order1.reference), `${order1.reference}, ${order1.ms} ms`);
  const ownerTexts = await expectTexts(OWNER, 1, (t) => t.message.includes(order1.reference), order1.id);
  report.ok('owner texted about the new order', ownerTexts.length === 1, ownerTexts[0] && ownerTexts[0].message);
  report.ok('owner text has name and total', ownerTexts[0] && /John Doe/.test(ownerTexts[0].message) && /€|EUR/.test(ownerTexts[0].message));

  // 6. Back office: tracking number, then Shipped; the customer gets a text with the tracking link.
  await setTrackingNumber(order1.id, 'TRACK123FR');
  await setStatus(order1.id, SHIPPED);
  const shipped = await expectTexts(CUSTOMER, 1, (t) => t.message.includes(order1.reference), order1.id);
  report.ok('customer texted when the order shipped', shipped.length === 1, shipped[0] && shipped[0].message);
  report.ok('shipped text has the tracking link', shipped[0] && shipped[0].message.includes('https://tracking.example.com/track?n=TRACK123FR'));
  report.ok('national number completed with the address country code', shipped[0] && shipped[0].phoneNumber === CUSTOMER);

  await setStatus(order1.id, DELIVERED);
  const delivered = await expectTexts(CUSTOMER, 2, (t) => t.message.includes(order1.reference), order1.id);
  report.ok('customer texted when the order was delivered', delivered.length === 2, delivered[1] && delivered[1].message);

  // 7. Duplicates: the same hooks again (in SMSTunnel only), the same status again (back office).
  psHelper(project, 'fire-status', order1.id, DELIVERED, SHIPPED, 2);
  psHelper(project, 'fire-validate', order1.id, 2);
  await setStatus(order1.id, SHIPPED);
  await sleep(3000);
  const customerAfter = (await textsTo(CUSTOMER, started)).filter((t) => t.message.includes(order1.reference));
  const ownerAfter = (await textsTo(OWNER, started)).filter((t) => t.message.includes(order1.reference));
  report.ok('repeated hooks and repeated statuses send nothing twice', customerAfter.length === 2 && ownerAfter.length === 1, `customer ${customerAfter.length}, owner ${ownerAfter.length}`);

  // 8. Invalid and premium-rate numbers are skipped, the order goes through.
  const order2 = await placeOrder({ phone: '12', firstname: 'Ana', lastname: 'Invalid' });
  await setStatus(order2.id, SHIPPED);
  const skipped2 = (psHelper(project, 'log', order2.id).rows || []).find((r) => r.event === 'status');
  report.ok('invalid number skipped, not sent', Boolean(order2.reference) && skipped2 && skipped2.status === 'skipped' && skipped2.error === 'number:invalid', skipped2 && `${skipped2.recipient} ${skipped2.error}`);

  const order3 = await placeOrder({ phone: '+1 900 555 0123', firstname: 'Pat', lastname: 'Premium' });
  await setStatus(order3.id, SHIPPED);
  const skipped3 = (psHelper(project, 'log', order3.id).rows || []).find((r) => r.event === 'status');
  report.ok('premium-rate number skipped', skipped3 && skipped3.status === 'skipped' && skipped3.error === 'number:premium');
  report.ok('nothing reached SMSTunnel for the premium number', (await textsTo('+19005550123', started)).length === 0);

  // 9. SMSTunnel down (refused connection), then unreachable (no answer): checkout still works.
  await bo.goto(configureUrl);
  await saveSettings({ url: 'http://host.docker.internal:1' });
  const order4 = await placeOrder({ phone: '(415) 555-0124', firstname: 'Down', lastname: 'Test' });
  const log4 = (psHelper(project, 'log', order4.id).rows || []).find((r) => r.event === 'new_order');
  report.ok('SMSTunnel down: checkout completes', Boolean(order4.reference), `${order4.ms} ms`);
  report.ok('SMSTunnel down: the failure is logged in the module', log4 && log4.status === 'failed', log4 && log4.error);
  const psLog = sql(project, `SELECT message FROM ps_log WHERE message LIKE 'SMSTunnel:%' AND object_id = ${order4.id}`);
  report.ok("SMSTunnel down: the failure is in PrestaShop's log", psLog.length > 0, psLog[0] && psLog[0][0]);

  await bo.goto(configureUrl);
  await saveSettings({ url: 'http://10.255.255.1:3101' });
  const order5 = await placeOrder({ phone: '(415) 555-0125', firstname: 'Slow', lastname: 'Network' });
  report.ok('SMSTunnel unreachable: checkout completes, delay bounded by the 3 s connect timeout', Boolean(order5.reference) && order5.ms < 9000, `${order5.ms} ms`);
  await bo.goto(configureUrl);
  await saveSettings({ url: server.fromDocker });

  // 10. A text that did not go out can be sent again from the log, once SMSTunnel is back.
  let resendAlert = '';
  for (let attempt = 0; attempt < 4; attempt++) {
    await bo.goto(configureUrl);
    await clickSendAgain(log4.id_smstunnel_log);
    resendAlert = await alertText();
    if (!/too many texts/i.test(resendAlert)) break;
    throttled++;
    await sleep(THROTTLE_PAUSE);
  }
  report.ok('"Send again" sends a text that did not go out', /sent again/i.test(resendAlert), resendAlert);
  const resent = await waitForTexts(OWNER, started, 1, (t) => t.message.includes(order4.reference));
  report.ok('the text sent again reached SMSTunnel', resent.length === 1, resent[0] && resent[0].message);
  await bo.goto(configureUrl);
  const orderLink = await bo.locator('#smstunnel-log tr', { hasText: order1.reference }).locator('a').first().getAttribute('href').catch(() => null);
  if (orderLink) {
    // PrestaShop 8 writes the link relative to the site root.
    await bo.goto(new URL(orderLink, bo.url()).href);
    await bo.waitForLoadState('networkidle').catch(() => {});
  }
  report.ok('the log links each text to its order page', Boolean(orderLink) && (await bo.content()).includes(order1.reference) && /orders/.test(bo.url()), bo.url().split('?')[0]);
  await bo.goto(configureUrl);
  // A clip of the full page: PrestaShop 9's sticky header would cover the top of an element screenshot.
  const logBox = await bo.$eval('#smstunnel-log', (el) => {
    const r = el.getBoundingClientRect();
    return { x: r.left + window.scrollX, y: r.top + window.scrollY, width: r.width, height: r.height };
  });
  await bo.screenshot({ path: path.join(screensDir, `${shots}-3-recent-texts.png`), fullPage: true, clip: logBox });

  // 11. French (when the shop has it): the module's own catalogue in the back office, and a French customer
  // gets the suggested text in French.
  const french = sql(project, "SELECT id_lang FROM ps_lang WHERE iso_code = 'fr' AND active = 1");
  if (french.length) {
    sql(project, `UPDATE ps_employee SET id_lang = ${french[0][0]} WHERE id_employee = 1`);
    await bo.goto(configureUrl);
    const heading = (await bo.locator('#smstunnel-status .panel-heading').innerText()).trim();
    report.ok('back office in French: the module is in French', /depuis votre propre téléphone/i.test(heading), heading);
    await bo.screenshot({ path: path.join(screensDir, `${shots}-4-back-office-in-french.png`), fullPage: false });
    // Back to an English back office, and "Payment accepted" texts the customer too (its text has the total).
    sql(project, 'UPDATE ps_employee SET id_lang = 1 WHERE id_employee = 1');
    await bo.goto(configureUrl);
    await bo.check(`#SMSTUNNEL_STATES_${PAYMENT}`);
    await saveSettings({});

    const order6 = await placeOrder({ phone: '(415) 555-0126', firstname: 'Marie', lastname: 'Durand', lang: 'fr' });
    const ownerForFrench = await expectTexts(OWNER, 1, (t) => t.message.includes(order6.reference), order6.id);
    report.ok("owner's text uses the shop language's price format during a French checkout", Boolean(ownerForFrench[0]) && /€\d+\.\d\d\.$/.test(ownerForFrench[0].message), ownerForFrench[0] && ownerForFrench[0].message);
    await setStatus(order6.id, PAYMENT);
    const paidInFrench = await expectTexts('+14155550126', 1, (t) => t.message.includes(order6.reference), order6.id);
    report.ok("customer's text uses the order language's price format, sent from an English back office", Boolean(paidInFrench[0]) && /paiement de \d+,\d\d € pour la commande/.test(paidInFrench[0].message), paidInFrench[0] && paidInFrench[0].message);
    await setStatus(order6.id, SHIPPED);
    const inFrench = await expectTexts('+14155550126', 2, (t) => t.message.includes(order6.reference), order6.id);
    const shippedFr = inFrench.find((t) => /en route/.test(t.message));
    report.ok("customer's text is in the language of the order", Boolean(shippedFr) && /^Bonjour Marie, votre commande \S+ de .+ est en route\./.test(shippedFr.message), shippedFr && shippedFr.message);
  }

  // 12. GDPR export and erasure (PrestaShop's psgdpr module calls these hooks).
  const idCustomer = sql(project, `SELECT id_customer FROM ps_orders WHERE id_order = ${order1.id}`)[0][0];
  const gdpr = psHelper(project, 'gdpr', idCustomer);
  report.ok('GDPR export lists the customer texts', Array.isArray(gdpr.export) && gdpr.export.length >= 2);
  report.ok('GDPR erasure deletes them', gdpr.delete === true && !Array.isArray(gdpr.after));

  // 13. Uninstall removes the table and the settings.
  const uninstall = docker(['exec', '-u', 'www-data', `${project}-prestashop-1`, 'php', '/var/www/html/bin/console', 'prestashop:module', 'uninstall', 'smstunnel']);
  report.ok('uninstall succeeds', /succeeded/i.test(uninstall), uninstall.trim().split('\n').pop().trim());
  report.ok('uninstall drops the log table', sql(project, "SHOW TABLES LIKE 'ps_smstunnel_log'").length === 0);
  report.ok('uninstall deletes the settings', sql(project, "SELECT COUNT(*) FROM ps_configuration WHERE name LIKE 'SMSTUNNEL\\_%'")[0][0] === '0');

  // Everything the test server received in this run, for the record.
  const received = [];
  for (const number of [OWNER, CUSTOMER, TEST_NUMBER, '+14155550124', '+14155550125', '+14155550126']) {
    received.push(...(await textsTo(number, started)));
  }
  fs.writeFileSync(path.join(resultsDir, `${project}-received.json`), JSON.stringify(received, null, 2));
} catch (error) {
  report.ok('run finished without an exception', false, error.stack.split('\n').slice(0, 4).join(' | '));
  await bo.screenshot({ path: path.join(resultsDir, `${project}-error.png`), fullPage: true }).catch(() => {});
} finally {
  report.throttledAndSentAgain = throttled;
  fs.writeFileSync(path.join(resultsDir, `${project}.json`), JSON.stringify(report, null, 2));
  console.log(report.summary() + (throttled ? ` (${throttled} send(s) hit the shared 30/minute limit and were sent again)` : ''));
  await browser.close();
  process.exitCode = report.failed ? 1 : 0;
}

// ---------------------------------------------------------------------------------------------------

async function uploadModule() {
  const zip = path.join(REPO, 'build', 'smstunnel.zip');
  const manage = await bo.$eval('a[href*="improve/modules/manage"][href*="_token"]', (a) => a.href);
  await bo.goto(manage);
  await bo.waitForLoadState('networkidle').catch(() => {});
  await bo.click('#page-header-desc-configuration-add_module');
  const input = bo.locator('.dz-hidden-input, #importDropzone input[type=file]').first();
  await input.waitFor({ state: 'attached', timeout: 20000 });
  await input.setInputFiles(zip);
  await bo.waitForFunction(
    () => [...document.querySelectorAll('.module-import-success, .module-import-failure')].some((el) => el.offsetParent !== null),
    null,
    { timeout: 120000 },
  );
  const failure = await bo.locator('.module-import-failure-details').innerText().catch(() => '');
  const success = await bo.locator('.module-import-success').isVisible();
  return { ok: success && !failure.trim(), detail: failure.trim().slice(0, 300) || 'Module installed' };
}

async function saveSettings({ key, deviceId, owner, url }) {
  if (key !== undefined) await bo.fill('#SMSTUNNEL_API_KEY', key);
  if (deviceId !== undefined) await bo.fill('#SMSTUNNEL_DEVICE_ID', deviceId);
  if (owner !== undefined) await bo.fill('#SMSTUNNEL_OWNER_NUMBERS', owner);
  if (url !== undefined) await bo.fill('#SMSTUNNEL_API_URL', url);
  await Promise.all([bo.waitForLoadState('load'), bo.locator('button[name="submitSmstunnelSettings"]').first().click()]);
  await bo.waitForSelector('.alert', { timeout: 20000 }).catch(() => {});
}

/** Sends a test text from the module page; waits and tries again when the shared limit is reached. */
async function sendTestText(number) {
  let alert = '';
  for (let attempt = 0; attempt < 4; attempt++) {
    await bo.goto(configureUrl);
    await bo.fill('#SMSTUNNEL_TEST_NUMBER', number);
    await Promise.all([bo.waitForLoadState('load'), bo.click('button[name="submitSmstunnelTest"]')]);
    alert = await alertText();
    if (!/too many texts/i.test(alert)) return alert;
    throttled++;
    await sleep(THROTTLE_PAUSE);
  }
  return alert;
}

/**
 * Waits for `count` texts to a number. When the module logged one of the order's texts as refused with
 * HTTP 429 (the shared limit), sends it again from the log after a pause.
 */
async function expectTexts(number, count, filter, idOrder) {
  let texts = await waitForTexts(number, started, count, filter, 15000);
  for (let attempt = 0; texts.length < count && attempt < 4; attempt++) {
    const refused = (psHelper(project, 'log', idOrder).rows || []).filter((r) => r.status === 'failed' && /HTTP 429/.test(r.error || ''));
    if (!refused.length) break;
    throttled += refused.length;
    await sleep(THROTTLE_PAUSE);
    for (const row of refused) {
      await bo.goto(configureUrl);
      await clickSendAgain(row.id_smstunnel_log);
    }
    texts = await waitForTexts(number, started, count, filter, 15000);
  }
  return texts;
}

async function clickSendAgain(idLog) {
  const button = bo.locator(`#smstunnel-log form:has(input[name="id_smstunnel_log"][value="${idLog}"]) button[name="submitSmstunnelResend"]`);
  if (await button.count()) {
    await Promise.all([bo.waitForLoadState('load'), button.click()]);
  }
}

async function alertText() {
  const alerts = await bo.$$eval('.module_confirmation, .module_error, .module_warning, .alert-success, .alert-danger, .alert-warning', (els) =>
    els.filter((el) => el.offsetParent !== null).map((el) => el.innerText.replace(/\s+/g, ' ').replace('×', '').trim()),
  );
  return alerts.find((a) => /saved|sent|offline|could not|refused|not a mobile|look like|too many|SMSTunnel/i.test(a)) || alerts.join(' / ');
}

async function openOrder(idOrder) {
  await bo.goto(`${base}/admin-dev/index.php/sell/orders/${idOrder}/view?_token=${tokens.symfonyToken}`);
  await bo.waitForLoadState('networkidle').catch(() => {});
}

async function setTrackingNumber(idOrder, tracking) {
  await openOrder(idOrder);
  await bo.click('#orderShippingTab');
  await bo.click('.js-update-shipping-btn');
  await bo.waitForSelector('#update_order_shipping_tracking_number', { state: 'visible' });
  await bo.fill('#update_order_shipping_tracking_number', tracking);
  await Promise.all([bo.waitForLoadState('load'), bo.click('#updateOrderShippingModal button[type="submit"]')]);
  await bo.waitForLoadState('networkidle').catch(() => {});
}

/** Changes the status on the order page, as the merchant does. */
async function setStatus(idOrder, idState) {
  await openOrder(idOrder);
  await bo.selectOption('#update_order_status_action_input', String(idState));
  await Promise.all([bo.waitForLoadState('load'), bo.click('#update_order_status_action_btn')]);
  await bo.waitForLoadState('networkidle').catch(() => {});
}

/**
 * Opens a front-office page; in debug mode PrestaShop answers a non-canonical URL with a "This page has
 * moved" page (PrestaShop 9 drops the language from the default language's URLs): follow its link.
 */
async function gotoCanonical(url) {
  await shop.goto(url);
  if (/This page has moved/i.test(await shop.innerText('body').catch(() => ''))) {
    await shop.goto(await shop.$eval('a', (a) => a.href));
  }
}

/** Guest checkout of the first product with a US address and bank wire, in English or in French. */
async function placeOrder({ phone, firstname, lastname, lang = 'en' }) {
  // A shop with several languages puts the language in its URLs.
  const prefix = Number(sql(project, 'SELECT COUNT(*) FROM ps_lang WHERE active = 1')[0][0]) > 1 ? `/${lang}` : '';
  const slug = sql(project, `SELECT ml.url_rewrite FROM ps_meta m JOIN ps_meta_lang ml ON ml.id_meta = m.id_meta AND ml.id_shop = 1
    JOIN ps_lang l ON l.id_lang = ml.id_lang AND l.iso_code = '${lang}' WHERE m.page = 'order'`)[0][0];
  const us = sql(project, "SELECT id_country FROM ps_country WHERE iso_code = 'US'")[0][0];
  await shop.context().clearCookies();
  await gotoCanonical(lang === 'en' ? `${base}/` : `${base}${prefix}/`);
  await shop.goto(await shop.$eval('.product-miniature a', (a) => a.href));
  await shop.click('button[data-button-action="add-to-cart"]');
  await shop.waitForSelector('#blockcart-modal', { state: 'visible', timeout: 20000 });
  await gotoCanonical(`${base}${prefix}/${slug}`);
  await shop.fill('#field-firstname', firstname);
  await shop.fill('#field-lastname', lastname);
  await shop.fill('#field-email', `${firstname.toLowerCase()}.${Date.now()}@example.com`);
  for (const name of ['customer_privacy', 'psgdpr']) {
    await shop.check(`input[name="${name}"]`).catch(() => {});
  }
  await shop.locator('button[name="continue"]:visible').first().click();
  await shop.waitForSelector('#field-address1', { state: 'visible' });
  await shop.selectOption('#field-id_country', us);
  await shop.waitForSelector('#field-id_state', { state: 'visible', timeout: 20000 });
  await shop.fill('#field-address1', '1 Market Street');
  await shop.fill('#field-city', 'San Francisco');
  await shop.selectOption('#field-id_state', { label: 'California' });
  await shop.fill('#field-postcode', '94105');
  await shop.fill('#field-phone', phone);
  await shop.click('button[name="confirm-addresses"]');
  await shop.waitForSelector('button[name="confirmDeliveryOption"]', { state: 'visible', timeout: 20000 });
  await shop.click('button[name="confirmDeliveryOption"]');
  await shop.waitForSelector('#payment-confirmation', { state: 'visible', timeout: 20000 });
  const wire = shop.locator('input[data-module-name="ps_wirepayment"]');
  await shop.click(`label[for="${await wire.getAttribute('id')}"]`);
  await shop.check('input[name="conditions_to_approve[terms-and-conditions]"]');
  const t0 = Date.now();
  await Promise.all([shop.waitForURL(/order-confirmation/, { timeout: 90000 }), shop.click('#payment-confirmation button')]);
  const ms = Date.now() - t0;
  const id = Number(new URL(shop.url()).searchParams.get('id_order'));
  const reference = id ? sql(project, `SELECT reference FROM ps_orders WHERE id_order = ${id}`)[0][0] : '';
  return { id, reference, ms };
}
