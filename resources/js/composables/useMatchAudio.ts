import { onBeforeUnmount, onMounted } from 'vue';
import { getAudioEngine } from '@/lib/audioEngine';
import type { SoundSettings } from '@/types/hockey';

export function useMatchAudio() {
    const engine = getAudioEngine();
    const onVisibilityChange = () => {
        if (document.visibilityState === 'visible') void engine.resume();
    };

    onMounted(() =>
        document.addEventListener('visibilitychange', onVisibilityChange),
    );
    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisibilityChange);
        engine.stopAll();
    });

    return {
        play: (sounds: SoundSettings, event: keyof SoundSettings) =>
            void engine.play(sounds[event].file),
        countdown: () => engine.beep(),
        unlock: (sounds?: SoundSettings) =>
            engine.unlock(
                sounds
                    ? (['start', 'end', 'rest_end'] as const).map(
                          (key) => sounds[key].file,
                      )
                    : [],
            ),
        stop: () => engine.stopAll(),
    };
}
