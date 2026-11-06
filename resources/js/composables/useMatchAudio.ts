import type { SoundSettings } from '@/types/hockey';

export function useMatchAudio() {
    let audio: HTMLAudioElement | null = null;
    let context: AudioContext | null = null;

    async function unlock(): Promise<void> {
        const AudioContextConstructor =
            window.AudioContext ??
            (
                window as typeof window & {
                    webkitAudioContext?: typeof AudioContext;
                }
            ).webkitAudioContext;
        if (!AudioContextConstructor) return;
        try {
            context ??= new AudioContextConstructor();
            if (context.state === 'suspended') await context.resume();
        } catch {
            context = null;
        }
    }

    function countdown(): void {
        if (!context || context.state !== 'running') return;
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
        audio?.pause();
        audio = new Audio(sounds[event].file);
        void audio.play().catch(() => {
            /* O navegador pode pedir interação antes de liberar áudio. */
        });
    }

    function stop(): void {
        audio?.pause();
        void context?.close();
        context = null;
    }

    return { play, countdown, unlock, stop };
}
