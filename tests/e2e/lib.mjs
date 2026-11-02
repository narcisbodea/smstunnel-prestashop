// Shared helpers for the end-to-end tests: Docker test shops, the local SMSTunnel test server, its database.
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const HERE = path.dirname(fileURLToPath(import.meta.url));
export const REPO = path.resolve(HERE, '..', '..');
export const ADMIN_EMAIL = 'demo@prestashop.com';
export const ADMIN_PASSWORD = 'Smstunnel-test-2026';

/**
 * The local test server and its key, from the JSON file named by SMSTUNNEL_TEST_KEY_FILE
 * ({ serverUrl, apiKey, deviceId }). Production is refused.
 */
export function testServer() {
  const file = process.env.SMSTUNNEL_TEST_KEY_FILE;
  if (!file) throw new Error('Set SMSTUNNEL_TEST_KEY_FILE to the JSON file of the local test key.');
  const key = JSON.parse(fs.readFileSync(file, 'utf8'));
  if (/smstunnel\.io/i.test(key.serverUrl)) throw new Error('Refusing to test against production.');
  const url = new URL(key.serverUrl);
  // The shop runs in Docker: the host's server is host.docker.internal there.
  const fromDocker = `${url.protocol}//host.docker.internal:${url.port || (url.protocol === 'https:' ? 443 : 80)}`;
  return { ...key, fromDocker };
}

/** Runs fn(messages collection) against the local SMSTunnel database (read only). */
export async function withMessages(fn) {
  const require = createRequire(process.env.SMSTUNNEL_MONGO_FROM || 'D:/ANDROID/SMSserver/package.json');
  const { MongoClient } = require('mongodb');
  const client = await MongoClient.connect(process.env.SMSTUNNEL_TEST_MONGO || 'mongodb://127.0.0.1:27018');
  try {
    return await fn(client.db(process.env.SMSTUNNEL_TEST_DB || 'smstunnel_app_dev').collection('messages'));
  } finally {
    await client.close();
  }
}

/** Texts the server accepted for a number since a date, oldest first. */
export async function textsTo(number, since) {
  return withMessages((messages) =>
    messages
      .find({ phoneNumber: number, createdAt: { $gte: since } })
      .project({ phoneNumber: 1, message: 1, status: 1, createdAt: 1 })
      .sort({ createdAt: 1 })
      .toArray(),
  );
}

/** Waits until at least `count` texts matching `filter` reached the number. */
export async function waitForTexts(number, since, count, filter = () => true, timeout = 20000) {
  const end = Date.now() + timeout;
  let found = [];
  while (Date.now() < end) {
    found = (await textsTo(number, since)).filter(filter);
    if (found.length >= count) return found;
    await sleep(700);
  }
  return found;
}

export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

export function docker(args, input) {
  return execFileSync('docker', args, { encoding: 'utf8', input, stdio: ['pipe', 'pipe', 'pipe'], maxBuffer: 32 * 1024 * 1024 });
}

/** One SQL statement on the test shop's database; rows as arrays of strings. */
export function sql(project, query) {
  const out = docker(['exec', `${project}-db-1`, 'mariadb', '-uroot', '-pprestashop', 'prestashop', '-N', '-B', '-e', query]);
  return out
    .split('\n')
    .filter((line) => line !== '')
    .map((line) => line.split('\t'));
}

/** Runs tests/e2e/php/ps-helper.php in the shop container and returns its JSON. */
export function psHelper(project, ...args) {
  const container = `${project}-prestashop-1`;
  docker(['cp', path.join(HERE, 'php', 'ps-helper.php'), `${container}:/tmp/ps-helper.php`]);
  const out = docker(['exec', '-u', 'www-data', container, 'php', '/tmp/ps-helper.php', ...args.map(String)]);
  const line = out.trim().split('\n').pop();
  return JSON.parse(line);
}

/** Logs into the back office; returns { adminModulesToken, symfonyToken }. */
export async function loginBackOffice(page, base) {
  await page.goto(`${base}/admin-dev/`);
  await page.fill('#email', ADMIN_EMAIL);
  await page.fill('#passwd', ADMIN_PASSWORD);
  await Promise.all([page.waitForURL(/AdminDashboard|dashboard/, { timeout: 90000 }), page.click('#submit_login')]);
  await page.waitForLoadState('networkidle').catch(() => {});
  return backOfficeTokens(page);
}

export async function backOfficeTokens(page) {
  const hrefs = await page.$$eval('a[href]', (links) => links.map((a) => a.href));
  const legacy = hrefs.find((h) => /controller=AdminModules&token=/.test(h));
  const symfony = hrefs.find((h) => /[?&]_token=/.test(h));
  return {
    adminModulesToken: legacy ? new URL(legacy).searchParams.get('token') : '',
    symfonyToken: symfony ? new URL(symfony).searchParams.get('_token') : '',
  };
}

/** A small report of checks. */
export class Report {
  constructor(label) {
    this.label = label;
    this.checks = [];
  }

  ok(name, condition, detail = '') {
    this.checks.push({ name, pass: Boolean(condition), detail });
    console.log(`${condition ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`);
    return Boolean(condition);
  }

  get failed() {
    return this.checks.filter((c) => !c.pass).length;
  }

  summary() {
    const passed = this.checks.length - this.failed;
    return `${this.label}: ${passed}/${this.checks.length} checks passed`;
  }
}
