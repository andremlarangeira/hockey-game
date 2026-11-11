<?php

use App\Championships\BracketPlanner;
use App\Championships\Data\ChampionshipRules;
use App\Championships\Data\GroupStandings;
use App\Championships\Data\StandingRow;
use App\Championships\Enums\GamePhase;

function makeStandingRow(
    int $position,
    int $teamId,
    string $groupCode,
    int $points,
    int $wins,
    int $goalDifference,
    int $goalsFor,
    int $goalsAgainst,
    bool $qualifies,
): StandingRow {
    return new StandingRow(
        position: $position,
        teamId: $teamId,
        teamName: "Time {$teamId}",
        teamLogoUrl: null,
        primaryColor: '#2563eb',
        groupCode: $groupCode,
        points: $points,
        played: 3,
        wins: $wins,
        draws: 0,
        losses: 3 - $wins,
        goalsFor: $goalsFor,
        goalsAgainst: $goalsAgainst,
        goalDifference: $goalDifference,
        qualifies: $qualifies,
        tiedWithNext: false,
        resolvedByCriterion: null,
    );
}

test('gera chave de 4 classificados com cruzamento 1A x 2B e 1B x 2A mais 3RD e FINAL', function () {
    $rules = ChampionshipRules::defaults(
        groupsCount: 2,
        qualifiersPerGroup: 2,
        hasThirdPlaceMatch: true,
    );

    $standings = [
        'A' => new GroupStandings('A', [
            makeStandingRow(1, 101, 'A', 9, 3, 6, 8, 2, true),
            makeStandingRow(2, 102, 'A', 6, 2, 2, 5, 3, true),
            makeStandingRow(3, 103, 'A', 0, 0, -8, 1, 9, false),
        ], true, []),
        'B' => new GroupStandings('B', [
            makeStandingRow(1, 201, 'B', 9, 3, 5, 7, 2, true),
            makeStandingRow(2, 202, 'B', 6, 2, 1, 4, 3, true),
            makeStandingRow(3, 203, 'B', 0, 0, -6, 1, 7, false),
        ], true, []),
    ];

    $planner = new BracketPlanner;
    $slots = $planner->buildKnockoutSlots($rules, $standings);

    expect($slots)->toHaveCount(4);

    // SF1: 1A (101) vs 2B (202)
    expect($slots[0]['slot_key'])->toBe('SF1')
        ->and($slots[0]['phase'])->toBe(GamePhase::SemiFinal)
        ->and($slots[0]['round_number'])->toBe(1)
        ->and($slots[0]['home_source'])->toBe('group:A:1')
        ->and($slots[0]['away_source'])->toBe('group:B:2')
        ->and($slots[0]['home_team_id'])->toBe(101)
        ->and($slots[0]['away_team_id'])->toBe(202)
        ->and($slots[0]['is_bye'])->toBeFalse();

    // SF2: 1B (201) vs 2A (102)
    expect($slots[1]['slot_key'])->toBe('SF2')
        ->and($slots[1]['phase'])->toBe(GamePhase::SemiFinal)
        ->and($slots[1]['round_number'])->toBe(1)
        ->and($slots[1]['home_source'])->toBe('group:B:1')
        ->and($slots[1]['away_source'])->toBe('group:A:2')
        ->and($slots[1]['home_team_id'])->toBe(201)
        ->and($slots[1]['away_team_id'])->toBe(102)
        ->and($slots[1]['is_bye'])->toBeFalse();

    // 3RD: loser:SF1 vs loser:SF2
    expect($slots[2]['slot_key'])->toBe('3RD')
        ->and($slots[2]['phase'])->toBe(GamePhase::ThirdPlace)
        ->and($slots[2]['round_number'])->toBe(2)
        ->and($slots[2]['home_source'])->toBe('loser:SF1')
        ->and($slots[2]['away_source'])->toBe('loser:SF2')
        ->and($slots[2]['home_team_id'])->toBeNull()
        ->and($slots[2]['away_team_id'])->toBeNull();

    // FINAL: winner:SF1 vs winner:SF2
    expect($slots[3]['slot_key'])->toBe('FINAL')
        ->and($slots[3]['phase'])->toBe(GamePhase::Final)
        ->and($slots[3]['round_number'])->toBe(2)
        ->and($slots[3]['home_source'])->toBe('winner:SF1')
        ->and($slots[3]['away_source'])->toBe('winner:SF2');
});

