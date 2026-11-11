<?php

namespace App\Http\Controllers\Championships;

use App\Championships\Data\ChampionshipSummary;
use App\Championships\Data\GroupStandings;
use App\Championships\Data\PlayerLeaderRow;
use App\Championships\Data\StandingRow;
use App\Championships\Enums\GameStatus;
use App\Championships\TournamentEngine;
use App\Models\Championship;
use App\Models\ChampionshipGame;
use App\Models\ChampionshipGameEvent;
use App\Models\ChampionshipPlayer;
use App\Models\ChampionshipTeam;
use Illuminate\Support\Collection;

final readonly class PublicChampionshipPresenter
{
    public function __construct(
        private TournamentEngine $engine,
    ) {}

    /** @return array<string, mixed> */
    public function showProps(Championship $championship): array
    {
        $teams = $this->teams($championship);
        $teamsById = $teams->keyBy('id');
        $games = ChampionshipGame::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->orderBy('round_number')
            ->orderBy('bracket_position')
            ->orderBy('id')
            ->get();

        return [
            'championship' => $this->championship($championship),
            'teams' => $teams->map(fn (ChampionshipTeam $team): array => $this->team($team))->values()->all(),
            'games' => $games->map(fn (ChampionshipGame $game): array => $this->gameData($game, $teamsById))->values()->all(),
            'standings' => $this->standings($this->engine->standings($championship)),
            'stats' => $this->stats($this->engine->summary($championship), $teamsById),
        ];
    }

    /** @return array<string, mixed> */
    public function gameProps(Championship $championship, ChampionshipGame $game): array
    {
        $teams = $this->teams($championship);
        $teamsById = $teams->keyBy('id');
        $events = ChampionshipGameEvent::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->where('championship_game_id', $game->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();
        $playersById = ChampionshipPlayer::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->whereIn(
                'id',
                $events->flatMap(static fn (ChampionshipGameEvent $event): array => array_filter([
                    $event->primary_player_id,
                    $event->first_assist_player_id,
                    $event->second_assist_player_id,
                ]))->unique()->values(),
            )
            ->get()
            ->keyBy('id');

        return [
            'championship' => $this->championship($championship),
            'game' => $this->gameData($game, $teamsById),
            'events' => $events->map(
                fn (ChampionshipGameEvent $event): array => $this->event($event, $playersById),
            )->values()->all(),
            'poll_interval_ms' => $game->status === GameStatus::Live ? 5000 : null,
        ];
    }

    /** @return Collection<int, ChampionshipTeam> */
    private function teams(Championship $championship): Collection
    {
        return ChampionshipTeam::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->orderBy('name')
            ->get();
    }

    /** @return array<string, mixed> */
    private function championship(Championship $championship): array
    {
        return [
            'name' => $championship->name,
            'city' => $championship->city,
            'venue' => $championship->venue,
            'starts_on' => $championship->starts_on?->toDateString(),
            'ends_on' => $championship->ends_on?->toDateString(),
            'status' => $championship->status->value,
            'status_label' => $championship->status->label(),
        ];
    }

    /** @return array<string, mixed> */
    private function team(ChampionshipTeam $team): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'short_name' => $team->short_name,
            'primary_color' => $team->primary_color,
            'secondary_color' => $team->secondary_color,
            'logo_url' => $team->logo_url,
            'group_code' => $team->group_code,
        ];
    }

    /**
     * @param  Collection<int, ChampionshipTeam>  $teamsById
     * @return array<string, mixed>
     */
    private function gameData(ChampionshipGame $game, Collection $teamsById): array
    {
        $homeTeam = $game->home_team_id !== null ? $teamsById->get($game->home_team_id) : null;
        $awayTeam = $game->away_team_id !== null ? $teamsById->get($game->away_team_id) : null;

        return [
            'id' => $game->id,
            'phase' => $game->phase->value,
            'phase_label' => $game->phase->label(),
            'group_code' => $game->group_code,
            'round_number' => $game->round_number,
            'leg_number' => $game->leg_number,
            'bracket_position' => $game->bracket_position,
            'home_source' => $homeTeam === null ? $this->sourceLabel($game->home_source) : null,
            'away_source' => $awayTeam === null ? $this->sourceLabel($game->away_source) : null,
            'home_team' => $homeTeam !== null ? $this->team($homeTeam) : null,
            'away_team' => $awayTeam !== null ? $this->team($awayTeam) : null,
            'status' => $game->status->value,
            'is_bye' => (bool) $game->is_bye,
            'home_regular_score' => $game->home_regular_score,
            'away_regular_score' => $game->away_regular_score,
            'went_to_overtime' => (bool) $game->went_to_overtime,
            'went_to_shootout' => (bool) $game->went_to_shootout,
            'home_shootout_score' => $game->home_shootout_score,
            'away_shootout_score' => $game->away_shootout_score,
            'winner_team_id' => $game->winner_team_id,
            'venue' => $game->venue,
            'scheduled_at' => $game->scheduled_at?->toIso8601String(),
            'started_at' => $game->started_at?->toIso8601String(),
            'finished_at' => $game->finished_at?->toIso8601String(),
        ];
    }

    private function sourceLabel(string $source): string
    {
        if (str_starts_with($source, 'winner:'))
        {
            return 'Vencedor de '.substr($source, 7);
        }

        if (str_starts_with($source, 'loser:'))
        {
            return 'Perdedor de '.substr($source, 6);
        }

        return $source;
    }

    /**
     * @param  array<string, GroupStandings>  $standings
     * @return array<string, array<string, mixed>>
     */
    private function standings(array $standings): array
    {
        $formatted = [];

        foreach ($standings as $groupCode => $group)
        {
            $formatted[$groupCode] = [
                'group_code' => $group->groupCode,
                'all_games_finished' => $group->allGamesFinished,
                'rows' => array_map(static fn (StandingRow $row): array => [
                    'position' => $row->position,
                    'team_id' => $row->teamId,
                    'team_name' => $row->teamName,
                    'team_logo_url' => $row->teamLogoUrl,
                    'primary_color' => $row->primaryColor,
                    'points' => $row->points,
                    'played' => $row->played,
                    'wins' => $row->wins,
                    'draws' => $row->draws,
                    'losses' => $row->losses,
                    'goals_for' => $row->goalsFor,
                    'goals_against' => $row->goalsAgainst,
                    'goal_difference' => $row->goalDifference,
                    'qualifies' => $row->qualifies,
                ], $group->rows),
            ];
        }

        return $formatted;
    }

    /**
     * @param  Collection<int, ChampionshipTeam>  $teamsById
     * @return array<string, mixed>
     */
    private function stats(ChampionshipSummary $summary, Collection $teamsById): array
    {
        $leader = static fn (PlayerLeaderRow $row): array => [
            'player_id' => $row->playerId,
            'player_name' => $row->playerName,
            'jersey_number' => $row->jerseyNumber,
            'team_id' => $row->teamId,
            'team_name' => $row->teamName,
            'team_logo_url' => $row->teamLogoUrl,
            'goals' => $row->goals,
            'assists' => $row->assists,
            'points' => $row->points,
        ];

        return [
            'podium' => [
                'champion_team_id' => $summary->podium->championTeamId,
                'champion_team_name' => $teamsById->get($summary->podium->championTeamId)?->name,
                'runner_up_team_id' => $summary->podium->runnerUpTeamId,
                'runner_up_team_name' => $teamsById->get($summary->podium->runnerUpTeamId)?->name,
                'third_place_team_id' => $summary->podium->thirdPlaceTeamId,
                'third_place_team_name' => $teamsById->get($summary->podium->thirdPlaceTeamId)?->name,
            ],
            'top_scorers' => array_map($leader, $summary->topScorers),
            'top_assists' => array_map($leader, $summary->topAssists),
            'top_point_leaders' => array_map($leader, $summary->topPointLeaders),
            'total_games' => $summary->totalGames,
            'finished_games' => $summary->finishedGames,
            'total_goals' => $summary->totalGoals,
        ];
    }

    /**
     * @param  Collection<int, ChampionshipPlayer>  $playersById
     * @return array<string, mixed>
     */
    private function event(ChampionshipGameEvent $event, Collection $playersById): array
    {
        return [
            'id' => $event->id,
            'type' => $event->type->value,
            'side' => $event->side?->value,
            'team_id' => $event->team_id,
            'primary_player_name' => $playersById->get($event->primary_player_id)?->name,
            'first_assist_player_name' => $playersById->get($event->first_assist_player_id)?->name,
            'second_assist_player_name' => $playersById->get($event->second_assist_player_id)?->name,
            'period' => $event->period,
            'is_overtime' => (bool) $event->is_overtime,
            'elapsed_seconds' => $event->elapsed_seconds,
            'shootout_round' => $event->shootout_round,
            'shootout_scored' => $event->shootout_scored,
            'period_mark_type' => $event->period_mark_type,
        ];
    }
}
