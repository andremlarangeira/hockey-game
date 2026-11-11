<?php

namespace App\Championships\Data;

final readonly class GroupStandings
{
    /**
     * @param  list<StandingRow>  $rows
     * @param  list<list<int>>  $unresolvedTieGroups
     */
    public function __construct(
        public string $groupCode,
        public array $rows,
        public bool $allGamesFinished,
        public array $unresolvedTieGroups,
    ) {}
}
