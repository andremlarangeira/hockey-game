<?php

use App\Models\Goal;
use App\Models\HockeyMatch;
use App\Models\Player;

require_once __DIR__.'/../matchHelpers.php';

test('estatísticas somam gols e assistências e ordenam por pontos, gols, assistências e nome', function () {
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

    expect($players->pluck('id')->take(3)->all())->toBe([$second->id, $third->id, $first->id])
        ->and($playersById[$second->id]['points'])->toBe(4)
        ->and($playersById[$second->id]['goals'])->toBe(1)
        ->and($playersById[$second->id]['assists'])->toBe(3)
        ->and($playersById[$third->id]['points'])->toBe(3)
        ->and($playersById[$third->id]['goals'])->toBe(3)
        ->and($playersById[$first->id]['points'])->toBe(2)
        ->and($playersById[$first->id]['goals'])->toBe(2);

    foreach ([$first->id, $second->id, $third->id] as $playerId)
    {
        expect($playersById[$playerId]['points'])->toBe($playersById[$playerId]['goals'] + $playersById[$playerId]['assists']);
    }
});

test('desempata estatísticas com mesmos pontos por gols, assistências e nome em ordem alfabética', function () {
    [$team, $carlos] = $this->teamWithPlayer();
    $carlos->update(['name' => 'Carlos', 'jersey_number' => 10]);
    $bruno = Player::factory()->for($team)->create(['name' => 'Bruno', 'jersey_number' => 11]);
    $diego = Player::factory()->for($team)->create(['name' => 'Diego', 'jersey_number' => 12]);
    $eduardo = Player::factory()->for($team)->create(['name' => 'Eduardo', 'jersey_number' => 13]);

    $this->actingAs($team)->post('/partidas', matchPayload($team, $carlos, $bruno));
    $match = HockeyMatch::withoutGlobalScopes()->firstOrFail();
    Goal::withoutGlobalScopes()->where('match_id', $match->id)->delete();

    // Todos ficam com 2 pontos:
    // - Diego: 2 gols, 0 assistências (2 pts -> 1º pelo critério de mais gols)
    // - Bruno: 1 gol, 1 assistência (2 pts -> 2º por gols=1 e nome 'Bruno' antes de 'Carlos')
    // - Carlos: 1 gol, 1 assistência (2 pts -> 3º por gols=1 e nome 'Carlos')
    // - Eduardo: 0 gols, 2 assistências (2 pts -> 4º por 0 gols e 2 assistências)
    foreach ([
        [$diego, $eduardo],
        [$diego, $eduardo],
        [$bruno, $carlos],
        [$carlos, $bruno],
    ] as [$scorer, $assist])
    {
        Goal::withoutGlobalScopes()->create([
            'user_id' => $team->id,
            'match_id' => $match->id,
            'team' => 'home',
            'scorer_id' => $scorer->id,
            'assist_id' => $assist->id,
            'scorer_name' => $scorer->name,
            'assist_name' => $assist->name,
            'period' => 1,
            'elapsed_seconds' => 30,
            'remaining_seconds' => 570,
            'recorded_at' => now(),
        ]);
    }

    $players = collect($this->get('/estatisticas')->assertOk()->viewData('page')['props']['players']);

    expect($players->pluck('id')->take(4)->all())->toBe([
        $diego->id,
        $bruno->id,
        $carlos->id,
        $eduardo->id,
    ]);
});
