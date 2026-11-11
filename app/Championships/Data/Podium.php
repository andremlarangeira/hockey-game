<?php

namespace App\Championships\Data;

final readonly class Podium
{
    public function __construct(
        public ?int $championTeamId,
        public ?int $runnerUpTeamId,
        public ?int $thirdPlaceTeamId,
    ) {}
}
