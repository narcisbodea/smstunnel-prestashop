import { chromium } from 'playwright-core';
const url = process.argv[2] || 'http://127.0.0.1:8471/';
const b = await chromium.launch({ channel: 'chrome', headless: true });
const p = await b.newPage();
const r = await p.goto(url, { timeout: 30000 });
console.log('status', r.status(), await p.title());
await b.close();
