<?php

use App\Championships\Data\ChampionshipRules;
use App\Championships\Data\GameScheduleCommand;
use App\Championships\Data\GoalCommand;
use App\Championships\Data\ShootoutAttemptCommand;
use App\Championships\Enums\ChampionshipStatus;
use App\Championships\Enums\GamePhase;
use App\Championships\Enums\GameStatus;
use App\Championships\Enums\PlayerPosition;
use App\Championships\Enums\TeamSide;
use App\Championships\TournamentEngine;
use App\Models\Championship;
use App\Models\ChampionshipAuditLog;
use App\Models\ChampionshipGame;
use App\Models\ChampionshipGameEvent;
use App\Models\ChampionshipPlayer;
use App\Models\ChampionshipTeam;
use App\Models\Player;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../matchHelpers.php';

/**
 * @return array{
 *     0: array{team: ChampionshipTeam, players: list<ChampionshipPlayer>},
 *     1: array{team: ChampionshipTeam, players: list<ChampionshipPlayer>},
 *     2: array{team: ChampionshipTeam, players: list<ChampionshipPlayer>},
 *     3: array{team: ChampionshipTeam, players: list<ChampionshipPlayer>}
 * }
 */
function enrollFourTeamsWithRosters(TournamentEngine $engine, Championship $championship): array
{
    $specs = [
        ['name' => 'Tigres HC', 'short' => 'TIG', 'pot' => 1],
        ['name' => 'Lobos HC', 'short' => 'LOB', 'pot' => 1],
        ['name' => 'Falcões HC', 'short' => 'FAL', 'pot' => 2],
        ['name' => 'Ursos HC', 'short' => 'URS', 'pot' => 2],
    ];

    $enrolled = [];

    foreach ($specs as $idx => $spec)
    {
        $team = $engine->enrollTeam(
            championship: $championship,
            name: $spec['name'],
            shortName: $spec['short'],
            primaryColor: '#2563eb',
            secondaryColor: '#ffffff',
            seedPot: $spec['pot'],
        );

        $p1 = $engine->addPlayer($team, "Atacante 1 {$spec['short']}", 10, PlayerPosition::Forward);
        $p2 = $engine->addPlayer($team, "Atacante 2 {$spec['short']}", 11, PlayerPosition::Forward);
        $p3 = $engine->addPlayer($team, "Defensor {$spec['short']}", 4, PlayerPosition::Defender);
        $gk = $engine->addPlayer($team, "Goleiro {$spec['short']}", 1, PlayerPosition::Goalkeeper, true);

        $enrolled[$idx] = [
            'team' => $team,
            'players' => [$p1, $p2, $p3, $gk],
        ];
    }

    return $enrolled;
}

test('cria campeonato, atualiza detalhes e trava edição de regras após o sorteio dos grupos', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $engine = app(TournamentEngine::class);

    $championship = $engine->createChampionship(
        user: $user,
        name: 'Copa Municipal de Hóquei',
        city: 'São Paulo',
        venue: 'Ginásio Ibirapuera',
        startsOn: CarbonImmutable::parse('2026-11-15'),
        endsOn: CarbonImmutable::parse('2026-11-22'),
        rules: ChampionshipRules::defaults(groupsCount: 2, qualifiersPerGroup: 2),
    );

    expect($championship->status)->toBe(ChampionshipStatus::Draft)
        ->and($championship->name)->toBe('Copa Municipal de Hóquei')
        ->and($championship->city)->toBe('São Paulo')
        ->and($championship->venue)->toBe('Ginásio Ibirapuera');

    $engine->updateDetails(
        championship: $championship,
        name: 'Copa Municipal Na Quadra',
        city: 'Campinas',
        venue: 'Arena Central',
        startsOn: '2026-11-16',
        endsOn: '2026-11-23',
    );

    expect($championship->fresh()->name)->toBe('Copa Municipal Na Quadra')
        ->and($championship->fresh()->city)->toBe('Campinas')
        ->and($championship->fresh()->venue)->toBe('Arena Central');

    $updatedRules = ChampionshipRules::defaults(
        groupsCount: 2,
        qualifiersPerGroup: 2,
        doubleRoundRobin: true,
        maxAssistsPerGoal: 1,
    );
    $engine->updateRules($championship, $updatedRules);

    expect($championship->fresh()->rules->doubleRoundRobin)->toBeTrue()
        ->and($championship->fresh()->rules->maxAssistsPerGoal)->toBe(1);

    enrollFourTeamsWithRosters($engine, $championship);
    $engine->drawGroups($championship, actor: $user);

    expect($championship->fresh()->status)->toBe(ChampionshipStatus::GroupStage);

    expect(fn () => $engine->updateRules($championship, ChampionshipRules::defaults()))
        ->toThrow(DomainException::class, 'As regras do campeonato estão travadas');
});

