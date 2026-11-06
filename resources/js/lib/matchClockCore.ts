import type { MatchSettings } from '@/types/hockey';
export type ClockPhase = 'idle' | 'countdown' | 'period' | 'rest' | 'finished';
export interface ClockTransition {
    at: string;
    elapsed: number;
    period: number;
    ended: Exclude<ClockPhase, 'idle' | 'finished'>;
    started: ClockPhase;
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
        this.remaining = settings.minutes * 60;
    }
    start(now = Date.now()): void {
        if (this.phase !== 'idle') return;
        this.startedAt = new Date(now).toISOString();
        let at = now;
        if (this.settings.countdown_seconds > 0)
            this.stages.push({
                phase: 'countdown',
                number: 0,
                end: (at += this.settings.countdown_seconds * 1000),
            });
        for (let i = 1; i <= this.settings.periods; i++) {
            this.stages.push({
                phase: 'period',
                number: i,
                end: (at += this.settings.minutes * 60000),
            });
            if (i < this.settings.periods)
                this.stages.push({
                    phase: 'rest',
                    number: i,
                    end: (at += this.settings.rest_seconds * 1000),
                });
        }
        this.stages.push({
            phase: 'finished',
            number: this.settings.periods,
            end: at,
        });
        this.index = 0;
        this.phase = this.current.phase;
        this.period = this.current.number || 1;
        this.remaining = Math.ceil((this.current.end - now) / 1000);
        if (this.phase === 'period') this.playStartedAt = now;
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
        if (this.paused || this.phase === 'idle') return this.remaining;
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
        if (
            !saved ||
            typeof saved !== 'object' ||
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
