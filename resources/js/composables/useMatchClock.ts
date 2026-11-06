import { computed, onBeforeUnmount, onMounted, reactive } from 'vue';
import type { MatchSettings } from '@/types/hockey';
import { formatClock } from '@/lib/matchClock';
import { MatchClockCore } from '@/lib/matchClockCore';

export function useMatchClock(
    settings: MatchSettings,
    onTransition: () => void,
) {
    const clock = new MatchClockCore(settings);
    const state = reactive({
        phase: clock.phase,
        period: 1,
        remaining: clock.remaining,
        elapsed: 0,
        paused: false,
        startedAt: null as string | null,
    });
    let timer: ReturnType<typeof setInterval> | undefined;
    const sync = () => {
        const previous = clock.transitions.length;
        clock.tick();
        Object.assign(state, {
            phase: clock.phase as typeof state.phase,
            period: clock.period,
            remaining: clock.remaining,
            elapsed: clock.elapsed,
            paused: clock.paused,
            startedAt: clock.startedAt,
        });
        if (previous !== clock.transitions.length) onTransition();
    };
    onMounted(() => {
        timer = setInterval(sync, 200);
        document.addEventListener('visibilitychange', sync);
    });
    onBeforeUnmount(() => {
        if (timer !== undefined) clearInterval(timer);
        document.removeEventListener('visibilitychange', sync);
    });
    return reactive({
        state,
        transitions: computed(() => clock.transitions),
        export: () => clock.export(),
        running: computed(() =>
            ['countdown', 'period', 'rest'].includes(state.phase),
        ),
        formatted: computed(() => formatClock(state.remaining)),
        start: () => {
            clock.start();
            sync();
        },
        restore: (serialized: string) => {
            clock.restore(serialized);
            sync();
        },
        togglePause: () => {
            clock.togglePause();
            sync();
        },
        finish: () => {
            clock.finish();
            sync();
        },
        reset: () => {
            clock.reset();
            sync();
        },
        tick: sync,
    });
}
