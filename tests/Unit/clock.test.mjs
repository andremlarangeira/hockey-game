import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const cwd = new URL('../..', import.meta.url).pathname;
const temp = mkdtempSync(join(tmpdir(), 'clock-'));
const source = readFileSync(
    `${cwd}/resources/js/lib/matchClockCore.ts`,
    'utf8',
).replace(
    "import type { MatchSettings } from '@/types/hockey';",
    'type MatchSettings = any;',
);
writeFileSync(`${temp}/matchClockCore.ts`, source);
execFileSync(`${cwd}/node_modules/typescript/bin/tsc`, [
    `${temp}/matchClockCore.ts`,
    '--target',
    'ES2022',
    '--module',
    'commonjs',
    '--outDir',
    temp,
]);
writeFileSync(`${temp}/package.json`, '{"type":"commonjs"}');
const { MatchClockCore } = await import(`${temp}/matchClockCore.js`);
let now = 0;
const clock = new MatchClockCore({
    minutes: 2,
    periods: 2,
    rest_seconds: 10,
    countdown_seconds: 5,
});
clock.start(now);
now += 3000;
clock.tick(now);
assert.equal(clock.phase, 'countdown');
assert.equal(clock.remaining, 2);
clock.togglePause(now);
now += 30000;
clock.tick(now);
assert.equal(clock.remaining, 2);
clock.togglePause(now);
now += 2000;
clock.tick(now);
assert.equal(clock.phase, 'period');
now += 111000;
clock.tick(now);
assert.equal(clock.elapsed, 111);
clock.togglePause(now);
now += 10000;
clock.tick(now);
assert.equal(clock.elapsed, 111);
clock.togglePause(now);
now += 9000;
clock.tick(now);
assert.equal(clock.elapsed, 120);
assert.equal(clock.phase, 'rest');
assert.equal(clock.period, 1);
clock.tick(now);
assert.equal(clock.remaining, 10);
assert.equal(clock.transitions[0].ended, 'countdown');
assert.equal(clock.transitions[1].ended, 'period');
assert.equal(clock.transitions[1].elapsed, 120);
const delayed = new MatchClockCore({
    minutes: 1,
    periods: 2,
    rest_seconds: 5,
    countdown_seconds: 0,
});
delayed.start(0);
delayed.tick(70_000);
assert.equal(delayed.phase, 'period');
assert.equal(delayed.period, 2);
assert.equal(delayed.remaining, 55);
assert.equal(delayed.elapsed, 65);
assert.equal(delayed.transitions.length, 2);
delayed.tick(125_000);
assert.equal(delayed.phase, 'finished');
assert.equal(delayed.transitions.length, 3);
assert.equal(delayed.elapsed, 120);
const unspecifiedCountdown = new MatchClockCore({
    minutes: 1,
    periods: 1,
    rest_seconds: 5,
    countdown_seconds: 0,
});
unspecifiedCountdown.start();
unspecifiedCountdown.reset();
assert.equal(unspecifiedCountdown.remaining, 60);
const serialized = new MatchClockCore({
    minutes: 1,
    periods: 2,
    rest_seconds: 5,
    countdown_seconds: 0,
});
serialized.start(0);
serialized.tick(10_000);
const snapshot = serialized.export();
const active = new MatchClockCore({
    minutes: 1,
    periods: 2,
    rest_seconds: 5,
    countdown_seconds: 0,
});
active.restore(snapshot);
active.tick(20_000);
assert.equal(active.elapsed, 20);
assert.equal(active.remaining, 40);
serialized.togglePause(20_000);
serialized.tick(50_000);
assert.equal(serialized.elapsed, 20);
const restoredPaused = new MatchClockCore({
    minutes: 1,
    periods: 2,
    rest_seconds: 5,
    countdown_seconds: 0,
});
restoredPaused.restore(serialized.export());
restoredPaused.tick(100_000);
assert.equal(restoredPaused.elapsed, 20);
restoredPaused.togglePause(100_000);
restoredPaused.tick(110_000);
assert.equal(restoredPaused.elapsed, 30);
assert.equal(restoredPaused.remaining, 30);
console.log(
    'Relógio: pré-contagem, pausa, descanso, tempos, atraso e recuperação passaram.',
);
