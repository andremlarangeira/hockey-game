<?php

use App\Models\Goal;
use App\Models\HockeyMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Support\MatchPlayersBackfill;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

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

test('caracterização: estatísticas agregam jogos, gols, assistências, pontos, jogadores arquivados, jogadores removidos e isolamento entre usuários', function () {
    [$team, $ana] = $this->teamWithPlayer();
    $ana->update(['name' => 'Ana', 'jersey_number' => 10, 'level' => 4.5, 'present' => true]);
    $bia = Player::factory()->for($team)->create(['name' => 'Bia', 'jersey_number' => 11, 'level' => 4.0, 'present' => true]);
    $archived = Player::factory()->for($team)->create(['name' => 'Veterano', 'jersey_number' => 99, 'level' => 3.5, 'present' => true]);
    $bench = Player::factory()->for($team)->create(['name' => 'Sem Jogo', 'jersey_number' => 50, 'level' => 2.0, 'present' => false]);

    // Partida 1: Ana (2 gols, 1 assist), Bia (1 gol, 2 assists), Veterano (0 gols, 0 assists, mas jogou)
    $snake = fn (Player $p): array => [
        'id' => $p->id,
        'name' => $p->name,
        'jersey_number' => $p->jersey_number,
        'level' => (float) $p->level,
        'photo_url' => null,
    ];

    $match1Payload = [
        'started_at' => now()->subHour()->toISOString(),
        'finished_at' => now()->subMinutes(40)->toISOString(),
        'home_score' => 2,
        'away_score' => 1,
        'settings' => ['minutes' => 10, 'periods' => 2, 'rest_seconds' => 120, 'countdown_seconds' => 5],
        'roster' => [
            'home' => ['name' => 'Verdes', 'color' => '#166534', 'players' => [$snake($ana), $snake($bia)]],
            'away' => ['name' => 'Azuis', 'color' => '#2563eb', 'players' => [$snake($archived)]],
        ],
        'timeline' => [
            ['at' => now()->subHour()->toISOString(), 'type' => 'start', 'period' => 1, 'elapsed_seconds' => 0],
            ['at' => now()->subMinutes(40)->toISOString(), 'type' => 'finish', 'period' => 2, 'elapsed_seconds' => 600],
        ],
        'goals' => [
            [
                'id' => 'g1',
                'team' => 'home',
                'scorer_id' => $ana->id,
                'scorer_name' => $ana->name,
                'assist_id' => $bia->id,
                'assist_name' => $bia->name,
                'period' => 1,
                'elapsed_seconds' => 30,
                'remaining_seconds' => 570,
                'recorded_at' => now()->subMinutes(55)->toISOString(),
            ],
            [
                'id' => 'g2',
                'team' => 'home',
                'scorer_id' => $bia->id,
                'scorer_name' => $bia->name,
                'assist_id' => $ana->id,
                'assist_name' => $ana->name,
                'period' => 1,
                'elapsed_seconds' => 90,
                'remaining_seconds' => 510,
                'recorded_at' => now()->subMinutes(50)->toISOString(),
            ],
            [
                'id' => 'g3',
                'team' => 'away',
                'scorer_id' => $archived->id,
                'scorer_name' => $archived->name,
                'assist_id' => null,
                'assist_name' => null,
                'period' => 2,
                'elapsed_seconds' => 120,
                'remaining_seconds' => 480,
                'recorded_at' => now()->subMinutes(45)->toISOString(),
            ],
        ],
    ];

    $this->actingAs($team)->post('/partidas', $match1Payload)->assertRedirect();

    // Arquiva o Veterano após a partida 1
    $archived->update(['archived_at' => now(), 'present' => false]);

    // Partida 2: Ana + um jogador já removido do cadastro (id inexistente)
    $removedId = 888888;
    $match2Payload = [
        'started_at' => now()->subMinutes(30)->toISOString(),
        'finished_at' => now()->subMinutes(10)->toISOString(),
        'home_score' => 1,
        'away_score' => 1,
        'settings' => ['minutes' => 10, 'periods' => 2, 'rest_seconds' => 120, 'countdown_seconds' => 5],
        'roster' => [
            'home' => ['name' => 'Verdes', 'color' => '#166534', 'players' => [$snake($ana)]],
            'away' => [
                'name' => 'Azuis',
                'color' => '#2563eb',
                'players' => [
                    ['id' => $removedId, 'name' => 'Lenda Removida', 'jersey_number' => 77, 'level' => 4.0, 'photo_url' => null],
                ],
            ],
        ],
        'timeline' => [
            ['at' => now()->subMinutes(30)->toISOString(), 'type' => 'start', 'period' => 1, 'elapsed_seconds' => 0],
            ['at' => now()->subMinutes(10)->toISOString(), 'type' => 'finish', 'period' => 2, 'elapsed_seconds' => 600],
        ],
        'goals' => [
            [
                'id' => 'g4',
                'team' => 'home',
                'scorer_id' => $ana->id,
                'scorer_name' => $ana->name,
                'assist_id' => null,
                'assist_name' => null,
                'period' => 1,
                'elapsed_seconds' => 40,
                'remaining_seconds' => 560,
                'recorded_at' => now()->subMinutes(25)->toISOString(),
            ],
            [
                'id' => 'g5',
                'team' => 'away',
                'scorer_id' => $removedId,
                'scorer_name' => 'Lenda Removida',
                'assist_id' => null,
                'assist_name' => null,
                'period' => 2,
                'elapsed_seconds' => 200,
                'remaining_seconds' => 400,
                'recorded_at' => now()->subMinutes(15)->toISOString(),
            ],
        ],
    ];

    $this->actingAs($team)->post('/partidas', $match2Payload)->assertRedirect();

    // Outro usuário (isolamento de tenant)
    [$otherTeam, $otherPlayer] = $this->teamWithPlayer();
    $otherPlayer->update(['name' => 'Invasor']);
    $this->actingAs($otherTeam)->post('/partidas', matchPayload($otherTeam, $otherPlayer))->assertRedirect();

    // Consulta estatísticas do time original
    $response = $this->actingAs($team)->get('/estatisticas')->assertOk();
    $players = collect($response->viewData('page')['props']['players']);

    // Não deve conter jogadores de outro usuário
    expect($players->pluck('name')->all())->not->toContain('Invasor')
        ->and($players)->toHaveCount(5);

    // Ordem esperada:
    // 1. Ana: 3 pts (2G, 1A, 2J)
    // 2. Bia: 2 pts (1G, 1A, 1J)
    // 3. Lenda Removida: 1 pt (1G, 0A, 1J)
    // 4. Veterano (arquivado): 1 pt (1G, 0A, 1J)
    // 5. Sem Jogo: 0 pts (0G, 0A, 0J)
    expect($players->pluck('name')->all())->toBe([
        'Ana',
        'Bia',
        'Lenda Removida',
        'Veterano',
        'Sem Jogo',
    ]);

    $anaRow = $players->firstWhere('name', 'Ana');
    expect($anaRow)->toMatchArray([
        'id' => $ana->id,
        'name' => 'Ana',
        'jersey_number' => 10,
        'goals' => 2,
        'assists' => 1,
        'points' => 3,
        'games' => 2,
    ]);

    $biaRow = $players->firstWhere('name', 'Bia');
    expect($biaRow)->toMatchArray([
        'id' => $bia->id,
        'name' => 'Bia',
        'jersey_number' => 11,
        'goals' => 1,
        'assists' => 1,
        'points' => 2,
        'games' => 1,
    ]);

    $archivedRow = $players->firstWhere('name', 'Veterano');
    expect($archivedRow)->toMatchArray([
        'id' => $archived->id,
        'name' => 'Veterano',
        'jersey_number' => 99,
        'goals' => 1,
        'assists' => 0,
        'points' => 1,
        'games' => 1,
    ]);

    $removedRow = $players->firstWhere('name', 'Lenda Removida');
    expect($removedRow)->toHaveKeys(['id', 'name', 'jersey_number', 'level', 'present', 'photo_url', 'goals', 'assists', 'points', 'games'])
        ->and($removedRow['name'])->toBe('Lenda Removida')
        ->and($removedRow['goals'])->toBe(1)
        ->and($removedRow['assists'])->toBe(0)
        ->and($removedRow['points'])->toBe(1)
        ->and($removedRow['games'])->toBe(1)
        ->and($removedRow['present'])->toBeFalse()
        ->and($removedRow['photo_url'])->toBeNull();

    $benchRow = $players->firstWhere('name', 'Sem Jogo');
    expect($benchRow)->toMatchArray([
        'id' => $bench->id,
        'name' => 'Sem Jogo',
        'jersey_number' => 50,
        'goals' => 0,
        'assists' => 0,
        'points' => 0,
        'games' => 0,
    ]);
});

