<?php

namespace App\Championships;

use App\Championships\Data\Podium;
use App\Championships\Enums\GamePhase;
use App\Championships\Enums\GameStatus;
use App\Models\ChampionshipGame;

final class PodiumResolver
{
    /** @param list<ChampionshipGame> $allGames */
    public function resolve(array $allGames): Podium
    {
        $finalGame = null;
        $thirdPlaceGame = null;
        foreach ($allGames as $game)
        {
            if ($game->phase === GamePhase::Final || $game->slot_key === 'FINAL')
            {
                $finalGame = $game;
            }
            elseif ($game->phase === GamePhase::ThirdPlace || $game->slot_key === '3RD')
            {
                $thirdPlaceGame = $game;
            }
        }

        $championTeamId = null;
        $runnerUpTeamId = null;
        $thirdPlaceTeamId = null;
        if ($finalGame !== null && $finalGame->status === GameStatus::Finished && $finalGame->winner_team_id !== null && $finalGame->home_team_id !== null && $finalGame->away_team_id !== null)
        {
            $championTeamId = (int) $finalGame->winner_team_id;
            $runnerUpTeamId = $championTeamId === (int) $finalGame->home_team_id
                ? (int) $finalGame->away_team_id
                : (int) $finalGame->home_team_id;
        }
        if ($thirdPlaceGame !== null && $thirdPlaceGame->status === GameStatus::Finished && $thirdPlaceGame->winner_team_id !== null)
        {
            $thirdPlaceTeamId = (int) $thirdPlaceGame->winner_team_id;
        }

        return new Podium($championTeamId, $runnerUpTeamId, $thirdPlaceTeamId);
    }
}
