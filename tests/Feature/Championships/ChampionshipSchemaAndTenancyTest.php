<?php

use App\Championships\Data\ChampionshipRules;
use App\Championships\Enums\ChampionshipStatus;
use App\Championships\Enums\GameEventType;
use App\Championships\Enums\GamePhase;
use App\Championships\Enums\GameStatus;
use App\Championships\Enums\KnockoutTieResolution;
use App\Championships\Enums\PlayerPosition;
use App\Championships\Enums\TeamSide;
use App\Championships\Enums\TieBreakCriterion;
use App\Models\Championship;
use App\Models\ChampionshipAuditLog;
use App\Models\ChampionshipGame;
use App\Models\ChampionshipGameEvent;
use App\Models\ChampionshipPlayer;
use App\Models\ChampionshipTeam;
use App\Models\Player;
use App\Models\User;
use Illuminate\Database\QueryException;

test('isola todos os 6 modelos de campeonato por conta via BelongsToTeam', function () {
    $tenantA = User::factory()->create();
    $tenantB = User::factory()->create();

    $this->actingAs($tenantA);
    $champA = Championship::factory()->create(['name' => 'Copa Tenant A']);
    $teamA1 = ChampionshipTeam::factory()->for($champA, 'championship')->create(['name' => 'Tigres']);
    $teamA2 = ChampionshipTeam::factory()->for($champA, 'championship')->create(['name' => 'Lobos']);
    $playerA = ChampionshipPlayer::factory()->for($teamA1, 'team')->create([
        'championship_id' => $champA->id,
        'name' => 'Atleta A',
        'jersey_number' => 10,
    ]);
    $gameA = ChampionshipGame::factory()->for($champA, 'championship')->create([
        'slot_key' => 'G-A-L1-1-2',
        'home_team_id' => $teamA1->id,
        'away_team_id' => $teamA2->id,
    ]);
    $eventA = ChampionshipGameEvent::create([
        'championship_id' => $champA->id,
        'championship_game_id' => $gameA->id,
        'type' => GameEventType::Goal,
        'side' => TeamSide::Home,
        'team_id' => $teamA1->id,
        'primary_player_id' => $playerA->id,
        'period' => 1,
        'recorded_at' => now(),
    ]);
    $auditA = ChampionshipAuditLog::create([
        'championship_id' => $champA->id,
        'championship_game_id' => $gameA->id,
        'actor_user_id' => $tenantA->id,
        'action' => 'groups_drawn',
        'reason' => 'Sorteio inicial',
        'recorded_at' => now(),
    ]);

    expect($champA->user_id)->toBe($tenantA->id)
        ->and($teamA1->user_id)->toBe($tenantA->id)
        ->and($playerA->user_id)->toBe($tenantA->id)
        ->and($gameA->user_id)->toBe($tenantA->id)
        ->and($eventA->user_id)->toBe($tenantA->id)
        ->and($auditA->user_id)->toBe($tenantA->id);

    // Switch to tenant B: none of tenant A's records are visible
    $this->actingAs($tenantB);

    expect(Championship::count())->toBe(0)
        ->and(Championship::find($champA->id))->toBeNull()
        ->and(ChampionshipTeam::count())->toBe(0)
        ->and(ChampionshipPlayer::count())->toBe(0)
        ->and(ChampionshipGame::count())->toBe(0)
        ->and(ChampionshipGameEvent::count())->toBe(0)
        ->and(ChampionshipAuditLog::count())->toBe(0);

    // Switch back to tenant A: all records are visible and linked via relations
    $this->actingAs($tenantA);

    expect(Championship::count())->toBe(1)
        ->and($champA->teams()->count())->toBe(2)
        ->and($champA->players()->count())->toBe(1)
        ->and($champA->games()->count())->toBe(1)
        ->and($champA->events()->count())->toBe(1)
        ->and($champA->auditLogs()->count())->toBe(1);
});

test('impede nomes de time duplicados no mesmo campeonato mas permite em campeonatos distintos', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $champ1 = Championship::factory()->create(['name' => 'Copa Verão']);
    $champ2 = Championship::factory()->create(['name' => 'Copa Inverno']);

    ChampionshipTeam::factory()->for($champ1, 'championship')->create(['name' => 'Tigres do Sul']);
    ChampionshipTeam::factory()->for($champ2, 'championship')->create(['name' => 'Tigres do Sul']);

    expect(fn () => ChampionshipTeam::factory()->for($champ1, 'championship')->create(['name' => 'Tigres do Sul']))
        ->toThrow(QueryException::class);
});

test('impede ordem de sorteio duplicada no mesmo grupo do campeonato', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $champ = Championship::factory()->create();

    ChampionshipTeam::factory()->for($champ, 'championship')->create([
        'name' => 'Time 1',
        'group_code' => 'A',
        'group_draw_order' => 1,
    ]);

    expect(fn () => ChampionshipTeam::factory()->for($champ, 'championship')->create([
        'name' => 'Time 2',
        'group_code' => 'A',
        'group_draw_order' => 1,
    ]))->toThrow(QueryException::class);
});

