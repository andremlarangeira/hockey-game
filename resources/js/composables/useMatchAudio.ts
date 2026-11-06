import type { SoundSettings } from '@/types/hockey';

export function useMatchAudio() {
    let audio: HTMLAudioElement | null = null;
    let context: AudioContext | null = null;
    let bufferSource: AudioBufferSourceNode | null = null;
    const buffers = new Map<string, AudioBuffer>();
    const loading = new Map<string, Promise<AudioBuffer | null>>();

    function ensureContext(): AudioContext | null {
        const AudioContextConstructor =
            window.AudioContext ??
            (
                window as typeof window & {
                    webkitAudioContext?: typeof AudioContext;
                }
            ).webkitAudioContext;
        if (!AudioContextConstructor) return null;
        try {
            context ??= new AudioContextConstructor();
            return context;
        } catch {
            context = null;
            return null;
        }
    }

    function loadBuffer(file: string): Promise<AudioBuffer | null> {
        const currentContext = ensureContext();
        if (!currentContext || !file) return Promise.resolve(null);
        const cached = buffers.get(file);
        if (cached) return Promise.resolve(cached);
        const pending = loading.get(file);
        if (pending) return pending;
        const promise = fetch(file)
            .then((response) =>
                response.ok ? response.arrayBuffer() : Promise.reject(),
            )
            .then((data) => currentContext.decodeAudioData(data.slice(0)))
            .then((decoded) => {
                buffers.set(file, decoded);
                loading.delete(file);
                return decoded;
            })
            .catch(() => {
                loading.delete(file);
                return null;
            });
        loading.set(file, promise);
        return promise;
    }

    function stopBuffer(): void {
        if (!bufferSource) return;
        try {
            bufferSource.stop();
            bufferSource.disconnect();
        } catch {
            /* Buffer já encerrado. */
        }
        bufferSource = null;
    }

    async function playBuffer(file: string): Promise<void> {
        const currentContext = ensureContext();
        if (!currentContext) return;
        try {
            if (currentContext.state === 'suspended') {
                await currentContext.resume();
            }
            const decoded = await loadBuffer(file);
            if (!decoded || currentContext.state !== 'running') return;
            stopBuffer();
            const source = currentContext.createBufferSource();
            source.buffer = decoded;
            source.connect(currentContext.destination);
            bufferSource = source;
            source.addEventListener('ended', () => {
                if (bufferSource === source) bufferSource = null;
                source.disconnect();
            });
            source.start(0);
        } catch {
            /* O navegador pode pedir interação antes de liberar áudio. */
        }
    }

    async function unlock(sounds?: SoundSettings): Promise<void> {
        const currentContext = ensureContext();
        if (!currentContext) return;
        try {
            const resumePromise =
                currentContext.state === 'suspended'
                    ? currentContext.resume()
                    : Promise.resolve();
            const silent = currentContext.createBuffer(1, 1, 22050);
            const source = currentContext.createBufferSource();
            source.buffer = silent;
            source.connect(currentContext.destination);
            source.start(0);
            source.addEventListener('ended', () => source.disconnect());
            if (sounds) {
                for (const key of ['start', 'end', 'rest_end'] as const) {
                    void loadBuffer(sounds[key].file);
                }
            }
            await resumePromise;
        } catch {
            context = null;
        }
    }

    function countdown(): void {
        if (!context) return;
        if (context.state === 'suspended') {
            void context.resume();
        }
        if (context.state !== 'running') return;
        const startsAt = context.currentTime;
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, startsAt);
        gain.gain.setValueAtTime(0, startsAt);
        gain.gain.linearRampToValueAtTime(0.18, startsAt + 0.01);
        gain.gain.setValueAtTime(0.18, startsAt + 0.09);
        gain.gain.linearRampToValueAtTime(0, startsAt + 0.13);
        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start(startsAt);
        oscillator.stop(startsAt + 0.14);
        oscillator.addEventListener('ended', () => {
            oscillator.disconnect();
            gain.disconnect();
        });
    }

    function play(sounds: SoundSettings, event: 'start' | 'end' | 'rest_end') {
        const file = sounds[event].file;
        audio?.pause();
        stopBuffer();
        audio = new Audio(file);
        void audio.play().catch(() => {
            void playBuffer(file);
        });
    }

    function stop(): void {
        audio?.pause();
        stopBuffer();
        void context?.close();
        context = null;
    }

    return { play, countdown, unlock, stop };
}
