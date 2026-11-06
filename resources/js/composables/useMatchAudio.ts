import { ref } from 'vue';
import type { SoundSettings } from '@/types/hockey';

export function useMatchAudio() {
    const audio = ref<HTMLAudioElement | null>(null);
    function play(sounds: SoundSettings, event: 'start' | 'end' | 'rest_end') {
        audio.value?.pause();
        audio.value = new Audio(sounds[event].file);
        void audio.value.play().catch(() => {
            /* O navegador pode pedir interação antes de liberar áudio. */
        });
    }
    return { play };
}
