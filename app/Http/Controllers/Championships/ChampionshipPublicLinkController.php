<?php

namespace App\Http\Controllers\Championships;

use App\Championships\TournamentEngine;
use App\Http\Controllers\Controller;
use App\Models\Championship;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ChampionshipPublicLinkController extends Controller
{
    public function __construct(
        private readonly TournamentEngine $engine,
    ) {}

    public function store(Request $request, Championship $championship): RedirectResponse
    {
        $this->engine->enablePublicLink($championship, $request->user());

        return back()->with('success', 'Link público ativado.');
    }

    public function regenerate(Request $request, Championship $championship): RedirectResponse
    {
        $this->engine->regeneratePublicLink($championship, $request->user());

        return back()->with('success', 'Link público regenerado.');
    }

    public function destroy(Request $request, Championship $championship): RedirectResponse
    {
        $this->engine->disablePublicLink($championship, $request->user());

        return back()->with('success', 'Link público desativado.');
    }
}
