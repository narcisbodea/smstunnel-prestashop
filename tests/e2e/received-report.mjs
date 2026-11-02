// Renders the texts the local SMSTunnel test server received during the end-to-end runs
// (results/<project>-received.json, written by run.mjs) as a page, and screenshots it.
//   node received-report.mjs smst-int-ps8 smst-int-ps9
import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';
import { HERE, REPO } from './lib.mjs';

const projects = process.argv.slice(2);
const screensDir = path.resolve(REPO, '..', 'prestashop-screens');
const escape = (text) => String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

const sections = projects.map((project) => {
  const file = path.join(HERE, 'results', `${project}-received.json`);
  const texts = JSON.parse(fs.readFileSync(file, 'utf8')).sort((a, b) => new Date(a.createdAt) - new Date(b.createdAt));
  const label = JSON.parse(fs.readFileSync(path.join(HERE, 'results', `${project}.json`), 'utf8')).label;
  const rows = texts
    .map(
      (t) => `<tr><td>${escape(new Date(t.createdAt).toISOString().replace('T', ' ').slice(0, 19))}</td>
        <td class="nowrap">${escape(t.phoneNumber)}</td><td>${escape(t.message)}</td><td><span class="s ${escape(t.status)}">${escape(t.status)}</span></td></tr>`,
    )
    .join('');
  return `<h2>${escape(label)} <small>${texts.length} texts</small></h2>
    <table><thead><tr><th>Received (UTC)</th><th>To</th><th>Text</th><th>Status</th></tr></thead><tbody>${rows}</tbody></table>`;
});

const html = `<!doctype html><html><head><meta charset="utf-8"><title>Texts received by the SMSTunnel test server</title>
<style>
  body { font: 14px/1.45 system-ui, sans-serif; margin: 24px; color: #1f2937; background: #fff; }
  h1 { font-size: 20px; margin: 0 0 4px; } p { color: #6b7280; margin: 0 0 18px; }
  h2 { font-size: 16px; margin: 22px 0 8px; } h2 small { color: #6b7280; font-weight: normal; }
  table { border-collapse: collapse; width: 100%; } th, td { text-align: left; padding: 6px 10px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  th { background: #f3f4f6; font-weight: 600; } .nowrap { white-space: nowrap; }
  .s { border-radius: 4px; padding: 1px 6px; font-size: 12px; background: #e5e7eb; }
  .s.sent, .s.delivered { background: #d1fae5; color: #065f46; } .s.pending { background: #fef3c7; color: #92400e; }
  .s.failed { background: #fee2e2; color: #991b1b; }
</style></head><body>
<h1>Texts received by the local SMSTunnel test server</h1>
<p>Recorded by the end-to-end tests of SMSTunnel for PrestaShop (database <code>messages</code>: phoneNumber, message, status).
"pending" means the text waits for its phone: the test that targets an offline phone leaves one.</p>
${sections.join('\n')}
</body></html>`;

const out = path.join(HERE, 'results', 'received-texts.html');
fs.writeFileSync(out, html);
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
await page.goto(`file:///${out.replace(/\\/g, '/')}`);
await page.screenshot({ path: path.join(screensDir, 'received-texts.png'), fullPage: true });
await browser.close();
console.log(`Wrote ${path.join(screensDir, 'received-texts.png')}`);
