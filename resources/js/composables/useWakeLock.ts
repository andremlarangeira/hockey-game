import { onBeforeUnmount, ref } from 'vue';
export function useWakeLock() {
    const active = ref(false);
    let wanted = false;
    let lock: WakeLockSentinel | null = null;
    async function request() {
        wanted = true;
        if (
            lock ||
            !navigator.wakeLock ||
            document.visibilityState !== 'visible'
        )
            return;
        try {
            lock = await navigator.wakeLock.request('screen');
            active.value = true;
            lock.addEventListener('release', () => {
                active.value = false;
                lock = null;
            });
        } catch {
            active.value = false;
        }
    }
    async function release() {
        wanted = false;
        await lock?.release();
        lock = null;
        active.value = false;
    }
    const visibility = () => {
        if (document.visibilityState === 'visible' && wanted && !active.value)
            void request();
    };
    document.addEventListener('visibilitychange', visibility);
    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', visibility);
        void release();
    });
    return { active, request, release };
}
