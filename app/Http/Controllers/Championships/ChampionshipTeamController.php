<?php

namespace App\Http\Controllers\Championships;

use App\Championships\TournamentEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Championships\StoreChampionshipTeamRequest;
use App\Http\Requests\Championships\UpdateChampionshipTeamRequest;
use App\Models\Championship;
use App\Models\ChampionshipTeam;
use Illuminate\Http\RedirectResponse;

class ChampionshipTeamController extends Controller
{
    public function __construct(
        private readonly TournamentEngine $engine,
    ) {}

    public function store(
        StoreChampionshipTeamRequest $request,
        Championship $championship,
    ): RedirectResponse {
        $this->engine->enrollTeam(
            championship: $championship,
            name: (string) $request->validated('name'),
            shortName: $request->validated('short_name'),
            primaryColor: $request->primaryColor(),
            secondaryColor: $request->secondaryColor(),
            logo: $request->logoFile(),
            seedPot: $request->seedPot(),
        );

        return back()->with('success', 'Equipe inscrita no campeonato.');
    }

    public function update(
        UpdateChampionshipTeamRequest $request,
        Championship $championship,
        ChampionshipTeam $team,
    ): RedirectResponse {
        $this->engine->updateTeam(
            team: $team,
            name: $request->validated('name'),
            shortName: $request->has('short_name') ? $request->validated('short_name') : $team->short_name,
            primaryColor: $request->validated('primary_color'),
            secondaryColor: $request->validated('secondary_color'),
            logo: $request->logoFile(),
            seedPot: $request->seedPot(),
            removeLogo: $request->boolean('remove_logo'),
        );

        return back()->with('success', 'Equipe atualizada.');
    }

    public function destroy(
        Championship $championship,
        ChampionshipTeam $team,
    ): RedirectResponse {
        $this->engine->removeTeam($team);

        return back()->with('success', 'Equipe removida do campeonato.');
    }
}
