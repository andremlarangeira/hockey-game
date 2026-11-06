<?php

namespace Tests;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @return array{0: User, 1: Player} */
    protected function teamWithPlayer(array $user = []): array
    {
        $team = User::factory()->create($user);
        $player = Player::factory()->for($team)->create(['name' => 'Jogador Um', 'jersey_number' => 10, 'level' => 4]);

        return [$team, $player];
    }
}
