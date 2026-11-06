<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Player> */
class PlayerFactory extends Factory
{
    protected $model = Player::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'jersey_number' => fake()->unique()->numberBetween(1, 999),
            'level' => fake()->randomElement([1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5, 5]),
            'present' => true,
        ];
    }
}
