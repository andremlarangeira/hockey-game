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
    /import type \{ MatchSettings \} from ['"]@\/types\/hockey['"];/,
    `type MatchSettings = {
        minutes: number;
        periods: number;
        rest_seconds: number;
        countdown_seconds: number;
    };`,
);
writeFileSync(`${temp}/matchClockCore.ts`, source);
writeFileSync(
    `${temp}/matchClockTransitions.ts`,
    readFileSync(
        `${cwd}/resources/js/lib/matchClockTransitions.ts`,
        'utf8',
    ).replace('@/lib/matchClockCore', './matchClockCore'),
);
execFileSync(`${cwd}/node_modules/typescript/bin/tsc`, [
    `${temp}/matchClockCore.ts`,
    `${temp}/matchClockTransitions.ts`,
    '--target',
    'ES2022',
    '--module',
    'commonjs',
    '--outDir',
    temp,
]);
writeFileSync(`${temp}/package.json`, '{"type":"commonjs"}');
const { MatchClockCore, transitionSounds } = await import(
    `${temp}/matchClockCore.js`
);
const { createMatchClockTransitions } = await import(
    `${temp}/matchClockTransitions.js`
);
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

// O consumidor precisa enxergar o novo array depois de reiniciar uma partida.
const secondMatch = new MatchClockCore({
    minutes: 1,
    periods: 1,
    rest_seconds: 5,
    countdown_seconds: 1,
});
const transitionView = createMatchClockTransitions(secondMatch);
const visibleTransitions = () => transitionView.transitions;
secondMatch.start(0);
secondMatch.tick(1_000);
assert.equal(visibleTransitions().length, 1);
const firstMatchTransitions = visibleTransitions();
secondMatch.reset();
secondMatch.start(10_000);
secondMatch.tick(11_000);
assert.equal(visibleTransitions().length, 1);
assert.notEqual(visibleTransitions(), firstMatchTransitions);
assert.equal(visibleTransitions()[0].at, new Date(11_000).toISOString());
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
const afterGoal = new MatchClockCore({
    minutes: 1,
    periods: 1,
    rest_seconds: 5,
    countdown_seconds: 5,
});
afterGoal.start(0);
assert.equal(afterGoal.restartCountdown(2_000), false);
afterGoal.tick(6_000);
assert.equal(afterGoal.restartCountdown(10_000), true);
afterGoal.tick(12_000);
assert.equal(afterGoal.phase, 'countdown');
assert.equal(afterGoal.remaining, 3);
assert.equal(afterGoal.elapsed, 5);
const goalDraft = afterGoal.export();
const restoredGoalDraft = new MatchClockCore({
    minutes: 1,
    periods: 1,
    rest_seconds: 5,
    countdown_seconds: 5,
});
restoredGoalDraft.restore(goalDraft);
restoredGoalDraft.tick(15_000);
assert.equal(restoredGoalDraft.phase, 'period');
assert.equal(restoredGoalDraft.remaining, 55);
assert.equal(restoredGoalDraft.elapsed, 5);
restoredGoalDraft.tick(20_000);
assert.equal(restoredGoalDraft.remaining, 50);
assert.equal(restoredGoalDraft.elapsed, 10);
restoredGoalDraft.togglePause(20_000);
restoredGoalDraft.tick(40_000);
assert.equal(restoredGoalDraft.elapsed, 10);
restoredGoalDraft.togglePause(40_000);
restoredGoalDraft.tick(90_000);
assert.equal(restoredGoalDraft.phase, 'finished');
assert.equal(restoredGoalDraft.elapsed, 60);
afterGoal.togglePause(12_000);
afterGoal.tick(30_000);
assert.equal(afterGoal.phase, 'countdown');
assert.equal(afterGoal.remaining, 3);
afterGoal.togglePause(30_000);
afterGoal.tick(33_000);
assert.equal(afterGoal.phase, 'period');
assert.equal(afterGoal.remaining, 55);
assert.equal(afterGoal.restartCountdown(33_000), true);
afterGoal.tick(39_000);
assert.equal(afterGoal.elapsed, 6);
assert.equal(afterGoal.remaining, 54);
afterGoal.finish(39_000);
assert.equal(afterGoal.restartCountdown(39_000), false);
assert.equal(afterGoal.elapsed, 6);

