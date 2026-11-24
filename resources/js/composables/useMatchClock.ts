import { computed, onBeforeUnmount, onMounted, reactive } from 'vue';
import type { MatchSettings } from '@/types/hockey';
import { formatClock } from '@/lib/matchClock';
import { type CountdownReason, MatchClockCore } from '@/lib/matchClockCore';
import { createMatchClockTransitions } from '@/lib/matchClockTransitions';

export function useMatchClock(
    settings: MatchSettings,
    onTransition: () => void,
    onCountdown: (second: number) => void,
) {
    const clock = new MatchClockCore(settings);
    const transitionView = createMatchClockTransitions(clock);
    const state = reactive({
        phase: clock.phase,
        period: 1,
        remaining: clock.remaining,
        elapsed: 0,
        paused: false,
        startedAt: null as string | null,
        countdownReason: clock.countdownReason as CountdownReason | null,
    });
    let timer: ReturnType<typeof setInterval> | undefined;
    let countdownSecond: number | null = null;

    const shouldTick = (): boolean =>
        !clock.paused && ['countdown', 'period', 'rest'].includes(clock.phase);

    const stopTimer = () => {
        if (timer !== undefined) {
            clearInterval(timer);
            timer = undefined;
        }
    };

    const ensureTimer = () => {
        if (shouldTick()) {
            if (timer === undefined) {
                timer = setInterval(sync, 200);
            }
        } else {
            stopTimer();
        }
    };

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
            countdownReason: clock.countdownReason,
        });
        if (previous !== clock.transitions.length) onTransition();
        if (state.phase !== 'countdown') {
            countdownSecond = null;
        } else if (!state.paused) {
            const second = state.remaining;
            if (second > 0 && second <= 5 && second !== countdownSecond) {
                onCountdown(second);
            }
            countdownSecond = second;
        }
        ensureTimer();
    };

    onMounted(() => {
        ensureTimer();
        document.addEventListener('visibilitychange', sync);
    });
    onBeforeUnmount(() => {
        stopTimer();
        document.removeEventListener('visibilitychange', sync);
    });
    return reactive({
        state,
        get transitions() {
            return transitionView.transitions;
        },
        export: () => clock.export(),
        running: computed(() =>
            ['countdown', 'period', 'rest'].includes(state.phase),
        ),
        formatted: computed(() => formatClock(state.remaining)),
        configure: (nextSettings: MatchSettings) => {
            clock.configure(nextSettings);
            sync();
        },
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
        restartCountdown: (reason: 'goal' | 'restart' = 'goal') => {
            const restarted = clock.restartCountdown(Date.now(), reason);
            sync();
            return restarted;
        },
        tick: sync,
    });
}
