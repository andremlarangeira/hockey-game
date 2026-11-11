<?php

namespace App\Championships\Data;

use App\Championships\Enums\TeamSide;

final readonly class GoalCommand
{
    /**
     * @param  list<int>  $assistIds
     */
    public function __construct(
        public TeamSide $side,
        public int $scorerId,
        public array $assistIds = [],
        public int $period = 1,
        public bool $overtime = false,
        public int $elapsedSeconds = 0,
        public int $remainingSeconds = 0,
    ) {}
}
