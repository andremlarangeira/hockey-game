<?php

namespace Database\Factories;

use App\Championships\Data\ChampionshipRules;
use App\Championships\Enums\ChampionshipStatus;
use App\Models\Championship;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Championship> */
class ChampionshipFactory extends Factory
{
    protected $model = Championship::class;

    public function definition(): array
    {
        return [
            'user_id' => fn (): int => (int) (auth()->id() ?? User::factory()->create()->id),
            'name' => 'Copa '.fake()->unique()->city().' de Hóquei',
            'city' => fake()->city(),
            'venue' => 'Ginásio Municipal',
            'starts_on' => '2026-11-15',
            'ends_on' => '2026-11-22',
            'status' => ChampionshipStatus::Draft,
            'rules' => ChampionshipRules::defaults(),
            'manual_tie_resolutions' => [],
        ];
    }
}
