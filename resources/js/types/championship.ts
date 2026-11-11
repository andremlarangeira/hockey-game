import type { SoundSettings } from '@/types/hockey';

export type ChampionshipStatus =
    | 'draft'
    | 'group_stage'
    | 'knockout_stage'
    | 'completed';

export type GamePhase =
    | 'group'
    | 'round_of_16'
    | 'quarter_final'
    | 'semi_final'
    | 'third_place'
    | 'final';

export type GameStatus = 'scheduled' | 'live' | 'finished';

export type GameEventType = 'goal' | 'shootout_attempt' | 'period_mark';

export type TeamSide = 'home' | 'away';

export type PlayerPosition =
    | 'goalkeeper'
    | 'defender'
    | 'forward'
    | 'universal';

export type TieBreakCriterion =
    | 'points'
    | 'head_to_head'
    | 'goal_difference'
    | 'goals_for'
    | 'wins'
    | 'fewest_goals_against'
    | 'manual_draw';

export type KnockoutTieResolution = 'overtime_then_shootout' | 'shootout_only';

export interface ChampionshipRulesProp {
    groups_count: number;
    qualifiers_per_group: number;
    extra_best_qualifiers: number;
    double_round_robin: boolean;
    points_win: number;
    points_draw: number;
    points_loss: number;
    allow_group_overtime_or_shootout: boolean;
    points_overtime_win: number;
    points_overtime_loss: number;
    tie_break_order: TieBreakCriterion[];
    period_minutes: number;
    periods_count: number;
    rest_seconds: number;
    countdown_seconds: number;
    overtime_minutes: number;
    knockout_tie_resolution: KnockoutTieResolution;
    has_third_place_match: boolean;
    max_assists_per_goal: number;
}

export interface ChampionshipStatusFlagsProp {
    can_edit_rules: boolean;
    can_draw: boolean;
    can_generate_bracket: boolean;
}

export type ManualTieResolutionsMap = Record<string, number[] | number[][]>;

export interface ChampionshipProp {
    id: number;
    name: string;
    city: string;
    venue: string | null;
    starts_on: string | null;
    ends_on: string | null;
    status: ChampionshipStatus;
    rules: ChampionshipRulesProp;
    manual_tie_resolutions: ManualTieResolutionsMap | number[];
    drawn_at: string | null;
    bracket_generated_at: string | null;
    completed_at: string | null;
    teams_count?: number;
    games_count?: number;
    can_edit_rules?: boolean;
    can_draw?: boolean;
    can_generate_bracket?: boolean;
}

export type ChampionshipHeaderProp = ChampionshipProp;

export interface EnrolledPlayerProp {
    id: number;
    championship_team_id: number;
    name: string;
    jersey_number: number;
    position: PlayerPosition;
    is_goalkeeper: boolean;
}

export type ChampionshipPlayerProp = EnrolledPlayerProp;

export interface EnrolledTeamProp {
    id: number;
    championship_id: number;
    name: string;
    short_name: string | null;
    primary_color: string;
    secondary_color: string;
    logo_path: string | null;
    logo_url: string | null;
    group_code: string | null;
    group_draw_order: number | null;
    seed_pot: number | null;
    players: EnrolledPlayerProp[];
}

export type ChampionshipTeamProp = EnrolledTeamProp;

export interface GameTeamSummaryProp {
    id: number;
    name: string;
    short_name: string | null;
    primary_color: string;
    secondary_color: string;
    logo_url: string | null;
    championship_id?: number;
    logo_path?: string | null;
    group_code?: string | null;
    group_draw_order?: number | null;
    seed_pot?: number | null;
    players?: EnrolledPlayerProp[];
}

export interface ChampionshipGameProp {
    id: number;
    championship_id: number;
    phase: GamePhase;
    phase_label: string;
    group_code: string | null;
    round_number: number;
    leg_number: number;
    slot_key: string;
    bracket_position: number | null;
    home_source: string;
    away_source: string;
    home_team_id: number | null;
    away_team_id: number | null;
    home_team_name: string | null;
    away_team_name: string | null;
    home_team: GameTeamSummaryProp | null;
    away_team: GameTeamSummaryProp | null;
    winner_team_id: number | null;
    winner_team_name: string | null;
    venue: string | null;
    scheduled_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    status: GameStatus;
    is_bye: boolean;
    home_regular_score: number;
    away_regular_score: number;
    went_to_overtime: boolean;
    went_to_shootout: boolean;
    home_shootout_score: number;
    away_shootout_score: number;
}

export interface ChampionshipGameEventProp {
    id: number;
    championship_game_id: number;
    type: GameEventType;
    side: TeamSide | null;
    team_id: number | null;
    primary_player_id: number | null;
    primary_player_name: string | null;
    first_assist_player_id: number | null;
    first_assist_player_name: string | null;
    second_assist_player_id: number | null;
    second_assist_player_name: string | null;
    period: number | null;
    is_overtime: boolean;
    elapsed_seconds: number;
    remaining_seconds: number;
    shootout_round: number | null;
    shootout_scored: boolean | null;
    period_mark_type: string | null;
    recorded_at: string | null;
}

export interface ChampionshipClockSettingsProp {
    minutes: number;
    periods: number;
    rest_seconds: number;
    countdown_seconds: number;
}