test('gerencia inscrição, atualização e remoção de equipes com upload de escudo raster, exclusão do antigo e rejeição de SVG', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    $engine = app(TournamentEngine::class);
    $championship = $engine->createChampionship($user, 'Copa Escudos', 'Curitiba');

    $firstLogo = UploadedFile::fake()->image('tigres.png', 200, 200);
    $team = $engine->enrollTeam(
        championship: $championship,
        name: 'Tigres do Paraná',
        shortName: 'TIG',
        primaryColor: '#1d4ed8',
        secondaryColor: '#f8fafc',
        logo: $firstLogo,
        seedPot: 1,
    );

    expect($team->logo_path)->not->toBeNull()
        ->and($team->logo_path)->toStartWith("championships/{$user->id}/logos/");
    Storage::disk('public')->assertExists($team->logo_path);

    $oldLogoPath = $team->logo_path;
    $secondLogo = UploadedFile::fake()->image('tigres-novo.webp', 200, 200);

    $engine->updateTeam(
        team: $team,
        name: 'Tigres Curitiba HC',
        shortName: 'TCHC',
        primaryColor: '#0f172a',
        secondaryColor: '#ffffff',
        logo: $secondLogo,
        seedPot: 2,
    );

    expect($team->fresh()->name)->toBe('Tigres Curitiba HC')
        ->and($team->fresh()->short_name)->toBe('TCHC')
        ->and($team->fresh()->seed_pot)->toBe(2)
        ->and($team->fresh()->logo_path)->not->toBe($oldLogoPath);

    Storage::disk('public')->assertMissing($oldLogoPath);
    Storage::disk('public')->assertExists($team->fresh()->logo_path);

    // Rejects SVG upload and preserves current raster logo
    $currentLogoPath = $team->fresh()->logo_path;
    $svgFile = UploadedFile::fake()->create('malicioso.svg', 12, 'image/svg+xml');

    expect(fn () => $engine->updateTeam(team: $team, logo: $svgFile))
        ->toThrow(DomainException::class, 'SVG');
    Storage::disk('public')->assertExists($currentLogoPath);

    expect(fn () => $engine->enrollTeam(
        championship: $championship,
        name: 'Equipe SVG',
        shortName: 'SVG',
        primaryColor: '#000000',
        secondaryColor: '#ffffff',
        logo: $svgFile,
    ))->toThrow(DomainException::class, 'SVG');

    // Removing team in draft deletes its logo from disk
    $engine->removeTeam($team);
    Storage::disk('public')->assertMissing($currentLogoPath);
    expect(ChampionshipTeam::count())->toBe(0);

    // Once groups are drawn, enrollTeam and removeTeam are blocked
    $enrolled = enrollFourTeamsWithRosters($engine, $championship);
    $engine->drawGroups($championship, actor: $user);

    expect(fn () => $engine->enrollTeam($championship, 'Quinto Time'))
        ->toThrow(DomainException::class, 'Não é possível inscrever novas equipes após o sorteio');

    expect(fn () => $engine->removeTeam($enrolled[0]['team']))
        ->toThrow(DomainException::class, 'Equipes não podem ser removidas após o sorteio');
});

test('gerencia elenco da equipe e bloqueia remoção de jogador com eventos ativos permitindo após soft delete', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $engine = app(TournamentEngine::class);
    $championship = $engine->createChampionship($user, 'Copa Elencos', 'Santos');
    $enrolled = enrollFourTeamsWithRosters($engine, $championship);

    $tigres = $enrolled[0]['team'];
    $scorer = $enrolled[0]['players'][0];

    // Duplicate jersey on the same team is rejected by domain
    expect(fn () => $engine->addPlayer($tigres, 'Duplicado', $scorer->jersey_number))
        ->toThrow(DomainException::class, 'já está em uso nesta equipe');

    // Update player details
    $engine->updatePlayer($scorer, name: 'Artilheiro Tigres', jerseyNumber: 99, position: PlayerPosition::Forward);
    expect($scorer->fresh()->name)->toBe('Artilheiro Tigres')
        ->and($scorer->fresh()->jersey_number)->toBe(99);

    $engine->drawGroups($championship, actor: $user);

    // Can still add a new player during group stage
    $extraPlayer = $engine->addPlayer($tigres, 'Reforço Fase de Grupos', 77, PlayerPosition::Defender);
    expect($extraPlayer->jersey_number)->toBe(77);

    // Start a game and record a goal for $scorer
    $game = $championship->games()->where(function ($q) use ($tigres) {
        $q->where('home_team_id', $tigres->id)->orWhere('away_team_id', $tigres->id);
    })->firstOrFail();

    $engine->startGame($game, $user);

    $side = (int) $game->home_team_id === (int) $tigres->id ? TeamSide::Home : TeamSide::Away;
    $goalEvent = $engine->recordGoal($game, new GoalCommand(
        side: $side,
        scorerId: $scorer->id,
        period: 1,
        elapsedSeconds: 45,
        remainingSeconds: 555,
    ));

    // Cannot remove player with non-deleted events
    expect(fn () => $engine->removePlayer($scorer))
        ->toThrow(DomainException::class, 'Não é possível remover um jogador que possui gols');

    // Can remove player without events
    $engine->removePlayer($extraPlayer);
    expect(ChampionshipPlayer::find($extraPlayer->id))->toBeNull();

    // After soft-deleting the goal event, the player can be removed
    $engine->removeEvent($goalEvent, $user, 'Gol anulado');
    $engine->removePlayer($scorer);
    expect(ChampionshipPlayer::find($scorer->id))->toBeNull();
});

