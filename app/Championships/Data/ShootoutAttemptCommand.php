<?php

namespace App\Championships\Data;

use App\Championships\Enums\TeamSide;

final readonly class ShootoutAttemptCommand
{
    public int $shooterId;

    public bool $scored;

    public int $roundNumber;

    public function __construct(
        public TeamSide $side,
        ?int $shooterId = null,
        ?bool $scored = null,
        int $roundNumber = 1,
        ?int $playerId = null,
        ?bool $converted = null,
        ?int $round = null,
    ) {
        $this->shooterId = (int) ($shooterId ?? $playerId ?? 0);
        $this->scored = (bool) ($scored ?? $converted ?? false);
        $this->roundNumber = $round ?? $roundNumber;
    }
}
