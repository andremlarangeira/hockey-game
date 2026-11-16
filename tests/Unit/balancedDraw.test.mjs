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
const {
    applyTeamColorsToRoster,
    applyTeamColorsToTeams,
    balancedDraw,
    drawPalette,
    readableText,
} = await import(`${temp}/balancedDraw.js`);

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

const withTeamDefaults = balancedDraw(
    players(16),
    'teams',
    4,
    seeded(),
    '#166534',
    '#f59e0b',
);
assert.deepEqual(
    withTeamDefaults.teams.map((team) => team.color),
    ['#166534', '#f59e0b', '#eab308', '#16a34a'],
    'usa as cores de Meu time nas duas primeiras equipes e mantém o restante da paleta',
);

const deduplicated = balancedDraw(
    players(16),
    'teams',
    4,
    seeded(),
    '#eab308',
    '#16a34a',
);
assert.deepEqual(
    deduplicated.teams.map((team) => team.color),
    ['#eab308', '#16a34a', '#9333ea', '#ea580c'],
    'evita duplicar as duas primeiras cores nas equipes 3+',
);

const withFallbacks = balancedDraw(
    players(12),
    'teams',
    3,
    seeded(),
    '',
    'invalida',
);
assert.deepEqual(
    withFallbacks.teams.map((team) => team.color),
    ['#2563eb', '#dc2626', '#eab308'],
    'usa azul e vermelho como fallback quando as cores são vazias ou inválidas',
);
assert.deepEqual(drawPalette(null, '#abc'), [
    '#2563eb',
    '#dc2626',
    '#eab308',
    '#16a34a',
    '#9333ea',
    '#ea580c',
    '#0891b2',
    '#db2777',
]);

assert.equal(readableText('#166534'), '#ffffff');
assert.equal(readableText('#2563eb'), '#ffffff');
assert.equal(readableText('#dc2626'), '#ffffff');
assert.equal(readableText('#0f172a'), '#ffffff');
assert.equal(readableText('#f59e0b'), '#0f172a');
assert.equal(readableText('#eab308'), '#0f172a');
assert.equal(readableText('#84cc16'), '#0f172a');
assert.equal(readableText('#ffffff'), '#0f172a');
assert.equal(readableText('invalida'), '#ffffff');

const recoloredTeams = applyTeamColorsToTeams(
    [
        {
            id: 'equipe-2',
            name: 'Lobos Renomeados',
            color: '#dc2626',
            players: players(2),
        },
        {
            id: 'equipe-1',
            name: 'Tigres Renomeados',
            color: '#2563eb',
            players: players(2),
        },
        {
            id: 'equipe-3',
            name: 'Ursos',
            color: '#eab308',
            players: players(2),
        },
    ],
    '#0f766e',
    '#f59e0b',
);
assert.equal(recoloredTeams[0].color, '#f59e0b');
assert.equal(recoloredTeams[1].color, '#0f766e');
assert.equal(recoloredTeams[2].color, '#eab308');

const swappedRoster = applyTeamColorsToRoster(
    {
        home: {
            id: 'equipe-2',
            name: 'Time Visitante no Topo',
            color: '#dc2626',
            players: players(2),
        },
        away: {
            id: 'equipe-1',
            name: 'Time Casa em Baixo',
            color: '#2563eb',
            players: players(2),
        },
    },
    '#166534',
    '#f59e0b',
);
assert.equal(
    swappedRoster.home.color,
    '#f59e0b',
    'identifica equipe-2 pelo id estável mesmo quando está em home',
);
assert.equal(
    swappedRoster.away.color,
    '#166534',
    'identifica equipe-1 pelo id estável mesmo quando está em away',
);

const allPlayers = players(4);
const legacyDrawList = [
    {
        name: 'Primeiro Sorteado',
        color: '#2563eb',
        players: allPlayers.slice(0, 2),
    },
    {
        name: 'Segundo Sorteado',
        color: '#dc2626',
        players: allPlayers.slice(2, 4),
    },
];
const legacyRoster = applyTeamColorsToRoster(
    {
        home: {
            name: 'Segundo Sorteado',
            color: '#dc2626',
            players: allPlayers.slice(2, 4),
        },
        away: {
            name: 'Primeiro Sorteado',
            color: '#2563eb',
            players: allPlayers.slice(0, 2),
        },
    },
    '#166534',
    '#f59e0b',
    legacyDrawList,
);
assert.equal(
    legacyRoster.home.color,
    '#f59e0b',
    'sem id estável, usa a ordem da lista do sorteio e não a ordem home/away do roster',
);
assert.equal(legacyRoster.away.color, '#166534');
