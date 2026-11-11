<?php

namespace App\Http\Controllers\Championships;

use App\Championships\TournamentEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Championships\FinishChampionshipGameRequest;
use App\Http\Requests\Championships\RecordChampionshipGoalRequest;
use App\Http\Requests\Championships\RecordShootoutAttemptRequest;
use App\Http\Requests\Championships\RemoveChampionshipGameEventRequest;
use App\Http\Requests\Championships\ReopenChampionshipGameRequest;
use App\Http\Requests\Championships\ScheduleChampionshipGameRequest;
use App\Http\Requests\Championships\StartChampionshipGameRequest;
use App\Models\Championship;
use App\Models\ChampionshipGame;
use App\Models\ChampionshipGameEvent;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ChampionshipGameController extends Controller
{
    public function __construct(
        private readonly TournamentEngine $engine,
        private readonly ChampionshipPresenter $presenter,
    ) {}

    public function showLive(
        Championship $championship,
        ChampionshipGame $game,
    ): Response {
        return Inertia::render(
            'championships/GameLive',
            $this->presenter->liveGameProps($championship, $game),
        );
    }

    public function schedule(
        ScheduleChampionshipGameRequest $request,
        Championship $championship,
        ChampionshipGame $game,
    ): RedirectResponse {
        $this->engine->scheduleGame($game, $request->toCommand());

        return back()->with('success', 'Agendamento da partida atualizado.');
    }

    public function start(
        StartChampionshipGameRequest $request,
        Championship $championship,
        ChampionshipGame $game,
    ): RedirectResponse {
        $this->engine->startGame(
            game: $game,
            actor: $request->user(),
            startedAt: $request->startedAt(),
        );

        return back()->with('success', 'Partida iniciada.');
    }

    public function recordGoal(
        RecordChampionshipGoalRequest $request,
        Championship $championship,
        ChampionshipGame $game,
    ): RedirectResponse {
        $this->engine->recordGoal($game, $request->toCommand());

        return back()->with('success', 'Gol registrado na súmula.');
    }

    public function recordShootout(
        RecordShootoutAttemptRequest $request,
        Championship $championship,
        ChampionshipGame $game,
    ): RedirectResponse {
        $this->engine->recordShootoutAttempt($game, $request->toCommand());

        return back()->with('success', 'Cobrança de shootout registrada.');
    }

    public function removeEvent(
        RemoveChampionshipGameEventRequest $request,
        Championship $championship,
        ChampionshipGame $game,
        ChampionshipGameEvent $event,
    ): RedirectResponse {
        $this->engine->removeEvent(
            event: $event,
            actor: $request->user(),
            reason: $request->reason(),
        );

        return back()->with('success', 'Evento removido da súmula.');
    }

    public function finish(
        FinishChampionshipGameRequest $request,
        Championship $championship,
        ChampionshipGame $game,
    ): RedirectResponse {
        $this->engine->finishGame(
            game: $game,
            actor: $request->user(),
            finishedAt: $request->finishedAt(),
        );

        return back()->with('success', 'Partida encerrada e classificação atualizada.');
    }

    public function reopen(
        ReopenChampionshipGameRequest $request,
        Championship $championship,
        ChampionshipGame $game,
    ): RedirectResponse {
        $this->engine->reopenGame(
            game: $game,
            actor: $request->user(),
            reason: $request->reason(),
            resetBracket: $request->resetBracket(),
        );

        return back()->with('success', 'Partida reaberta para retificação.');
    }
}
