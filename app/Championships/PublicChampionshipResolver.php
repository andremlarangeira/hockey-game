<?php

namespace App\Championships;

use App\Models\Championship;
use App\Models\ChampionshipGame;

final class PublicChampionshipResolver
{
    public function championship(string $token): Championship
    {
        return Championship::withoutGlobalScope('team')
            ->where('public_token', $token)
            ->whereNotNull('public_enabled_at')
            ->firstOrFail();
    }

    public function game(Championship $championship, int $gameId): ChampionshipGame
    {
        return ChampionshipGame::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->whereKey($gameId)
            ->firstOrFail();
    }
}
