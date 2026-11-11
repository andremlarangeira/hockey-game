<?php

use App\Championships\Data\ChampionshipRules;
use App\Championships\Enums\GamePhase;
use App\Championships\Enums\GameStatus;
use App\Championships\Enums\TieBreakCriterion;
use App\Championships\StandingsCalculator;
use App\Models\ChampionshipGame;
use App\Models\ChampionshipTeam;

function makeTeam(int $id, string $name, string $groupCode = 'A', int $drawOrder = 1): ChampionshipTeam
{
    return new ChampionshipTeam([
        'id' => $id,
        'name' => $name,
        'primary_color' => '#2563eb',
        'secondary_color' => '#ffffff',
        'group_code' => $groupCode,
        'group_draw_order' => $drawOrder,
    ]);
}

function makeGroupGame(
    int $homeTeamId,
    int $awayTeamId,
    int $homeScore,
    int $awayScore,
    string $groupCode = 'A',
    GameStatus $status = GameStatus::Finished,
    bool $wentToOvertime = false,
    bool $wentToShootout = false,
    int $homeShootoutScore = 0,
    int $awayShootoutScore = 0,
): ChampionshipGame {
    return new ChampionshipGame([
        'phase' => GamePhase::Group,
        'group_code' => $groupCode,
        'slot_key' => "G-{$groupCode}-L1-{$homeTeamId}-{$awayTeamId}",
        'home_source' => "team:{$homeTeamId}",
        'away_source' => "team:{$awayTeamId}",
        'home_team_id' => $homeTeamId,
        'away_team_id' => $awayTeamId,
        'status' => $status,
        'is_bye' => false,
        'home_regular_score' => $homeScore,
        'away_regular_score' => $awayScore,
        'went_to_overtime' => $wentToOvertime,
        'went_to_shootout' => $wentToShootout,
        'home_shootout_score' => $homeShootoutScore,
        'away_shootout_score' => $awayShootoutScore,
    ]);
}

test('calcula P J V E D GP GC SG apenas de jogos finalizados ignorando agendados e em andamento', function () {
    $rules = ChampionshipRules::defaults(groupsCount: 1, qualifiersPerGroup: 2);
    $calculator = new StandingsCalculator;

    $teams = [
        makeTeam(1, 'Tigres', 'A', 1),
        makeTeam(2, 'Lobos', 'A', 2),
        makeTeam(3, 'Falcões', 'A', 3),
    ];

    $games = [
        makeGroupGame(1, 2, 4, 1, 'A', GameStatus::Finished),
        makeGroupGame(2, 3, 2, 2, 'A', GameStatus::Finished),
        makeGroupGame(1, 3, 5, 0, 'A', GameStatus::Live),
    ];

    $standings = $calculator->calculate($rules, $teams, $games);

    expect($standings)->toHaveKey('A')
        ->and($standings['A']->allGamesFinished)->toBeFalse();

    $rows = $standings['A']->rows;

    expect($rows[0]->teamId)->toBe(1)
        ->and($rows[0]->position)->toBe(1)
        ->and($rows[0]->points)->toBe(3)
        ->and($rows[0]->played)->toBe(1)
        ->and($rows[0]->wins)->toBe(1)
        ->and($rows[0]->draws)->toBe(0)
        ->and($rows[0]->losses)->toBe(0)
        ->and($rows[0]->goalsFor)->toBe(4)
        ->and($rows[0]->goalsAgainst)->toBe(1)
        ->and($rows[0]->goalDifference)->toBe(3)
        ->and($rows[0]->qualifies)->toBeTrue();

    expect($rows[1]->teamId)->toBe(3)
        ->and($rows[1]->points)->toBe(1)
        ->and($rows[1]->draws)->toBe(1)
        ->and($rows[1]->goalDifference)->toBe(0)
        ->and($rows[1]->qualifies)->toBeTrue();

    expect($rows[2]->teamId)->toBe(2)
        ->and($rows[2]->points)->toBe(1)
        ->and($rows[2]->played)->toBe(2)
        ->and($rows[2]->draws)->toBe(1)
        ->and($rows[2]->losses)->toBe(1)
        ->and($rows[2]->goalsFor)->toBe(3)
        ->and($rows[2]->goalsAgainst)->toBe(6)
        ->and($rows[2]->goalDifference)->toBe(-3)
        ->and($rows[2]->qualifies)->toBeFalse();
});

