// Builds smstunnel/docs/readme_en.pdf, the user guide PrestaShop Addons expects in the module's docs/ folder.
// Addons asks for no website URL or email address in the documentation, so the guide names places in words.
// Needs the end-to-end tools (cd tests/e2e && npm install) and the screenshots in docs/screenshots.
//   node tools/user-guide.mjs
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(path.join(root, 'tests', 'e2e', 'package.json'));
const { chromium } = require('playwright-core');

const version = /\$this->version = '([^']+)'/.exec(fs.readFileSync(path.join(root, 'smstunnel', 'smstunnel.php'), 'utf8'))[1];
const image = (file) => `data:image/png;base64,${fs.readFileSync(path.join(root, 'docs', 'screenshots', file)).toString('base64')}`;

const html = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>SMSTunnel: SMS notifications - user guide</title>
<style>
  @page { size: A4; margin: 18mm 16mm; }
  body { font: 10.5pt/1.5 "Segoe UI", Arial, sans-serif; color: #1f2937; }
  h1 { font-size: 21pt; margin: 0 0 2mm; color: #2240ae; } .sub { color: #4b5563; margin: 0 0 8mm; }
  h2 { font-size: 13pt; color: #2240ae; margin: 7mm 0 2mm; break-after: avoid; }
  table { border-collapse: collapse; width: 100%; margin: 2mm 0; } td, th { border: 1px solid #d1d5db; padding: 1.5mm 2.5mm; text-align: left; vertical-align: top; }
  th { background: #eef2ff; } code { font-family: Consolas, monospace; font-size: 9.5pt; background: #f3f4f6; padding: 0 1mm; }
  img { max-width: 100%; border: 1px solid #d1d5db; margin: 2mm 0; } li { margin: 1mm 0; } .note { color: #4b5563; font-size: 9.5pt; }
</style></head><body>
<h1>SMSTunnel: SMS notifications</h1>
<p class="sub">User guide, version ${version}. For PrestaShop 8.1 to 9.2.</p>

<p>The module texts your customers about their orders, and you about new orders, from <b>your own Android phone</b> and
<b>your own number</b>. Your shop hands each text to your SMSTunnel account, and the SMSTunnel app on your phone sends it
from its own SIM: customers see a number they know and can reply to it.</p>

<h2>1. Before you start</h2>
<ul>
  <li>An Android phone with the <b>SMSTunnel</b> app from Google Play, signed in to your SMSTunnel account.</li>
  <li>The SMSTunnel dashboard on your computer, signed in with the same account: your phone appears under <b>Phones</b>.</li>
</ul>

<h2>2. Install</h2>
<ol>
  <li>In your back office, open <b>Modules &gt; Module Manager</b> and click <b>Upload a module</b>.</li>
  <li>Drop <code>smstunnel.zip</code>, then click <b>Configure</b>.</li>
</ol>

<h2>3. Connect your account</h2>
<ol>
  <li>In the SMSTunnel dashboard, open <b>Apps</b>, then <b>Add an app</b>, and copy its key.</li>
  <li>Paste it into <b>API key</b> on the module page. The saved key is never shown again; leave the field empty to keep it.</li>
  <li><b>Phone ID</b> is optional: leave it empty to send from any of your phones, or copy one phone's ID from <b>Phones</b> in the dashboard.</li>
  <li>Save, then use <b>Send a test text</b> at the top of the page. The page lists your phones and whether they are online.</li>
</ol>
<img src="${image('phones-and-test-text.png')}" alt="Your phones and the test text">

<h2>4. Choose the texts</h2>
<p><b>New orders: a text to you.</b> Add one or more of your numbers (at most 5), in international format such as
<code>+33612345678</code>. Every new order sends a short text to each of them.</p>
<p><b>Order updates: a text to the customer.</b> Tick the statuses that should text the customer (Shipped, Delivered and
Canceled are ticked at installation). Each ticked status has its own text, in each language of your shop; the customer
gets the text in the language of the order. A text left as suggested follows the module's updates.</p>
<table>
  <tr><th>Placeholder</th><th>Becomes</th></tr>
  <tr><td><code>{firstname}</code> <code>{lastname}</code></td><td>The customer's name.</td></tr>
  <tr><td><code>{order_reference}</code></td><td>The order reference.</td></tr>
  <tr><td><code>{total_paid}</code></td><td>The total paid, as your shop shows prices.</td></tr>
  <tr><td><code>{shop_name}</code></td><td>Your shop's name.</td></tr>
  <tr><td><code>{tracking_number}</code> <code>{tracking_url}</code></td><td>The tracking number, and the carrier's tracking link with it (the carrier's Tracking URL, with @ where the number goes). Empty until you add a tracking number.</td></tr>
  <tr><td><code>{status}</code></td><td>The new status, in the order's language.</td></tr>
</table>
<p class="note">A text can be up to 1600 characters. One character outside the basic SMS alphabet (such as ș or á) makes
each SMS part 70 characters long instead of 160.</p>

<h2>5. Phone numbers</h2>
<p>The customer's number comes from the delivery address (mobile phone first, then phone), then from the invoice
address. A number written without + or 00 is completed with the calling code of the address country: in France,
<code>06 12 34 56 78</code> becomes <code>+33612345678</code>. Invalid numbers, premium-rate numbers, short codes and
satellite networks are skipped, and the module shows why.</p>

<h2>6. Recent texts</h2>
<p>The bottom of the module page lists the last texts: when, to whom, the text, and whether it went out. A text that
did not go out (SMSTunnel could not be reached, or too many texts at once) has a <b>Send again</b> button. When your
phone is offline, texts wait on your account and go out when it is back online.</p>
<img src="${image('recent-texts.png')}" alt="Recent texts">
<p>The module never stops a checkout: if SMSTunnel cannot be reached, the order goes through and the failure is also
written to <b>Advanced Parameters &gt; Logs</b>. Each text goes out once, even if an order gets the same status again.</p>

<h2>7. Privacy and GDPR</h2>
<ul>
  <li>Only transactional texts about orders are sent: to your own numbers for new orders, and to the customer when the order moves to a status you chose.</li>
  <li>SMSTunnel receives, for each text, the recipient's number and the text itself (which can contain the customer's name, the order reference, the total and the tracking link), plus your API key and the country calling code. Nothing else: no email address, no postal address, no order contents.</li>
  <li>The module keeps a log of the last 1000 texts in your shop's database. With PrestaShop's GDPR module, a customer's data export includes the texts about their orders, and erasing the customer deletes them.</li>
  <li>Uninstalling the module deletes its log and all its settings.</li>
</ul>

<h2>8. Advanced</h2>
<p>The <b>SMSTunnel server</b> field is for testing the module against a test server only. A shop keeps the default.</p>
</body></html>`;

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage();
await page.setContent(html, { waitUntil: 'load' });
const docsDir = path.join(root, 'smstunnel', 'docs');
fs.mkdirSync(docsDir, { recursive: true });
await page.pdf({ path: path.join(docsDir, 'readme_en.pdf'), format: 'A4', printBackground: true });
await browser.close();
const index = path.join(docsDir, 'index.php');
if (!fs.existsSync(index)) fs.copyFileSync(path.join(root, 'tools', 'index.php.tpl'), index);
console.log(`Wrote smstunnel/docs/readme_en.pdf (${fs.statSync(path.join(docsDir, 'readme_en.pdf')).size} bytes)`);
