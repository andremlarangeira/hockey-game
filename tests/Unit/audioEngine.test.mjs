import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const cwd = new URL('../..', import.meta.url).pathname;
const temp = mkdtempSync(join(tmpdir(), 'audio-engine-'));
writeFileSync(
    `${temp}/audioEngine.ts`,
    readFileSync(`${cwd}/resources/js/lib/audioEngine.ts`, 'utf8'),
);
execFileSync(`${cwd}/node_modules/typescript/bin/tsc`, [
    `${temp}/audioEngine.ts`,
    '--target',
    'ES2022',
    '--module',
    'commonjs',
    '--outDir',
    temp,
]);
writeFileSync(`${temp}/package.json`, '{"type":"commonjs"}');
const { createAudioEngine, getAudioEngine, resetAudioEngineForTests } =
    await import(`${temp}/audioEngine.js`);

const log = [];
const buffers = new Map();

class FakeSource {
    constructor(kind = 'buffer') {
        this.kind = kind;
        this.buffer = null;
        this.handlers = [];
        this.frequency = {
            setValueAtTime: (...args) => log.push(['frequency', ...args]),
        };
    }
    connect() {}
    disconnect() {
        log.push(['disconnect', this.kind, this.buffer?.file]);
    }
    addEventListener(event, handler) {
        if (event === 'ended') this.handlers.push(handler);
    }
    start() {
        log.push(['start', this.kind, this.buffer?.file]);
    }
    stop() {
        log.push(['stop', this.kind, this.buffer?.file]);
    }
}

class FakeGain {
    constructor() {
        this.gain = {
            setValueAtTime: (...args) => log.push(['gain-set', ...args]),
            linearRampToValueAtTime: (...args) =>
                log.push(['gain-ramp', ...args]),
        };
    }
    connect() {}
    disconnect() {
        log.push(['disconnect', 'gain']);
    }
}

class FakeAudioContext {
    static constructed = 0;
    constructor() {
        FakeAudioContext.constructed += 1;
        this.state = 'running';
        this.currentTime = 10;
        this.destination = {};
    }
    resume() {
        log.push(['resume']);
        this.state = 'running';
        return Promise.resolve();
    }
    close() {
        log.push(['close']);
        this.state = 'closed';
        return Promise.resolve();
    }
    createBuffer() {
        return { file: 'silent' };
    }
    createBufferSource() {
        return new FakeSource();
    }
    createOscillator() {
        return new FakeSource('beep');
    }
    createGain() {
        return new FakeGain();
    }
    decodeAudioData(data) {
        const file = buffers.get(new Uint8Array(data)[0]) ?? 'unknown';
        log.push(['decode', file]);
        return Promise.resolve({ file });
    }
}

class FakeAudio {
    static constructed = 0;
    constructor(file) {
        FakeAudio.constructed += 1;
        this.file = file;
        this.currentTime = 0;
        this.preload = '';
        log.push(['audio-new', file]);
    }
    play() {
        log.push(['audio-play', this.file, this.currentTime]);
        return Promise.resolve();
    }
    pause() {
        log.push(['audio-pause', this.file]);
    }
}

const fetches = new Map();
const fakeFetch = async (file) => {
    fetches.set(file, (fetches.get(file) ?? 0) + 1);
    log.push(['fetch', file]);
    if (file === 'missing') throw new Error('missing');
    const data = new ArrayBuffer(1);
    const bufferId = buffers.size + 1;
    new Uint8Array(data)[0] = bufferId;
    buffers.set(bufferId, file);
    return { ok: true, arrayBuffer: async () => data };
};

function deferred() {
    let resolve;
    const promise = new Promise((resolvePromise) => {
        resolve = resolvePromise;
    });
    return { promise, resolve };
}

const fakeDocument = {
    visibilityState: 'visible',
    listeners: new Set(),
    addEventListener(_event, listener) {
        this.listeners.add(listener);
    },
    removeEventListener(_event, listener) {
        this.listeners.delete(listener);
    },
    async dispatchVisibilityChange() {
        await Promise.all([...this.listeners].map((listener) => listener()));
    },
};

const deps = {
    AudioContext: FakeAudioContext,
    Audio: FakeAudio,
    fetch: fakeFetch,
    document: fakeDocument,
};

function mountPageLikeWrapper() {
    const engine = getAudioEngine(deps);
    const onVisibilityChange = () =>
        fakeDocument.visibilityState === 'visible'
            ? engine.resume()
            : Promise.resolve();
    fakeDocument.addEventListener('visibilitychange', onVisibilityChange);
    return {
        engine,
        unmount: () => {
            fakeDocument.removeEventListener(
                'visibilitychange',
                onVisibilityChange,
            );
            engine.stopAll();
        },
    };
}

// Duas montagens de página compartilham o único contexto do aplicativo.
await resetAudioEngineForTests();
const firstLifecycle = mountPageLikeWrapper();
const firstPage = firstLifecycle.engine;
firstLifecycle.unmount();
const secondLifecycle = mountPageLikeWrapper();
const secondPage = secondLifecycle.engine;
assert.equal(firstPage, secondPage);
assert.equal(firstPage.ensureContext(), secondPage.ensureContext());
assert.equal(FakeAudioContext.constructed, 1);