test('estatísticas agregam em SQL via goals e match_players sem carregar a tabela matches', function () {
    [$team, $first] = $this->teamWithPlayer();
    $second = Player::factory()->for($team)->create(['name' => 'Bia', 'jersey_number' => 11]);

    $this->actingAs($team)->post('/partidas', matchPayload($team, $first, $second))->assertRedirect();

    $queriesOnMatches = [];
    DB::listen(function (QueryExecuted $query) use (&$queriesOnMatches): void {
        if (preg_match('/\bfrom\s+["`]?matches["`]?\b/i', $query->sql) === 1)
        {
            $queriesOnMatches[] = $query->sql;
        }
    });

    $this->actingAs($team)->get('/estatisticas')->assertOk();

    expect($queriesOnMatches)->toBeEmpty();
});

test('filtro mensal usa os limites UTC do fuso de exibição e a página do jogador respeita o período', function () {
    [$team, $player] = $this->teamWithPlayer();
    $payload = matchPayload($team, $player);

    $this->actingAs($team)->post('/partidas', $payload)->assertRedirect();
    $janMatch = HockeyMatch::withoutGlobalScopes()->latest('id')->firstOrFail();
    $janMatch->forceFill(['finished_at' => '2026-02-01 02:59:59'])->save(); // 31/01 23:59:59 em São Paulo

    $this->actingAs($team)->post('/partidas', $payload)->assertRedirect();
    $febMatch = HockeyMatch::withoutGlobalScopes()->latest('id')->firstOrFail();
    $febMatch->forceFill(['finished_at' => '2026-02-01 03:00:00'])->save(); // 01/02 00:00:00 em São Paulo

    $response = $this->get('/estatisticas?period=month&month=2026-01')->assertOk();
    $props = $response->viewData('page')['props'];
    $row = collect($props['players'])->firstWhere('id', $player->id);

    expect($props['period']['utc_from'])->toBe('2026-01-01 03:00:00')
        ->and($props['period']['utc_to'])->toBe('2026-02-01 02:59:59')
        ->and($row['games'])->toBe(1)
        ->and($row['goals'])->toBe(1);

    $this->get("/estatisticas/jogadores/{$player->id}?period=month&month=2026-01")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('stats/Player')
            ->where('player.games', 1)
            ->where('matches.data.0.id', $janMatch->id));
});

