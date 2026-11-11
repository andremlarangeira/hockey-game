<?php

namespace App\Championships\Data;

use Carbon\CarbonImmutable;

final readonly class GameScheduleCommand
{
    public function __construct(
        public ?string $venue,
        public ?CarbonImmutable $scheduledAt,
    ) {}
}