test('gera chave de 8 classificados (4 grupos x 2) com quartas, semifinais, 3º lugar e final', function () {
    $rules = ChampionshipRules::defaults(
        groupsCount: 4,
        qualifiersPerGroup: 2,
        hasThirdPlaceMatch: true,
    );

    $standings = [
        'A' => new GroupStandings('A', [
            makeStandingRow(1, 11, 'A', 9, 3, 6, 8, 2, true),
            makeStandingRow(2, 12, 'A', 6, 2, 2, 5, 3, true),
        ], true, []),
        'B' => new GroupStandings('B', [
            makeStandingRow(1, 21, 'B', 9, 3, 5, 7, 2, true),
            makeStandingRow(2, 22, 'B', 6, 2, 1, 4, 3, true),
        ], true, []),
        'C' => new GroupStandings('C', [
            makeStandingRow(1, 31, 'C', 9, 3, 4, 6, 2, true),
            makeStandingRow(2, 32, 'C', 6, 2, 1, 3, 2, true),
        ], true, []),
        'D' => new GroupStandings('D', [
            makeStandingRow(1, 41, 'D', 9, 3, 3, 5, 2, true),
            makeStandingRow(2, 42, 'D', 6, 2, 0, 3, 3, true),
        ], true, []),
    ];

    $planner = new BracketPlanner;
    $slots = $planner->buildKnockoutSlots($rules, $standings);

    expect($slots)->toHaveCount(8);

    $byKey = array_column($slots, null, 'slot_key');

    expect($byKey['QF1']['home_source'])->toBe('group:A:1')
        ->and($byKey['QF1']['away_source'])->toBe('group:B:2')
        ->and($byKey['QF2']['home_source'])->toBe('group:C:1')
        ->and($byKey['QF2']['away_source'])->toBe('group:D:2')
        ->and($byKey['QF3']['home_source'])->toBe('group:B:1')
        ->and($byKey['QF3']['away_source'])->toBe('group:A:2')
        ->and($byKey['QF4']['home_source'])->toBe('group:D:1')
        ->and($byKey['QF4']['away_source'])->toBe('group:C:2')
        ->and($byKey['SF1']['home_source'])->toBe('winner:QF1')
        ->and($byKey['SF1']['away_source'])->toBe('winner:QF2')
        ->and($byKey['SF2']['home_source'])->toBe('winner:QF3')
        ->and($byKey['SF2']['away_source'])->toBe('winner:QF4')
        ->and($byKey['3RD']['home_source'])->toBe('loser:SF1')
        ->and($byKey['3RD']['away_source'])->toBe('loser:SF2')
        ->and($byKey['FINAL']['home_source'])->toBe('winner:SF1')
        ->and($byKey['FINAL']['away_source'])->toBe('winner:SF2');
});