test('impede número de camisa duplicado no mesmo time inscrito mas permite em times diferentes e na pelada', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Casual pickup player wearing #10
    Player::factory()->for($user)->create(['name' => 'Peladeiro Dez', 'jersey_number' => 10, 'level' => 4]);

    $champ = Championship::factory()->create();
    $tigres = ChampionshipTeam::factory()->for($champ, 'championship')->create(['name' => 'Tigres']);
    $lobos = ChampionshipTeam::factory()->for($champ, 'championship')->create(['name' => 'Lobos']);

    ChampionshipPlayer::factory()->for($tigres, 'team')->create([
        'championship_id' => $champ->id,
        'name' => 'Camisa 10 Tigres',
        'jersey_number' => 10,
        'position' => PlayerPosition::Forward,
    ]);

    // Same jersey number #10 on another enrolled team in the same championship is allowed
    $lobosTen = ChampionshipPlayer::factory()->for($lobos, 'team')->create([
        'championship_id' => $champ->id,
        'name' => 'Camisa 10 Lobos',
        'jersey_number' => 10,
        'position' => PlayerPosition::Defender,
    ]);

    expect($lobosTen->jersey_number)->toBe(10);

    // Duplicate jersey number #10 on the same enrolled team is rejected by DB constraint
    expect(fn () => ChampionshipPlayer::factory()->for($tigres, 'team')->create([
        'championship_id' => $champ->id,
        'name' => 'Outro 10 Tigres',
        'jersey_number' => 10,
    ]))->toThrow(QueryException::class);
});

test('impede slot_key duplicado e confronto de grupo duplicado no mesmo campeonato', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $champ1 = Championship::factory()->create();
    $champ2 = Championship::factory()->create();
    $t1 = ChampionshipTeam::factory()->for($champ1, 'championship')->create(['name' => 'Tigres']);
    $t2 = ChampionshipTeam::factory()->for($champ1, 'championship')->create(['name' => 'Lobos']);

    ChampionshipGame::factory()->for($champ1, 'championship')->create([
        'phase' => GamePhase::Group,
        'group_code' => 'A',
        'leg_number' => 1,
        'slot_key' => 'G-A-L1-1-2',
        'home_team_id' => $t1->id,
        'away_team_id' => $t2->id,
    ]);

    // Same slot_key in a different championship is allowed
    ChampionshipGame::factory()->for($champ2, 'championship')->create([
        'phase' => GamePhase::SemiFinal,
        'group_code' => null,
        'slot_key' => 'G-A-L1-1-2',
    ]);

    // Duplicate slot_key in the same championship is rejected
    expect(fn () => ChampionshipGame::factory()->for($champ1, 'championship')->create([
        'phase' => GamePhase::SemiFinal,
        'group_code' => null,
        'slot_key' => 'G-A-L1-1-2',
    ]))->toThrow(QueryException::class);

    // Duplicate group fixture (same phase, group_code, leg_number, home_team_id, away_team_id) is rejected even with different slot_key
    expect(fn () => ChampionshipGame::factory()->for($champ1, 'championship')->create([
        'phase' => GamePhase::Group,
        'group_code' => 'A',
        'leg_number' => 1,
        'slot_key' => 'G-A-L1-ALT',
        'home_team_id' => $t1->id,
        'away_team_id' => $t2->id,
    ]))->toThrow(QueryException::class);
});

test('faz round-trip completo do ChampionshipRulesCast preservando todos os campos e enums', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $customRules = new ChampionshipRules(
        groupsCount: 3,
        qualifiersPerGroup: 2,
        extraBestQualifiers: 2,
        doubleRoundRobin: true,
        pointsWin: 3,
        pointsDraw: 1,
        pointsLoss: 0,
        allowGroupOvertimeOrShootout: true,
        pointsOvertimeWin: 2,
        pointsOvertimeLoss: 1,
        tieBreakOrder: [
            TieBreakCriterion::Points,
            TieBreakCriterion::Wins,
            TieBreakCriterion::HeadToHead,
            TieBreakCriterion::GoalDifference,
            TieBreakCriterion::GoalsFor,
            TieBreakCriterion::FewestGoalsAgainst,
            TieBreakCriterion::ManualDraw,
        ],
        periodMinutes: 15,
        periodsCount: 3,
        restSeconds: 180,
        countdownSeconds: 3,
        overtimeMinutes: 5,
        knockoutTieResolution: KnockoutTieResolution::ShootoutOnly,
        hasThirdPlaceMatch: false,
        maxAssistsPerGoal: 1,
    );

    $championship = Championship::factory()->create([
        'status' => ChampionshipStatus::Draft,
        'rules' => $customRules,
    ]);

    $reloaded = $championship->fresh();

    expect($reloaded)->not->toBeNull()
        ->and($reloaded->status)->toBe(ChampionshipStatus::Draft)
        ->and($reloaded->rules)->toEqual($customRules)
        ->and($reloaded->rules->totalQualifiers())->toBe(8)
        ->and($reloaded->rules->knockoutTieResolution)->toBe(KnockoutTieResolution::ShootoutOnly)
        ->and($reloaded->rules->tieBreakOrder)->toBe([
            TieBreakCriterion::Points,
            TieBreakCriterion::Wins,
            TieBreakCriterion::HeadToHead,
            TieBreakCriterion::GoalDifference,
            TieBreakCriterion::GoalsFor,
            TieBreakCriterion::FewestGoalsAgainst,
            TieBreakCriterion::ManualDraw,
        ]);
});

test('eventos de jogo usam soft delete mantendo histórico para auditoria', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $champ = Championship::factory()->create();
    $game = ChampionshipGame::factory()->for($champ, 'championship')->create([
        'status' => GameStatus::Live,
    ]);

    $event = ChampionshipGameEvent::create([
        'championship_id' => $champ->id,
        'championship_game_id' => $game->id,
        'type' => GameEventType::Goal,
        'side' => TeamSide::Home,
        'period' => 1,
        'recorded_at' => now(),
    ]);

    $event->delete();

    expect($game->events()->count())->toBe(0)
        ->and(ChampionshipGameEvent::withTrashed()->find($event->id)?->deleted_at)->not->toBeNull();
});
