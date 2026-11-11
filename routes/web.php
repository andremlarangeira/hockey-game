<?php

use App\Http\Controllers\Championships\ChampionshipController;
use App\Http\Controllers\Championships\ChampionshipGameController;
use App\Http\Controllers\Championships\ChampionshipPlayerController;
use App\Http\Controllers\Championships\ChampionshipPublicLinkController;
use App\Http\Controllers\Championships\ChampionshipStageController;
use App\Http\Controllers\Championships\ChampionshipTeamController;
use App\Http\Controllers\Championships\PublicChampionshipController;
use App\Http\Controllers\DrawController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\TeamController;
use App\Models\HockeyMatch;
use Illuminate\Support\Facades\Route;

Route::bind('match', fn (string $value): HockeyMatch => HockeyMatch::findOrFail($value));

Route::redirect('/', '/jogadores')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/jogadores')->name('dashboard');
    Route::resource('jogadores', PlayerController::class)->parameters(['jogadores' => 'player'])->except(['show', 'create', 'edit'])->names('players');
    Route::patch('jogadores/{player}/presenca', [PlayerController::class, 'presence'])->name('players.presence');
    Route::post('presenca', [PlayerController::class, 'presenceAll'])->name('players.presence-all');
    Route::get('sorteio', [DrawController::class, 'index'])->name('draw.index');
    Route::post('sorteio', [DrawController::class, 'store'])->name('draw.store');
    Route::get('partida', [MatchController::class, 'create'])->name('matches.create');
    Route::get('partidas', [MatchController::class, 'index'])->name('matches.index');
    Route::post('partidas', [MatchController::class, 'store'])->name('matches.store');
    Route::get('partidas/{match}', [MatchController::class, 'show'])->name('matches.show');
    Route::get('estatisticas', StatsController::class)->name('stats.index');
    Route::get('time', [TeamController::class, 'edit'])->name('team.edit');
    Route::patch('time', [TeamController::class, 'update'])->name('team.update');

    Route::prefix('campeonatos')->name('championships.')->scopeBindings()->group(function () {
        Route::get('/', [ChampionshipController::class, 'index'])->name('index');
        Route::post('/', [ChampionshipController::class, 'store'])->name('store');
        Route::get('{championship}', [ChampionshipController::class, 'show'])->name('show');
        Route::match(['put', 'patch'], '{championship}', [ChampionshipController::class, 'update'])->name('update');
        Route::match(['put', 'patch'], '{championship}/regras', [ChampionshipController::class, 'updateRules'])->name('rules.update');
        Route::get('{championship}/resumo', [ChampionshipController::class, 'summary'])->name('summary');
        Route::post('{championship}/link-publico', [ChampionshipPublicLinkController::class, 'store'])->name('public-link.store');
        Route::post('{championship}/link-publico/regenerar', [ChampionshipPublicLinkController::class, 'regenerate'])->name('public-link.regenerate');
        Route::delete('{championship}/link-publico', [ChampionshipPublicLinkController::class, 'destroy'])->name('public-link.destroy');

        Route::post('{championship}/equipes', [ChampionshipTeamController::class, 'store'])->name('teams.store');
        Route::match(['put', 'patch', 'post'], '{championship}/equipes/{team}', [ChampionshipTeamController::class, 'update'])->name('teams.update');
        Route::delete('{championship}/equipes/{team}', [ChampionshipTeamController::class, 'destroy'])->name('teams.destroy');

        Route::post('{championship}/times', [ChampionshipTeamController::class, 'store'])->name('times.store');
        Route::match(['put', 'patch', 'post'], '{championship}/times/{team}', [ChampionshipTeamController::class, 'update'])->name('times.update');
        Route::delete('{championship}/times/{team}', [ChampionshipTeamController::class, 'destroy'])->name('times.destroy');

        Route::post('{championship}/equipes/{team}/jogadores', [ChampionshipPlayerController::class, 'store'])->name('players.store');
        Route::match(['put', 'patch'], '{championship}/equipes/{team}/jogadores/{player}', [ChampionshipPlayerController::class, 'update'])->name('players.update');
        Route::delete('{championship}/equipes/{team}/jogadores/{player}', [ChampionshipPlayerController::class, 'destroy'])->name('players.destroy');

        Route::post('{championship}/times/{team}/jogadores', [ChampionshipPlayerController::class, 'store'])->name('times.players.store');
        Route::match(['put', 'patch'], '{championship}/times/{team}/jogadores/{player}', [ChampionshipPlayerController::class, 'update'])->name('times.players.update');
        Route::delete('{championship}/times/{team}/jogadores/{player}', [ChampionshipPlayerController::class, 'destroy'])->name('times.players.destroy');

        Route::post('{championship}/sorteio', [ChampionshipStageController::class, 'draw'])->name('draw');
        Route::post('{championship}/sorteio-grupos', [ChampionshipStageController::class, 'draw'])->name('groups.draw');
        Route::post('{championship}/desempate', [ChampionshipStageController::class, 'resolveTie'])->name('resolve-tie');
        Route::post('{championship}/desempate-manual', [ChampionshipStageController::class, 'resolveTie'])->name('groups.resolve-tie');
        Route::post('{championship}/gerar-mata-mata', [ChampionshipStageController::class, 'generateBracket'])->name('bracket.generate');

        Route::get('{championship}/jogos/{game}', [ChampionshipGameController::class, 'showLive'])->name('games.live');
        Route::match(['put', 'patch'], '{championship}/jogos/{game}/agendamento', [ChampionshipGameController::class, 'schedule'])->name('games.schedule');
        Route::match(['put', 'patch'], '{championship}/jogos/{game}', [ChampionshipGameController::class, 'schedule'])->name('games.update');
        Route::post('{championship}/jogos/{game}/iniciar', [ChampionshipGameController::class, 'start'])->name('games.start');
        Route::post('{championship}/jogos/{game}/gols', [ChampionshipGameController::class, 'recordGoal'])->name('games.goals.store');
        Route::post('{championship}/jogos/{game}/penaltis', [ChampionshipGameController::class, 'recordShootout'])->name('games.shootouts.store');
        Route::post('{championship}/jogos/{game}/shootout', [ChampionshipGameController::class, 'recordShootout'])->name('games.shootout.store');
        Route::delete('{championship}/jogos/{game}/eventos/{event}', [ChampionshipGameController::class, 'removeEvent'])->name('games.events.destroy');
        Route::post('{championship}/jogos/{game}/finalizar', [ChampionshipGameController::class, 'finish'])->name('games.finish');
        Route::post('{championship}/jogos/{game}/reabrir', [ChampionshipGameController::class, 'reopen'])->name('games.reopen');
    });
});

Route::middleware(['throttle:120,1'])
    ->prefix('p/campeonatos/{token}')
    ->where(['token' => '[A-Za-z0-9]{40}'])
    ->name('public.championships.')
    ->group(function (): void {
        Route::get('/', [PublicChampionshipController::class, 'show'])->name('show');
        Route::get('/jogos/{game}', [PublicChampionshipController::class, 'game'])
            ->whereNumber('game')
            ->name('games.show');
    });

require __DIR__.'/settings.php';
