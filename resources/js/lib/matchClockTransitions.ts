import type { ClockTransition } from '@/lib/matchClockCore';

/** Mantém o consumidor apontando para as transições atuais do relógio. */
export function createMatchClockTransitions(clock: {
    transitions: ClockTransition[];
}): { readonly transitions: ClockTransition[] } {
    return {
        get transitions() {
            return clock.transitions;
        },
    };
}
