// Bundle budget (TRD §7): the front desk entry — the app entry, the room board page and every
// chunk they import statically, plus their CSS — must be at most 350 kB gzip, Nuxt UI included.
// Runs after `vite build` (see package.json "build") and fails the build when over.
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { gzipSync } from 'node:zlib';

const BUDGET = 350 * 1024;
const ENTRIES = ['resources/js/app.ts', 'resources/js/Pages/Board/Index.vue'];
const dir = 'public/build';
const manifest = JSON.parse(readFileSync(join(dir, 'manifest.json'), 'utf8'));

const files = new Set();
const visit = (key) => {
    const chunk = manifest[key];
    if (!chunk || files.has(chunk.file)) {
        return;
    }
    files.add(chunk.file);
    (chunk.css ?? []).forEach((css) => files.add(css));
    (chunk.imports ?? []).forEach(visit);
};
ENTRIES.forEach(visit);

let total = 0;
for (const file of files) {
    total += gzipSync(readFileSync(join(dir, file)), { level: 9 }).length;
}

const kb = (n) => `${(n / 1024).toFixed(1)} kB`;
const line = `Front desk entry: ${kb(total)} gzip in ${files.size} files (budget ${kb(BUDGET)})`;

if (total > BUDGET) {
    console.error(`✗ ${line}`);
    process.exit(1);
}

console.log(`✓ ${line}`);
