import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';
import type { MatchSettings } from '@/types/hockey';

interface PersistedClock {
    config?: MatchSettings;
    isOvertimeMode?: boolean;
    serializedClock?: string;
}

interface ClockStorageAdapter {
    state: { phase: string };
    configure(config: MatchSettings): void;
    export(): string;
    restore(serializedClock: string): void;
}

interface Options {
    storageKey: Ref<string>;
    gameStatus: () => string;
    config: Ref<MatchSettings>;
    isOvertimeMode: Ref<boolean>;
    clock: ClockStorageAdapter;
    beforeRestore: (serializedClock: string) => void;
    afterRestore: () => void;
}

export function useChampionshipClockPersistence(options: Options) {
    const restoring = ref(true);

    const clear = () => {
        try {
            localStorage.removeItem(options.storageKey.value);
        } catch {
            /* Ignora erro de localStorage. */
        }
    };

    const save = () => {
        if (
            restoring.value ||
            options.gameStatus() === 'finished' ||
            options.clock.state.phase === 'idle'
        ) {
            return;
        }

        try {
            localStorage.setItem(
                options.storageKey.value,
                JSON.stringify({
                    config: options.config.value,
                    isOvertimeMode: options.isOvertimeMode.value,
                    serializedClock: options.clock.export(),
                }),
            );
        } catch {
            /* Armazenamento local indisponível. */
        }
    };

    onMounted(() => {
        try {
            const saved = JSON.parse(
                localStorage.getItem(options.storageKey.value) ?? 'null',
            ) as PersistedClock | null;

            if (
                saved?.serializedClock &&
                options.gameStatus() !== 'finished'
            ) {
                if (typeof saved.isOvertimeMode === 'boolean') {
                    options.isOvertimeMode.value = saved.isOvertimeMode;
                }
                if (saved.config) {
                    Object.assign(options.config.value, saved.config);
                    options.clock.configure(options.config.value);
                }
                options.beforeRestore(saved.serializedClock);
                options.clock.restore(saved.serializedClock);
                options.clock.configure(options.config.value);
                options.afterRestore();
            }
        } catch {
            clear();
        }
        restoring.value = false;
    });

    const onPageHide = () => save();
    onMounted(() => window.addEventListener('pagehide', onPageHide));
    onBeforeUnmount(() => window.removeEventListener('pagehide', onPageHide));

    return { clear, restoring, save };
}
