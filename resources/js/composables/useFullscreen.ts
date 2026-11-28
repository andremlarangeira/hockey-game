import { onBeforeUnmount, onMounted, ref } from 'vue';

export function useFullscreen() {
    const isFullscreen = ref(false);

    const syncFullscreen = () => {
        isFullscreen.value = Boolean(document.fullscreenElement);
    };

    const toggleFullscreen = async () => {
        try {
            if (document.fullscreenElement) {
                await document.exitFullscreen();
            } else {
                await document.documentElement.requestFullscreen();
                if ('orientation' in screen) {
                    await (
                        screen.orientation as ScreenOrientation & {
                            lock?: (orientation: string) => Promise<void>;
                        }
                    )
                        .lock?.('landscape')
                        .catch(() => undefined);
                }
            }
        } catch {
            /* O navegador pode bloquear tela cheia ou rotação. */
        }
    };

    onMounted(() =>
        document.addEventListener('fullscreenchange', syncFullscreen),
    );
    onBeforeUnmount(() =>
        document.removeEventListener('fullscreenchange', syncFullscreen),
    );

    return { isFullscreen, toggleFullscreen };
}