test('sorteia grupos com potes, gera turno e returno sem duplicatas invertidas, mantém idempotência e bloqueia refazer após jogo iniciar', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $engine = app(TournamentEngine::class);

    // Validation: too few teams for 2 groups
    $champSmall = $engine->createChampionship($user, 'Copa Pequena', 'Sorocaba');
    $engine->enrollTeam($champSmall, 'Time 1');
    $engine->enrollTeam($champSmall, 'Time 2');
    $engine->enrollTeam($champSmall, 'Time 3');

    expect(fn () => $engine->drawGroups($champSmall, actor: $user))
        ->toThrow(DomainException::class, 'pelo menos 4 equipes');

    // Full 6-team championship across 2 groups with doubleRoundRobin = true and seed pots
    $championship = $engine->createChampionship(
        user: $user,
        name: 'Copa Turno e Returno',
        city: 'São José',
        rules: ChampionshipRules::defaults(
            groupsCount: 2,
            qualifiersPerGroup: 2,
            doubleRoundRobin: true,
        ),
    );

    $pot1A = $engine->enrollTeam($championship, 'Cabeça 1', 'C1', seedPot: 1);
    $pot1B = $engine->enrollTeam($championship, 'Cabeça 2', 'C2', seedPot: 1);
    $pot2A = $engine->enrollTeam($championship, 'Pote2 A', 'P2A', seedPot: 2);
    $pot2B = $engine->enrollTeam($championship, 'Pote2 B', 'P2B', seedPot: 2);
    $pot3A = $engine->enrollTeam($championship, 'Pote3 A', 'P3A', seedPot: 3);
    $pot3B = $engine->enrollTeam($championship, 'Pote3 B', 'P3B', seedPot: 3);

    $engine->drawGroups($championship, actor: $user);

    expect($championship->fresh()->status)->toBe(ChampionshipStatus::GroupStage)
        ->and($championship->fresh()->drawn_at)->not->toBeNull();

    // Pot 1 teams must be separated into distinct groups (A and B)
    expect($pot1A->fresh()->group_code)->not->toBe($pot1B->fresh()->group_code)
        ->and($pot2A->fresh()->group_code)->not->toBe($pot2B->fresh()->group_code)
        ->and($pot3A->fresh()->group_code)->not->toBe($pot3B->fresh()->group_code);

    // 2 groups of 3 teams -> 3 games per leg per group -> 6 games per group -> 12 games total
    $games = $championship->games()->orderBy('id')->get();
    expect($games)->toHaveCount(12);

    // Verify deterministic slot_key G-<group>-L<leg>-<min>-<max> and inverted home/away on leg 2
    foreach (['A', 'B'] as $groupCode)
    {
        $leg1Games = $games->where('group_code', $groupCode)->where('leg_number', 1)->values();
        $leg2Games = $games->where('group_code', $groupCode)->where('leg_number', 2)->values();

        expect($leg1Games)->toHaveCount(3)
            ->and($leg2Games)->toHaveCount(3);

        foreach ($leg1Games as $l1)
        {
            $minId = min((int) $l1->home_team_id, (int) $l1->away_team_id);
            $maxId = max((int) $l1->home_team_id, (int) $l1->away_team_id);
            expect($l1->slot_key)->toBe("G-{$groupCode}-L1-{$minId}-{$maxId}");

            // Reverse-duplicate fixture in the same leg is impossible because slot_key uses ordered min-max IDs
            expect(fn () => ChampionshipGame::create([
                'user_id' => $user->id,
                'championship_id' => $championship->id,
                'phase' => GamePhase::Group,
                'group_code' => $groupCode,
                'round_number' => 99,
                'leg_number' => 1,
                'slot_key' => "G-{$groupCode}-L1-{$minId}-{$maxId}",
                'home_source' => "team:{$l1->away_team_id}",
                'away_source' => "team:{$l1->home_team_id}",
                'home_team_id' => $l1->away_team_id,
                'away_team_id' => $l1->home_team_id,
                'status' => GameStatus::Scheduled,
            ]))->toThrow(QueryException::class);

            // Matching leg 2 game has swapped home and away teams
            $matchingL2 = $leg2Games->firstWhere('slot_key', "G-{$groupCode}-L2-{$minId}-{$maxId}");
            expect($matchingL2)->not->toBeNull()
                ->and($matchingL2->home_team_id)->toBe($l1->away_team_id)
                ->and($matchingL2->away_team_id)->toBe($l1->home_team_id);
        }
    }

    // Idempotent draw / refresh does not change groups or game IDs
    $groupsSnapshot = $championship->teams()->orderBy('id')->pluck('group_code', 'id')->all();
    $ordersSnapshot = $championship->teams()->orderBy('id')->pluck('group_draw_order', 'id')->all();
    $gameIdsSnapshot = $championship->games()->orderBy('id')->pluck('id')->all();

    $engine->drawGroups($championship, actor: $user, redraw: false);

    expect($championship->teams()->orderBy('id')->pluck('group_code', 'id')->all())->toBe($groupsSnapshot)
        ->and($championship->teams()->orderBy('id')->pluck('group_draw_order', 'id')->all())->toBe($ordersSnapshot)
        ->and($championship->games()->orderBy('id')->pluck('id')->all())->toBe($gameIdsSnapshot)
        ->and($championship->auditLogs()->where('action', 'groups_drawn')->count())->toBe(1);

    // Redraw before any game starts requires a reason and audits 'groups_redrawn'
    expect(fn () => $engine->drawGroups($championship, actor: $user, redraw: true, reason: ''))
        ->toThrow(DomainException::class, 'Informe o motivo');

    $engine->drawGroups($championship, actor: $user, redraw: true, reason: 'Novo sorteio solicitado pelos representantes');

    expect($championship->auditLogs()->where('action', 'groups_redrawn')->count())->toBe(1)
        ->and($championship->games()->count())->toBe(12);

    // Once a game starts (scheduled -> live), redraw is blocked
    $firstGame = $championship->games()->firstOrFail();
    $engine->startGame($firstGame, $user);

    expect(fn () => $engine->drawGroups($championship, actor: $user, redraw: true, reason: 'Tentativa após início'))
        ->toThrow(DomainException::class, 'Não é possível refazer o sorteio após o início dos jogos.');
});