const noCountdown = new MatchClockCore({
    minutes: 1,
    periods: 1,
    rest_seconds: 5,
    countdown_seconds: 0,
});
noCountdown.start(0);
noCountdown.tick(10_000);
assert.equal(noCountdown.restartCountdown(10_000), false);
assert.equal(noCountdown.remaining, 50);
assert.equal(noCountdown.elapsed, 10);

// Atualizar configuração (ex.: de 10 min para 2 min) antes de iniciar, em rascunho idle e durante a pré-contagem.
const configuredClock = new MatchClockCore({
    minutes: 10,
    periods: 2,
    rest_seconds: 120,
    countdown_seconds: 5,
});
assert.equal(configuredClock.remaining, 600);
configuredClock.configure({
    minutes: 2,
    periods: 2,
    rest_seconds: 120,
    countdown_seconds: 5,
});
assert.equal(configuredClock.remaining, 120);
const idleSnapshot = configuredClock.export();
const restoredIdle = new MatchClockCore({
    minutes: 10,
    periods: 2,
    rest_seconds: 120,
    countdown_seconds: 5,
});
restoredIdle.configure({
    minutes: 2,
    periods: 2,
    rest_seconds: 120,
    countdown_seconds: 5,
});
restoredIdle.restore(idleSnapshot);
assert.equal(restoredIdle.phase, 'idle');
assert.equal(restoredIdle.remaining, 120);
restoredIdle.start(0);
assert.equal(restoredIdle.phase, 'countdown');
assert.equal(restoredIdle.remaining, 5);
restoredIdle.tick(5_000);
assert.equal(restoredIdle.phase, 'period');
assert.equal(restoredIdle.remaining, 120);

const countdownReconfigured = new MatchClockCore({
    minutes: 10,
    periods: 2,
    rest_seconds: 60,
    countdown_seconds: 5,
});
countdownReconfigured.start(0);
countdownReconfigured.tick(2_000);
assert.equal(countdownReconfigured.phase, 'countdown');
countdownReconfigured.configure(
    {
        minutes: 2,
        periods: 2,
        rest_seconds: 30,
        countdown_seconds: 5,
    },
    2_000,
);
countdownReconfigured.tick(5_000);
assert.equal(countdownReconfigured.phase, 'period');
assert.equal(countdownReconfigured.remaining, 120);
countdownReconfigured.tick(125_000);
assert.equal(countdownReconfigured.phase, 'rest');
assert.equal(countdownReconfigured.remaining, 30);

const interPeriodCountdown = new MatchClockCore({
    minutes: 1,
    periods: 2,
    rest_seconds: 10,
    countdown_seconds: 5,
});
interPeriodCountdown.start(0);
interPeriodCountdown.tick(5_000);
assert.equal(interPeriodCountdown.phase, 'period');
assert.equal(interPeriodCountdown.period, 1);
assert.equal(interPeriodCountdown.remaining, 60);
interPeriodCountdown.tick(65_000);
assert.equal(interPeriodCountdown.phase, 'rest');
assert.equal(interPeriodCountdown.period, 1);
assert.equal(interPeriodCountdown.remaining, 10);
assert.equal(interPeriodCountdown.elapsed, 60);
interPeriodCountdown.tick(70_000);
assert.equal(interPeriodCountdown.phase, 'rest');
assert.equal(interPeriodCountdown.period, 1);
assert.equal(interPeriodCountdown.remaining, 5);
assert.equal(interPeriodCountdown.elapsed, 60);
interPeriodCountdown.tick(75_000);
assert.equal(interPeriodCountdown.phase, 'countdown');
assert.equal(interPeriodCountdown.period, 2);
assert.equal(interPeriodCountdown.remaining, 5);
assert.equal(interPeriodCountdown.elapsed, 60);
interPeriodCountdown.tick(80_000);
assert.equal(interPeriodCountdown.phase, 'period');
assert.equal(interPeriodCountdown.period, 2);
assert.equal(interPeriodCountdown.remaining, 60);
interPeriodCountdown.tick(140_000);
assert.equal(interPeriodCountdown.phase, 'finished');
assert.equal(interPeriodCountdown.transitions.length, 5);

