<?php

namespace App\Championships\Data;

use App\Championships\Enums\TeamSide;

final readonly class GoalCommand
{
    public function __construct(
        public TeamSide $side,
        public int $scorerId,
        public ?int $firstAssistId = null,
        public ?int $secondAssistId = null,
        public int $period = 1,
        public bool $isOvertime = false,
        public int $elapsedSeconds = 0,
        public int $remainingSeconds = 0,
    ) {}
}
