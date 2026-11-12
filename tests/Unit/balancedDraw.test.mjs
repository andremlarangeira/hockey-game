import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const cwd = new URL('../..', import.meta.url).pathname;
const temp = mkdtempSync(join(tmpdir(), 'balanced-draw-'));
const source = readFileSync(
    `${cwd}/resources/js/lib/balancedDraw.ts`,
    'utf8',
).replace(
    "import type { DrawResult, Player } from '@/types/hockey';",
    `
interface Player { id: number; name: string; jersey_number: number; photo_url: string | null; level: number | string; present: boolean; }
interface DrawTeam { id: string; name: string; color: string; players: Player[]; total: number; average: number; }
interface DrawResult { teams: DrawTeam[]; reserves: Player[]; }`,
);
writeFileSync(`${temp}/balancedDraw.ts`, source);
execFileSync(`${cwd}/node_modules/typescript/bin/tsc`, [
    `${temp}/balancedDraw.ts`,
    '--target',
    'ES2022',
    '--module',
    'commonjs',
    '--outDir',
    temp,
]);
writeFileSync(`${temp}/package.json`, '{"type":"commonjs"}');
const { balancedDraw } = await import(`${temp}/balancedDraw.js`);

const players = (count = 18) =>
    Array.from({ length: count }, (_, index) => ({
        id: index + 1,
        name: `Jogador ${index + 1}`,
        jersey_number: index + 1,
        level: ((index + 1) % 5) + 1,
        photo_url: null,
        present: true,
    }));
const seeded = () => {
    let state = 123456;
    return (min, max) => {
        state = (state * 73 + 19) % 1000003;
        return min + (state % (max - min + 1));
    };
};

const result = balancedDraw(players(), 'players', 4, seeded());
assert.equal(result.teams.length, 4);
assert.equal(result.reserves.length, 2);
assert.ok(result.teams.every((team) => team.players.length === 4));
assert.equal(
    new Set(
        [
            ...result.teams.flatMap((team) => team.players),
            ...result.reserves,
        ].map((player) => player.id),
    ).size,
    18,
);
assert.ok(
    Math.max(...result.teams.map((team) => team.total)) -
        Math.min(...result.teams.map((team) => team.total)) <=
        3,
);

const teams = balancedDraw(players(11), 'teams', 3, seeded());
const sizes = teams.teams.map((team) => team.players.length);
assert.equal(teams.reserves.length, 0);
assert.ok(Math.max(...sizes) - Math.min(...sizes) <= 1);
assert.throws(() => balancedDraw(players(5), 'teams', 4, seeded()));

const first = balancedDraw(players(14), 'players', 4, seeded());
const second = balancedDraw(players(14), 'players', 4, seeded());
assert.deepEqual(second, first, 'um RNG injetado torna o sorteio reproduzível');

const phpScenarios = JSON.parse(
    execFileSync('php', [`${cwd}/tests/Unit/balancedDrawFixture.php`, cwd], {
        encoding: 'utf8',
    }),
);
for (const scenario of phpScenarios) {
    let state = scenario.seed;
    const random = (min, max) => {
        state = (state * 73 + 19) % 1000003;
        return min + (state % (max - min + 1));
    };
    assert.deepEqual(
        balancedDraw(scenario.players, scenario.mode, scenario.size, random),
        scenario.result,
        `PHP e TypeScript concordam: ${scenario.mode}/${scenario.size}/${scenario.seed}`,
    );
}

assert.throws(() => balancedDraw([], 'players', 4, seeded()));
assert.throws(() => balancedDraw(players(8), 'invalid', 4, seeded()));
assert.throws(() => balancedDraw(players(8), 'players', 0, seeded()));