test('agenda, inicia partida, valida regras de gols e assistências, recalcula placar e audita remoção de evento', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $engine = app(TournamentEngine::class);
    $championship = $engine->createChampionship(
        user: $user,
        name: 'Copa Súmula Ao Vivo',
        city: 'Paulínia',
        rules: ChampionshipRules::defaults(
            groupsCount: 2,
            qualifiersPerGroup: 2,
            maxAssistsPerGoal: 1,
        ),
    );

    [$tigresData, $lobosData, $falcoesData, $ursosData] = enrollFourTeamsWithRosters($engine, $championship);

    $engine->drawGroups($championship, [
        'A' => [$tigresData['team']->id, $lobosData['team']->id],
        'B' => [$falcoesData['team']->id, $ursosData['team']->id],
    ], actorId: $user->id);

    $gameA = $championship->games()->where('group_code', 'A')->firstOrFail();

    // Schedule game
    $scheduledTime = CarbonImmutable::parse('2026-11-20 19:30:00');
    $engine->scheduleGame($gameA, new GameScheduleCommand('Quadra Principal', $scheduledTime));

    expect($gameA->fresh()->venue)->toBe('Quadra Principal')
        ->and($gameA->fresh()->scheduled_at?->toDateTimeString())->toBe('2026-11-20 19:30:00');

    $homePlayers = (int) $gameA->home_team_id === (int) $tigresData['team']->id
        ? $tigresData['players']
        : $lobosData['players'];
    $awayPlayers = (int) $gameA->away_team_id === (int) $tigresData['team']->id
        ? $tigresData['players']
        : $lobosData['players'];

    // Cannot record goal while game is still scheduled (not live)
    expect(fn () => $engine->recordGoal($gameA, new GoalCommand(
        side: TeamSide::Home,
        scorerId: $homePlayers[0]->id,
    )))->toThrow(DomainException::class, 'em andamento (ao vivo)');

    // Start game: scheduled -> live
    $engine->startGame($gameA, $user);
    expect($gameA->fresh()->status)->toBe(GameStatus::Live)
        ->and($gameA->fresh()->started_at)->not->toBeNull();

    // Rejects scorer from the opposing team
    expect(fn () => $engine->recordGoal($gameA, new GoalCommand(
        side: TeamSide::Home,
        scorerId: $awayPlayers[0]->id,
    )))->toThrow(DomainException::class, 'elenco da equipe');

    // Rejects self-assist (assist == scorer)
    expect(fn () => $engine->recordGoal($gameA, new GoalCommand(
        side: TeamSide::Home,
        scorerId: $homePlayers[0]->id,
        firstAssistId: $homePlayers[0]->id,
    )))->toThrow(DomainException::class, 'próprio gol');

    // Rejects 2 assists when maxAssistsPerGoal == 1
    expect(fn () => $engine->recordGoal($gameA, new GoalCommand(
        side: TeamSide::Home,
        scorerId: $homePlayers[0]->id,
        firstAssistId: $homePlayers[1]->id,
        secondAssistId: $homePlayers[2]->id,
    )))->toThrow(DomainException::class, 'no máximo 1 assistência');

    // Valid goal with 1 assist
    $goal1 = $engine->recordGoal($gameA, new GoalCommand(
        side: TeamSide::Home,
        scorerId: $homePlayers[0]->id,
        firstAssistId: $homePlayers[1]->id,
        period: 1,
        elapsedSeconds: 110,
        remainingSeconds: 490,
    ));

    $goal2 = $engine->recordGoal($gameA, new GoalCommand(
        side: TeamSide::Away,
        scorerId: $awayPlayers[0]->id,
        period: 2,
        elapsedSeconds: 200,
        remainingSeconds: 400,
    ));

    expect($gameA->fresh()->home_regular_score)->toBe(1)
        ->and($gameA->fresh()->away_regular_score)->toBe(1);

    // Undo / remove goal2 -> soft deletes, audits event_removed, recomputes materialized score
    $engine->removeEvent($goal2, $user, 'Impedimento de área');

    expect($gameA->fresh()->home_regular_score)->toBe(1)
        ->and($gameA->fresh()->away_regular_score)->toBe(0)
        ->and(ChampionshipGameEvent::withTrashed()->find($goal2->id)?->trashed())->toBeTrue()
        ->and(ChampionshipAuditLog::where('action', 'event_removed')->count())->toBe(1);
});

