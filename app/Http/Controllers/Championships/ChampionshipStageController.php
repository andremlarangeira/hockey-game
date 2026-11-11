<?php

namespace App\Http\Controllers\Championships;

use App\Championships\ChampionshipException;
use App\Championships\Enums\ChampionshipStatus;
use App\Championships\TournamentEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Championships\DrawGroupsRequest;
use App\Http\Requests\Championships\ResolveGroupTieRequest;
use App\Models\Championship;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ChampionshipStageController extends Controller
{
    public function __construct(
        private readonly TournamentEngine $engine,
    ) {}

    public function draw(
        DrawGroupsRequest $request,
        Championship $championship,
    ): RedirectResponse {
        if ($championship->status !== ChampionshipStatus::Draft && ! $request->redraw())
        {
            throw new ChampionshipException('Os grupos já foram sorteados. Confirme a opção de refazer sorteio e informe o motivo.');
        }

        $this->engine->drawGroups(
            championship: $championship,
            actor: $request->user(),
            redraw: $request->redraw(),
            reason: $request->reason(),
            explicitGroups: $request->explicitGroups(),
        );

        return back()->with('success', 'Grupos sorteados e jogos gerados.');
    }

    public function resolveTie(
        ResolveGroupTieRequest $request,
        Championship $championship,
    ): RedirectResponse {
        $this->engine->resolveTie(
            championship: $championship,
            group: $request->groupCode(),
            orderedTeamIds: $request->orderedTeamIds(),
            actor: $request->user(),
            reason: $request->reason(),
        );

        return back()->with('success', 'Desempate manual registrado na auditoria.');
    }

    public function generateBracket(
        Request $request,
        Championship $championship,
    ): RedirectResponse {
        $this->engine->generateBracket(
            championship: $championship,
            actor: $request->user(),
        );

        return back()->with('success', 'Chave do mata-mata gerada com sucesso.');
    }
}
