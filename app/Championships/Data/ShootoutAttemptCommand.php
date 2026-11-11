<?php

namespace App\Championships\Data;

use App\Championships\Enums\TeamSide;

final readonly class ShootoutAttemptCommand
{
    public function __construct(
        public TeamSide $side,
        public int $shooterId,
        public bool $scored,
        public int $roundNumber = 1,
    ) {}
}
