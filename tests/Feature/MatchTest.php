<?php

use App\Models\Goal;
use App\Models\HockeyMatch;
use App\Models\Player;
use App\Support\TeamColors;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../matchHelpers.php';

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
        ->where('sounds.end.file', '/sons/buzzer-fim.wav')
        ->where('team.primary_color', '#166534')
        ->where('team.secondary_color', '#f59e0b'));
    expect(public_path('sons/apito-inicio.wav'))->toBeFile();
});

test('compartilha as cores de Meu time no cronômetro e usa azul e vermelho como fallback quando inválidas', function () {
    [$team] = $this->teamWithPlayer([
        'primary_color' => '#0f766e',
        'secondary_color' => '#eab308',
    ]);

    $this->actingAs($team)->get('/partida')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('matches/Create')
        ->where('team.primary_color', TeamColors::adjustForLightText('#0f766e'))
        ->where('team.secondary_color', '#eab308'));

    $team->forceFill(['primary_color' => '', 'secondary_color' => 'invalida']);

    $this->actingAs($team)->get('/partida')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('matches/Create')
        ->where('team.primary_color', '#2563eb')
        ->where('team.secondary_color', '#dc2626'));
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

test('aceita jogador renomeado no elenco salvo offline', function () {
    [$team, $scorer] = $this->teamWithPlayer();
    $payload = matchPayload($team, $scorer);
    $scorer->update(['name' => 'Nome atualizado depois da partida']);

    $this->actingAs($team)->post('/partidas', $payload)->assertRedirect();
    expect(HockeyMatch::count())->toBe(1);
});

test('não duplica uma partida reenviada com o mesmo uuid do cliente', function () {
    [$team, $scorer] = $this->teamWithPlayer();
    $payload = [...matchPayload($team, $scorer), 'client_uuid' => (string) Str::uuid()];

    $this->actingAs($team)->post('/partidas', $payload)->assertRedirect();
    $this->post('/partidas', $payload)->assertRedirect();
    expect(HockeyMatch::count())->toBe(1);
});

test('a API JSON cria uma partida e reconhece o reenvio pelo uuid', function () {
    [$team, $scorer] = $this->teamWithPlayer();
    $payload = [...matchPayload($team, $scorer), 'client_uuid' => (string) Str::uuid()];

    $this->actingAs($team)
        ->postJson('/partidas', $payload)
        ->assertCreated()
        ->assertJsonPath('duplicate', null)
        ->assertJsonStructure(['id', 'url']);
    $this->postJson('/partidas', $payload)
        ->assertOk()
        ->assertJsonPath('duplicate', true);

    expect(HockeyMatch::count())->toBe(1);
});

test('a API JSON informa erros de validação', function () {
    [$team, $scorer] = $this->teamWithPlayer();
    $payload = matchPayload($team, $scorer);
    $payload['home_score'] = -1;

    $this->actingAs($team)
        ->postJson('/partidas', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('home_score');
});

test('dono exclui partida, gols e suas estatísticas', function () {
    [$team, $scorer] = $this->teamWithPlayer();
    $assist = Player::factory()->for($team)->create(['name' => 'Assistente', 'jersey_number' => 12]);
    $payload = matchPayload($team, $scorer, $assist);
    $payload['goals'][0]['assist_id'] = $assist->id;
    $payload['goals'][0]['assist_name'] = $assist->name;
    $this->actingAs($team)->post('/partidas', $payload)->assertRedirect();
    $match = HockeyMatch::withoutGlobalScopes()->firstOrFail();

    $before = collect($this->get('/estatisticas')->assertOk()->viewData('page')['props']['players']);
    expect($before->firstWhere('id', $scorer->id)['goals'])->toBe(1)
        ->and($before->firstWhere('id', $scorer->id)['points'])->toBe(1)
        ->and($before->firstWhere('id', $assist->id)['assists'])->toBe(1)
        ->and($before->firstWhere('id', $assist->id)['points'])->toBe(1);

    $this->delete("/partidas/$match->id")->assertRedirect('/partidas')->assertSessionHas('success');

    expect(HockeyMatch::withoutGlobalScopes()->find($match->id))->toBeNull()
        ->and(Goal::withoutGlobalScopes()->where('match_id', $match->id)->exists())->toBeFalse();
    $after = collect($this->get('/estatisticas')->assertOk()->viewData('page')['props']['players']);
    expect($after->firstWhere('id', $scorer->id)['goals'])->toBe(0)
        ->and($after->firstWhere('id', $scorer->id)['points'])->toBe(0)
        ->and($after->firstWhere('id', $assist->id)['assists'])->toBe(0)
        ->and($after->firstWhere('id', $assist->id)['points'])->toBe(0);
});

test('não permite excluir partida de outra conta e visitante é redirecionado', function () {
    [$owner, $scorer] = $this->teamWithPlayer();
    $this->actingAs($owner)->post('/partidas', matchPayload($owner, $scorer));
    $match = HockeyMatch::withoutGlobalScopes()->firstOrFail();
    [$other] = $this->teamWithPlayer();

    $this->actingAs($other)->delete("/partidas/$match->id")->assertNotFound();
    expect(HockeyMatch::withoutGlobalScopes()->find($match->id))->not->toBeNull()
        ->and(Goal::withoutGlobalScopes()->where('match_id', $match->id)->exists())->toBeTrue();
    $this->actingAsGuest()->delete("/partidas/$match->id")->assertRedirect('/login');
});

test('exibe a data da partida na listagem no fuso horário de exibição', function () {
    [$team, $scorer] = $this->teamWithPlayer();
    $payload = matchPayload($team, $scorer);
    $payload['started_at'] = '2026-11-19T00:05:00Z';
    $payload['finished_at'] = '2026-11-19T00:30:00Z';

    $this->actingAs($team)->post('/partidas', $payload)->assertRedirect();

    $match = HockeyMatch::withoutGlobalScopes()->firstOrFail();
    expect($match->finished_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-19 00:30:00');

    $this->actingAs($team)
        ->get('/partidas')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('matches/Index')
            ->has('matches', 1)
            ->where('matches.0.id', $match->id)
            ->where('matches.0.finished_at', '18/11/2026 21:30'));
});
