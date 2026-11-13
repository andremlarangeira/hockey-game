import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const cwd = new URL('../..', import.meta.url).pathname;
const temp = mkdtempSync(join(tmpdir(), 'inertia-page-'));
writeFileSync(
    `${temp}/inertiaPage.ts`,
    readFileSync(`${cwd}/resources/js/lib/inertiaPage.ts`, 'utf8'),
);
execFileSync(`${cwd}/node_modules/typescript/bin/tsc`, [
    `${temp}/inertiaPage.ts`,
    '--target',
    'ES2022',
    '--module',
    'commonjs',
    '--lib',
    'ES2022,DOM',
    '--outDir',
    temp,
]);
writeFileSync(`${temp}/package.json`, '{"type":"commonjs"}');
const { initialInertiaPage } = await import(`${temp}/inertiaPage.js`);

const page = { props: { team: { id: 7 } }, version: 'abc' };
const documentWith = (script, legacy) => ({
    querySelector(selector) {
        if (selector.startsWith('script')) return script;
        return legacy;
    },
});

assert.deepEqual(
    initialInertiaPage(
        documentWith(
            { dataset: { page: 'app' }, textContent: JSON.stringify(page) },
            null,
        ),
    ),
    page,
    'lê o JSON no script do Inertia v3',
);
assert.deepEqual(
    initialInertiaPage(
        documentWith(null, { dataset: { page: JSON.stringify(page) } }),
    ),
    page,
    'mantém suporte ao atributo legado',
);
assert.equal(
    initialInertiaPage(documentWith({ textContent: '{inválido' }, null)),
    null,
    'JSON inválido não interrompe o bootstrap',
);
