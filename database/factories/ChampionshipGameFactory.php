<?php

namespace Database\Factories;

use App\Championships\Enums\GamePhase;
use App\Championships\Enums\GameStatus;
use App\Models\Championship;
use App\Models\ChampionshipGame;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChampionshipGame> */
class ChampionshipGameFactory extends Factory
{
    protected $model = ChampionshipGame::class;

    public function definition(): array
    {
        return [
            'championship_id' => Championship::factory(),
            'user_id' => function (array $attributes): int {
                if (isset($attributes['championship_id']) && is_int($attributes['championship_id']))
                {
                    $ownerId = Championship::withoutGlobalScopes()->whereKey($attributes['championship_id'])->value('user_id');
                    if ($ownerId !== null)
                    {
                        return (int) $ownerId;
                    }
                }

                return (int) (auth()->id() ?? User::factory()->create()->id);
            },
            'phase' => GamePhase::Group,
            'group_code' => 'A',
            'round_number' => 1,
            'leg_number' => 1,
            'slot_key' => 'G-A-L1-'.fake()->unique()->numberBetween(1, 9999),
            'bracket_position' => null,
            'home_source' => 'team:1',
            'away_source' => 'team:2',
            'home_team_id' => null,
            'away_team_id' => null,
            'venue' => 'Ginásio Municipal',
            'scheduled_at' => null,
            'status' => GameStatus::Scheduled,
            'is_bye' => false,
            'home_regular_score' => 0,
            'away_regular_score' => 0,
            'went_to_overtime' => false,
            'went_to_shootout' => false,
            'home_shootout_score' => 0,
            'away_shootout_score' => 0,
            'winner_team_id' => null,
        ];
    }
}
