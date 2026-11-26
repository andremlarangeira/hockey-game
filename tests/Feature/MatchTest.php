<?php

use App\Models\Goal;
use App\Models\HockeyMatch;
use App\Models\Player;
use App\Support\TeamColors;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../matchHelpers.php';

/** @param array<mixed> $value */
function assertMatchPropsHaveNoPrivateData(array $value): void
{
    $forbidden = ['user_id', 'email', 'password', 'client_uuid', 'public_token', 'public_enabled_at', 'settings', 'photo_url', 'level'];

    foreach ($value as $key => $item)
    {
        expect($forbidden)->not->toContain((string) $key);

        if (is_array($item))
        {
            assertMatchPropsHaveNoPrivateData($item);
        }
    }
}

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

test('calcula resumo de gols e assistências por jogador ordenado no detalhe da partida e trata casos-limite', function () {
    [$team, $alpha] = $this->teamWithPlayer();
    $alpha->update(['name' => 'Alpha', 'jersey_number' => 7]);
    $bravo = Player::factory()->for($team)->create(['name' => 'Bravo', 'jersey_number' => 11, 'level' => 3]);
    $charlie = Player::factory()->for($team)->create(['name' => 'Charlie', 'jersey_number' => 23, 'level' => 3]);
    $awayScorer = Player::factory()->for($team)->create(['name' => 'Visitante Artilheiro', 'jersey_number' => 99, 'level' => 2]);

    $snake = fn (Player $p): array => [
        'id' => $p->id,
        'name' => $p->name,
        'jersey_number' => $p->jersey_number,
        'level' => (float) $p->level,
        'photo_url' => null,
    ];

    $payload = [
        'started_at' => '2026-11-19T00:05:00Z',
        'finished_at' => '2026-11-19T00:30:00Z',
        'home_score' => 3,
        'away_score' => 1,
        'settings' => ['minutes' => 10, 'periods' => 2, 'rest_seconds' => 120, 'countdown_seconds' => 5],
        'roster' => [
            'home' => ['name' => 'Verdes', 'color' => '#166534', 'players' => [$snake($alpha), $snake($bravo), $snake($charlie)]],
            'away' => ['name' => 'Azuis', 'color' => '#2563eb', 'players' => [$snake($awayScorer)]],
        ],
        'timeline' => [
            ['at' => '2026-11-19T00:05:00Z', 'type' => 'start', 'period' => 1, 'elapsed_seconds' => 0],
            ['at' => '2026-11-19T00:30:00Z', 'type' => 'finish', 'period' => 2, 'elapsed_seconds' => 600],
        ],
        'goals' => [
            [
                'id' => 'gol-1',
                'team' => 'home',
                'scorer_id' => $bravo->id,
                'scorer_name' => $bravo->name,
                'assist_id' => $alpha->id,
                'assist_name' => $alpha->name,
                'period' => 1,
                'elapsed_seconds' => 30,
                'remaining_seconds' => 570,
                'recorded_at' => '2026-11-19T00:06:00Z',
            ],
            [
                'id' => 'gol-2',
                'team' => 'home',
                'scorer_id' => $bravo->id,
                'scorer_name' => $bravo->name,
                'assist_id' => $charlie->id,
                'assist_name' => $charlie->name,
                'period' => 1,
                'elapsed_seconds' => 90,
                'remaining_seconds' => 510,
                'recorded_at' => '2026-11-19T00:10:00Z',
            ],
            [
                'id' => 'gol-3',
                'team' => 'home',
                'scorer_id' => $alpha->id,
                'scorer_name' => $alpha->name,
                'assist_id' => $charlie->id,
                'assist_name' => $charlie->name,
                'period' => 2,
                'elapsed_seconds' => 120,
                'remaining_seconds' => 480,
                'recorded_at' => '2026-11-19T00:20:00Z',
            ],
            [
                'id' => 'gol-4',
                'team' => 'away',
                'scorer_id' => $awayScorer->id,
                'scorer_name' => $awayScorer->name,
                'assist_id' => null,
                'assist_name' => null,
                'period' => 2,
                'elapsed_seconds' => 200,
                'remaining_seconds' => 400,
                'recorded_at' => '2026-11-19T00:25:00Z',
            ],
        ],
    ];

    $this->actingAs($team)->post('/partidas', $payload)->assertRedirect();
    $match = HockeyMatch::withoutGlobalScopes()->latest('id')->firstOrFail();

    // Bravo: 2G 0A (2P), Alpha: 1G 1A (2P), Charlie: 0G 2A (2P — só assistência)
    // Desempate com 2 pontos: mais gols primeiro (Bravo > Alpha > Charlie)
    $response = $this->actingAs($team)->get("/partidas/$match->id")->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('matches/Show')
        ->where('match.finished_at', '18/11/2026 21:30')
        ->where('match.timeline.0.at', '21:05:00')
        ->where('goals.0.recorded_at', '21:06')
        ->where('public_link.enabled', false)
        ->where('public_link.url', null)
        ->has('player_stats.home', 3)
        ->where('player_stats.home.0.name', 'Bravo')
        ->where('player_stats.home.0.jersey_number', 11)
        ->where('player_stats.home.0.goals', 2)
        ->where('player_stats.home.0.assists', 0)
        ->where('player_stats.home.0.points', 2)
        ->where('player_stats.home.1.name', 'Alpha')
        ->where('player_stats.home.1.jersey_number', 7)
        ->where('player_stats.home.1.goals', 1)
        ->where('player_stats.home.1.assists', 1)
        ->where('player_stats.home.1.points', 2)
        ->where('player_stats.home.2.name', 'Charlie')
        ->where('player_stats.home.2.jersey_number', 23)
        ->where('player_stats.home.2.goals', 0)
        ->where('player_stats.home.2.assists', 2)
        ->where('player_stats.home.2.points', 2)
        ->has('player_stats.away', 1)
        ->where('player_stats.away.0.name', 'Visitante Artilheiro')
        ->where('player_stats.away.0.jersey_number', 99)
        ->where('player_stats.away.0.goals', 1)
        ->where('player_stats.away.0.assists', 0)
        ->where('player_stats.away.0.points', 1));

    $authProps = $response->viewData('page')['props'];
    assertMatchPropsHaveNoPrivateData([
        'match' => $authProps['match'],
        'goals' => $authProps['goals'],
        'player_stats' => $authProps['player_stats'],
        'public_link' => $authProps['public_link'],
    ]);

    // Caso-limite: jogador removido (scorer_id null e nome vazio)
    Goal::withoutGlobalScopes()->where('match_id', $match->id)->where('team', 'away')->update([
        'scorer_id' => null,
        'scorer_name' => '   ',
    ]);

    $this->actingAs($team)->get("/partidas/$match->id")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('player_stats.away.0.name', 'Jogador removido')
            ->where('player_stats.away.0.goals', 1)
            ->where('goals.3.scorer_name', 'Jogador removido'));
});