test('arredonda para próxima potência de 2 e atribui byes aos melhores seeds evitando confronto do mesmo grupo', function () {
    // 3 grupos x 2 classificados = 6 classificados -> chave de 8 com 2 byes para os 2 melhores 1ºs colocados
    $rules = ChampionshipRules::defaults(
        groupsCount: 3,
        qualifiersPerGroup: 2,
        hasThirdPlaceMatch: false,
    );

    $standings = [
        'A' => new GroupStandings('A', [
            makeStandingRow(1, 1, 'A', 9, 3, 8, 9, 1, true), // Seed 1 (melhor 1º)
            makeStandingRow(2, 2, 'A', 6, 2, 4, 6, 2, true), // Seed 4 (melhor 2º)
        ], true, []),
        'B' => new GroupStandings('B', [
            makeStandingRow(1, 3, 'B', 9, 3, 6, 7, 1, true), // Seed 2 (2º melhor 1º)
            makeStandingRow(2, 4, 'B', 6, 2, 2, 5, 3, true), // Seed 5 (2º melhor 2º)
        ], true, []),
        'C' => new GroupStandings('C', [
            makeStandingRow(1, 5, 'C', 7, 2, 3, 5, 2, true), // Seed 3 (3º melhor 1º)
            makeStandingRow(2, 6, 'C', 4, 1, 1, 3, 2, true), // Seed 6 (3º melhor 2º)
        ], true, []),
    ];

    $planner = new BracketPlanner;
    $slots = $planner->buildKnockoutSlots($rules, $standings);

    // Sem 3º lugar: 4 QFs + 2 SFs + 1 FINAL = 7 slots
    expect($slots)->toHaveCount(7);

    $byKey = array_column($slots, null, 'slot_key');

    expect($byKey)->not->toHaveKey('3RD')
        ->and($byKey['QF1']['is_bye'])->toBeTrue()
        ->and($byKey['QF1']['home_team_id'])->toBe(1)
        ->and($byKey['QF1']['away_source'])->toBe('bye')
        ->and($byKey['QF1']['away_team_id'])->toBeNull()
        ->and($byKey['QF2']['is_bye'])->toBeTrue()
        ->and($byKey['QF2']['home_team_id'])->toBe(3)
        ->and($byKey['QF2']['away_source'])->toBe('bye')
        ->and($byKey['QF3']['is_bye'])->toBeFalse()
        ->and($byKey['QF4']['is_bye'])->toBeFalse();

    // Em QF3 (home = 1º do C, id 5) o adversário seria Seed 6 (2º do C, id 6), mas o planner troca com Seed 5 (2º do B, id 4)
    // para evitar confronto do mesmo grupo C na 1ª rodada!
    expect($byKey['QF3']['home_team_id'])->toBe(5)
        ->and($byKey['QF3']['away_team_id'])->toBe(4)
        ->and($byKey['QF4']['home_team_id'])->toBe(2)
        ->and($byKey['QF4']['away_team_id'])->toBe(6);
});

test('rejeita tamanhos inválidos de chave ou empates pendentes com DomainException em português', function () {
    $planner = new BracketPlanner;

    $tooSmallRules = ChampionshipRules::defaults(groupsCount: 1, qualifiersPerGroup: 1);
    expect(fn () => $planner->buildKnockoutSlots($tooSmallRules, []))
        ->toThrow(DomainException::class, 'O mata-mata exige entre 2 e 16 equipes classificadas.');

    $tooLargeRules = ChampionshipRules::defaults(groupsCount: 4, qualifiersPerGroup: 4, extraBestQualifiers: 2);
    expect(fn () => $planner->buildKnockoutSlots($tooLargeRules, []))
        ->toThrow(DomainException::class, 'O mata-mata exige entre 2 e 16 equipes classificadas.');

    $rules = ChampionshipRules::defaults(groupsCount: 2, qualifiersPerGroup: 2);
    $unresolvedStandings = [
        'A' => new GroupStandings('A', [
            makeStandingRow(1, 10, 'A', 6, 2, 2, 4, 2, true),
            makeStandingRow(2, 20, 'A', 6, 2, 2, 4, 2, true),
        ], true, [[10, 20]]),
        'B' => new GroupStandings('B', [
            makeStandingRow(1, 30, 'B', 6, 2, 2, 4, 2, true),
            makeStandingRow(2, 40, 'B', 3, 1, 0, 2, 2, true),
        ], true, []),
    ];

    expect(fn () => $planner->buildKnockoutSlots($rules, $unresolvedStandings))
        ->toThrow(DomainException::class, 'Há um empate técnico pendente de definição manual no grupo A.');
});