test('presença calcula frequência e sumidos a partir de match_players no período', function () {
    [$team, $present] = $this->teamWithPlayer();
    $absent = Player::factory()->for($team)->create(['name' => 'Ausente', 'jersey_number' => 82]);
    $payload = matchPayload($team, $present);
    $payload['roster']['home']['players'] = [[
        'id' => $present->id, 'name' => $present->name, 'jersey_number' => $present->jersey_number, 'level' => 3, 'photo_url' => null,
    ]];
    $this->actingAs($team)->post('/partidas', $payload)->assertRedirect();

    $response = $this->get('/estatisticas?aba=presenca')->assertOk();
    $attendance = $response->viewData('page')['props']['attendance'];
    $presentRow = collect($attendance['players'])->firstWhere('id', $present->id);
    $absentRow = collect($attendance['players'])->firstWhere('id', $absent->id);

    expect($attendance['total_games'])->toBe(1)
        ->and($presentRow['games'])->toBe(1)
        ->and($presentRow['percentage'])->toBe(100.0)
        ->and($absentRow['games'])->toBe(0)
        ->and($attendance['missing'][0]['id'])->toBe($absent->id);
});

test('perfil do jogador é isolado por conta', function () {
    [$owner, $player] = $this->teamWithPlayer();
    [$other] = $this->teamWithPlayer();

    $this->actingAs($other)->get("/estatisticas/jogadores/{$player->id}")->assertNotFound();
    $this->actingAs($owner)->get("/estatisticas/jogadores/{$player->id}")->assertOk();
});

