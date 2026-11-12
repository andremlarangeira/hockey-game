export interface AudioEngineDependencies {
    AudioContext?: typeof AudioContext;
    Audio?: typeof Audio;
    fetch?: typeof fetch;
    document?: Pick<Document, 'visibilityState'>;
}

export interface AudioEngine {
    ensureContext(): AudioContext | null;
    unlock(files?: readonly string[]): Promise<void>;
    play(file: string): Promise<void>;
    beep(): void;
    resume(): Promise<void>;
    stopAll(): void;
    dispose(): Promise<void>;
}

const BUFFER_WAIT_MS = 1500;
const RESUME_WAIT_MS = 250;

/**
 * Motor de áudio independente do Vue. Um contexto é reutilizado para evitar o
 * limite de AudioContexts dos navegadores durante uma sessão de partidas.
 */
export function createAudioEngine(
    dependencies: AudioEngineDependencies = {},
): AudioEngine {
    const AudioContextConstructor = dependencies.AudioContext;
    const AudioConstructor = dependencies.Audio;
    const fetchAudio = dependencies.fetch;
    let context: AudioContext | null = null;
    const buffers = new Map<string, AudioBuffer>();
    const loading = new Map<string, Promise<AudioBuffer | null>>();
    const fallbackAudio = new Map<string, HTMLAudioElement>();
    const activeSources = new Set<AudioScheduledSourceNode>();

    function ensureContext(): AudioContext | null {
        if (!AudioContextConstructor) return null;
        if (context?.state === 'closed') context = null;
        if (context) return context;
        try {
            context = new AudioContextConstructor();
            return context;
        } catch {
            return null;
        }
    }

    async function waitForResume(resumePromise: Promise<void>): Promise<void> {
        let timeout: ReturnType<typeof setTimeout> | undefined;
        try {
            await Promise.race([
                resumePromise,
                new Promise<void>((resolve) => {
                    timeout = setTimeout(resolve, RESUME_WAIT_MS);
                }),
            ]);
        } catch {
            /* O navegador pode exigir uma interação explícita. */
        } finally {
            if (timeout !== undefined) clearTimeout(timeout);
        }
    }

    function resumeContext(currentContext: AudioContext): Promise<void> {
        if (currentContext.state === 'running') return Promise.resolve();
        try {
            return waitForResume(currentContext.resume());
        } catch {
            return Promise.resolve();
        }
    }

    function resume(): Promise<void> {
        const currentContext = ensureContext();
        if (!currentContext) return Promise.resolve();
        return resumeContext(currentContext);
    }

    function loadBuffer(file: string): Promise<AudioBuffer | null> {
        const currentContext = ensureContext();
        if (!currentContext || !fetchAudio || !file)
            return Promise.resolve(null);
        const cached = buffers.get(file);
        if (cached) return Promise.resolve(cached);
        const pending = loading.get(file);
        if (pending) return pending;

        let request: Promise<Response>;
        try {
            request = fetchAudio(file);
        } catch {
            return Promise.resolve(null);
        }

        const promise = Promise.resolve(request)
            .then((response) =>
                response.ok ? response.arrayBuffer() : Promise.reject(),
            )
            .then((data) => currentContext.decodeAudioData(data.slice(0)))
            .then((decoded) => {
                buffers.set(file, decoded);
                return decoded;
            })
            .catch(() => null)
            .finally(() => loading.delete(file));
        loading.set(file, promise);
        return promise;
    }

    async function waitForBuffer(
        pending: Promise<AudioBuffer | null>,
    ): Promise<AudioBuffer | null> {
        let timeout: ReturnType<typeof setTimeout> | undefined;
        try {
            return await Promise.race([
                pending,
                new Promise<null>((resolve) => {
                    timeout = setTimeout(() => resolve(null), BUFFER_WAIT_MS);
                }),
            ]);
        } catch {
            return null;
        } finally {
            if (timeout !== undefined) clearTimeout(timeout);
        }
    }

    function removeSource(source: AudioScheduledSourceNode): void {
        activeSources.delete(source);
        try {
            source.disconnect();
        } catch {
            /* A fonte já pode ter sido desconectada. */
        }
    }

    function playBuffer(
        currentContext: AudioContext,
        buffer: AudioBuffer,
    ): boolean {
        if (currentContext.state !== 'running') return false;
        try {
            const source = currentContext.createBufferSource();
            source.buffer = buffer;
            source.connect(currentContext.destination);
            activeSources.add(source);
            source.addEventListener('ended', () => removeSource(source));
            source.start(0);
            return true;
        } catch {
            return false;
        }
    }

    function playFallback(file: string): void {
        if (!AudioConstructor || !file) return;
        try {
            let audio = fallbackAudio.get(file);
            if (!audio) {
                audio = new AudioConstructor(file);
                audio.preload = 'auto';
                fallbackAudio.set(file, audio);
            }
            audio.currentTime = 0;
            void audio.play().catch(() => undefined);
        } catch {
            /* Sem fallback disponível, falhar silenciosamente. */
        }
    }

    async function unlock(files: readonly string[] = []): Promise<void> {
        const currentContext = ensureContext();
        if (!currentContext) return;

        // Estas chamadas precisam ocorrer durante o gesto que iniciou a ação,
        // antes de qualquer await, para desbloquear o Web Audio no iOS.
        const resumePromise = resumeContext(currentContext);
        try {
            const silent = currentContext.createBuffer(1, 1, 22050);
            const source = currentContext.createBufferSource();
            source.buffer = silent;
            source.connect(currentContext.destination);
            source.start(0);
            source.addEventListener('ended', () => {
                try {
                    source.disconnect();
                } catch {
                    /* Fonte já finalizada. */
                }
            });
        } catch {
            /* O desbloqueio silencioso é apenas uma otimização. */
        }

        for (const file of files) {
            if (file) void loadBuffer(file);
        }
        await resumePromise;
    }

    async function play(file: string): Promise<void> {
        if (!file) return;
        const currentContext = ensureContext();
        if (!currentContext) {
            playFallback(file);
            return;
        }

        await resume();
        const cached = buffers.get(file);
        if (cached && playBuffer(currentContext, cached)) return;

        const decoded = await waitForBuffer(loadBuffer(file));
        if (decoded && playBuffer(currentContext, decoded)) return;
        playFallback(file);
    }

    function beep(): void {
        const currentContext = ensureContext();
        if (!currentContext) return;
        if (currentContext.state === 'suspended') void resume();
        if (currentContext.state !== 'running') return;
        try {
            const startsAt = currentContext.currentTime;
            const oscillator = currentContext.createOscillator();
            const gain = currentContext.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(880, startsAt);
            gain.gain.setValueAtTime(0, startsAt);
            gain.gain.linearRampToValueAtTime(0.18, startsAt + 0.01);
            gain.gain.setValueAtTime(0.18, startsAt + 0.09);
            gain.gain.linearRampToValueAtTime(0, startsAt + 0.13);
            oscillator.connect(gain);
            gain.connect(currentContext.destination);
            activeSources.add(oscillator);
            oscillator.start(startsAt);
            oscillator.stop(startsAt + 0.14);
            oscillator.addEventListener('ended', () => {
                activeSources.delete(oscillator);
                try {
                    oscillator.disconnect();
                    gain.disconnect();
                } catch {
                    /* Nós já podem ter sido desconectados. */
                }
            });
        } catch {
            /* Falha silenciosa quando a política de autoplay bloquear áudio. */
        }
    }

    function stopAll(): void {
        for (const source of activeSources) {
            try {
                source.stop();
                source.disconnect();
            } catch {
                /* A fonte pode já ter terminado. */
            }
        }
        activeSources.clear();
        for (const audio of fallbackAudio.values()) {
            try {
                audio.pause();
                audio.currentTime = 0;
            } catch {
                /* Elemento de mídia indisponível. */
            }
        }
    }

    async function dispose(): Promise<void> {
        stopAll();
        const currentContext = context;
        context = null;
        buffers.clear();
        loading.clear();
        fallbackAudio.clear();
        try {
            await currentContext?.close();
        } catch {
            /* Encerramento é somente para testes. */
        }
    }

    return { ensureContext, unlock, play, beep, resume, stopAll, dispose };
}

let sharedEngine: AudioEngine | null = null;

function browserDependencies(): AudioEngineDependencies {
    if (typeof window === 'undefined') return {};
    const browserWindow = window as typeof window & {
        webkitAudioContext?: typeof AudioContext;
    };
    return {
        AudioContext:
            browserWindow.AudioContext ?? browserWindow.webkitAudioContext,
        Audio: typeof Audio === 'undefined' ? undefined : Audio,
        fetch: typeof fetch === 'undefined' ? undefined : fetch,
        document: typeof document === 'undefined' ? undefined : document,
    };
}

/** Retorna o único motor de áudio da sessão SPA. Dependências são para testes. */
export function getAudioEngine(
    dependencies?: AudioEngineDependencies,
): AudioEngine {
    sharedEngine ??= createAudioEngine(dependencies ?? browserDependencies());
    return sharedEngine;
}

/** Utilitário de teste para isolar o singleton entre cenários. */
export async function resetAudioEngineForTests(): Promise<void> {
    await sharedEngine?.dispose();
    sharedEngine = null;
}
