<?php

namespace App\Championships\Data;

final readonly class PlayerLeaderRow
{
    public function __construct(
        public int $playerId,
        public string $playerName,
        public int $jerseyNumber,
        public int $teamId,
        public string $teamName,
        public ?string $teamLogoUrl,
        public int $goals,
        public int $assists,
        public int $points,
        public int $gamesPlayed,
    ) {}
}
