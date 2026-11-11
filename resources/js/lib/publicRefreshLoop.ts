export type RefreshResult = 'ok' | 'retry' | 'stop';

type Timer = ReturnType<typeof setTimeout>;

export interface RefreshLoopScheduler {
    setTimeout(callback: () => void, delay: number): Timer;
    clearTimeout(timer: Timer): void;
}

export interface RefreshLoopState {
    running: boolean;
    hidden: boolean;
    failures: number;
    stopped: boolean;
    pendingTimer: boolean;
    inFlight: boolean;
}

export interface CreateRefreshLoopOptions {
    intervalMs: () => number | null;
    refresh: () => Promise<RefreshResult>;
    isHidden: () => boolean;
    scheduler?: RefreshLoopScheduler;
    maxBackoffMs?: number;
}

export interface RefreshLoop {
    start(): void;
    stop(): void;
    onVisibilityChange(): void;
    state(): RefreshLoopState;
}

const defaultScheduler: RefreshLoopScheduler = {
    setTimeout: (callback, delay) => setTimeout(callback, delay),
    clearTimeout: (timer) => clearTimeout(timer),
};

/**
 * Schedules public-page partial reloads without depending on a UI framework.
 * A loop can be stopped and started again, except after a refresh explicitly
 * reports that its public link is no longer available.
 */
export function createRefreshLoop({
    intervalMs,
    refresh,
    isHidden,
    scheduler = defaultScheduler,
    maxBackoffMs = 120_000,
}: CreateRefreshLoopOptions): RefreshLoop {
    let running = false;
    let hidden = isHidden();
    let failures = 0;
    let stopped = false;
    let timer: Timer | null = null;
    let inFlight = false;
    let generation = 0;

    const clearTimer = () => {
        if (timer === null) return;
        scheduler.clearTimeout(timer);
        timer = null;
    };

    const currentInterval = (): number | null => {
        const interval = intervalMs();
        return typeof interval === 'number' && interval > 0 ? interval : null;
    };

    const schedule = (delay: number) => {
        if (!running || stopped || hidden || inFlight || timer !== null) return;

        timer = scheduler.setTimeout(() => {
            timer = null;
            runRefresh();
        }, delay);
    };

    const scheduleNext = () => {
        const interval = currentInterval();
        if (interval === null) return;
        schedule(interval);
    };

    const runRefresh = () => {
        if (!running || stopped || hidden || inFlight) return;
        if (currentInterval() === null) return;

        inFlight = true;
        const refreshGeneration = generation;

        let request: Promise<RefreshResult>;
        try {
            request = refresh();
        } catch {
            request = Promise.resolve('retry');
        }

        void Promise.resolve(request)
            .catch<RefreshResult>(() => 'retry')
            .then((result) => {
                if (refreshGeneration !== generation) {
                    inFlight = false;
                    scheduleNext();
                    return;
                }

                inFlight = false;

                if (result === 'stop') {
                    stopped = true;
                    running = false;
                    clearTimer();
                    return;
                }

                if (!running || stopped || hidden) return;

                if (result === 'ok') {
                    failures = 0;
                    scheduleNext();
                    return;
                }

                failures++;
                const interval = currentInterval();
                if (interval === null) return;
                schedule(Math.min(interval * 2 ** failures, maxBackoffMs));
            });
    };

    return {
        start() {
            if (stopped) return;

            running = true;
            hidden = isHidden();
            if (hidden) return;

            scheduleNext();
        },
        stop() {
            running = false;
            generation++;
            clearTimer();
        },
        onVisibilityChange() {
            hidden = isHidden();

            if (hidden) {
                clearTimer();
                return;
            }

            if (!running || stopped || currentInterval() === null) return;

            clearTimer();
            runRefresh();
        },
        state() {
            return {
                running,
                hidden,
                failures,
                stopped,
                pendingTimer: timer !== null,
                inFlight,
            };
        },
    };
}
