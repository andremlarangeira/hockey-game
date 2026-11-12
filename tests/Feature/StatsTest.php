<?php

use App\Models\Goal;
use App\Models\HockeyMatch;
use App\Models\Player;

require_once __DIR__.'/../matchHelpers.php';

test('estatísticas somam gols e assistências e ordenam por gols e nome', function () {
    [$team, $first] = $this->teamWithPlayer();
    $first->update(['name' => 'Ana']);
    $second = Player::factory()->for($team)->create(['name' => 'Bia', 'jersey_number' => 11]);
    $third = Player::factory()->for($team)->create(['name' => 'Caio', 'jersey_number' => 12]);

    $this->actingAs($team)->post('/partidas', matchPayload($team, $first, $second));
    $match = HockeyMatch::withoutGlobalScopes()->firstOrFail();

    foreach ([
        [$first->id, null],
        [$second->id, null],
        [$third->id, $second->id],
        [$third->id, $second->id],
        [$third->id, $second->id],
    ] as [$scorerId, $assistId])
    {
        $scorer = Player::withoutGlobalScopes()->findOrFail($scorerId);
        $assist = $assistId ? Player::withoutGlobalScopes()->findOrFail($assistId) : null;
        Goal::withoutGlobalScopes()->create([
            'user_id' => $team->id,
            'match_id' => $match->id,
            'team' => 'home',
            'scorer_id' => $scorer->id,
            'assist_id' => $assist?->id,
            'scorer_name' => $scorer->name,
            'assist_name' => $assist?->name,
            'period' => 1,
            'elapsed_seconds' => 30,
            'remaining_seconds' => 570,
            'recorded_at' => now(),
        ]);
    }

    $players = collect($this->get('/estatisticas')->assertOk()->viewData('page')['props']['players']);
    $playersById = $players->keyBy('id');

    expect($players->pluck('id')->take(3)->all())->toBe([$third->id, $first->id, $second->id])
        ->and($playersById[$third->id]['goals'])->toBe(3)
        ->and($playersById[$first->id]['goals'])->toBe(2)
        ->and($playersById[$second->id]['goals'])->toBe(1)
        ->and($playersById[$second->id]['points'])->toBe(4);

    foreach ([$first->id, $second->id, $third->id] as $playerId)
    {
        expect($playersById[$playerId]['points'])->toBe($playersById[$playerId]['goals'] + $playersById[$playerId]['assists']);
    }
});
