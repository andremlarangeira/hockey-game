export interface Player {
    id: number;
    name: string;
    jersey_number: number;
    photo_url: string | null;
    level: number | string;
    present: boolean;
}

export interface DrawTeam {
    id: string;
    name: string;
    color: string;
    players: Player[];
    total: number;
    average: number;
}

export interface DrawResult {
    teams: DrawTeam[];
    reserves: Player[];
}

export interface MatchSettings {
    minutes: number;
    periods: number;
    rest_seconds: number;
    countdown_seconds: number;
}

export interface SoundSetting {
    type: 'builtin' | 'custom';
    file: string;
    name?: string;
}

export type SoundSettings = Record<'start' | 'end' | 'rest_end', SoundSetting>;

export interface GoalEvent {
    id: string;
    team: 'home' | 'away';
    scorer_id: number;
    scorer_name: string;
    assist_id: number | null;
    assist_name: string | null;
    period: number;
    elapsed_seconds: number;
    remaining_seconds: number;
    recorded_at: string;
}

export interface TimelineEvent {
    at: string;
    type: string;
    period: number | null;
    elapsed_seconds: number;
    details?: Record<string, string | number | boolean | null>;
}