test('executa ciclo completo com desempate manual auditado, mata-mata, shootout separado de gols, reabertura com travas de fase e resumo com pódio', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $engine = app(TournamentEngine::class);

    $championship = $engine->createChampionship(
        user: $user,
        name: 'Campeonato Municipal Completo',
        city: 'São Paulo',
        rules: ChampionshipRules::defaults(
            groupsCount: 2,
            qualifiersPerGroup: 1,
            hasThirdPlaceMatch: false,
            maxAssistsPerGoal: 2,
        ),
    );

    [$tigresData, $lobosData, $falcoesData, $ursosData] = enrollFourTeamsWithRosters($engine, $championship);
    $tigres = $tigresData['team'];
    $lobos = $lobosData['team'];
    $falcoes = $falcoesData['team'];
    $ursos = $ursosData['team'];

    $engine->drawGroups($championship, [
        'A' => [$tigres->id, $lobos->id],
        'B' => [$falcoes->id, $ursos->id],
    ], actorId: $user->id);

    $gameA = $championship->games()->where('group_code', 'A')->firstOrFail();
    $gameB = $championship->games()->where('group_code', 'B')->firstOrFail();

    // Cannot generate bracket while group games are unfinished
    expect(fn () => $engine->generateBracket($championship, $user))
        ->toThrow(DomainException::class, 'devem estar encerradas antes de gerar o mata-mata');

    // Group A finishes tied 1x1 -> since qualifiersPerGroup=1, Tigres and Lobos are tied across the qualification line!
    $engine->startGame($gameA, $user);

    // Shootout is rejected in group game when allowGroupOvertimeOrShootout is false (allow_draw)
    expect(fn () => $engine->recordShootoutAttempt($gameA, new ShootoutAttemptCommand(
        side: TeamSide::Home,
        shooterId: $tigresData['players'][0]->id,
        scored: true,
        roundNumber: 1,
    )))->toThrow(DomainException::class, 'não utilizam disputa de shootout');

    $homeSideA = (int) $gameA->home_team_id === (int) $tigres->id ? TeamSide::Home : TeamSide::Away;
    $awaySideA = $homeSideA === TeamSide::Home ? TeamSide::Away : TeamSide::Home;

    $engine->recordGoal($gameA, new GoalCommand(
        side: $homeSideA,
        scorerId: $tigresData['players'][0]->id,
        assistIds: [$tigresData['players'][1]->id, $tigresData['players'][2]->id],
        period: 1,
        elapsedSeconds: 120,
        remainingSeconds: 480,
    ));
    $engine->recordGoal($gameA, new GoalCommand(
        side: $awaySideA,
        scorerId: $lobosData['players'][0]->id,
        firstAssistId: $lobosData['players'][1]->id,
        period: 2,
        elapsedSeconds: 300,
        remainingSeconds: 300,
    ));
    $engine->finishGame($gameA, $user);

    // FinishGame is idempotent
    $engine->finishGame($gameA, $user);
    expect($championship->auditLogs()->where('action', 'game_finished')->where('championship_game_id', $gameA->id)->count())->toBe(1);

    // Group B: Falcões wins 2x0
    $engine->startGame($gameB, $user);
    $falcoesSide = (int) $gameB->home_team_id === (int) $falcoes->id ? TeamSide::Home : TeamSide::Away;
    $engine->recordGoal($gameB, new GoalCommand(
        side: $falcoesSide,
        scorerId: $falcoesData['players'][0]->id,
        firstAssistId: $falcoesData['players'][1]->id,
        period: 1,
    ));
    $engine->recordGoal($gameB, new GoalCommand(
        side: $falcoesSide,
        scorerId: $falcoesData['players'][0]->id,
        period: 2,
    ));
    $engine->finishGame($gameB, $user);

    // Group A has an unresolved tie crossing the qualification line (1st vs 2nd with 1 qualifier)
    $standingsBeforeTie = $engine->standings($championship);
    expect($standingsBeforeTie['A']->unresolvedTieGroups)->toHaveCount(1);

    expect(fn () => $engine->generateBracket($championship, $user))
        ->toThrow(DomainException::class, 'empate técnico pendente de definição manual no grupo A');

    // Resolve tie manually with audit log
    $engine->resolveTie(
        championship: $championship,
        group: 'A',
        orderedTeamIds: [$lobos->id, $tigres->id],
        actor: $user,
        reason: 'Sorteio na presença dos capitães',
    );

    expect($championship->auditLogs()->where('action', 'tie_break_resolved')->count())->toBe(1);
    $standingsAfterTie = $engine->standings($championship);
    expect($standingsAfterTie['A']->rows[0]->teamId)->toBe($lobos->id)
        ->and($standingsAfterTie['A']->rows[0]->qualifies)->toBeTrue()
        ->and($standingsAfterTie['A']->unresolvedTieGroups)->toBe([]);

    // Generate bracket (2 qualifiers -> FINAL between Lobos and Falcões)
    $engine->generateBracket($championship, $user);
    expect($championship->fresh()->status)->toBe(ChampionshipStatus::KnockoutStage);

    $finalSlot = $championship->games()->where('slot_key', 'FINAL')->firstOrFail();
    $finalFirstId = $finalSlot->id;

    // Calling generateBracket again before knockout starts is idempotent (upserts by slot_key)
    $engine->generateBracket($championship, $user);
    expect($championship->games()->where('slot_key', 'FINAL')->count())->toBe(1)
        ->and($championship->games()->where('slot_key', 'FINAL')->firstOrFail()->id)->toBe($finalFirstId);

    // Reopening a group game when bracket exists requires resetBracket=true
    expect(fn () => $engine->reopenGame($gameA, $user, 'Correção de súmula', resetBracket: false))
        ->toThrow(DomainException::class, 'Confirme o reinício da chave');

    $engine->reopenGame($gameA, $user, 'Correção de súmula autorizada', resetBracket: true);

    expect($championship->fresh()->status)->toBe(ChampionshipStatus::GroupStage)
        ->and($championship->games()->where('phase', '!=', GamePhase::Group)->count())->toBe(0)
        ->and($championship->auditLogs()->where('action', 'bracket_reset')->count())->toBe(1)
        ->and($championship->auditLogs()->where('action', 'game_reopened')->count())->toBe(1);

    $reopenAudit = $championship->auditLogs()->where('action', 'game_reopened')->latest('id')->firstOrFail();
    expect($reopenAudit->before_snapshot['status'])->toBe('finished')
        ->and($reopenAudit->after_snapshot['status'])->toBe('live');

    // Finish gameA again and regenerate bracket
    $engine->finishGame($gameA, $user);
    $engine->generateBracket($championship, $user);

    $final = $championship->games()->where('slot_key', 'FINAL')->firstOrFail();
    $engine->startGame($final, $user);

    // Once knockout game is live, reopening group game is blocked even with resetBracket=true
    expect(fn () => $engine->reopenGame($gameA, $user, 'Tentar mudar grupo após início da final', resetBracket: true))
        ->toThrow(DomainException::class, 'mata-mata já foi iniciado');

    // Knockout game tied 0x0 cannot finish without shootout winner
    expect(fn () => $engine->finishGame($final, $user))
        ->toThrow(DomainException::class, 'exigem um vencedor');

    // Podium is still null before FINAL finishes
    $summaryBeforeFinal = $engine->summary($championship);
    expect($summaryBeforeFinal->podium->championTeamId)->toBeNull()
        ->and($summaryBeforeFinal->podium->runnerUpTeamId)->toBeNull();

    // Decide FINAL via shootout (Lobos vs Falcões): shootout goals do NOT add to regular score or player goal stats!
    $homePlayersFinal = (int) $final->home_team_id === (int) $lobos->id ? $lobosData['players'] : $falcoesData['players'];
    $awayPlayersFinal = (int) $final->away_team_id === (int) $lobos->id ? $lobosData['players'] : $falcoesData['players'];

    $soEvent1 = $engine->recordShootoutAttempt($final, new ShootoutAttemptCommand(
        side: TeamSide::Home,
        shooterId: $homePlayersFinal[0]->id,
        scored: true,
        roundNumber: 1,
    ));
    $engine->recordShootoutAttempt($final, new ShootoutAttemptCommand(
        side: TeamSide::Away,
        shooterId: $awayPlayersFinal[0]->id,
        scored: false,
        roundNumber: 1,
    ));

    expect($soEvent1->period)->toBeNull()
        ->and($final->fresh()->home_regular_score)->toBe(0)
        ->and($final->fresh()->away_regular_score)->toBe(0)
        ->and($final->fresh()->home_shootout_score)->toBe(1)
        ->and($final->fresh()->away_shootout_score)->toBe(0)
        ->and($final->fresh()->went_to_shootout)->toBeTrue();

    $engine->finishGame($final, $user);

    expect($championship->fresh()->status)->toBe(ChampionshipStatus::Completed)
        ->and($championship->fresh()->completed_at)->not->toBeNull();

    $summary = $engine->summary($championship);

    // Total goals = 4 (2 in Game A + 2 in Game B; shootout goal in FINAL is excluded!)
    expect($summary->totalGames)->toBe(3)
        ->and($summary->finishedGames)->toBe(3)
        ->and($summary->totalGoals)->toBe(4)
        ->and($summary->podium->championTeamId)->toBe($final->home_team_id)
        ->and($summary->podium->runnerUpTeamId)->toBe($final->away_team_id)
        ->and($summary->podium->thirdPlaceTeamId)->toBeNull();

    // Top scorer: Falcões player 0 has 2 goals
    expect($summary->topScorers[0]->playerId)->toBe($falcoesData['players'][0]->id)
        ->and($summary->topScorers[0]->goals)->toBe(2);

    // Both 1st and 2nd assists on Tigres' goal are counted in topAssists
    $assistPlayerIds = array_map(fn ($row) => $row->playerId, $summary->topAssists);
    expect($assistPlayerIds)->toContain($tigresData['players'][1]->id)
        ->and($assistPlayerIds)->toContain($tigresData['players'][2]->id);
});

