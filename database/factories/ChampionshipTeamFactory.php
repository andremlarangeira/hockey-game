<?php

namespace Database\Factories;

use App\Models\Championship;
use App\Models\ChampionshipTeam;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChampionshipTeam> */
class ChampionshipTeamFactory extends Factory
{
    protected $model = ChampionshipTeam::class;

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
            'name' => fake()->unique()->company().' HC',
            'short_name' => strtoupper(fake()->lexify('???')),
            'primary_color' => '#2563eb',
            'secondary_color' => '#ffffff',
            'logo_path' => null,
            'group_code' => null,
            'group_draw_order' => null,
            'seed_pot' => null,
        ];
    }
}
