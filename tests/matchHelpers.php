<?php

use App\Models\Player;

function matchPayload($team, $scorer, $other = null): array
{
    $usedNumbers = Player::withoutGlobalScopes()->where('user_id', $team->id)->pluck('jersey_number')->map(fn ($number) => (int) $number)->all();
    $opponentNumber = 100;
    while (in_array($opponentNumber, $usedNumbers, true))
    {
        $opponentNumber++;
    }
    $other ??= Player::factory()->for($team)->create(['name' => 'Marcador Dois', 'jersey_number' => 200 + $team->id * 7 + Player::withoutGlobalScopes()->where('user_id', $team->id)->count(), 'level' => 3]);
    $opponent = Player::factory()->for($team)->create(['name' => 'Adversário', 'jersey_number' => $opponentNumber, 'level' => 2]);
    $snake = fn (Player $p): array => ['id' => $p->id, 'name' => $p->name, 'jersey_number' => $p->jersey_number, 'level' => (float) $p->level, 'photo_url' => null];

    return [
        'started_at' => now()->subMinutes(25)->toISOString(), 'finished_at' => now()->toISOString(),
        'home_score' => 1, 'away_score' => 0,
        'settings' => ['minutes' => 10, 'periods' => 2, 'rest_seconds' => 120, 'countdown_seconds' => 5],
        'roster' => [
            'home' => ['name' => 'Verdes', 'color' => '#166534', 'players' => [$snake($scorer), $snake($other)]],
            'away' => ['name' => 'Azuis', 'color' => '#2563eb', 'players' => [$snake($opponent)]],
        ],
        'timeline' => [['at' => now()->subMinutes(25)->toISOString(), 'type' => 'start', 'period' => 1, 'elapsed_seconds' => 0], ['at' => now()->toISOString(), 'type' => 'finish', 'period' => 2, 'elapsed_seconds' => 600]],
        'goals' => [['id' => 'gol-1', 'team' => 'home', 'scorer_id' => $scorer->id, 'scorer_name' => $scorer->name, 'assist_id' => null, 'assist_name' => null, 'period' => 1, 'elapsed_seconds' => 32, 'remaining_seconds' => 568, 'recorded_at' => now()->subMinutes(20)->toISOString()]],
    ];
}
