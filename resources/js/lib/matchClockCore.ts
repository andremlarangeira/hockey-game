import type { MatchSettings } from '@/types/hockey';
export type ClockPhase = 'idle' | 'countdown' | 'period' | 'rest' | 'finished';
export interface ClockTransition {
    at: string;
    elapsed: number;
    period: number;
    ended: Exclude<ClockPhase, 'idle' | 'finished'>;
    started: ClockPhase;
}
export function transitionSounds(
    transition: ClockTransition,
): Array<'end' | 'rest_end' | 'start'> {
    if (transition.ended === 'period') return ['end'];
    if (transition.ended === 'countdown') return ['start'];
    if (transition.ended === 'rest')
        return transition.started === 'period'
            ? ['rest_end', 'start']
            : ['rest_end'];
    return [];
}
type Stage = {
    phase: Exclude<ClockPhase, 'idle'>;
    number: number;
    end: number;
};
export class MatchClockCore {
    phase: ClockPhase = 'idle';
    period = 1;
    remaining: number;
    elapsed = 0;
    paused = false;
    startedAt: string | null = null;
    transitions: ClockTransition[] = [];
    private index = -1;
    private playedMs = 0;
    private playStartedAt: number | null = null;
    private pausedAt: number | null = null;
    private stages: Stage[] = [];
    constructor(private settings: MatchSettings) {
        this.remaining = this.settings.minutes * 60;
    }
    configure(settings: MatchSettings, now = Date.now()): void {
        const minutes =
            Number.isFinite(settings.minutes) && settings.minutes > 0
                ? Math.floor(settings.minutes)
                : this.settings.minutes;
        const periods =
            Number.isFinite(settings.periods) && settings.periods > 0
                ? Math.floor(settings.periods)
                : this.settings.periods;
        const rest_seconds =
            Number.isFinite(settings.rest_seconds) && settings.rest_seconds > 0
                ? Math.floor(settings.rest_seconds)
                : this.settings.rest_seconds;
        const countdown_seconds =
            Number.isFinite(settings.countdown_seconds) &&
            settings.countdown_seconds >= 0
                ? Math.floor(settings.countdown_seconds)
                : this.settings.countdown_seconds;
        Object.assign(this.settings, {
            minutes,
            periods,
            rest_seconds,
            countdown_seconds,
        });
        if (this.phase === 'idle') {
            this.remaining = this.settings.minutes * 60;
            return;
        }
        if (this.phase === 'finished' || this.index < 0) return;
        if (this.phase === 'countdown' && this.current.number === 0) {
            this.rebuildStagesFrom(1, this.current.end);
            return;
        }
        if (
            this.phase === 'period' &&
            this.playedMs === 0 &&
            this.elapsed === 0
        ) {
            const base = this.paused
                ? (this.pausedAt ?? now)
                : (this.playStartedAt ?? now);
            this.current.end = base + this.settings.minutes * 60000;
            this.remaining = this.settings.minutes * 60;
            this.rebuildStagesFrom(this.period + 1, this.current.end);
            return;
        }
        if (this.phase === 'rest') {
            this.rebuildStagesFrom(this.period + 1, this.current.end);
        }
    }
    start(now = Date.now()): void {
        if (this.phase !== 'idle') return;
        this.startedAt = new Date(now).toISOString();
        this.stages = this.buildStages(1, now, true);
        this.index = 0;
        this.phase = this.current.phase;
        this.period = this.current.number || 1;
        this.remaining = Math.ceil((this.current.end - now) / 1000);
        if (this.phase === 'period') this.playStartedAt = now;
    }
    /** Insere uma pré-contagem sem consumir o tempo restante do período. */
    restartCountdown(now = Date.now()): boolean {
        if (this.phase !== 'period' || this.paused) return false;
        this.tick(now);
        if (this.phase !== 'period' || this.settings.countdown_seconds <= 0)
            return false;
        const duration = this.settings.countdown_seconds * 1000;
        this.stopPlaying(now);
        this.stages.splice(this.index, 0, {
            phase: 'countdown',
            number: this.period,
            end: now + duration,
        });
        for (let i = this.index + 1; i < this.stages.length; i++)
            this.stages[i].end += duration;
        this.phase = 'countdown';
        this.remaining = this.settings.countdown_seconds;
        this.playStartedAt = null;
        return true;
    }