test('partida sem gols retorna listas vazias no resumo de jogadores', function () {
    [$team, $scorer] = $this->teamWithPlayer();
    $payload = matchPayload($team, $scorer);
    $payload['home_score'] = 0;
    $payload['away_score'] = 0;
    $payload['goals'] = [];

    $this->actingAs($team)->post('/partidas', $payload)->assertRedirect();
    $match = HockeyMatch::withoutGlobalScopes()->latest('id')->firstOrFail();

    $this->actingAs($team)->get("/partidas/$match->id")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('matches/Show')
            ->has('goals', 0)
            ->has('player_stats.home', 0)
            ->has('player_stats.away', 0));
});

test('dono ativa, reutiliza, regenera e desativa o link público da partida', function () {
    [$owner, $scorer] = $this->teamWithPlayer();
    $this->actingAs($owner)->post('/partidas', matchPayload($owner, $scorer))->assertRedirect();
    $match = HockeyMatch::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($owner)
        ->post(route('matches.public-link.store', $match))
        ->assertRedirect();

    $match->refresh();
    $firstToken = $match->public_token;
    $firstEnabledAt = $match->public_enabled_at;
    expect($firstToken)->toBeString()->toHaveLength(40)
        ->and($firstEnabledAt)->not->toBeNull();

    // URL presente nas props do detalhe autenticado
    $this->actingAs($owner)
        ->get(route('matches.show', $match))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('public_link.enabled', true)
            ->where('public_link.url', route('public.matches.show', ['token' => $firstToken])));

    // Reativar não troca o token
    $this->actingAs($owner)
        ->post(route('matches.public-link.store', $match))
        ->assertRedirect();
    expect($match->fresh()->public_token)->toBe($firstToken);

    // Regenerar troca o token e invalida o antigo
    $this->actingAs($owner)
        ->post(route('matches.public-link.regenerate', $match))
        ->assertRedirect();

    $secondToken = $match->fresh()->public_token;
    expect($secondToken)->toBeString()->toHaveLength(40)->not->toBe($firstToken);

    $this->actingAsGuest();
    $this->get(route('public.matches.show', $firstToken))->assertNotFound();
    $this->get(route('public.matches.show', $secondToken))->assertOk();

    // Desativar zera token e public_enabled_at
    $this->actingAs($owner)
        ->delete(route('matches.public-link.destroy', $match))
        ->assertRedirect();

    $match->refresh();
    expect($match->public_token)->toBeNull()
        ->and($match->public_enabled_at)->toBeNull();

    $this->actingAsGuest();
    $this->get(route('public.matches.show', $secondToken))->assertNotFound();
});

