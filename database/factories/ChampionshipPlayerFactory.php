<?php

namespace Database\Factories;

use App\Championships\Enums\PlayerPosition;
use App\Models\ChampionshipPlayer;
use App\Models\ChampionshipTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChampionshipPlayer> */
class ChampionshipPlayerFactory extends Factory
{
    protected $model = ChampionshipPlayer::class;

    public function definition(): array
    {
        return [
            'championship_team_id' => ChampionshipTeam::factory(),
            'championship_id' => function (array $attributes): int {
                if (isset($attributes['championship_team_id']) && is_int($attributes['championship_team_id']))
                {
                    return (int) ChampionshipTeam::withoutGlobalScopes()
                        ->whereKey($attributes['championship_team_id'])
                        ->value('championship_id');
                }

                return 0;
            },
            'user_id' => function (array $attributes): int {
                if (isset($attributes['championship_team_id']) && is_int($attributes['championship_team_id']))
                {
                    $ownerId = ChampionshipTeam::withoutGlobalScopes()->whereKey($attributes['championship_team_id'])->value('user_id');
                    if ($ownerId !== null)
                    {
                        return (int) $ownerId;
                    }
                }

                return (int) (auth()->id() ?? User::factory()->create()->id);
            },
            'name' => fake()->name(),
            'jersey_number' => fake()->numberBetween(1, 99),
            'position' => PlayerPosition::Universal,
            'is_goalkeeper' => false,
        ];
    }
}
