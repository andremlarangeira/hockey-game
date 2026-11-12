import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const cwd = new URL('../..', import.meta.url).pathname;
const temp = mkdtempSync(join(tmpdir(), 'match-queue-'));
const source = readFileSync(
    `${cwd}/resources/js/lib/matchQueue.ts`,
    'utf8',
).replace(
    "import type { StorageLike } from '@/lib/offlineStorage';",
    'interface StorageLike { getItem(key: string): string | null; setItem(key: string, value: string): void; removeItem(key: string): void; }',
);
writeFileSync(`${temp}/matchQueue.ts`, source);
execFileSync(`${cwd}/node_modules/typescript/bin/tsc`, [
    `${temp}/matchQueue.ts`,
    '--target',
    'ES2022',
    '--module',
    'commonjs',
    '--outDir',
    temp,
]);
writeFileSync(`${temp}/package.json`, '{"type":"commonjs"}');
const { enqueueMatch, flushQueue, loadQueue } = await import(
    `${temp}/matchQueue.js`
);

class MemoryStorage {
    data = new Map();
    getItem(key) {
        return this.data.get(key) ?? null;
    }
    setItem(key, value) {
        this.data.set(key, value);
    }
    removeItem(key) {
        this.data.delete(key);
    }
}
const storage = new MemoryStorage();
enqueueMatch(storage, 9, { home_score: 2 });
assert.equal(loadQueue(storage, 9).length, 1);
await flushQueue(storage, 9, async () => ({
    status: 0,
    ok: false,
    error: 'Sem conexão',
}));
assert.equal(
    loadQueue(storage, 9).length,
    1,
    'uma falha de rede nunca apaga a partida',
);
let result = await flushQueue(storage, 9, async () => ({
    status: 419,
    ok: false,
    error: 'Sessão expirada',
}));
assert.equal(result.relogin, true);
assert.equal(loadQueue(storage, 9).length, 1);
result = await flushQueue(storage, 9, async () => ({
    status: 422,
    ok: false,
    error: 'Dados inválidos',
}));
assert.equal(result.remaining, 1);
result = await flushQueue(storage, 9, async () => ({ status: 201, ok: true }));
assert.equal(result.remaining, 0, 'a confirmação remove a fila');

const first = enqueueMatch(storage, 9, { home_score: 1 });
let release;
const sending = flushQueue(storage, 9, async () => {
    await new Promise((resolve) => {
        release = resolve;
    });
    return { status: 201, ok: true };
});
await new Promise((resolve) => setImmediate(resolve));
const second = enqueueMatch(storage, 9, { home_score: 2 });
release();
await sending;
const queuedAfterRace = loadQueue(storage, 9);
assert.equal(queuedAfterRace.length, 1, 'um envio não sobrescreve novos itens');
assert.equal(queuedAfterRace[0].client_uuid, second.client_uuid);
assert.notEqual(first.client_uuid, second.client_uuid);
