import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const cwd = new URL('../..', import.meta.url).pathname;
const temp = mkdtempSync(join(tmpdir(), 'public-refresh-'));

execFileSync(`${cwd}/node_modules/typescript/bin/tsc`, [
    `${cwd}/resources/js/lib/publicRefreshLoop.ts`,
    '--target',
    'ES2022',
    '--module',
    'commonjs',
    '--outDir',
    temp,
]);

const { createRefreshLoop } = await import(`${temp}/publicRefreshLoop.js`);

function deferred() {
    let resolve;
    const promise = new Promise((done) => {
        resolve = done;
    });
    return { promise, resolve };
}

function fakeScheduler() {
    let nextId = 1;
    const timers = new Map();
    const scheduled = [];

    return {
        scheduled,
        setTimeout(callback, delay) {
            const id = nextId++;
            timers.set(id, callback);
            scheduled.push({ id, delay, callback });
            return id;
        },
        clearTimeout(id) {
            timers.delete(id);
        },
        pending() {
            return timers.size;
        },
        runNext() {
            const [id, callback] = timers.entries().next().value;
            timers.delete(id);
            callback();
        },
    };
}

async function settle() {
    await Promise.resolve();
    await Promise.resolve();
}

{
    const scheduler = fakeScheduler();
    let calls = 0;
    const loop = createRefreshLoop({
        intervalMs: () => null,
        refresh: async () => {
            calls++;
            return 'ok';
        },
        isHidden: () => false,
        scheduler,
    });

    loop.start();
    assert.equal(calls, 0);
    assert.equal(scheduler.pending(), 0);
    assert.equal(loop.state().running, true);
}

{
    const scheduler = fakeScheduler();
    const request = deferred();
    let calls = 0;
    const loop = createRefreshLoop({
        intervalMs: () => 100,
        refresh: () => {
            calls++;
            return request.promise;
        },
        isHidden: () => false,
        scheduler,
    });

    loop.start();
    loop.start();
    assert.equal(scheduler.pending(), 1);
    assert.equal(scheduler.scheduled.at(-1).delay, 100);

    const staleTick = scheduler.scheduled.at(-1).callback;
    scheduler.runNext();
    assert.equal(calls, 1);
    staleTick();
    assert.equal(calls, 1);
    assert.equal(scheduler.pending(), 0);

    request.resolve('ok');
    await settle();
    assert.equal(scheduler.pending(), 1);
}

{
    const scheduler = fakeScheduler();
    let interval = 60_000;
    let request = deferred();
    const loop = createRefreshLoop({
        intervalMs: () => interval,
        refresh: () => request.promise,
        isHidden: () => false,
        scheduler,
    });

    loop.start();
    assert.equal(scheduler.scheduled.at(-1).delay, 60_000);
    scheduler.runNext();
    interval = 15_000;
    request.resolve('ok');
    await settle();
    assert.equal(scheduler.scheduled.at(-1).delay, 15_000);

    scheduler.runNext();
    interval = null;
    await settle();
    // A successful refresh must not schedule another timer once completed.
    assert.equal(scheduler.pending(), 0);
    assert.equal(loop.state().pendingTimer, false);
}

{
    const scheduler = fakeScheduler();
    const requests = [deferred(), deferred(), deferred(), deferred()];
    let index = 0;
    const loop = createRefreshLoop({
        intervalMs: () => 100,
        refresh: () => requests[index++].promise,
        isHidden: () => false,
        scheduler,
        maxBackoffMs: 500,
    });

    loop.start();
    scheduler.runNext();
    requests[0].resolve('retry');
    await settle();
    assert.equal(scheduler.scheduled.at(-1).delay, 200);

    scheduler.runNext();
    requests[1].resolve('retry');
    await settle();
    assert.equal(scheduler.scheduled.at(-1).delay, 400);

    scheduler.runNext();
    requests[2].resolve('retry');
    await settle();
    assert.equal(scheduler.scheduled.at(-1).delay, 500);

    scheduler.runNext();
    requests[3].resolve('ok');
    await settle();
    assert.equal(loop.state().failures, 0);
    assert.equal(scheduler.scheduled.at(-1).delay, 100);
}

{
    const scheduler = fakeScheduler();
    const loop = createRefreshLoop({
        intervalMs: () => 100,
        refresh: async () => 'stop',
        isHidden: () => false,
        scheduler,
    });

    loop.start();
    scheduler.runNext();
    await settle();
    assert.equal(loop.state().stopped, true);
    assert.equal(loop.state().running, false);
    loop.start();
    assert.equal(scheduler.pending(), 0);
}

{
    const scheduler = fakeScheduler();
    let hidden = false;
    const request = deferred();
    let calls = 0;
    const loop = createRefreshLoop({
        intervalMs: () => 100,
        refresh: () => {
            calls++;
            return request.promise;
        },
        isHidden: () => hidden,
        scheduler,
    });

    loop.start();
    hidden = true;
    loop.onVisibilityChange();
    assert.equal(scheduler.pending(), 0);
    assert.equal(calls, 0);

    hidden = false;
    loop.onVisibilityChange();
    assert.equal(calls, 1);
    assert.equal(loop.state().inFlight, true);
    request.resolve('ok');
    await settle();
    assert.equal(scheduler.pending(), 1);
}

{
    const scheduler = fakeScheduler();
    const request = deferred();
    const loop = createRefreshLoop({
        intervalMs: () => 100,
        refresh: () => request.promise,
        isHidden: () => false,
        scheduler,
    });

    loop.start();
    scheduler.runNext();
    loop.stop();
    request.resolve('ok');
    await settle();
    assert.equal(loop.state().running, false);
    assert.equal(loop.state().inFlight, false);
    assert.equal(scheduler.pending(), 0);
}

console.log(
    'Atualização pública: temporização, retomada, backoff e pausa passaram.',
);
