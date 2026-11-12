<?php

use App\Championships\ChampionshipException;
use App\Championships\Enums\ChampionshipStatus;
use App\Championships\Enums\GameEventType;
use App\Championships\Enums\GamePhase;
use App\Championships\Enums\GameStatus;
use App\Models\Championship;
use App\Models\ChampionshipAuditLog;
use App\Models\ChampionshipGame;
use App\Models\ChampionshipGameEvent;
use App\Models\ChampionshipPlayer;
use App\Models\ChampionshipTeam;
use App\Models\Player;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../matchHelpers.php';

beforeEach(function () {
    $this->withoutVite();
});

test('executa torneio determinístico completo de 8 equipes exclusivamente via HTTP com reabertura da final e atualização do pódio', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    // 1. Index page renders championships/Index
    $this->get('/campeonatos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('championships/Index')
            ->has('championships', 0)
            ->has('default_rules'));

    // 2. Create championship via POST /campeonatos
    $this->post('/campeonatos', [
        'name' => 'Copa Municipal Rascunho',
        'city' => 'Campinas',
        'venue' => 'Ginásio Municipal',
        'starts_on' => '2026-11-10',
        'ends_on' => '2026-11-25',
    ])->assertRedirect();

    $championship = Championship::firstOrFail();

    // 3. Update championship details & set rules (2 groups, 2 qualify, third place on, max 2 assists)
    $this->patch("/campeonatos/{$championship->id}", [
        'name' => 'Copa Municipal Hockey Game',
        'city' => 'São Paulo',
        'venue' => 'Arena Ibirapuera',
        'starts_on' => '2026-11-12',
        'ends_on' => '2026-11-28',
    ])->assertRedirect();

    $this->patch("/campeonatos/{$championship->id}/regras", [
        'groups_count' => 2,
        'qualifiers_per_group' => 2,
        'extra_best_qualifiers' => 0,
        'double_round_robin' => false,
        'has_third_place_match' => true,
        'max_assists_per_goal' => 2,
        'period_minutes' => 12,
        'periods_count' => 2,
        'rest_seconds' => 90,
        'countdown_seconds' => 5,
        'overtime_minutes' => 5,
    ])->assertRedirect();

    expect($championship->fresh()->name)->toBe('Copa Municipal Hockey Game')
        ->and($championship->fresh()->rules->groupsCount)->toBe(2)
        ->and($championship->fresh()->rules->qualifiersPerGroup)->toBe(2)
        ->and($championship->fresh()->rules->hasThirdPlaceMatch)->toBeTrue()
        ->and($championship->fresh()->rules->periodMinutes)->toBe(12);

    // 4. Enroll 8 teams with players (one team with uploaded raster logo)
    $logoFile = UploadedFile::fake()->image('escudo-equipe-1.png', 200, 200);

    for ($i = 1; $i <= 8; $i++)
    {
        $payload = [
            'name' => "Equipe {$i} HC",
            'short_name' => "EQ{$i}",
            'primary_color' => '#1d4ed8',
            'secondary_color' => '#ffffff',
            'seed_pot' => $i <= 2 ? 1 : ($i <= 4 ? 2 : 3),
        ];

        if ($i === 1)
        {
            $payload['logo'] = $logoFile;
        }

        $this->post("/campeonatos/{$championship->id}/equipes", $payload)->assertRedirect();
    }

    $teams = ChampionshipTeam::where('championship_id', $championship->id)->orderBy('id')->get();
    expect($teams)->toHaveCount(8)
        ->and($teams[0]->logo_path)->not->toBeNull()
        ->and($teams[0]->logo_url)->not->toBeNull();
    Storage::disk('public')->assertExists($teams[0]->logo_path);

    // Update team 2 details via PATCH
    $this->patch("/campeonatos/{$championship->id}/equipes/{$teams[1]->id}", [
        'name' => 'Equipe 2 Oficial HC',
        'short_name' => 'E2O',
        'primary_color' => '#dc2626',
        'secondary_color' => '#ffffff',
    ])->assertRedirect();
    expect($teams[1]->fresh()->name)->toBe('Equipe 2 Oficial HC');

    /** @var array<int, list<ChampionshipPlayer>> $playersByTeam */
    $playersByTeam = [];

    foreach ($teams as $team)
    {
        $this->post("/campeonatos/{$championship->id}/equipes/{$team->id}/jogadores", [
            'name' => "Atacante Principal {$team->short_name}",
            'jersey_number' => 10,
            'position' => 'forward',
            'is_goalkeeper' => false,
        ])->assertRedirect();

        $this->post("/campeonatos/{$championship->id}/equipes/{$team->id}/jogadores", [
            'name' => "Armador {$team->short_name}",
            'jersey_number' => 11,
            'position' => 'forward',
            'is_goalkeeper' => false,
        ])->assertRedirect();

        $this->post("/campeonatos/{$championship->id}/equipes/{$team->id}/jogadores", [
            'name' => "Defensor {$team->short_name}",
            'jersey_number' => 4,
            'position' => 'defender',
            'is_goalkeeper' => false,
        ])->assertRedirect();

        $playersByTeam[(int) $team->id] = ChampionshipPlayer::where('championship_team_id', $team->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    // Test player update and delete on a temporary 4th player of team 1
    $this->post("/campeonatos/{$championship->id}/equipes/{$teams[0]->id}/jogadores", [
        'name' => 'Jogador Temporário',
        'jersey_number' => 99,
        'position' => 'universal',
    ])->assertRedirect();

    $tempPlayer = ChampionshipPlayer::where('championship_team_id', $teams[0]->id)
        ->where('jersey_number', 99)
        ->firstOrFail();

    $this->patch("/campeonatos/{$championship->id}/equipes/{$teams[0]->id}/jogadores/{$tempPlayer->id}", [
        'name' => 'Jogador Temporário Editado',
        'jersey_number' => 98,
    ])->assertRedirect();
    expect($tempPlayer->fresh()->jersey_number)->toBe(98);

    $this->delete("/campeonatos/{$championship->id}/equipes/{$teams[0]->id}/jogadores/{$tempPlayer->id}")
        ->assertRedirect();
    expect(ChampionshipPlayer::find($tempPlayer->id))->toBeNull();

    // 5. Draw groups via POST /campeonatos/{id}/sorteio
    $this->post("/campeonatos/{$championship->id}/sorteio")->assertRedirect();

    expect($championship->fresh()->status)->toBe(ChampionshipStatus::GroupStage);

    // Read drawn group assignments from DB to play deterministically according to group_draw_order (1 beats 2, 3, 4; 2 beats 3, 4; 3 beats 4)
    $groupATeams = ChampionshipTeam::where('championship_id', $championship->id)
        ->where('group_code', 'A')
        ->orderBy('group_draw_order')
        ->get()
        ->values();
    $groupBTeams = ChampionshipTeam::where('championship_id', $championship->id)
        ->where('group_code', 'B')
        ->orderBy('group_draw_order')
        ->get()
        ->values();

    expect($groupATeams)->toHaveCount(4)
        ->and($groupBTeams)->toHaveCount(4);

    $teamA1 = $groupATeams[0];
    $teamA2 = $groupATeams[1];
    $teamB1 = $groupBTeams[0];
    $teamB2 = $groupBTeams[1];

    $drawOrderByTeamId = ChampionshipTeam::where('championship_id', $championship->id)
        ->pluck('group_draw_order', 'id')
        ->all();

    // 6. Schedule, start, score (with 1st and 2nd assists) and finish all 12 group games via HTTP
    $groupGames = ChampionshipGame::where('championship_id', $championship->id)
        ->where('phase', GamePhase::Group)
        ->orderBy('id')
        ->get();

    expect($groupGames)->toHaveCount(12);

    foreach ($groupGames as $idx => $game)
    {
        $this->patch("/campeonatos/{$championship->id}/jogos/{$game->id}/agendamento", [
            'venue' => 'Quadra Central',
            'scheduled_at' => '2026-11-15 14:00:00',
        ])->assertRedirect();

        $this->post("/campeonatos/{$championship->id}/jogos/{$game->id}/iniciar")->assertRedirect();

        $homeOrder = (int) $drawOrderByTeamId[$game->home_team_id];
        $awayOrder = (int) $drawOrderByTeamId[$game->away_team_id];

        $winningSide = $homeOrder < $awayOrder ? 'home' : 'away';
        $winningTeamId = $winningSide === 'home' ? (int) $game->home_team_id : (int) $game->away_team_id;
        [$scorer, $assist1, $assist2] = $playersByTeam[$winningTeamId];

        // Goal 1 with two assists (1st + 2nd assist)
        $this->post("/campeonatos/{$championship->id}/jogos/{$game->id}/gols", [
            'side' => $winningSide,
            'scorer_id' => $scorer->id,
            'assist_ids' => [$assist1->id, $assist2->id],
            'period' => 1,
            'elapsed_seconds' => 120,
            'remaining_seconds' => 600,
        ])->assertRedirect();

        // Goal 2 with 1st assist
        $this->post("/campeonatos/{$championship->id}/jogos/{$game->id}/gols", [
            'side' => $winningSide,
            'scorer_id' => $scorer->id,
            'first_assist_id' => $assist1->id,
            'period' => 2,
            'elapsed_seconds' => 300,
            'remaining_seconds' => 420,
        ])->assertRedirect();

        $this->post("/campeonatos/{$championship->id}/jogos/{$game->id}/finalizar")->assertRedirect();
    }

    // 7. Verify Show page props before generating bracket
    $this->get("/campeonatos/{$championship->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('championships/Show')
            ->where('championship.id', $championship->id)
            ->where('championship.rules.groups_count', 2)
            ->where('status_flags.can_edit_rules', false)
            ->where('status_flags.can_draw', false)
            ->where('status_flags.can_generate_bracket', true)
            ->has('teams', 8)
            ->has('standings.A.rows', 4)
            ->has('standings.B.rows', 4)
            ->has('games.group', 12)
            ->where('standings.A.rows.0.team_id', $teamA1->id)
            ->where('standings.A.rows.0.points', 9)
            ->where('standings.A.rows.1.team_id', $teamA2->id)
            ->where('standings.A.rows.1.points', 6)
            ->where('standings.B.rows.0.team_id', $teamB1->id)
            ->where('standings.B.rows.0.points', 9)
            ->where('standings.B.rows.1.team_id', $teamB2->id)
            ->where('standings.B.rows.1.points', 6));

    // 8. Generate knockout bracket via POST /campeonatos/{id}/gerar-mata-mata
    $this->post("/campeonatos/{$championship->id}/gerar-mata-mata")->assertRedirect();

    expect($championship->fresh()->status)->toBe(ChampionshipStatus::KnockoutStage);

    $sf1 = ChampionshipGame::where('championship_id', $championship->id)->where('slot_key', 'SF1')->firstOrFail();
    $sf2 = ChampionshipGame::where('championship_id', $championship->id)->where('slot_key', 'SF2')->firstOrFail();

    expect($sf1->home_team_id)->toBe($teamA1->id)
        ->and($sf1->away_team_id)->toBe($teamB2->id)
        ->and($sf2->home_team_id)->toBe($teamB1->id)
        ->and($sf2->away_team_id)->toBe($teamA2->id);

    // 9. Play SF1 (1A vs 2B): tied 1x1 in regulation/OT, decided by shootout (Home teamA1 wins shootout 2x0)
    $this->post("/campeonatos/{$championship->id}/jogos/{$sf1->id}/iniciar")->assertRedirect();

    [$a1Scorer, $a1Assist1, $a1Assist2] = $playersByTeam[(int) $teamA1->id];
    [$b2Scorer, $b2Assist1] = $playersByTeam[(int) $teamB2->id];

    $this->post("/campeonatos/{$championship->id}/jogos/{$sf1->id}/gols", [
        'side' => 'home',
        'scorer_id' => $a1Scorer->id,
        'assist_ids' => [$a1Assist1->id, $a1Assist2->id],
        'period' => 1,
        'elapsed_seconds' => 180,
        'remaining_seconds' => 540,
    ])->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$sf1->id}/gols", [
        'side' => 'away',
        'scorer_id' => $b2Scorer->id,
        'assist_ids' => [$b2Assist1->id],
        'period' => 2,
        'is_overtime' => true,
        'elapsed_seconds' => 60,
        'remaining_seconds' => 240,
    ])->assertRedirect();

    // Assert GameLive page props (game, teams/rosters, non-deleted events, clock_settings, overtime_minutes, max_assists)
    $this->get("/campeonatos/{$championship->id}/jogos/{$sf1->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('championships/GameLive')
            ->where('game.id', $sf1->id)
            ->where('clock_settings.minutes', 12)
            ->where('clock_settings.periods', 2)
            ->where('clock_settings.rest_seconds', 90)
            ->where('clock_settings.countdown_seconds', 5)
            ->where('overtime_minutes', 5)
            ->where('max_assists', 2)
            ->has('rosters.home', 3)
            ->has('rosters.away', 3)
            ->has('events', 2));

    // Shootout in SF1: Home (teamA1) converts 2 attempts, Away (teamB2) misses 2 attempts
    $this->post("/campeonatos/{$championship->id}/jogos/{$sf1->id}/penaltis", [
        'side' => 'home',
        'shooter_id' => $a1Scorer->id,
        'scored' => true,
        'round' => 1,
    ])->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$sf1->id}/penaltis", [
        'side' => 'away',
        'shooter_id' => $b2Scorer->id,
        'scored' => false,
        'round' => 1,
    ])->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$sf1->id}/penaltis", [
        'side' => 'home',
        'shooter_id' => $a1Assist1->id,
        'scored' => true,
        'round' => 2,
    ])->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$sf1->id}/penaltis", [
        'side' => 'away',
        'shooter_id' => $b2Assist1->id,
        'scored' => false,
        'round' => 2,
    ])->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$sf1->id}/finalizar")->assertRedirect();

    // 10. Play SF2 (1B vs 2A): Home (teamB1) wins 2x0 in regulation
    $this->post("/campeonatos/{$championship->id}/jogos/{$sf2->id}/iniciar")->assertRedirect();

    [$b1Scorer, $b1Assist1, $b1Assist2] = $playersByTeam[(int) $teamB1->id];

    $this->post("/campeonatos/{$championship->id}/jogos/{$sf2->id}/gols", [
        'side' => 'home',
        'scorer_id' => $b1Scorer->id,
        'assist_ids' => [$b1Assist1->id, $b1Assist2->id],
        'period' => 1,
        'elapsed_seconds' => 100,
        'remaining_seconds' => 620,
    ])->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$sf2->id}/gols", [
        'side' => 'home',
        'scorer_id' => $b1Scorer->id,
        'assist_ids' => [$b1Assist1->id],
        'period' => 2,
        'elapsed_seconds' => 250,
        'remaining_seconds' => 470,
    ])->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$sf2->id}/finalizar")->assertRedirect();

    $thirdPlaceGame = ChampionshipGame::where('championship_id', $championship->id)->where('slot_key', '3RD')->firstOrFail();
    $finalGame = ChampionshipGame::where('championship_id', $championship->id)->where('slot_key', 'FINAL')->firstOrFail();

    expect($thirdPlaceGame->home_team_id)->toBe($teamB2->id)
        ->and($thirdPlaceGame->away_team_id)->toBe($teamA2->id)
        ->and($finalGame->home_team_id)->toBe($teamA1->id)
        ->and($finalGame->away_team_id)->toBe($teamB1->id);

    // 11. Play 3RD place match: Home (teamB2) wins 1x0 over Away (teamA2)
    $this->post("/campeonatos/{$championship->id}/jogos/{$thirdPlaceGame->id}/iniciar")->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$thirdPlaceGame->id}/gols", [
        'side' => 'home',
        'scorer_id' => $b2Scorer->id,
        'assist_ids' => [$b2Assist1->id],
        'period' => 1,
        'elapsed_seconds' => 200,
        'remaining_seconds' => 520,
    ])->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$thirdPlaceGame->id}/finalizar")->assertRedirect();

    // 12. Play FINAL: Home (teamA1) wins 2x0 over Away (teamB1)
    $this->post("/campeonatos/{$championship->id}/jogos/{$finalGame->id}/iniciar")->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$finalGame->id}/gols", [
        'side' => 'home',
        'scorer_id' => $a1Scorer->id,
        'assist_ids' => [$a1Assist1->id, $a1Assist2->id],
        'period' => 1,
        'elapsed_seconds' => 150,
        'remaining_seconds' => 570,
    ])->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$finalGame->id}/gols", [
        'side' => 'home',
        'scorer_id' => $a1Scorer->id,
        'assist_ids' => [$a1Assist1->id, $a1Assist2->id],
        'period' => 2,
        'elapsed_seconds' => 400,
        'remaining_seconds' => 320,
    ])->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$finalGame->id}/finalizar")->assertRedirect();

    expect($championship->fresh()->status)->toBe(ChampionshipStatus::Completed);

    // 13. Assert Summary page props:
    // - Podium: Champion = teamA1, Runner-Up = teamB1, Third = teamB2
    // - Total games = 16 (12 group + 2 SF + 1 3RD + 1 FINAL), finished = 16
    // - Total goals = 31 (24 group + 2 SF1 + 2 SF2 + 1 3RD + 2 FINAL; 2 shootout goals in SF1 excluded!)
    // - Top scorer: $a1Scorer has 9 goals (6 in group + 1 in SF1 + 2 in FINAL; shootout goal excluded!)
    // - Assists: $a1Assist1 has 9 assists (1st assists), $a1Assist2 has 6 assists (all 6 are 2nd assists!)
    $summaryResponse = $this->get("/campeonatos/{$championship->id}/resumo")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('championships/Summary')
            ->where('summary.podium.champion_team_id', $teamA1->id)
            ->where('summary.podium.runner_up_team_id', $teamB1->id)
            ->where('summary.podium.third_place_team_id', $teamB2->id)
            ->where('summary.total_games', 16)
            ->where('summary.finished_games', 16)
            ->where('summary.total_goals', 31)
            ->where('summary.top_scorers.0.player_id', $a1Scorer->id)
            ->where('summary.top_scorers.0.goals', 9)
            ->where('summary.top_scorers.1.player_id', $b1Scorer->id)
            ->where('summary.top_scorers.1.goals', 8)
            ->where('summary.top_assists.0.player_id', $a1Assist1->id)
            ->where('summary.top_assists.0.assists', 9)
            ->has('standings.A.rows', 4)
            ->has('standings.B.rows', 4)
            ->has('bracket', 4)
            ->has('results', 16));

    $topAssistsProps = collect($summaryResponse->viewData('page')['props']['summary']['top_assists']);
    $secondAssistPlayerRow = $topAssistsProps->firstWhere('player_id', $a1Assist2->id);
    expect($secondAssistPlayerRow)->not->toBeNull()
        ->and($secondAssistPlayerRow['assists'])->toBe(6);

    // 14. Reopen FINAL with motivo, remove both of teamA1's goals, add 1 goal for teamB1, finish again
    $this->post("/campeonatos/{$championship->id}/jogos/{$finalGame->id}/reabrir", [
        'motivo' => 'Revisão oficial de súmula da grande final',
    ])->assertRedirect();

    expect($championship->fresh()->status)->toBe(ChampionshipStatus::KnockoutStage)
        ->and($finalGame->fresh()->status)->toBe(GameStatus::Live);

    $finalGoalEvents = ChampionshipGameEvent::where('championship_game_id', $finalGame->id)
        ->where('type', GameEventType::Goal)
        ->get();
    expect($finalGoalEvents)->toHaveCount(2);

    foreach ($finalGoalEvents as $goalEvent)
    {
        $this->delete("/campeonatos/{$championship->id}/jogos/{$finalGame->id}/eventos/{$goalEvent->id}", [
            'motivo' => 'Gol anulado após revisão de vídeo',
        ])->assertRedirect();
    }

    // Score winning goal for Away (teamB1) in FINAL
    $this->post("/campeonatos/{$championship->id}/jogos/{$finalGame->id}/gols", [
        'side' => 'away',
        'scorer_id' => $b1Scorer->id,
        'assist_ids' => [$b1Assist1->id, $b1Assist2->id],
        'period' => 2,
        'elapsed_seconds' => 500,
        'remaining_seconds' => 220,
    ])->assertRedirect();

    $this->post("/campeonatos/{$championship->id}/jogos/{$finalGame->id}/finalizar")->assertRedirect();

    expect($championship->fresh()->status)->toBe(ChampionshipStatus::Completed);

    // 15. Assert updated podium, updated top scorer, and audit log entries
    $this->get("/campeonatos/{$championship->id}/resumo")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('championships/Summary')
            ->where('summary.podium.champion_team_id', $teamB1->id)
            ->where('summary.podium.runner_up_team_id', $teamA1->id)
            ->where('summary.podium.third_place_team_id', $teamB2->id)
            ->where('summary.total_goals', 30)
            ->where('summary.top_scorers.0.player_id', $b1Scorer->id)
            ->where('summary.top_scorers.0.goals', 9));

    $auditActions = ChampionshipAuditLog::where('championship_id', $championship->id)
        ->pluck('action')
        ->all();

    expect($auditActions)->toContain('groups_drawn')
        ->and($auditActions)->toContain('bracket_generated')
        ->and($auditActions)->toContain('game_reopened')
        ->and($auditActions)->toContain('event_removed')
        ->and($auditActions)->toContain('game_finished');
});