const soundsFor = (clock) =>
    clock.transitions.flatMap((transition) => transitionSounds(transition));
assert.deepEqual(soundsFor(interPeriodCountdown), [
    'start',
    'end',
    'rest_end',
    'start',
    'end',
]);
const interPeriodTransitionCount = interPeriodCountdown.transitions.length;
interPeriodCountdown.tick(140_000);
interPeriodCountdown.tick(140_000);
interPeriodCountdown.tick(140_000);
assert.equal(
    interPeriodCountdown.transitions.length,
    interPeriodTransitionCount,
);
assert.deepEqual(soundsFor(interPeriodCountdown), [
    'start',
    'end',
    'rest_end',
    'start',
    'end',
]);

const directNextPeriod = new MatchClockCore({
    minutes: 1,
    periods: 2,
    rest_seconds: 10,
    countdown_seconds: 0,
});
directNextPeriod.start(0);
directNextPeriod.tick(60_000);
assert.equal(directNextPeriod.phase, 'rest');
directNextPeriod.tick(70_000);
assert.equal(directNextPeriod.phase, 'period');
assert.equal(directNextPeriod.period, 2);
assert.equal(directNextPeriod.remaining, 60);
directNextPeriod.tick(130_000);
assert.equal(directNextPeriod.phase, 'finished');
// The page plays the initial whistle at start(); it is not a clock transition.
assert.deepEqual(soundsFor(directNextPeriod), [
    'end',
    'rest_end',
    'start',
    'end',
]);

const pausedInterPeriodCountdown = new MatchClockCore({
    minutes: 1,
    periods: 2,
    rest_seconds: 10,
    countdown_seconds: 5,
});
pausedInterPeriodCountdown.start(0);
pausedInterPeriodCountdown.tick(65_000);
pausedInterPeriodCountdown.tick(75_000);
assert.equal(pausedInterPeriodCountdown.phase, 'countdown');
assert.equal(pausedInterPeriodCountdown.period, 2);
pausedInterPeriodCountdown.tick(77_000);
assert.equal(pausedInterPeriodCountdown.remaining, 3);
pausedInterPeriodCountdown.togglePause(77_000);
pausedInterPeriodCountdown.tick(97_000);
assert.equal(pausedInterPeriodCountdown.remaining, 3);
pausedInterPeriodCountdown.togglePause(97_000);
pausedInterPeriodCountdown.tick(100_000);
assert.equal(pausedInterPeriodCountdown.phase, 'period');
assert.equal(pausedInterPeriodCountdown.period, 2);
assert.equal(pausedInterPeriodCountdown.remaining, 60);
pausedInterPeriodCountdown.tick(160_000);
assert.equal(pausedInterPeriodCountdown.phase, 'finished');

const jumpedInterPeriodCountdown = new MatchClockCore({
    minutes: 1,
    periods: 2,
    rest_seconds: 10,
    countdown_seconds: 5,
});
jumpedInterPeriodCountdown.start(0);
jumpedInterPeriodCountdown.tick(65_000);
assert.equal(jumpedInterPeriodCountdown.phase, 'rest');
jumpedInterPeriodCountdown.tick(98_000);
assert.equal(jumpedInterPeriodCountdown.phase, 'period');
assert.equal(jumpedInterPeriodCountdown.period, 2);
assert.equal(jumpedInterPeriodCountdown.remaining, 42);
assert.deepEqual(
    jumpedInterPeriodCountdown.transitions.slice(2).map((transition) => ({
        ended: transition.ended,
        started: transition.started,
    })),
    [
        { ended: 'rest', started: 'countdown' },
        { ended: 'countdown', started: 'period' },
    ],
);
const jumpedTransitionCount = jumpedInterPeriodCountdown.transitions.length;
jumpedInterPeriodCountdown.tick(98_000);
assert.equal(
    jumpedInterPeriodCountdown.transitions.length,
    jumpedTransitionCount,
);

console.log(
    'Relógio: pré-contagem, pós-gol, pausa, descanso, tempos, atraso, configuração e recuperação passaram.',
);