test('avança vencedores e perdedores (3º lugar), resolve byes automaticamente e bloqueia reabertura de semifinal quando a final já iniciou', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $engine = app(TournamentEngine::class);

    // 3 groups x 2 qualifiers = 6 qualifiers -> 8-slot bracket with 2 first-round byes + 3RD + FINAL
    $championship = $engine->createChampionship(
        user: $user,
        name: 'Copa Com Byes e 3º Lugar',
        city: 'Joinville',
        rules: ChampionshipRules::defaults(
            groupsCount: 3,
            qualifiersPerGroup: 2,
            hasThirdPlaceMatch: true,
        ),
    );

    $groupsInput = ['A' => [], 'B' => [], 'C' => []];
    $playersByTeam = [];

    foreach (['A', 'B', 'C'] as $groupCode)
    {
        for ($i = 1; $i <= 2; $i++)
        {
            $team = $engine->enrollTeam($championship, "Equipe {$groupCode}{$i}", "{$groupCode}{$i}");
            $p1 = $engine->addPlayer($team, "Atleta {$groupCode}{$i}", 10);
            $groupsInput[$groupCode][] = $team->id;
            $playersByTeam[$team->id] = $p1;
        }
    }

    $engine->drawGroups($championship, $groupsInput, actorId: $user->id);

    // Play and finish all 3 group games (home wins 2x0 in each)
    foreach ($championship->games()->where('phase', GamePhase::Group)->get() as $groupGame)
    {
        $engine->startGame($groupGame, $user);
        $scorer = $playersByTeam[(int) $groupGame->home_team_id];
        $engine->recordGoal($groupGame, new GoalCommand(side: TeamSide::Home, scorerId: $scorer->id, period: 1));
        $engine->recordGoal($groupGame, new GoalCommand(side: TeamSide::Home, scorerId: $scorer->id, period: 2));
        $engine->finishGame($groupGame, $user);
    }

    $engine->generateBracket($championship, $user);

    $qf1 = $championship->games()->where('slot_key', 'QF1')->firstOrFail();
    $qf2 = $championship->games()->where('slot_key', 'QF2')->firstOrFail();
    $qf3 = $championship->games()->where('slot_key', 'QF3')->firstOrFail();
    $qf4 = $championship->games()->where('slot_key', 'QF4')->firstOrFail();
    $sf1 = $championship->games()->where('slot_key', 'SF1')->firstOrFail();

    // QF1 and QF2 are auto-byes and already advanced their winners into SF1!
    expect($qf1->is_bye)->toBeTrue()
        ->and($qf1->status)->toBe(GameStatus::Finished)
        ->and($qf2->is_bye)->toBeTrue()
        ->and($qf2->status)->toBe(GameStatus::Finished)
        ->and($sf1->home_team_id)->toBe($qf1->winner_team_id)
        ->and($sf1->away_team_id)->toBe($qf2->winner_team_id);

    // Play QF3 and QF4 to populate SF2
    foreach ([$qf3, $qf4] as $qfGame)
    {
        $engine->startGame($qfGame, $user);
        $scorer = $playersByTeam[(int) $qfGame->home_team_id];
        $engine->recordGoal($qfGame, new GoalCommand(side: TeamSide::Home, scorerId: $scorer->id, period: 1));
        $engine->finishGame($qfGame, $user);
    }

    $sf2 = $championship->games()->where('slot_key', 'SF2')->firstOrFail();
    expect($sf2->home_team_id)->toBe($qf3->winner_team_id)
        ->and($sf2->away_team_id)->toBe($qf4->winner_team_id);

    // Play SF1 and SF2: winners go to FINAL, losers go to 3RD
    foreach ([$sf1->fresh(), $sf2->fresh()] as $sfGame)
    {
        $engine->startGame($sfGame, $user);
        $scorer = $playersByTeam[(int) $sfGame->home_team_id];
        $engine->recordGoal($sfGame, new GoalCommand(side: TeamSide::Home, scorerId: $scorer->id, period: 1));
        $engine->finishGame($sfGame, $user);
    }

    $final = $championship->games()->where('slot_key', 'FINAL')->firstOrFail();
    $third = $championship->games()->where('slot_key', '3RD')->firstOrFail();

    expect($final->home_team_id)->toBe($sf1->fresh()->winner_team_id)
        ->and($final->away_team_id)->toBe($sf2->fresh()->winner_team_id)
        ->and($third->home_team_id)->toBe($sf1->fresh()->away_team_id)
        ->and($third->away_team_id)->toBe($sf2->fresh()->away_team_id);

    // Reopening SF1 while FINAL and 3RD are still scheduled clears the slot in FINAL and 3RD
    $engine->reopenGame($sf1, $user, 'Revisão de gol na semifinal 1');
    expect($final->fresh()->home_team_id)->toBeNull()
        ->and($third->fresh()->home_team_id)->toBeNull();

    // Finish SF1 again -> re-populates FINAL and 3RD
    $engine->finishGame($sf1, $user);
    expect($final->fresh()->home_team_id)->toBe($sf1->fresh()->winner_team_id)
        ->and($third->fresh()->home_team_id)->toBe($sf1->fresh()->away_team_id);

    // Start FINAL -> now reopening SF1 is blocked because the dependent game (FINAL) has started!
    $engine->startGame($final->fresh(), $user);

    expect(fn () => $engine->reopenGame($sf1->fresh(), $user, 'Tentativa após início da final'))
        ->toThrow(DomainException::class, 'fase seguinte já foi iniciada');

    // Finish FINAL -> championship is NOT completed yet because 3RD is enabled and still scheduled!
    $finalScorer = $playersByTeam[(int) $final->fresh()->home_team_id];
    $engine->recordGoal($final->fresh(), new GoalCommand(side: TeamSide::Home, scorerId: $finalScorer->id, period: 1));
    $engine->finishGame($final->fresh(), $user);

    expect($championship->fresh()->status)->toBe(ChampionshipStatus::KnockoutStage)
        ->and($engine->summary($championship)->podium->thirdPlaceTeamId)->toBeNull();

    // Finish 3RD -> now championship transitions to Completed and full podium is determined
    $engine->startGame($third->fresh(), $user);
    $thirdScorer = $playersByTeam[(int) $third->fresh()->away_team_id];
    $engine->recordGoal($third->fresh(), new GoalCommand(side: TeamSide::Away, scorerId: $thirdScorer->id, period: 1));
    $engine->finishGame($third->fresh(), $user);

    expect($championship->fresh()->status)->toBe(ChampionshipStatus::Completed);

    $podium = $engine->summary($championship)->podium;
    expect($podium->championTeamId)->toBe($final->fresh()->home_team_id)
        ->and($podium->runnerUpTeamId)->toBe($final->fresh()->away_team_id)
        ->and($podium->thirdPlaceTeamId)->toBe($third->fresh()->away_team_id);
});