test('rejeita upload de escudo em formato SVG ou arquivo que não seja imagem raster', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/campeonatos', [
        'name' => 'Copa Segurança de Upload',
        'city' => 'Curitiba',
    ])->assertRedirect();

    $championship = Championship::firstOrFail();

    $svgFile = UploadedFile::fake()->create('escudo.svg', 16, 'image/svg+xml');
    $this->post("/campeonatos/{$championship->id}/equipes", [
        'name' => 'Equipe SVG',
        'logo' => $svgFile,
    ])->assertRedirect()->assertSessionHasErrors('logo');

    $pdfFile = UploadedFile::fake()->create('regulamento.pdf', 64, 'application/pdf');
    $this->post("/campeonatos/{$championship->id}/equipes", [
        'name' => 'Equipe PDF',
        'logo' => $pdfFile,
    ])->assertRedirect()->assertSessionHasErrors('logo');

    $txtFile = UploadedFile::fake()->create('script.txt', 8, 'text/plain');
    $this->post("/campeonatos/{$championship->id}/equipes", [
        'name' => 'Equipe TXT',
        'logo' => $txtFile,
    ])->assertRedirect()->assertSessionHasErrors('logo');

    expect(ChampionshipTeam::count())->toBe(0);
});

test('isola rotas entre contas (404 cross-tenant) e valida hierarquia de parâmetros via scopeBindings', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $this->actingAs($owner);

    $this->post('/campeonatos', ['name' => 'Campeonato A', 'city' => 'São Paulo']);
    $this->post('/campeonatos', ['name' => 'Campeonato B', 'city' => 'Campinas']);

    [$champA, $champB] = Championship::orderBy('id')->get();

    for ($i = 1; $i <= 4; $i++)
    {
        $this->post("/campeonatos/{$champA->id}/equipes", ['name' => "Time A{$i}"]);
        $this->post("/campeonatos/{$champB->id}/equipes", ['name' => "Time B{$i}"]);
    }

    $teamA1 = ChampionshipTeam::where('championship_id', $champA->id)->firstOrFail();
    $teamB1 = ChampionshipTeam::where('championship_id', $champB->id)->firstOrFail();

    $this->post("/campeonatos/{$champA->id}/equipes/{$teamA1->id}/jogadores", [
        'name' => 'Atleta A1',
        'jersey_number' => 10,
    ]);
    $playerA1 = ChampionshipPlayer::where('championship_team_id', $teamA1->id)->firstOrFail();

    $this->post("/campeonatos/{$champA->id}/sorteio");
    $this->post("/campeonatos/{$champB->id}/sorteio");

    $gameA = ChampionshipGame::where('championship_id', $champA->id)
        ->where(fn ($q) => $q->where('home_team_id', $teamA1->id)->orWhere('away_team_id', $teamA1->id))
        ->firstOrFail();
    $gameB = ChampionshipGame::where('championship_id', $champB->id)->firstOrFail();

    $this->post("/campeonatos/{$champA->id}/jogos/{$gameA->id}/iniciar");

    $sideA1 = (int) $gameA->home_team_id === (int) $teamA1->id ? 'home' : 'away';
    $this->post("/campeonatos/{$champA->id}/jogos/{$gameA->id}/gols", [
        'side' => $sideA1,
        'scorer_id' => $playerA1->id,
        'period' => 1,
    ]);
    $eventA = ChampionshipGameEvent::where('championship_game_id', $gameA->id)->firstOrFail();

    // 1. Cross-championship within same tenant returns 404 due to scopeBindings()
    $this->get("/campeonatos/{$champA->id}/jogos/{$gameB->id}")->assertNotFound();
    $this->post("/campeonatos/{$champA->id}/jogos/{$gameB->id}/iniciar")->assertNotFound();
    $this->patch("/campeonatos/{$champA->id}/equipes/{$teamB1->id}", ['name' => 'Cruzado'])->assertNotFound();
    $this->delete("/campeonatos/{$champB->id}/jogos/{$gameB->id}/eventos/{$eventA->id}")->assertNotFound();

    // 2. Other user (cross-tenant) gets 404 on show, summary, team, player, game and event routes and cannot record goals
    $this->actingAs($intruder);

    $this->get("/campeonatos/{$champA->id}")->assertNotFound();
    $this->get("/campeonatos/{$champA->id}/resumo")->assertNotFound();
    $this->patch("/campeonatos/{$champA->id}/equipes/{$teamA1->id}", ['name' => 'Invasão'])->assertNotFound();
    $this->delete("/campeonatos/{$champA->id}/equipes/{$teamA1->id}")->assertNotFound();
    $this->get("/campeonatos/{$champA->id}/jogos/{$gameA->id}")->assertNotFound();
    $this->post("/campeonatos/{$champA->id}/jogos/{$gameA->id}/gols", [
        'side' => $sideA1,
        'scorer_id' => $playerA1->id,
        'period' => 1,
    ])->assertNotFound();
    $this->delete("/campeonatos/{$champA->id}/jogos/{$gameA->id}/eventos/{$eventA->id}")->assertNotFound();

    expect(ChampionshipGameEvent::withoutGlobalScopes()->where('championship_game_id', $gameA->id)->count())->toBe(1);
});

