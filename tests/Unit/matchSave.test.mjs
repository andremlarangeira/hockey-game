import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const cwd = new URL('../..', import.meta.url).pathname;
const temp = mkdtempSync(join(tmpdir(), 'match-save-'));
const queue = readFileSync(
    `${cwd}/resources/js/lib/matchQueue.ts`,
    'utf8',
).replace(
    "import type { StorageLike } from '@/lib/offlineStorage';",
    'export interface StorageLike { getItem(key: string): string | null; setItem(key: string, value: string): void; removeItem(key: string): void; }',
);
const save = readFileSync(`${cwd}/resources/js/lib/matchSave.ts`, 'utf8')
    .replace(
        "import { enqueueMatch, type QueuedMatch } from '@/lib/matchQueue';",
        "import { enqueueMatch, type QueuedMatch } from './matchQueue';",
    )
    .replace(
        "import type { StorageLike } from '@/lib/offlineStorage';",
        "import type { StorageLike } from './matchQueue';",
    );
writeFileSync(`${temp}/matchQueue.ts`, queue);
writeFileSync(`${temp}/matchSave.ts`, save);
execFileSync(
    `${cwd}/node_modules/typescript/bin/tsc`,
    [
        'matchQueue.ts',
        'matchSave.ts',
        '--target',
        'ES2022',
        '--module',
        'commonjs',
        '--outDir',
        temp,
    ],
    { cwd: temp },
);
writeFileSync(`${temp}/package.json`, '{"type":"commonjs"}');
const { enqueueMatchBeforeClearingDraft } = await import(
    `${temp}/matchSave.js`
);

class MemoryStorage {
    data = new Map([['draft', 'preserve me']]);
    fail = false;
    getItem(key) {
        return this.data.get(key) ?? null;
    }
    setItem(key, value) {
        if (this.fail) throw new Error('quota');
        this.data.set(key, value);
    }
    removeItem(key) {
        this.data.delete(key);
    }
}

const storage = new MemoryStorage();
storage.fail = true;
assert.throws(() =>
    enqueueMatchBeforeClearingDraft(storage, 4, { score: 1 }, 'draft'),
);
assert.equal(
    storage.getItem('draft'),
    'preserve me',
    'o rascunho fica quando a fila falha',
);
storage.fail = false;
enqueueMatchBeforeClearingDraft(storage, 4, { score: 1 }, 'draft');
assert.equal(
    storage.getItem('draft'),
    null,
    'o rascunho só sai após entrar na fila',
);
