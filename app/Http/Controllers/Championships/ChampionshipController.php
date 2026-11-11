<?php

namespace App\Http\Controllers\Championships;

use App\Championships\Data\ChampionshipRules;
use App\Championships\TournamentEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Championships\StoreChampionshipRequest;
use App\Http\Requests\Championships\UpdateChampionshipDetailsRequest;
use App\Http\Requests\Championships\UpdateChampionshipRulesRequest;
use App\Models\Championship;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ChampionshipController extends Controller
{
    public function __construct(
        private readonly TournamentEngine $engine,
        private readonly ChampionshipPresenter $presenter,
    ) {}

    public function index(): Response
    {
        $championships = Championship::query()
            ->withCount(['teams', 'games'])
            ->latest('id')
            ->get()
            ->map(fn (Championship $championship): array => array_merge(
                $this->presenter->formatChampionship($championship),
                [
                    'teams_count' => $championship->teams_count,
                    'games_count' => $championship->games_count,
                ],
            ))
            ->values()
            ->all();

        return Inertia::render('championships/Index', [
            'championships' => $championships,
            'default_rules' => ChampionshipRules::defaults()->toArray(),
        ]);
    }

    public function store(StoreChampionshipRequest $request): RedirectResponse
    {
        $championship = $this->engine->createChampionship(
            user: $request->user(),
            name: (string) $request->validated('name'),
            city: (string) $request->validated('city'),
            venue: $request->validated('venue'),
            startsOn: $request->startsOn(),
            endsOn: $request->endsOn(),
            rules: $request->championshipRules(),
        );

        return to_route('championships.show', $championship)
            ->with('success', 'Campeonato criado com sucesso.');
    }

    public function show(Championship $championship): Response
    {
        return Inertia::render('championships/Show', $this->presenter->showProps($championship));
    }

    public function update(
        UpdateChampionshipDetailsRequest $request,
        Championship $championship,
    ): RedirectResponse {
        $this->engine->updateDetails(
            championship: $championship,
            name: (string) $request->validated('name'),
            city: (string) $request->validated('city'),
            venue: $request->validated('venue'),
            startsOn: $request->startsOn(),
            endsOn: $request->endsOn(),
        );

        return back()->with('success', 'Dados do campeonato atualizados.');
    }

    public function updateRules(
        UpdateChampionshipRulesRequest $request,
        Championship $championship,
    ): RedirectResponse {
        $this->engine->updateRules(
            championship: $championship,
            rules: $request->championshipRules($championship),
        );

        return back()->with('success', 'Regras do campeonato atualizadas.');
    }

    public function summary(Championship $championship): Response
    {
        return Inertia::render('championships/Summary', $this->presenter->summaryProps($championship));
    }
}