    togglePause(now = Date.now()): void {
        this.tick(now);
        if (!['countdown', 'period', 'rest'].includes(this.phase)) return;
        if (this.paused) {
            const shift = now - (this.pausedAt ?? now);
            for (let i = this.index; i < this.stages.length; i++)
                this.stages[i].end += shift;
            this.paused = false;
            if (this.phase === 'period') this.playStartedAt = now;
            this.pausedAt = null;
        } else {
            this.stopPlaying(now);
            this.paused = true;
            this.pausedAt = now;
        }
    }
    tick(now = Date.now()): number {
        if (this.phase === 'idle') {
            this.remaining = this.settings.minutes * 60;
            return this.remaining;
        }
        if (this.paused) return this.remaining;
        while (this.current.end <= now) {
            const previous = this.phase;
            if (previous === 'finished') return 0;
            this.stopPlaying(this.current.end);
            const previousPeriod = this.period;
            this.index++;
            this.transitions.push({
                at: new Date(this.stages[this.index - 1].end).toISOString(),
                elapsed: this.elapsed,
                period: previousPeriod,
                ended: previous,
                started: this.current.phase,
            });
            this.phase = this.current.phase;
            this.period = this.current.number;
            this.playStartedAt =
                this.phase === 'period'
                    ? this.stages[this.index - 1].end
                    : null;
            if (this.phase === 'finished') {
                this.remaining = 0;
                this.paused = true;
                return 0;
            }
        }
        this.remaining = Math.ceil((this.current.end - now) / 1000);
        if (this.phase === 'period')
            this.elapsed = Math.floor(
                (this.playedMs +
                    (this.playStartedAt === null
                        ? 0
                        : Math.min(now, this.current.end) -
                          this.playStartedAt)) /
                    1000,
            );
        return this.remaining;
    }
    finish(now = Date.now()): void {
        this.tick(now);
        this.stopPlaying(now);
        this.phase = 'finished';
        this.paused = true;
    }
    export(): string {
        return JSON.stringify({
            phase: this.phase,
            period: this.period,
            remaining: this.remaining,
            elapsed: this.elapsed,
            paused: this.paused,
            startedAt: this.startedAt,
            transitions: this.transitions,
            index: this.index,
            playedMs: this.playedMs,
            playStartedAt: this.playStartedAt,
            pausedAt: this.pausedAt,
            stages: this.stages,
        });
    }
    restore(serialized: string): void {
        const saved = JSON.parse(serialized);
        if (!saved || typeof saved !== 'object') return;
        if (saved.phase === 'idle') {
            this.reset();
            return;
        }
        if (
            !Array.isArray(saved.stages) ||
            !Number.isInteger(saved.index) ||
            saved.index < 0 ||
            saved.index >= saved.stages.length ||
            !saved.stages.every(
                (stage: unknown) =>
                    stage &&
                    typeof stage === 'object' &&
                    typeof (stage as Stage).end === 'number',
            )
        )
            return;
        this.phase = saved.phase;
        this.period = saved.period;
        this.remaining = saved.remaining;
        this.elapsed = saved.elapsed;
        this.paused = saved.paused;
        this.startedAt = saved.startedAt;
        this.transitions = Array.isArray(saved.transitions)
            ? saved.transitions
            : [];
        this.index = saved.index;
        this.playedMs = saved.playedMs;
        this.playStartedAt = saved.playStartedAt;
        this.pausedAt = saved.pausedAt ?? null;
        this.stages = saved.stages;
    }
    reset(): void {
        this.phase = 'idle';
        this.period = 1;
        this.remaining = this.settings.minutes * 60;
        this.elapsed = 0;
        this.paused = false;
        this.startedAt = null;
        this.playedMs = 0;
        this.playStartedAt = null;
        this.stages = [];
        this.transitions = [];
        this.index = -1;
        this.pausedAt = null;
    }
    private rebuildStagesFrom(startPeriod: number, fromTime: number): void {
        const nextStages = this.buildStages(
            startPeriod,
            fromTime,
            false,
            this.phase === 'period',
        );
        this.stages.splice(
            this.index + 1,
            this.stages.length - (this.index + 1),
            ...nextStages,
        );
    }
    private buildStages(
        startPeriod: number,
        fromTime: number,
        includeInitialCountdown: boolean,
        includeLeadingRest = false,
    ): Stage[] {
        let at = fromTime;
        const stages: Stage[] = [];
        if (includeInitialCountdown && this.settings.countdown_seconds > 0)
            stages.push({
                phase: 'countdown',
                number: 0,
                end: (at += this.settings.countdown_seconds * 1000),
            });
        if (includeLeadingRest && startPeriod <= this.settings.periods)
            stages.push({
                phase: 'rest',
                number: startPeriod - 1,
                end: (at += this.settings.rest_seconds * 1000),
            });
        for (let i = startPeriod; i <= this.settings.periods; i++) {
            if (i > 1 && this.settings.countdown_seconds > 0)
                stages.push({
                    phase: 'countdown',
                    number: i,
                    end: (at += this.settings.countdown_seconds * 1000),
                });
            stages.push({
                phase: 'period',
                number: i,
                end: (at += this.settings.minutes * 60000),
            });
            if (i < this.settings.periods)
                stages.push({
                    phase: 'rest',
                    number: i,
                    end: (at += this.settings.rest_seconds * 1000),
                });
        }
        stages.push({
            phase: 'finished',
            number: this.settings.periods,
            end: at,
        });
        return stages;
    }
    private get current(): Stage {
        if (this.index < 0)
            throw new Error('O relógio ainda não foi iniciado.');
        return this.stages[this.index];
    }
    private stopPlaying(now: number): void {
        if (this.playStartedAt !== null)
            this.playedMs += Math.max(0, now - this.playStartedAt);
        this.playStartedAt = null;
        this.elapsed = Math.floor(this.playedMs / 1000);
    }
}
