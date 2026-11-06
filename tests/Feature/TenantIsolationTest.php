<?php

use App\Models\HockeyMatch;
use App\Models\Player;
use Inertia\Testing\AssertableInertia as Assert;

test('cada conta só enxerga o próprio elenco', function () {
    [$first, $mine] = $this->teamWithPlayer();
    [$other, $theirs] = $this->teamWithPlayer(['name' => 'Outro time']);
    $theirs->update(['name' => 'Não posso ver']);
    $this->actingAs($first)->get('/jogadores')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('players/Index')->has('players', 1)
        ->where('players.0.name', 'Jogador Um'));
    expect(Player::count())->toBe(1)
        ->and(Player::find($theirs->id))->toBeNull();
});

test('presença, atualização e remoção não podem alterar outro time', function () {
    [$first] = $this->teamWithPlayer();
    [$other, $theirs] = $this->teamWithPlayer();
    $this->actingAs($first);
    $this->patch("/jogadores/$theirs->id/presenca", ['present' => false])->assertNotFound();
    $this->patch("/jogadores/$theirs->id", ['name' => 'Invasão', 'jersey_number' => $theirs->jersey_number, 'level' => 3, 'present' => true])->assertNotFound();
    $this->delete("/jogadores/$theirs->id")->assertNotFound();
    expect($theirs->fresh()->name)->not->toBe('Invasão');
});

require_once __DIR__.'/../matchHelpers.php';

test('uma partida de outro time não abre', function () {
    [$first, $mine] = $this->teamWithPlayer();
    [$other, $theirs] = $this->teamWithPlayer();
    $match = $this->actingAs($other)->post('/partidas', matchPayload($other, $theirs));
    $saved = HockeyMatch::withoutGlobalScopes()->firstOrFail();
    $this->actingAs($first)->get("/partidas/$saved->id")->assertNotFound();
    $payload = matchPayload($first, $mine);
    $payload['roster']['away']['players'][0]['id'] = $theirs->id;
    $payload['goals'][0]['scorer_id'] = $theirs->id;

    $this->actingAs($first)->post('/partidas', $payload)->assertSessionHasErrors('roster');
});