// Quando o navegador fecha um contexto, o próximo uso cria somente um novo.
const firstContext = firstPage.ensureContext();
firstContext.state = 'closed';
const replacement = firstPage.ensureContext();
assert.notEqual(replacement, firstContext);
assert.equal(FakeAudioContext.constructed, 2);
assert.equal(firstPage.ensureContext(), replacement);

// O gesto desbloqueia o contexto imediatamente, sem esperar o preload.
const deferredResume = deferred();
const deferredFetch = deferred();
const gestureLog = [];
let preloadStarted = false;
class GestureAudioContext extends FakeAudioContext {
    constructor() {
        super();
        this.state = 'suspended';
    }
    resume() {
        gestureLog.push('resume');
        this.state = 'running';
        return deferredResume.promise;
    }
    createBufferSource() {
        const source = super.createBufferSource();
        const start = source.start.bind(source);
        source.start = (...args) => {
            gestureLog.push('silent-start');
            start(...args);
        };
        return source;
    }
}
const gestureEngine = createAudioEngine({
    AudioContext: GestureAudioContext,
    fetch: () => {
        preloadStarted = true;
        return deferredFetch.promise;
    },
});
let unlockResolved = false;
void gestureEngine.unlock(['deferred']).then(() => {
    unlockResolved = true;
});
assert.deepEqual(gestureLog, ['resume', 'silent-start']);
assert.equal(preloadStarted, true);
deferredResume.resolve();
await new Promise((resolve) => setImmediate(resolve));
assert.equal(unlockResolved, true);
deferredFetch.resolve({
    ok: true,
    arrayBuffer: async () => new ArrayBuffer(1),
});
await new Promise((resolve) => setImmediate(resolve));

await firstPage.unlock(['start', 'end', 'rest_end', 'start']);
assert.equal(fetches.get('start'), 1);
assert.equal(fetches.get('end'), 1);
assert.equal(fetches.get('rest_end'), 1);

const audioBeforeBuffers = FakeAudio.constructed;
await firstPage.play('start');
await firstPage.play('start');
assert.equal(FakeAudio.constructed, audioBeforeBuffers);
const startedStart = log.filter(
    ([event, kind, file]) =>
        event === 'start' && kind === 'buffer' && file === 'start',
);
assert.equal(startedStart.length, 2);
assert.equal(
    log.filter(
        ([event, kind, file]) =>
            event === 'stop' && kind === 'buffer' && file === 'start',
    ).length,
    0,
);

// Uma partida completa mantém bipes e sinais na mesma via Web Audio, em ordem.
log.length = 0;
for (let index = 0; index < 5; index += 1) firstPage.beep();
await firstPage.play('start');
await firstPage.play('end');
await firstPage.play('rest_end');
for (let index = 0; index < 5; index += 1) firstPage.beep();
await firstPage.play('start');
await firstPage.play('end');
assert.deepEqual(
    log
        .filter(([event]) => event === 'start')
        .map(([, kind, file]) => (kind === 'beep' ? 'beep' : file)),
    [
        'beep',
        'beep',
        'beep',
        'beep',
        'beep',
        'start',
        'end',
        'rest_end',
        'beep',
        'beep',
        'beep',
        'beep',
        'beep',
        'start',
        'end',
    ],
);

// Contexto suspenso é retomado antes de tocar; a chamada pública resume também.
replacement.state = 'suspended';
await firstPage.play('end');
assert.ok(log.some(([event]) => event === 'resume'));
replacement.state = 'suspended';
await fakeDocument.dispatchVisibilityChange();
assert.equal(replacement.state, 'running');

// Falha de carregamento usa um elemento HTML reutilizado, sem exceções.
await firstPage.play('missing');
await firstPage.play('missing');
assert.equal(
    log.filter(([event, file]) => event === 'audio-new' && file === 'missing')
        .length,
    1,
);
assert.equal(
    log.filter(([event, file]) => event === 'audio-play' && file === 'missing')
        .length,
    2,
);

firstPage.stopAll();
assert.ok(log.some(([event]) => event === 'stop'));
assert.ok(
    log.some(([event, file]) => event === 'audio-pause' && file === 'missing'),
);

// Falhas do contexto e do fallback permanecem silenciosas para a interface.
const failing = createAudioEngine({
    AudioContext: class {
        constructor() {
            throw new Error('blocked');
        }
    },
    Audio: class {
        constructor() {
            throw new Error('blocked');
        }
    },
    fetch: async () => {
        throw new Error('blocked');
    },
});
await assert.doesNotReject(() => failing.unlock(['anything']));
await assert.doesNotReject(() => failing.play('anything'));
assert.doesNotThrow(() => failing.beep());

secondLifecycle.unmount();
await resetAudioEngineForTests();
console.log('audio engine tests passed');
