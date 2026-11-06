<?php

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
});

require __DIR__.'/settings.php';
