import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const cwd = new URL('../..', import.meta.url).pathname;
const temp = mkdtempSync(join(tmpdir(), 'draw-storage-'));
const source = readFileSync(
    `${cwd}/resources/js/lib/drawStorage.ts`,
    'utf8',
).replace(
    /import type \{ DrawResult, DrawTeam, Player \} from ['"]@\/types\/hockey['"];/,
    `interface Player {
        id: number;
        name: string;
        jersey_number: number;
        photo_url: string | null;
        level: number | string;
        present: boolean;
    }
    interface DrawTeam {
        id: string;
        name: string;
        color: string;
        players: Player[];
        total: number;
        average: number;
    }
    interface DrawResult { teams: DrawTeam[]; reserves: Player[]; }`,
);
writeFileSync(`${temp}/drawStorage.ts`, source);
execFileSync(`${cwd}/node_modules/typescript/bin/tsc`, [
    `${temp}/drawStorage.ts`,
    '--target',
    'ES2022',
    '--module',
    'commonjs',
    '--outDir',
    temp,
]);
writeFileSync(`${temp}/package.json`, '{"type":"commonjs"}');
const { keyFor, load, reconcile, save } = await import(
    `${temp}/drawStorage.js`
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

const player = (id, name, level, extra = {}) => ({
    id,
    name,
    jersey_number: id + 10,
    level,
    photo_url: null,
    present: true,
    ...extra,
});
const draw = {
    teams: [
        {
            id: 'equipe-1',
            name: 'Fúria',
            color: '#123456',
            players: [player(1, 'Antes', 2), player(2, 'Ausente', 4)],
            total: 6,
            average: 3,
        },
    ],
    reserves: [],
    mode: 'teams',
    size: 2,
};
const storage = new MemoryStorage();

save(storage, 42, draw);
const saved = load(storage, 42);
assert.equal(saved?.teams[0].name, 'Fúria');
assert.equal(saved?.mode, 'teams');
assert.equal(saved?.size, 2);
assert.ok(saved?.saved_at);

storage.setItem(keyFor(99), '{inválido');
assert.equal(load(storage, 99), null);
assert.equal(storage.getItem(keyFor(99)), null);

const reconciled = reconcile(saved, [
    player(1, 'Atualizado', 3.5, { jersey_number: 77, photo_url: '/um.jpg' }),
    player(3, 'Novo', 1.5),
]);
assert.equal(reconciled.removedCount, 1);
assert.equal(reconciled.addedCount, 1);
assert.equal(reconciled.draw.teams[0].id, 'equipe-1');
assert.equal(reconciled.draw.teams[0].name, 'Fúria');
assert.equal(reconciled.draw.teams[0].color, '#123456');
assert.deepEqual(reconciled.draw.teams[0].players, [
    player(1, 'Atualizado', 3.5, { jersey_number: 77, photo_url: '/um.jpg' }),
]);
assert.equal(reconciled.draw.teams[0].total, 3.5);
assert.equal(reconciled.draw.teams[0].average, 3.5);
assert.deepEqual(reconciled.draw.reserves, [player(3, 'Novo', 1.5)]);

save(storage, 42, {
    teams: [
        {
            id: 'equipe-2',
            name: 'Segundos',
            color: '#dc2626',
            players: [player(1, 'Um', 3)],
            total: 3,
            average: 3,
        },
        {
            id: 'equipe-1',
            name: 'Primeiros',
            color: '#2563eb',
            players: [player(2, 'Dois', 3)],
            total: 3,
            average: 3,
        },
    ],
    reserves: [],
    mode: 'teams',
    size: 2,
});
const loadedWithTeamColors = load(storage, 42, '#166534', '#f59e0b');
assert.equal(loadedWithTeamColors?.teams[0].color, '#f59e0b');
assert.equal(loadedWithTeamColors?.teams[1].color, '#166534');

const reconciledWithColors = reconcile(
    load(storage, 42),
    [player(1, 'Um', 3), player(2, 'Dois', 3)],
    '#0f766e',
    'invalida',
);
assert.equal(reconciledWithColors.draw.teams[0].color, '#dc2626');
assert.equal(reconciledWithColors.draw.teams[1].color, '#0f766e');
