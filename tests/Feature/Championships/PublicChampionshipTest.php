<?php

use App\Championships\Data\ChampionshipRules;
use App\Championships\Data\GoalCommand;
use App\Championships\Enums\PlayerPosition;
use App\Championships\Enums\TeamSide;
use App\Championships\TournamentEngine;
use App\Models\Championship;
use App\Models\ChampionshipAuditLog;
use App\Models\ChampionshipGame;
use App\Models\ChampionshipPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

/** @return array{Championship, ChampionshipGame, array<int, ChampionshipPlayer>} */
function publicChampionshipFixture(TournamentEngine $engine, User $owner): array
{
    $championship = $engine->createChampionship(
        user: $owner,
        name: 'Copa Pública',
        city: 'São Paulo',
        venue: 'Ginásio Central',
        rules: ChampionshipRules::defaults(groupsCount: 1, qualifiersPerGroup: 2),
    );

    foreach (['Tigres', 'Lobos', 'Falcões', 'Ursos'] as $index => $name)
    {
        $team = $engine->enrollTeam($championship, $name, strtoupper(substr($name, 0, 3)), seedPot: $index < 2 ? 1 : 2);
        $engine->addPlayer($team, "Atacante {$name}", 10, PlayerPosition::Forward);
    }

    $engine->drawGroups($championship, actor: $owner);
    $game = ChampionshipGame::withoutGlobalScope('team')
        ->where('championship_id', $championship->id)
        ->orderBy('id')
        ->firstOrFail();
    $homePlayer = ChampionshipPlayer::withoutGlobalScope('team')
        ->where('championship_team_id', $game->home_team_id)
        ->firstOrFail();

    return [$championship, $game, [$homePlayer]];
}

/** @param array<mixed> $value */
function assertHasNoPrivatePublicData(array $value): void
{
    $forbidden = ['user_id', 'email', 'manual_tie_resolutions', 'audit', 'logo_path', 'rules', 'public_token'];

    foreach ($value as $key => $item)
    {
        expect($forbidden)->not->toContain((string) $key);

        if (is_array($item))
        {
            assertHasNoPrivatePublicData($item);
        }
    }
}

test('convidado vê o campeonato e a partida pública com cabeçalhos de privacidade e somente dados permitidos', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $engine = app(TournamentEngine::class);
    [$championship, $game, $players] = publicChampionshipFixture($engine, $owner);
    $engine->enablePublicLink($championship, $owner);
    $engine->startGame($game, $owner);
    $event = $engine->recordGoal($game, new GoalCommand(TeamSide::Home, $players[0]->id, period: 1, elapsedSeconds: 42));
    $token = $championship->public_token;

    $this->actingAsGuest();
    $show = $this->get(route('public.championships.show', $token));
    $show->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/championships/Show', true)
            ->has('championship.status_label')
            ->has('teams', 4)
            ->has('games')
            ->has('standings')
            ->has('stats'));

    $props = $show->viewData('page')['props'];
    assertHasNoPrivatePublicData($props);

    $standings = $engine->standings($championship);
    foreach ($standings as $groupCode => $group)
    {
        expect($props['standings'][$groupCode]['rows'])->toHaveCount(count($group->rows));
        foreach ($group->rows as $index => $row)
        {
            expect($props['standings'][$groupCode]['rows'][$index]['position'])->toBe($row->position)
                ->and($props['standings'][$groupCode]['rows'][$index]['points'])->toBe($row->points);
        }
    }

    $summary = $engine->summary($championship);
    expect($props['stats']['total_games'])->toBe($summary->totalGames)
        ->and($props['stats']['finished_games'])->toBe($summary->finishedGames)
        ->and($props['stats']['total_goals'])->toBe($summary->totalGoals);

    $live = $this->get(route('public.championships.games.show', ['token' => $token, 'game' => $game->id]));
    $live->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/championships/Game', true)
            ->where('game.home_regular_score', 1)
            ->where('events.0.id', $event->id)
            ->where('events.0.primary_player_name', $players[0]->name)
            ->where('poll_interval_ms', 5000));

    $engine->finishGame($game, $owner);

    $this->get(route('public.championships.games.show', ['token' => $token, 'game' => $game->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('poll_interval_ms', null));
});

