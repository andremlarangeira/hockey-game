export type ClockSnapshot = {
    phase: 'countdown' | 'period' | 'rest' | 'finished';
    period: number;
    remaining: number;
    elapsed: number;
};

/** Calcula o relógio pelo horário, sem depender de intervalos na aba ativa. */
export function readClock(
    stageEndsAt: number | null,
    now = Date.now(),
): number {
    return stageEndsAt === null
        ? 0
        : Math.max(0, Math.ceil((stageEndsAt - now) / 1000));
}

export function stageDuration(
    phase: ClockSnapshot['phase'],
    period: number,
    minutes: number,
    periods: number,
    restSeconds: number,
    countdownSeconds: number,
): number {
    if (phase === 'countdown') return countdownSeconds;
    if (phase === 'period') return minutes * 60;
    if (phase === 'rest') return restSeconds;
    return 0;
}

export function formatClock(seconds: number): string {
    return `${Math.floor(seconds / 60)
        .toString()
        .padStart(2, '0')}:${Math.floor(seconds % 60)
        .toString()
        .padStart(2, '0')}`;
}
