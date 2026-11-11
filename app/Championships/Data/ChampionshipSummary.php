<?php

namespace App\Championships\Data;

final readonly class ChampionshipSummary
{
    /**
     * @param  array<string, GroupStandings>  $standingsByGroup
     * @param  list<PlayerLeaderRow>  $topScorers
     * @param  list<PlayerLeaderRow>  $topAssists
     * @param  list<PlayerLeaderRow>  $topPointLeaders
     */
    public function __construct(
        public array $standingsByGroup,
        public Podium $podium,
        public int $totalGames,
        public int $finishedGames,
        public int $totalGoals,
        public array $topScorers,
        public array $topAssists,
        public array $topPointLeaders,
    ) {}
}