test('link público só resolve links ativos e nunca atravessa campeonatos ou contas', function () {
    $owner = User::factory()->create();
    $otherOwner = User::factory()->create();
    $this->actingAs($owner);
    $engine = app(TournamentEngine::class);
    [$championship, $game] = publicChampionshipFixture($engine, $owner);
    $engine->enablePublicLink($championship, $owner);
    $token = $championship->fresh()->public_token;

    [$otherChampionship, $otherGame] = publicChampionshipFixture($engine, $owner);
    $engine->enablePublicLink($otherChampionship, $owner);

    $this->actingAs($otherOwner);
    [$foreignChampionship, $foreignGame] = publicChampionshipFixture($engine, $otherOwner);
    $engine->enablePublicLink($foreignChampionship, $otherOwner);
    $foreignToken = $foreignChampionship->public_token;

    $this->actingAsGuest();
    $this->get('/p/campeonatos/'.str_repeat('a', 40))->assertNotFound();
    $this->get(route('public.championships.games.show', ['token' => $token, 'game' => $otherGame->id]))->assertNotFound();
    $this->get(route('public.championships.games.show', ['token' => $token, 'game' => $foreignGame->id]))->assertNotFound();
    $this->get(route('public.championships.show', $foreignToken))->assertOk();

    $this->actingAs($owner);
    $this->delete(route('championships.public-link.destroy', $championship))->assertRedirect();
    $this->actingAsGuest();
    $this->get(route('public.championships.show', $token))->assertNotFound();

    $this->actingAs($owner);
    $this->post(route('championships.public-link.store', $championship))->assertRedirect();
    $oldToken = $championship->fresh()->public_token;
    $this->post(route('championships.public-link.regenerate', $championship))->assertRedirect();
    $newToken = $championship->fresh()->public_token;
    expect($newToken)->not->toBe($oldToken);

    $this->actingAsGuest();
    $this->get(route('public.championships.show', $oldToken))->assertNotFound();
    $this->get(route('public.championships.show', $newToken))->assertOk();
});

test('somente GET é público, as mutações exigem autenticação e o proprietário audita o ciclo do link', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($owner);
    $engine = app(TournamentEngine::class);
    [$championship] = publicChampionshipFixture($engine, $owner);

    foreach (Route::getRoutes() as $route)
    {
        if (str_starts_with((string) $route->getName(), 'public.championships.'))
        {
            expect($route->methods())->toBe(['GET', 'HEAD']);
        }
    }

    $this->actingAsGuest();
    $this->post(route('championships.public-link.store', $championship))->assertRedirect(route('login'));
    $this->post(route('championships.public-link.regenerate', $championship))->assertRedirect(route('login'));
    $this->delete(route('championships.public-link.destroy', $championship))->assertRedirect(route('login'));

    $this->actingAs($otherUser);
    $this->post(route('championships.public-link.store', $championship))->assertNotFound();

    $this->actingAs($owner);
    $this->post(route('championships.public-link.store', $championship))->assertRedirect();
    $firstToken = $championship->fresh()->public_token;
    $this->post(route('championships.public-link.store', $championship))->assertRedirect();
    expect($championship->fresh()->public_token)->toBe($firstToken);
    $this->post(route('championships.public-link.regenerate', $championship))->assertRedirect();
    $this->delete(route('championships.public-link.destroy', $championship))->assertRedirect();

    expect(ChampionshipAuditLog::withoutGlobalScope('team')
        ->where('championship_id', $championship->id)
        ->whereIn('action', ['public_link_enabled', 'public_link_regenerated', 'public_link_disabled'])
        ->count())->toBeGreaterThanOrEqual(4);
});