test('backfill de match_players popula partidas legadas com jogador ativo, arquivado e removido e é idempotente ao rodar duas vezes', function () {
    [$team, $active] = $this->teamWithPlayer();
    $active->update(['name' => 'Ativo']);
    $archived = Player::factory()->for($team)->create([
        'name' => 'Arquivado',
        'jersey_number' => 25,
        'archived_at' => now(),
        'present' => false,
    ]);

    $this->actingAs($team)->post('/partidas', matchPayload($team, $active, $archived))->assertRedirect();
    $match = HockeyMatch::withoutGlobalScopes()->firstOrFail();

    // Simula partida legada na qual o roster continha também um jogador cujo registro foi removido do banco
    $rawRoster = [
        'home' => [
            'name' => 'Verdes',
            'color' => '#166534',
            'players' => [
                ['id' => $active->id, 'name' => 'Ativo', 'jersey_number' => $active->jersey_number, 'level' => 3.0],
                ['id' => $archived->id, 'name' => 'Arquivado', 'jersey_number' => $archived->jersey_number, 'level' => 3.0],
            ],
        ],
        'away' => [
            'name' => 'Azuis',
            'color' => '#2563eb',
            'players' => [
                ['id' => 777777, 'name' => 'Ex-Jogador Removido', 'jersey_number' => 99, 'level' => 3.5],
            ],
        ],
    ];

    DB::table('matches')->where('id', $match->id)->update([
        'roster' => json_encode($rawRoster, JSON_THROW_ON_ERROR),
    ]);
    DB::table('match_players')->where('match_id', $match->id)->delete();

    expect(MatchPlayer::withoutGlobalScopes()->where('match_id', $match->id)->count())->toBe(0);

    // 1ª execução do backfill (com chunk pequeno para exercitar paginação por chunk)
    $migration = require database_path('migrations/2026_11_28_000004_backfill_match_players_from_matches_roster.php');
    $migration->up();

    $rows = MatchPlayer::withoutGlobalScopes()
        ->where('match_id', $match->id)
        ->orderBy('id')
        ->get();

    expect($rows)->toHaveCount(3);

    $activeRow = $rows->firstWhere('player_id', $active->id);
    expect($activeRow)->not->toBeNull()
        ->and($activeRow->user_id)->toBe($team->id)
        ->and($activeRow->player_name)->toBe('Ativo')
        ->and($activeRow->team)->toBe('home')
        ->and($activeRow->present)->toBeTrue();

    $archivedRow = $rows->firstWhere('player_id', $archived->id);
    expect($archivedRow)->not->toBeNull()
        ->and($archivedRow->user_id)->toBe($team->id)
        ->and($archivedRow->player_name)->toBe('Arquivado')
        ->and($archivedRow->team)->toBe('home')
        ->and($archivedRow->present)->toBeTrue();

    $removedRow = $rows->firstWhere('player_id', null);
    expect($removedRow)->not->toBeNull()
        ->and($removedRow->user_id)->toBe($team->id)
        ->and($removedRow->player_name)->toBe('Ex-Jogador Removido')
        ->and($removedRow->team)->toBe('away')
        ->and($removedRow->present)->toBeTrue();

    // 2ª execução do backfill: idempotente, não duplica nem com player_id nulo
    $insertedSecondRun = MatchPlayersBackfill::run(chunkSize: 1);
    $migration->up();

    expect($insertedSecondRun)->toBe(0)
        ->and(MatchPlayer::withoutGlobalScopes()->where('match_id', $match->id)->count())->toBe(3);

    // down() do backfill não apaga dados da partida
    $migration->down();
    expect(HockeyMatch::withoutGlobalScopes()->find($match->id))->not->toBeNull();
});
