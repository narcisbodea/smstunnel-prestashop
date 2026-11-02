// Lists the module's wordings (domain Modules.Smstunnel.Admin) found in PHP trans() calls and Smarty {l}
// tags, as a JSON array on stdout. tools/translations.mjs builds the XLIFF catalogues from the same list.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'smstunnel');

export function extractStrings(dir = root) {
  const strings = new Set();
  const unescape = (s) => s.replace(/\\'/g, "'").replace(/\\\\/g, '\\');
  const walk = (current) => {
    for (const entry of fs.readdirSync(current, { withFileTypes: true })) {
      const full = path.join(current, entry.name);
      if (entry.isDirectory()) {
        if (entry.name !== 'vendor') walk(full);
      } else if (/\.(php|tpl)$/.test(entry.name)) {
        const text = fs.readFileSync(full, 'utf8');
        for (const m of text.matchAll(/->trans\(\s*'((?:[^'\\]|\\.)*)'\s*,[^;]*?'Modules\.Smstunnel\.Admin'/gs)) {
          strings.add(unescape(m[1]));
        }
        for (const m of text.matchAll(/\{l s='((?:[^'\\]|\\.)*)'[^}]*d='Modules\.Smstunnel\.Admin'[^}]*\}/g)) {
          strings.add(unescape(m[1]));
        }
      }
    }
  };
  walk(dir);
  return [...strings].sort();
}

if (process.argv[1] && fileURLToPath(import.meta.url) === path.resolve(process.argv[1])) {
  console.log(JSON.stringify(extractStrings(), null, 1));
}