export interface ChampionshipLiveGameProp extends ChampionshipGameProp {
    home_team: EnrolledTeamProp | null;
    away_team: EnrolledTeamProp | null;
    events: ChampionshipGameEventProp[];
    clock_settings: ChampionshipClockSettingsProp;
    overtime_minutes: number;
    max_assists: number;
    max_assists_per_goal: number;
}

export interface StandingRowProp {
    position: number;
    team_id: number;
    teamId: number;
    team_name: string;
    teamName: string;
    team_logo_url: string | null;
    teamLogoUrl: string | null;
    primary_color: string;
    primaryColor: string;
    group_code: string;
    groupCode: string;
    points: number;
    played: number;
    wins: number;
    draws: number;
    losses: number;
    goals_for: number;
    goalsFor: number;
    goals_against: number;
    goalsAgainst: number;
    goal_difference: number;
    goalDifference: number;
    qualifies: boolean;
    tied_with_next: boolean;
    tiedWithNext: boolean;
    resolved_by_criterion: string | null;
    resolvedByCriterion: string | null;
}

export interface GroupStandingsProp {
    group_code: string;
    groupCode: string;
    all_games_finished: boolean;
    allGamesFinished: boolean;
    unresolved_tie_groups: number[][];
    unresolvedTieGroups: number[][];
    rows: StandingRowProp[];
}

export interface PlayerLeaderRowProp {
    player_id: number;
    playerId: number;
    player_name: string;
    playerName: string;
    jersey_number: number;
    jerseyNumber: number;
    team_id: number;
    teamId: number;
    team_name: string;
    teamName: string;
    team_logo_url: string | null;
    teamLogoUrl: string | null;
    goals: number;
    assists: number;
    points: number;
    games_played: number;
    gamesPlayed: number;
}

export interface PodiumProp {
    champion_team_id: number | null;
    championTeamId: number | null;
    champion_team_name: string | null;
    runner_up_team_id: number | null;
    runnerUpTeamId: number | null;
    runner_up_team_name: string | null;
    third_place_team_id: number | null;
    thirdPlaceTeamId: number | null;
    third_place_team_name: string | null;
}

export interface ChampionshipSummaryProp {
    standings_by_group: Record<string, GroupStandingsProp>;
    standingsByGroup: Record<string, GroupStandingsProp>;
    podium: PodiumProp;
    total_games: number;
    totalGames: number;
    finished_games: number;
    finishedGames: number;
    total_goals: number;
    totalGoals: number;
    top_scorers: PlayerLeaderRowProp[];
    topScorers: PlayerLeaderRowProp[];
    top_assists: PlayerLeaderRowProp[];
    topAssists: PlayerLeaderRowProp[];
    top_point_leaders: PlayerLeaderRowProp[];
    topPointLeaders: PlayerLeaderRowProp[];
}

export interface ChampionshipAuditLogProp {
    id: number;
    championship_id: number;
    championship_game_id: number | null;
    actor_user_id: number;
    actor_name: string | null;
    action: string;
    reason: string | null;
    before_snapshot: Record<string, unknown> | null;
    after_snapshot: Record<string, unknown> | null;
    recorded_at: string | null;
}

export type AuditLogProp = ChampionshipAuditLogProp;

export interface ChampionshipIndexPageProps {
    championships: ChampionshipProp[];
    default_rules: ChampionshipRulesProp;
}

export interface ChampionshipShowPageProps {
    championship: ChampionshipProp;
    teams: EnrolledTeamProp[];
    standings: Record<string, GroupStandingsProp>;
    games: Partial<Record<GamePhase, ChampionshipGameProp[]>>;
    all_games: ChampionshipGameProp[];
    bracket: ChampionshipGameProp[];
    audit_logs: ChampionshipAuditLogProp[];
    audit_log: ChampionshipAuditLogProp[];
    status_flags: ChampionshipStatusFlagsProp;
    can_edit_rules: boolean;
    can_draw: boolean;
    can_generate_bracket: boolean;
    summary: ChampionshipSummaryProp;
}

export interface ChampionshipLiveGamePageProps {
    championship: ChampionshipProp;
    game: ChampionshipLiveGameProp;
    teams: {
        home: EnrolledTeamProp | null;
        away: EnrolledTeamProp | null;
    };
    rosters: {
        home: EnrolledPlayerProp[];
        away: EnrolledPlayerProp[];
    };
    events: ChampionshipGameEventProp[];
    clock_settings: ChampionshipClockSettingsProp;
    overtime_minutes: number;
    max_assists: number;
    sounds: SoundSettings;
}

export interface ChampionshipSummaryPageProps {
    championship: ChampionshipProp;
    summary: ChampionshipSummaryProp;
    podium: PodiumProp;
    top_scorers: PlayerLeaderRowProp[];
    topScorers: PlayerLeaderRowProp[];
    top_assists: PlayerLeaderRowProp[];
    topAssists: PlayerLeaderRowProp[];
    top_point_leaders: PlayerLeaderRowProp[];
    topPointLeaders: PlayerLeaderRowProp[];
    total_games: number;
    finished_games: number;
    total_goals: number;
    standings: Record<string, GroupStandingsProp>;
    bracket: ChampionshipGameProp[];
    results: ChampionshipGameProp[];
    audit_logs: ChampionshipAuditLogProp[];
    audit_log: ChampionshipAuditLogProp[];
}
