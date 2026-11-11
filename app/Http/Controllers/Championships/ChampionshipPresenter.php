<?php

namespace App\Http\Controllers\Championships;

use App\Championships\Data\ChampionshipSummary;
use App\Championships\Data\GroupStandings;
use App\Championships\Data\PlayerLeaderRow;
use App\Championships\Data\StandingRow;
use App\Championships\Enums\ChampionshipStatus;
use App\Championships\Enums\GamePhase;
use App\Championships\Enums\GameStatus;
use App\Championships\TournamentEngine;
use App\Models\Championship;
use App\Models\ChampionshipAuditLog;
use App\Models\ChampionshipGame;
use App\Models\ChampionshipGameEvent;
use App\Models\ChampionshipPlayer;
use App\Models\ChampionshipTeam;
use Illuminate\Support\Collection;

final readonly class ChampionshipPresenter
{
    public function __construct(
        private TournamentEngine $engine,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function showProps(Championship $championship): array
    {
        $teams = $championship->teams()
            ->with(['players' => fn ($query) => $query->orderBy('jersey_number')->orderBy('name')])
            ->orderBy('name')
            ->get();

        $teamsById = $teams->keyBy('id');

        $allGames = $championship->games()
            ->with(['homeTeam', 'awayTeam', 'winnerTeam'])
            ->orderBy('round_number')
            ->orderBy('bracket_position')
            ->orderBy('id')
            ->get();

        $standingsMap = $this->engine->standings($championship);
        $formattedStandings = $this->formatStandingsMap($standingsMap);
        $statusFlags = $this->computeStatusFlags($championship, $teams, $allGames, $standingsMap);

        $formattedGames = $allGames->map(
            fn (ChampionshipGame $game): array => $this->formatGame($game, $teamsById),
        )->values()->all();

        $gamesByPhase = [];
        foreach ($formattedGames as $gameData)
        {
            $phaseKey = (string) $gameData['phase'];
            $gamesByPhase[$phaseKey][] = $gameData;
        }

        $bracket = array_values(array_filter(
            $formattedGames,
            static fn (array $gameData): bool => $gameData['phase'] !== GamePhase::Group->value,
        ));

        $auditLogs = $this->formatAuditLogs(
            $championship->auditLogs()
                ->with('actor:id,name')
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        );

        $summary = $this->formatSummary($this->engine->summary($championship), $teamsById);

        return [
            'championship' => array_merge($this->formatChampionship($championship), $statusFlags),
            'teams' => $teams->map(fn (ChampionshipTeam $team): array => $this->formatTeam($team))->values()->all(),
            'standings' => $formattedStandings,
            'games' => $gamesByPhase,
            'all_games' => $formattedGames,
            'bracket' => $bracket,
            'audit_logs' => $auditLogs,
            'audit_log' => $auditLogs,
            'status_flags' => $statusFlags,
            'can_edit_rules' => $statusFlags['can_edit_rules'],
            'can_draw' => $statusFlags['can_draw'],
            'can_generate_bracket' => $statusFlags['can_generate_bracket'],
            'summary' => $summary,
            'public_link' => [
                'enabled' => $championship->public_enabled_at !== null && $championship->public_token !== null,
                'url' => $championship->public_enabled_at !== null && $championship->public_token !== null
                    ? route('public.championships.show', ['token' => $championship->public_token])
                    : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function liveGameProps(Championship $championship, ChampionshipGame $game): array
    {
        $game->load([
            'homeTeam.players' => fn ($query) => $query->orderBy('jersey_number')->orderBy('name'),
            'awayTeam.players' => fn ($query) => $query->orderBy('jersey_number')->orderBy('name'),
            'winnerTeam',
        ]);

        $events = $game->events()
            ->whereNull('deleted_at')
            ->with(['primaryPlayer', 'firstAssistPlayer', 'secondAssistPlayer'])
            ->orderBy('id')
            ->get()
            ->map(fn (ChampionshipGameEvent $event): array => $this->formatEvent($event))
            ->values()
            ->all();

        $rules = $championship->rules;
        $clockSettings = [
            'minutes' => $rules->periodMinutes,
            'periods' => $rules->periodsCount,
            'rest_seconds' => $rules->restSeconds,
            'countdown_seconds' => $rules->countdownSeconds,
        ];

        $homeTeamData = $game->homeTeam !== null ? $this->formatTeam($game->homeTeam) : null;
        $awayTeamData = $game->awayTeam !== null ? $this->formatTeam($game->awayTeam) : null;

        $teamsById = collect([$game->homeTeam, $game->awayTeam])
            ->filter()
            ->keyBy('id');

        $gameData = array_merge(
            $this->formatGame($game, $teamsById),
            [
                'home_team' => $homeTeamData,
                'away_team' => $awayTeamData,
                'events' => $events,
                'clock_settings' => $clockSettings,
                'overtime_minutes' => $rules->overtimeMinutes,
                'max_assists' => $rules->maxAssistsPerGoal,
                'max_assists_per_goal' => $rules->maxAssistsPerGoal,
            ],
        );

        return [
            'championship' => $this->formatChampionship($championship),
            'game' => $gameData,
            'teams' => [
                'home' => $homeTeamData,
                'away' => $awayTeamData,
            ],
            'rosters' => [
                'home' => $homeTeamData['players'] ?? [],
                'away' => $awayTeamData['players'] ?? [],
            ],
            'events' => $events,
            'clock_settings' => $clockSettings,
            'overtime_minutes' => $rules->overtimeMinutes,
            'max_assists' => $rules->maxAssistsPerGoal,
            'sounds' => [
                'start' => ['type' => 'builtin', 'file' => '/sons/apito-inicio.wav'],
                'end' => ['type' => 'builtin', 'file' => '/sons/buzzer-fim.wav'],
                'rest_end' => ['type' => 'builtin', 'file' => '/sons/bip-fim-descanso.wav'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryProps(Championship $championship): array
    {
        $teams = $championship->teams()->orderBy('name')->get();
        $teamsById = $teams->keyBy('id');

        $allGames = $championship->games()
            ->with(['homeTeam', 'awayTeam', 'winnerTeam'])
            ->orderBy('round_number')
            ->orderBy('bracket_position')
            ->orderBy('id')
            ->get();

        $formattedGames = $allGames->map(
            fn (ChampionshipGame $game): array => $this->formatGame($game, $teamsById),
        )->values()->all();

        $bracket = array_values(array_filter(
            $formattedGames,
            static fn (array $gameData): bool => $gameData['phase'] !== GamePhase::Group->value,
        ));

        $results = array_values(array_filter(
            $formattedGames,
            static fn (array $gameData): bool => ! $gameData['is_bye'] && $gameData['status'] === GameStatus::Finished->value,
        ));

        $engineSummary = $this->engine->summary($championship);
        $formattedSummary = $this->formatSummary($engineSummary, $teamsById);
        $formattedStandings = $this->formatStandingsMap($engineSummary->standingsByGroup);

        $auditLogs = $this->formatAuditLogs(
            $championship->auditLogs()
                ->with('actor:id,name')
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        );

        return [
            'championship' => $this->formatChampionship($championship),
            'summary' => $formattedSummary,
            'podium' => $formattedSummary['podium'],
            'top_scorers' => $formattedSummary['top_scorers'],
            'topScorers' => $formattedSummary['topScorers'],
            'top_assists' => $formattedSummary['top_assists'],
            'topAssists' => $formattedSummary['topAssists'],
            'top_point_leaders' => $formattedSummary['top_point_leaders'],
            'topPointLeaders' => $formattedSummary['topPointLeaders'],
            'total_games' => $formattedSummary['total_games'],
            'finished_games' => $formattedSummary['finished_games'],
            'total_goals' => $formattedSummary['total_goals'],
            'standings' => $formattedStandings,
            'bracket' => $bracket,
            'results' => $results,
            'audit_logs' => $auditLogs,
            'audit_log' => $auditLogs,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatChampionship(Championship $championship): array
    {
        return [
            'id' => $championship->id,
            'name' => $championship->name,
            'city' => $championship->city,
            'venue' => $championship->venue,
            'starts_on' => $championship->starts_on?->toDateString(),
            'ends_on' => $championship->ends_on?->toDateString(),
            'status' => $championship->status->value,
            'rules' => $championship->rules->toArray(),
            'manual_tie_resolutions' => $championship->manual_tie_resolutions ?? [],
            'drawn_at' => $championship->drawn_at?->toIso8601String(),
            'bracket_generated_at' => $championship->bracket_generated_at?->toIso8601String(),
            'completed_at' => $championship->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatTeam(ChampionshipTeam $team): array
    {
        $players = $team->relationLoaded('players')
            ? $team->players->map(fn (ChampionshipPlayer $p): array => [
                'id' => $p->id,
                'championship_team_id' => $p->championship_team_id,
                'name' => $p->name,
                'jersey_number' => $p->jersey_number,
                'position' => $p->position->value,
                'is_goalkeeper' => (bool) $p->is_goalkeeper,
            ])->values()->all()
            : [];

        return [
            'id' => $team->id,
            'championship_id' => $team->championship_id,
            'name' => $team->name,
            'short_name' => $team->short_name,
            'primary_color' => $team->primary_color,
            'secondary_color' => $team->secondary_color,
            'logo_path' => $team->logo_path,
            'logo_url' => $team->logo_url,
            'group_code' => $team->group_code,
            'group_draw_order' => $team->group_draw_order,
            'seed_pot' => $team->seed_pot,
            'players' => $players,
        ];
    }

    /**
     * @param  Collection<int, ChampionshipTeam>  $teamsById
     * @return array<string, mixed>
     */
    public function formatGame(ChampionshipGame $game, Collection $teamsById): array
    {
        $homeTeam = $game->relationLoaded('homeTeam')
            ? $game->homeTeam
            : ($game->home_team_id !== null ? $teamsById->get($game->home_team_id) : null);
        $awayTeam = $game->relationLoaded('awayTeam')
            ? $game->awayTeam
            : ($game->away_team_id !== null ? $teamsById->get($game->away_team_id) : null);
        $winnerTeam = $game->relationLoaded('winnerTeam')
            ? $game->winnerTeam
            : ($game->winner_team_id !== null ? $teamsById->get($game->winner_team_id) : null);

        return [
            'id' => $game->id,
            'championship_id' => $game->championship_id,
            'phase' => $game->phase->value,
            'phase_label' => $game->phase->label(),
            'group_code' => $game->group_code,
            'round_number' => $game->round_number,
            'leg_number' => $game->leg_number,
            'slot_key' => $game->slot_key,
            'bracket_position' => $game->bracket_position,
            'home_source' => $game->home_source,
            'away_source' => $game->away_source,
            'home_team_id' => $game->home_team_id,
            'away_team_id' => $game->away_team_id,
            'home_team_name' => $homeTeam?->name,
            'away_team_name' => $awayTeam?->name,
            'home_team' => $homeTeam !== null ? [
                'id' => $homeTeam->id,
                'name' => $homeTeam->name,
                'short_name' => $homeTeam->short_name,
                'primary_color' => $homeTeam->primary_color,
                'secondary_color' => $homeTeam->secondary_color,
                'logo_url' => $homeTeam->logo_url,
            ] : null,
            'away_team' => $awayTeam !== null ? [
                'id' => $awayTeam->id,
                'name' => $awayTeam->name,
                'short_name' => $awayTeam->short_name,
                'primary_color' => $awayTeam->primary_color,
                'secondary_color' => $awayTeam->secondary_color,
                'logo_url' => $awayTeam->logo_url,
            ] : null,
            'winner_team_id' => $game->winner_team_id,
            'winner_team_name' => $winnerTeam?->name,
            'venue' => $game->venue,
            'scheduled_at' => $game->scheduled_at?->toIso8601String(),
            'started_at' => $game->started_at?->toIso8601String(),
            'finished_at' => $game->finished_at?->toIso8601String(),
            'status' => $game->status->value,
            'is_bye' => (bool) $game->is_bye,
            'home_regular_score' => $game->home_regular_score,
            'away_regular_score' => $game->away_regular_score,
            'went_to_overtime' => (bool) $game->went_to_overtime,
            'went_to_shootout' => (bool) $game->went_to_shootout,
            'home_shootout_score' => $game->home_shootout_score,
            'away_shootout_score' => $game->away_shootout_score,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEvent(ChampionshipGameEvent $event): array
    {
        return [
            'id' => $event->id,
            'championship_game_id' => $event->championship_game_id,
            'type' => $event->type->value,
            'side' => $event->side?->value,
            'team_id' => $event->team_id,
            'primary_player_id' => $event->primary_player_id,
            'primary_player_name' => $event->primaryPlayer?->name,
            'first_assist_player_id' => $event->first_assist_player_id,
            'first_assist_player_name' => $event->firstAssistPlayer?->name,
            'second_assist_player_id' => $event->second_assist_player_id,
            'second_assist_player_name' => $event->secondAssistPlayer?->name,
            'period' => $event->period,
            'is_overtime' => (bool) $event->is_overtime,
            'elapsed_seconds' => $event->elapsed_seconds,
            'remaining_seconds' => $event->remaining_seconds,
            'shootout_round' => $event->shootout_round,
            'shootout_scored' => $event->shootout_scored,
            'period_mark_type' => $event->period_mark_type,
            'recorded_at' => $event->recorded_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, GroupStandings>  $standingsMap
     * @return array<string, array<string, mixed>>
     */
    private function formatStandingsMap(array $standingsMap): array
    {
        $formatted = [];

        foreach ($standingsMap as $groupCode => $groupStandings)
        {
            $rows = array_map(
                static fn (StandingRow $row): array => [
                    'position' => $row->position,
                    'team_id' => $row->teamId,
                    'teamId' => $row->teamId,
                    'team_name' => $row->teamName,
                    'teamName' => $row->teamName,
                    'team_logo_url' => $row->teamLogoUrl,
                    'teamLogoUrl' => $row->teamLogoUrl,
                    'primary_color' => $row->primaryColor,
                    'primaryColor' => $row->primaryColor,
                    'group_code' => $row->groupCode,
                    'groupCode' => $row->groupCode,
                    'points' => $row->points,
                    'played' => $row->played,
                    'wins' => $row->wins,
                    'draws' => $row->draws,
                    'losses' => $row->losses,
                    'goals_for' => $row->goalsFor,
                    'goalsFor' => $row->goalsFor,
                    'goals_against' => $row->goalsAgainst,
                    'goalsAgainst' => $row->goalsAgainst,
                    'goal_difference' => $row->goalDifference,
                    'goalDifference' => $row->goalDifference,
                    'qualifies' => $row->qualifies,
                    'tied_with_next' => $row->tiedWithNext,
                    'tiedWithNext' => $row->tiedWithNext,
                    'resolved_by_criterion' => $row->resolvedByCriterion,
                    'resolvedByCriterion' => $row->resolvedByCriterion,
                ],
                $groupStandings->rows,
            );

            $formatted[$groupCode] = [
                'group_code' => $groupStandings->groupCode,
                'groupCode' => $groupStandings->groupCode,
                'all_games_finished' => $groupStandings->allGamesFinished,
                'allGamesFinished' => $groupStandings->allGamesFinished,
                'unresolved_tie_groups' => $groupStandings->unresolvedTieGroups,
                'unresolvedTieGroups' => $groupStandings->unresolvedTieGroups,
                'rows' => $rows,
            ];
        }

        return $formatted;
    }

    /**
     * @param  Collection<int, ChampionshipTeam>  $teamsById
     * @return array<string, mixed>
     */
    private function formatSummary(ChampionshipSummary $summary, Collection $teamsById): array
    {
        $mapLeader = static fn (PlayerLeaderRow $row): array => [
            'player_id' => $row->playerId,
            'playerId' => $row->playerId,
            'player_name' => $row->playerName,
            'playerName' => $row->playerName,
            'jersey_number' => $row->jerseyNumber,
            'jerseyNumber' => $row->jerseyNumber,
            'team_id' => $row->teamId,
            'teamId' => $row->teamId,
            'team_name' => $row->teamName,
            'teamName' => $row->teamName,
            'team_logo_url' => $row->teamLogoUrl,
            'teamLogoUrl' => $row->teamLogoUrl,
            'goals' => $row->goals,
            'assists' => $row->assists,
            'points' => $row->points,
            'games_played' => $row->gamesPlayed,
            'gamesPlayed' => $row->gamesPlayed,
        ];

        $topScorers = array_map($mapLeader, $summary->topScorers);
        $topAssists = array_map($mapLeader, $summary->topAssists);
        $topPointLeaders = array_map($mapLeader, $summary->topPointLeaders);

        $championTeam = $summary->podium->championTeamId !== null
            ? $teamsById->get($summary->podium->championTeamId)
            : null;
        $runnerUpTeam = $summary->podium->runnerUpTeamId !== null
            ? $teamsById->get($summary->podium->runnerUpTeamId)
            : null;
        $thirdPlaceTeam = $summary->podium->thirdPlaceTeamId !== null
            ? $teamsById->get($summary->podium->thirdPlaceTeamId)
            : null;

        $podium = [
            'champion_team_id' => $summary->podium->championTeamId,
            'championTeamId' => $summary->podium->championTeamId,
            'champion_team_name' => $championTeam?->name,
            'runner_up_team_id' => $summary->podium->runnerUpTeamId,
            'runnerUpTeamId' => $summary->podium->runnerUpTeamId,
            'runner_up_team_name' => $runnerUpTeam?->name,
            'third_place_team_id' => $summary->podium->thirdPlaceTeamId,
            'thirdPlaceTeamId' => $summary->podium->thirdPlaceTeamId,
            'third_place_team_name' => $thirdPlaceTeam?->name,
        ];

        $standingsByGroup = $this->formatStandingsMap($summary->standingsByGroup);

        return [
            'standings_by_group' => $standingsByGroup,
            'standingsByGroup' => $standingsByGroup,
            'podium' => $podium,
            'total_games' => $summary->totalGames,
            'totalGames' => $summary->totalGames,
            'finished_games' => $summary->finishedGames,
            'finishedGames' => $summary->finishedGames,
            'total_goals' => $summary->totalGoals,
            'totalGoals' => $summary->totalGoals,
            'top_scorers' => $topScorers,
            'topScorers' => $topScorers,
            'top_assists' => $topAssists,
            'topAssists' => $topAssists,
            'top_point_leaders' => $topPointLeaders,
            'topPointLeaders' => $topPointLeaders,
        ];
    }

    /**
     * @param  Collection<int, ChampionshipAuditLog>  $logs
     * @return list<array<string, mixed>>
     */
    private function formatAuditLogs(Collection $logs): array
    {
        return $logs->map(static fn (ChampionshipAuditLog $log): array => [
            'id' => $log->id,
            'championship_id' => $log->championship_id,
            'championship_game_id' => $log->championship_game_id,
            'actor_user_id' => $log->actor_user_id,
            'actor_name' => $log->actor?->name,
            'action' => $log->action,
            'reason' => $log->reason,
            'before_snapshot' => $log->before_snapshot,
            'after_snapshot' => $log->after_snapshot,
            'recorded_at' => $log->recorded_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, ChampionshipTeam>  $teams
     * @param  Collection<int, ChampionshipGame>  $allGames
     * @param  array<string, GroupStandings>  $standingsMap
     * @return array{can_edit_rules: bool, can_draw: bool, can_generate_bracket: bool}
     */
    private function computeStatusFlags(
        Championship $championship,
        Collection $teams,
        Collection $allGames,
        array $standingsMap,
    ): array {
        $rules = $championship->rules;
        $minRequiredTeams = max($rules->groupsCount * 2, $rules->totalQualifiers());

        $canEditRules = $championship->status === ChampionshipStatus::Draft;

        $hasNonScheduledGames = $allGames->contains(
            static fn (ChampionshipGame $g): bool => $g->status !== GameStatus::Scheduled,
        );

        $canDraw = ($championship->status === ChampionshipStatus::Draft && $teams->count() >= $minRequiredTeams)
            || (
                $championship->status === ChampionshipStatus::GroupStage
                && $championship->bracket_generated_at === null
                && ! $hasNonScheduledGames
            );

        $allGroupsFinished = $standingsMap !== [] && collect($standingsMap)->every(
            static fn (GroupStandings $gs): bool => $gs->allGamesFinished,
        );

        $hasStartedKnockout = $allGames->contains(
            static fn (ChampionshipGame $g): bool => $g->phase !== GamePhase::Group
                && ! $g->is_bye
                && $g->status !== GameStatus::Scheduled,
        );

        $canGenerateBracket = $championship->status !== ChampionshipStatus::Draft
            && $allGroupsFinished
            && ! $hasStartedKnockout;

        return [
            'can_edit_rules' => $canEditRules,
            'can_draw' => $canDraw,
            'can_generate_bracket' => $canGenerateBracket,
        ];
    }
}
