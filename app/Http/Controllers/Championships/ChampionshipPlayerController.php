<?php

namespace App\Http\Controllers\Championships;

use App\Championships\TournamentEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Championships\StoreChampionshipPlayerRequest;
use App\Http\Requests\Championships\UpdateChampionshipPlayerRequest;
use App\Models\Championship;
use App\Models\ChampionshipPlayer;
use App\Models\ChampionshipTeam;
use Illuminate\Http\RedirectResponse;

class ChampionshipPlayerController extends Controller
{
    public function __construct(
        private readonly TournamentEngine $engine,
    ) {}

    public function store(
        StoreChampionshipPlayerRequest $request,
        Championship $championship,
        ChampionshipTeam $team,
    ): RedirectResponse {
        $this->engine->addPlayer(
            team: $team,
            name: (string) $request->validated('name'),
            jerseyNumber: (int) $request->validated('jersey_number'),
            position: $request->positionEnum(),
            isGoalkeeper: $request->boolean('is_goalkeeper'),
        );

        return back()->with('success', 'Jogador inscrito na equipe.');
    }

    public function update(
        UpdateChampionshipPlayerRequest $request,
        Championship $championship,
        ChampionshipTeam $team,
        ChampionshipPlayer $player,
    ): RedirectResponse {
        $this->engine->updatePlayer(
            player: $player,
            name: $request->validated('name'),
            jerseyNumber: $request->has('jersey_number') ? (int) $request->validated('jersey_number') : null,
            position: $request->positionEnum(),
            isGoalkeeper: $request->has('is_goalkeeper') ? $request->boolean('is_goalkeeper') : null,
        );

        return back()->with('success', 'Jogador atualizado.');
    }

    public function destroy(
        Championship $championship,
        ChampionshipTeam $team,
        ChampionshipPlayer $player,
    ): RedirectResponse {
        $this->engine->removePlayer($player);

        return back()->with('success', 'Jogador removido da equipe.');
    }
}