test('respeita pontuação configurável com vitória e derrota na prorrogação ou shootout sem somar shootout em GP/GC', function () {
    $rules = ChampionshipRules::defaults(
        groupsCount: 1,
        qualifiersPerGroup: 2,
        pointsWin: 3,
        pointsDraw: 1,
        pointsLoss: 0,
        allowGroupOvertimeOrShootout: true,
        pointsOvertimeWin: 2,
        pointsOvertimeLoss: 1,
    );

    $calculator = new StandingsCalculator;

    $teams = [
        makeTeam(1, 'Tigres', 'A', 1),
        makeTeam(2, 'Lobos', 'A', 2),
        makeTeam(3, 'Falcões', 'A', 3),
    ];

    $games = [
        makeGroupGame(
            homeTeamId: 1,
            awayTeamId: 2,
            homeScore: 2,
            awayScore: 2,
            groupCode: 'A',
            status: GameStatus::Finished,
            wentToOvertime: true,
            wentToShootout: true,
            homeShootoutScore: 3,
            awayShootoutScore: 1,
        ),
        makeGroupGame(2, 3, 3, 1, 'A', GameStatus::Finished),
        makeGroupGame(
            homeTeamId: 1,
            awayTeamId: 3,
            homeScore: 2,
            awayScore: 1,
            groupCode: 'A',
            status: GameStatus::Finished,
            wentToOvertime: true,
            wentToShootout: false,
        ),
    ];

    $standings = $calculator->calculate($rules, $teams, $games)['A'];

    expect($standings->allGamesFinished)->toBeTrue()
        ->and($standings->rows[0]->teamId)->toBe(1)
        ->and($standings->rows[0]->points)->toBe(4)
        ->and($standings->rows[0]->goalsFor)->toBe(4)
        ->and($standings->rows[0]->goalsAgainst)->toBe(3)
        ->and($standings->rows[0]->resolvedByCriterion)->toBe(TieBreakCriterion::HeadToHead->value)
        ->and($standings->rows[1]->teamId)->toBe(2)
        ->and($standings->rows[1]->points)->toBe(4)
        ->and($standings->rows[1]->goalsFor)->toBe(5)
        ->and($standings->rows[1]->goalsAgainst)->toBe(3)
        ->and($standings->rows[2]->teamId)->toBe(3)
        ->and($standings->rows[2]->points)->toBe(1);
});

test('desempata empate múltiplo via mini-tabela de confronto direto com reaplicação recursiva nos sub-baldes', function () {
    $rules = ChampionshipRules::defaults(
        groupsCount: 1,
        qualifiersPerGroup: 2,
        tieBreakOrder: [
            TieBreakCriterion::Points,
            TieBreakCriterion::HeadToHead,
            TieBreakCriterion::GoalDifference,
            TieBreakCriterion::GoalsFor,
        ],
    );

    $calculator = new StandingsCalculator;

    $teams = [
        makeTeam(1, 'Alfa', 'A', 1),
        makeTeam(2, 'Beta', 'A', 2),
        makeTeam(3, 'Gama', 'A', 3),
        makeTeam(4, 'Delta', 'A', 4),
        makeTeam(5, 'Épsilon', 'A', 5),
        makeTeam(6, 'Zeta', 'A', 6),
    ];

    // 4-way tie on 9 overall points among {1, 2, 3, 4}:
    // In the 4-team H2H mini-table {1, 2, 3, 4}:
    // - Team 1 has 9 pts (beats 2, 3, 4) -> 1st
    // - Team 2 and Team 3 both have 3 pts, 1 V, GP 2, GC 3, SG -1 in the 4-team mini-table -> still tied!
    // - Team 4 has 3 pts, 1 V, GP 2, GC 4, SG -2 -> 4th
    // Recursive HeadToHead on shrunk sub-bucket {2, 3} looks ONLY at game (2 vs 3), which Team 2 won 1x0,
    // placing Team 2 ahead of Team 3 despite Team 3 having a much larger overall goal difference!
    $games = [
        makeGroupGame(2, 3, 1, 0, 'A'),
        makeGroupGame(1, 2, 1, 0, 'A'),
        makeGroupGame(4, 2, 2, 1, 'A'),
        makeGroupGame(1, 3, 3, 1, 'A'),
        makeGroupGame(3, 4, 1, 0, 'A'),
        makeGroupGame(1, 4, 2, 0, 'A'),
        makeGroupGame(5, 1, 1, 0, 'A'),
        makeGroupGame(6, 1, 1, 0, 'A'),
        makeGroupGame(2, 5, 1, 0, 'A'),
        makeGroupGame(2, 6, 1, 0, 'A'),
        makeGroupGame(3, 5, 5, 0, 'A'),
        makeGroupGame(3, 6, 5, 0, 'A'),
        makeGroupGame(4, 5, 2, 0, 'A'),
        makeGroupGame(4, 6, 2, 0, 'A'),
        makeGroupGame(5, 6, 1, 1, 'A'),
    ];

    $standings = $calculator->calculate($rules, $teams, $games)['A'];

    expect($standings->rows[0]->teamId)->toBe(1)
        ->and($standings->rows[0]->points)->toBe(9)
        ->and($standings->rows[1]->teamId)->toBe(2)
        ->and($standings->rows[1]->points)->toBe(9)
        ->and($standings->rows[1]->resolvedByCriterion)->toBe(TieBreakCriterion::HeadToHead->value)
        ->and($standings->rows[2]->teamId)->toBe(3)
        ->and($standings->rows[2]->points)->toBe(9)
        ->and($standings->rows[3]->teamId)->toBe(4)
        ->and($standings->rows[3]->points)->toBe(9);
});