test('converte erros de domínio do TournamentEngine em redirecionamento com erros de validação (302) e nunca erro 500', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post('/campeonatos', [
        'name' => 'Copa Erros de Domínio',
        'city' => 'Santos',
        'groups_count' => 2,
        'qualifiers_per_group' => 1,
        'has_third_place_match' => false,
    ])->assertRedirect();

    $championship = Championship::firstOrFail();

    $teams = [];
    $players = [];
    for ($i = 1; $i <= 5; $i++)
    {
        $this->post("/campeonatos/{$championship->id}/equipes", ['name' => "Equipe {$i}"]);
        $team = ChampionshipTeam::where('championship_id', $championship->id)->where('name', "Equipe {$i}")->firstOrFail();
        $this->post("/campeonatos/{$championship->id}/equipes/{$team->id}/jogadores", [
            'name' => "Jogador {$i}",
            'jersey_number' => 10,
        ]);
        $teams[$i] = $team;
        $players[$team->id] = ChampionshipPlayer::where('championship_team_id', $team->id)->firstOrFail();
    }

    // Delete 5th team while in draft
    $this->delete("/campeonatos/{$championship->id}/equipes/{$teams[5]->id}")
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect(ChampionshipTeam::where('championship_id', $championship->id)->count())->toBe(4);

    // First draw succeeds
    $this->post("/campeonatos/{$championship->id}/sorteio")->assertRedirect()->assertSessionHasNoErrors();

    // Redraw with refazer=true and motivo succeeds before any game starts
    $this->post("/campeonatos/{$championship->id}/sorteio", [
        'refazer' => true,
        'motivo' => 'Ajuste solicitado na reunião técnica',
    ])->assertRedirect()->assertSessionHasNoErrors();

    // (1) Drawing a second time without refazer=true returns validation error redirect (not 500)
    $this->from("/campeonatos/{$championship->id}")
        ->post("/campeonatos/{$championship->id}/sorteio")
        ->assertRedirect("/campeonatos/{$championship->id}")
        ->assertSessionHasErrors('championship');

    // (2) Editing locked rules after draw returns validation error redirect (not 500)
    $this->from("/campeonatos/{$championship->id}")
        ->patch("/campeonatos/{$championship->id}/regras", ['groups_count' => 1])
        ->assertRedirect("/campeonatos/{$championship->id}")
        ->assertSessionHasErrors('championship');

    // Start first group game
    $groupGames = ChampionshipGame::where('championship_id', $championship->id)
        ->where('phase', GamePhase::Group)
        ->orderBy('id')
        ->get();

    $firstGame = $groupGames[0];
    $this->post("/campeonatos/{$championship->id}/jogos/{$firstGame->id}/iniciar")->assertRedirect();

    // (3) Goal scored with a player belonging to the opposing team returns validation error redirect (not 500)
    $awayPlayer = $players[(int) $firstGame->away_team_id];
    $this->from("/campeonatos/{$championship->id}/jogos/{$firstGame->id}")
        ->post("/campeonatos/{$championship->id}/jogos/{$firstGame->id}/gols", [
            'side' => 'home',
            'scorer_id' => $awayPlayer->id,
            'period' => 1,
        ])
        ->assertRedirect("/campeonatos/{$championship->id}/jogos/{$firstGame->id}")
        ->assertSessionHasErrors('championship');

    // Finish all group games (Group A tied 0x0 to exercise manual tie resolution via HTTP, Group B won 1x0 by home)
    foreach ($groupGames as $groupGame)
    {
        $this->post("/campeonatos/{$championship->id}/jogos/{$groupGame->id}/iniciar");
        if ($groupGame->group_code === 'B')
        {
            $homePlayer = $players[(int) $groupGame->home_team_id];
            $this->post("/campeonatos/{$championship->id}/jogos/{$groupGame->id}/gols", [
                'side' => 'home',
                'scorer_id' => $homePlayer->id,
                'period' => 1,
            ]);
        }
        $this->post("/campeonatos/{$championship->id}/jogos/{$groupGame->id}/finalizar");
    }

    // Generating bracket while Group A has an unresolved tie across the qualification line returns validation error
    $this->from("/campeonatos/{$championship->id}")
        ->post("/campeonatos/{$championship->id}/gerar-mata-mata")
        ->assertRedirect("/campeonatos/{$championship->id}")
        ->assertSessionHasErrors('championship');

    // Resolve Group A tie manually via HTTP
    $groupATeamIds = ChampionshipTeam::where('championship_id', $championship->id)
        ->where('group_code', 'A')
        ->orderBy('id')
        ->pluck('id')
        ->all();

    $this->post("/campeonatos/{$championship->id}/desempate", [
        'group_code' => 'A',
        'ordered_team_ids' => array_reverse($groupATeamIds),
        'motivo' => 'Sorteio na mesa diretora',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->post("/campeonatos/{$championship->id}/gerar-mata-mata")->assertRedirect()->assertSessionHasNoErrors();

    $final = ChampionshipGame::where('championship_id', $championship->id)->where('slot_key', 'FINAL')->firstOrFail();
    $this->post("/campeonatos/{$championship->id}/jogos/{$final->id}/iniciar")->assertRedirect();

    // (4) Reopening a group game after knockout has started returns validation error redirect (not 500)
    $this->from("/campeonatos/{$championship->id}")
        ->post("/campeonatos/{$championship->id}/jogos/{$firstGame->id}/reabrir", [
            'motivo' => 'Tentativa após início do mata-mata',
            'resetar_chave' => true,
        ])
        ->assertRedirect("/campeonatos/{$championship->id}")
        ->assertSessionHasErrors('championship');
});

test('mantém todas as rotas do fluxo casual de pelada funcionando normalmente', function () {
    [$teamUser, $scorer] = $this->teamWithPlayer();
    $mate = Player::factory()->for($teamUser)->create(['name' => 'Colega Pelada', 'jersey_number' => 14, 'level' => 3]);

    $this->actingAs($teamUser);

    $this->get('/jogadores')->assertOk();
    $this->get('/sorteio')->assertOk();
    $this->get('/partida')->assertOk();
    $this->post('/partidas', matchPayload($teamUser, $scorer, $mate))->assertRedirect();
    $this->get('/partidas')->assertOk();
    $this->get('/estatisticas')->assertOk();
    $this->get('/time')->assertOk();
});

test('mapeia apenas ChampionshipException para erro de validação sem interceptar DomainException genérica', function () {
    Route::post('/_test/championship-exception', function () {
        throw new ChampionshipException('Erro específico do campeonato.');
    })->middleware('web');

    Route::post('/_test/generic-domain-exception', function () {
        throw new DomainException('Erro de outro domínio.');
    })->middleware('web');

    $this->from('/campeonatos')
        ->post('/_test/championship-exception')
        ->assertRedirect('/campeonatos')
        ->assertSessionHasErrors(['championship' => 'Erro específico do campeonato.']);

    $this->post('/_test/generic-domain-exception')
        ->assertStatus(500);
});
