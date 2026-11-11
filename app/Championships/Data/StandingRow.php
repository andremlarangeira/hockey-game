<?php

namespace App\Championships\Data;

final readonly class StandingRow
{
    public function __construct(
        public int $position,
        public int $teamId,
        public string $teamName,
        public ?string $teamLogoUrl,
        public string $primaryColor,
        public string $groupCode,
        public int $points,
        public int $played,
        public int $wins,
        public int $draws,
        public int $losses,
        public int $goalsFor,
        public int $goalsAgainst,
        public int $goalDifference,
        public bool $qualifies,
        public bool $tiedWithNext,
        public ?string $resolvedByCriterion,
    ) {}
}
