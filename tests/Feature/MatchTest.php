<?php

use App\Models\Goal;
use App\Models\HockeyMatch;
use App\Models\Player;
use Inertia\Testing\AssertableInertia as Assert;

test('salva placar, elenco, gols e tempos no time correto', function () {
    [$team, $scorer] = $this->teamWithPlayer();
    $other = Player::factory()->for($team)->create(['name' => 'Marcador Dois', 'jersey_number' => 11, 'level' => 3]);
    $this->actingAs($team)->post('/partidas', matchPayload($team, $scorer, $other))->assertRedirect();
    $match = HockeyMatch::withoutGlobalScopes()->firstOrFail();
    expect($match->user_id)->toBe($team->id)->and($match->goals)->toHaveCount(1);
    $goal = $match->goals->first();
    expect($goal->user_id)->toBe($team->id)->and((int) $goal->scorer_id)->toBe($scorer->id)
        ->and($goal->assist_id)->toBeNull()->and($goal->remaining_seconds)->toBe(568);
    expect($match->roster->home->name)->toBe('Verdes')->and(count($match->roster->home->players))->toBe(2);
    $this->get("/partidas/$match->id")->assertOk()->assertInertia(fn (Assert $page) => $page->component('matches/Show')->has('goals', 1));
});

test('salva partida sem gol com assistência válida', function () {
    [$team, $scorer] = $this->teamWithPlayer();
    $payload = matchPayload($team, $scorer);
    $payload['home_score'] = 0;
    $payload['goals'] = [];
    $this->actingAs($team)->post('/partidas', $payload);
    expect(HockeyMatch::count())->toBe(1)->and(Goal::count())->toBe(0);
});

test('rejeita placar inconsistente e assistência do próprio autor', function () {
    [$team, $scorer] = $this->teamWithPlayer();
    $payload = matchPayload($team, $scorer);
    $payload['goals'][0]['assist_id'] = $scorer->id;
    $payload['goals'][0]['assist_name'] = $scorer->name;
    $this->actingAs($team)->post('/partidas', $payload)->assertSessionHasErrors('goals.0.assist_id');
    $payload['home_score'] = 2;
    expect(HockeyMatch::count())->toBe(0);
    $this->post('/partidas', $payload)->assertSessionHasErrors(['goals', 'goals.0.assist_id']);
});

test('estatísticas contam gols e assistências', function () {
    [$team, $scorer] = $this->teamWithPlayer();
    $assist = Player::factory()->for($team)->create(['name' => 'Assistente', 'jersey_number' => 12]);
    $payload = matchPayload($team, $scorer, $assist);
    $payload['goals'][0]['assist_id'] = $assist->id;
    $payload['goals'][0]['assist_name'] = $assist->name;
    $this->actingAs($team)->post('/partidas', $payload);
    expect(Goal::withoutGlobalScopes()->first()?->assist_id)->toBe($assist->id);
    $response = $this->get('/estatisticas')->assertOk();
    $stats = collect($response->viewData('page')['props']['players']);
    expect($stats->firstWhere('id', $scorer->id)['goals'])->toBe(1)->and($stats->firstWhere('id', $assist->id)['assists'])->toBe(1)
        ->and($stats->firstWhere('id', $scorer->id)['games'])->toBe(1);
});

test('oferece apito e buzzer grave como sons padrão da partida', function () {
    [$team] = $this->teamWithPlayer();
    $this->actingAs($team)->get('/partida')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('matches/Create')
        ->where('sounds.start.file', '/sons/apito-inicio.wav')
        ->where('sounds.end.file', '/sons/buzzer-fim.wav'));
    expect(public_path('sons/apito-inicio.wav'))->toBeFile();
});

test('salva partida com tempo por período personalizado configurado no placar', function () {
    [$team, $scorer] = $this->teamWithPlayer();
    $payload = matchPayload($team, $scorer);
    $payload['settings']['minutes'] = 2;
    $payload['goals'][0]['remaining_seconds'] = 95;
    $this->actingAs($team)->post('/partidas', $payload)->assertRedirect();
    $match = HockeyMatch::withoutGlobalScopes()->latest('id')->firstOrFail();
    expect($match->settings->minutes)->toBe(2);
});