test('mantém o fluxo de pelada e a rota /estatisticas totalmente isolados dos gols e jogos de campeonato', function () {
    [$clubUser, $casualPlayer] = $this->teamWithPlayer();
    $casualMate = Player::factory()->for($clubUser)->create(['name' => 'Parceiro Pelada', 'jersey_number' => 11]);

    // Record 1 casual match with 1 goal for $casualPlayer
    $this->actingAs($clubUser)
        ->post('/partidas', matchPayload($clubUser, $casualPlayer, $casualMate))
        ->assertRedirect();

    // Now run a championship in the exact same user account and score 5 championship goals
    $engine = app(TournamentEngine::class);
    $championship = $engine->createChampionship($clubUser, 'Copa Isolamento', 'Campinas');
    [$tigresData, $lobosData, $falcoesData, $ursosData] = enrollFourTeamsWithRosters($engine, $championship);

    $engine->drawGroups($championship, [
        'A' => [$tigresData['team']->id, $lobosData['team']->id],
        'B' => [$falcoesData['team']->id, $ursosData['team']->id],
    ], actorId: $clubUser->id);

    $gameA = $championship->games()->where('group_code', 'A')->firstOrFail();
    $engine->startGame($gameA, $clubUser);

    $homeScorer = (int) $gameA->home_team_id === (int) $tigresData['team']->id
        ? $tigresData['players'][0]
        : $lobosData['players'][0];

    for ($i = 0; $i < 5; $i++)
    {
        $engine->recordGoal($gameA, new GoalCommand(
            side: TeamSide::Home,
            scorerId: $homeScorer->id,
            period: 1,
            elapsedSeconds: 30 * ($i + 1),
            remainingSeconds: 600 - 30 * ($i + 1),
        ));
    }
    $engine->finishGame($gameA, $clubUser);

    expect($engine->summary($championship)->totalGoals)->toBe(5)
        ->and(ChampionshipPlayer::count())->toBe(16);

    // Hit casual /estatisticas route: only the 3 casual players appear (none of the 16 championship players), and only the 1 casual goal is counted!
    $response = $this->actingAs($clubUser)->get('/estatisticas')->assertOk();
    $casualStats = collect($response->viewData('page')['props']['players']);

    expect($casualStats)->toHaveCount(3)
        ->and($casualStats->pluck('name')->all())->not->toContain($homeScorer->name)
        ->and($casualStats->firstWhere('id', $casualPlayer->id)['goals'])->toBe(1)
        ->and($casualStats->firstWhere('id', $casualPlayer->id)['games'])->toBe(1)
        ->and($casualStats->sum('goals'))->toBe(1);
});