test('aplica critérios de desempate na ordem configurada (saldo, gols pró, vitórias, menos gols contra)', function () {
    $teams = [
        makeTeam(1, 'Time A', 'A', 1),
        makeTeam(2, 'Time B', 'A', 2),
        makeTeam(3, 'Time C', 'A', 3),
    ];

    $games = [
        makeGroupGame(1, 3, 2, 0, 'A'),
        makeGroupGame(2, 3, 3, 1, 'A'),
    ];

    $calculator = new StandingsCalculator;

    $rulesGfFirst = ChampionshipRules::defaults(
        groupsCount: 1,
        qualifiersPerGroup: 2,
        tieBreakOrder: [
            TieBreakCriterion::Points,
            TieBreakCriterion::GoalDifference,
            TieBreakCriterion::GoalsFor,
            TieBreakCriterion::FewestGoalsAgainst,
        ],
    );
    $resGf = $calculator->calculate($rulesGfFirst, $teams, $games)['A'];
    expect($resGf->rows[0]->teamId)->toBe(2)
        ->and($resGf->rows[0]->resolvedByCriterion)->toBe(TieBreakCriterion::GoalsFor->value)
        ->and($resGf->rows[1]->teamId)->toBe(1);

    $rulesGaFirst = ChampionshipRules::defaults(
        groupsCount: 1,
        qualifiersPerGroup: 2,
        tieBreakOrder: [
            TieBreakCriterion::Points,
            TieBreakCriterion::GoalDifference,
            TieBreakCriterion::FewestGoalsAgainst,
            TieBreakCriterion::GoalsFor,
        ],
    );
    $resGa = $calculator->calculate($rulesGaFirst, $teams, $games)['A'];
    expect($resGa->rows[0]->teamId)->toBe(1)
        ->and($resGa->rows[0]->resolvedByCriterion)->toBe(TieBreakCriterion::FewestGoalsAgainst->value)
        ->and($resGa->rows[1]->teamId)->toBe(2);
});

