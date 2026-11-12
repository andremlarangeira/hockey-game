import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const cwd = new URL('../..', import.meta.url).pathname;
const temp = mkdtempSync(join(tmpdir(), 'offline-storage-'));
const source = readFileSync(
    `${cwd}/resources/js/lib/offlineStorage.ts`,
    'utf8',
).replace(
    "import type { Player } from '@/types/hockey';",
    `interface Player { id: number; name: string; jersey_number: number; photo_url: string | null; level: number; present: boolean; presence_changed_at?: string | null; }`,
);
writeFileSync(`${temp}/offlineStorage.ts`, source);
execFileSync(`${cwd}/node_modules/typescript/bin/tsc`, [
    `${temp}/offlineStorage.ts`,
    '--target',
    'ES2022',
    '--module',
    'commonjs',
    '--outDir',
    temp,
]);
writeFileSync(`${temp}/package.json`, '{"type":"commonjs"}');
const { acknowledgePresence, changePresence, loadPlayers, pendingPresence } =
    await import(`${temp}/offlineStorage.js`);

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
const player = (id, present = true) => ({
    id,
    name: `P ${id}`,
    jersey_number: id,
    photo_url: null,
    level: 3,
    present,
});
const storage = new MemoryStorage();
let players = loadPlayers(storage, 3, [player(1), player(2)]);
players = changePresence(
    storage,
    3,
    players,
    1,
    false,
    '2026-01-01T00:00:00.000Z',
);
players = changePresence(
    storage,
    3,
    players,
    1,
    true,
    '2026-01-01T00:01:00.000Z',
);
assert.equal(
    pendingPresence(storage, 3).length,
    1,
    'a última alteração vence localmente',
);
assert.equal(
    loadPlayers(storage, 3, [player(1, false), player(2)]).find(
        (item) => item.id === 1,
    ).present,
    true,
);
acknowledgePresence(storage, 3, [1]);
assert.equal(pendingPresence(storage, 3).length, 0);

players = changePresence(
    storage,
    3,
    players,
    1,
    false,
    '2026-01-01T00:02:00.000Z',
);
const inFlight = pendingPresence(storage, 3);
players = changePresence(
    storage,
    3,
    players,
    1,
    true,
    '2026-01-01T00:03:00.000Z',
);
acknowledgePresence(storage, 3, inFlight);
assert.equal(
    pendingPresence(storage, 3)[0].changed_at,
    '2026-01-01T00:03:00.000Z',
    'uma alteração durante o envio permanece pendente',
);