test('convidado acessa a página pública da partida sem login com headers de privacidade e sem dados privados', function () {
    [$owner, $scorer] = $this->teamWithPlayer();
    $assist = Player::factory()->for($owner)->create(['name' => 'Passador', 'jersey_number' => 18, 'level' => 3]);
    $payload = matchPayload($owner, $scorer, $assist);
    $payload['started_at'] = '2026-11-19T00:05:00Z';
    $payload['finished_at'] = '2026-11-19T00:30:00Z';
    $payload['goals'][0]['assist_id'] = $assist->id;
    $payload['goals'][0]['assist_name'] = $assist->name;

    $this->actingAs($owner)->post('/partidas', $payload)->assertRedirect();
    $match = HockeyMatch::withoutGlobalScopes()->firstOrFail();

    $this->actingAs($owner)->post(route('matches.public-link.store', $match))->assertRedirect();
    $token = (string) $match->fresh()->public_token;

    $this->actingAsGuest();
    $response = $this->get(route('public.matches.show', $token));
    $response->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/matches/Show')
            ->where('match.home_score', 1)
            ->where('match.away_score', 0)
            ->where('match.finished_at', '18/11/2026 21:30')
            ->where('match.roster.home.name', 'Verdes')
            ->where('match.roster.away.name', 'Azuis')
            ->has('match.timeline', 2)
            ->has('goals', 1)
            ->where('goals.0.scorer_name', $scorer->name)
            ->where('goals.0.assist_name', $assist->name)
            ->has('player_stats.home', 2)
            ->has('player_stats.away', 0));

    $props = $response->viewData('page')['props'];
    assertMatchPropsHaveNoPrivateData($props);
    expect($props)->not->toHaveKey('public_link')
        ->and($props['match'])->not->toHaveKey('id');
});

test('rota pública retorna 404 para token inválido ou partida com link desativado e não permite mutações', function () {
    [$owner, $scorer] = $this->teamWithPlayer();
    $otherUser = Player::factory()->make();
    [$foreignTeam] = $this->teamWithPlayer();

    $this->actingAs($owner)->post('/partidas', matchPayload($owner, $scorer))->assertRedirect();
    $match = HockeyMatch::withoutGlobalScopes()->firstOrFail();

    foreach (Route::getRoutes() as $route)
    {
        if (str_starts_with((string) $route->getName(), 'public.matches.'))
        {
            expect($route->methods())->toBe(['GET', 'HEAD']);
        }
    }

    // Token inexistente de 40 caracteres -> 404
    $this->actingAsGuest();
    $this->get('/p/partidas/'.str_repeat('z', 40))->assertNotFound();

    // Token salvo no banco mas public_enabled_at nulo -> 404
    $disabledToken = Str::random(40);
    $match->forceFill(['public_token' => $disabledToken, 'public_enabled_at' => null])->save();
    $this->get('/p/partidas/'.$disabledToken)->assertNotFound();

    // Visitante é redirecionado para login nas rotas de gestão
    $this->post(route('matches.public-link.store', $match))->assertRedirect(route('login'));
    $this->post(route('matches.public-link.regenerate', $match))->assertRedirect(route('login'));
    $this->delete(route('matches.public-link.destroy', $match))->assertRedirect(route('login'));

    // Outro usuário autenticado recebe 404 nas rotas de gestão
    $this->actingAs($foreignTeam);
    $this->post(route('matches.public-link.store', $match))->assertNotFound();
    $this->post(route('matches.public-link.regenerate', $match))->assertNotFound();
    $this->delete(route('matches.public-link.destroy', $match))->assertNotFound();
});