test('sinaliza empate não resolvido e só aplica sorteio manual quando exatamente os mesmos times seguem empatados', function () {
    $rules = ChampionshipRules::defaults(groupsCount: 1, qualifiersPerGroup: 1);
    $calculator = new StandingsCalculator;

    $teams = [
        makeTeam(10, 'Tigres', 'A', 1),
        makeTeam(20, 'Lobos', 'A', 2),
        makeTeam(30, 'Falcões', 'A', 3),
    ];

    $games = [
        makeGroupGame(10, 20, 1, 1, 'A'),
        makeGroupGame(10, 30, 2, 0, 'A'),
        makeGroupGame(20, 30, 2, 0, 'A'),
    ];

    $unresolved = $calculator->calculate($rules, $teams, $games)['A'];
    expect($unresolved->unresolvedTieGroups)->toBe([[10, 20]])
        ->and($unresolved->rows[0]->tiedWithNext)->toBeTrue()
        ->and($unresolved->rows[1]->tiedWithNext)->toBeTrue()
        ->and($unresolved->rows[2]->tiedWithNext)->toBeFalse();

    $staleManual = $calculator->calculate($rules, $teams, $games, ['A' => [20, 10, 30]])['A'];
    expect($staleManual->unresolvedTieGroups)->toBe([[10, 20]])
        ->and($staleManual->rows[0]->teamId)->toBe(10)
        ->and($staleManual->rows[0]->tiedWithNext)->toBeTrue();

    $resolved = $calculator->calculate($rules, $teams, $games, ['A' => [20, 10]])['A'];
    expect($resolved->unresolvedTieGroups)->toBe([])
        ->and($resolved->rows[0]->teamId)->toBe(20)
        ->and($resolved->rows[0]->tiedWithNext)->toBeFalse()
        ->and($resolved->rows[0]->resolvedByCriterion)->toBe(TieBreakCriterion::ManualDraw->value)
        ->and($resolved->rows[0]->qualifies)->toBeTrue()
        ->and($resolved->rows[1]->teamId)->toBe(10)
        ->and($resolved->rows[1]->tiedWithNext)->toBeFalse()
        ->and($resolved->rows[1]->resolvedByCriterion)->toBe(TieBreakCriterion::ManualDraw->value)
        ->and($resolved->rows[1]->qualifies)->toBeFalse();
});

test('classifica os melhores terceiros colocados entre os grupos quando extraBestQualifiers > 0', function () {
    $rules = ChampionshipRules::defaults(
        groupsCount: 3,
        qualifiersPerGroup: 2,
        extraBestQualifiers: 2,
    );

    $calculator = new StandingsCalculator;

    $teams = [
        makeTeam(1, 'A1', 'A', 1),
        makeTeam(2, 'A2', 'A', 2),
        makeTeam(3, 'A3', 'A', 3),
        makeTeam(4, 'A4', 'A', 4),
        makeTeam(5, 'B1', 'B', 1),
        makeTeam(6, 'B2', 'B', 2),
        makeTeam(7, 'B3', 'B', 3),
        makeTeam(8, 'B4', 'B', 4),
        makeTeam(9, 'C1', 'C', 1),
        makeTeam(10, 'C2', 'C', 2),
        makeTeam(11, 'C3', 'C', 3),
        makeTeam(12, 'C4', 'C', 4),
    ];

    $games = [
        makeGroupGame(1, 2, 2, 0, 'A'),
        makeGroupGame(1, 3, 2, 0, 'A'),
        makeGroupGame(1, 4, 2, 0, 'A'),
        makeGroupGame(2, 3, 2, 0, 'A'),
        makeGroupGame(2, 4, 2, 0, 'A'),
        makeGroupGame(3, 4, 4, 0, 'A'),
        makeGroupGame(5, 6, 2, 0, 'B'),
        makeGroupGame(5, 7, 2, 1, 'B'),
        makeGroupGame(5, 8, 2, 0, 'B'),
        makeGroupGame(6, 7, 2, 1, 'B'),
        makeGroupGame(6, 8, 2, 0, 'B'),
        makeGroupGame(7, 8, 3, 0, 'B'),
        makeGroupGame(9, 10, 2, 0, 'C'),
        makeGroupGame(9, 11, 2, 1, 'C'),
        makeGroupGame(9, 12, 3, 0, 'C'),
        makeGroupGame(10, 11, 2, 1, 'C'),
        makeGroupGame(10, 12, 2, 0, 'C'),
        makeGroupGame(11, 12, 1, 1, 'C'),
    ];

    $standings = $calculator->calculate($rules, $teams, $games);

    expect($standings['A']->rows[2]->teamId)->toBe(3)
        ->and($standings['A']->rows[2]->qualifies)->toBeTrue()
        ->and($standings['B']->rows[2]->teamId)->toBe(7)
        ->and($standings['B']->rows[2]->qualifies)->toBeTrue()
        ->and($standings['C']->rows[2]->teamId)->toBe(11)
        ->and($standings['C']->rows[2]->qualifies)->toBeFalse();
});
