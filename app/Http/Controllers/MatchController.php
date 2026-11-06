<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMatchRequest;
use App\Models\HockeyMatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MatchController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('matches/Index', [
            'matches' => $request->user()->matches()->withCount('goals')->latest('finished_at')->latest()->get()
                ->map(fn (HockeyMatch $match): array => [
                    'id' => $match->id,
                    'title' => $match->roster->home->name.' × '.$match->roster->away->name,
                    'home_name' => $match->roster->home->name,
                    'away_name' => $match->roster->away->name,
                    'home_color' => $match->roster->home->color,
                    'away_color' => $match->roster->away->color,
                    'home_score' => $match->home_score,
                    'away_score' => $match->away_score,
                    'goals_count' => $match->goals_count,
                    'finished_at' => $match->finished_at?->format('d/m/Y H:i'),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('matches/Create', [
            'settings' => ['minutes' => 10, 'periods' => 2, 'rest_seconds' => 120, 'countdown_seconds' => 5],
            'sounds' => [
                'start' => ['type' => 'builtin', 'file' => '/sons/apito-inicio.wav'],
                'end' => ['type' => 'builtin', 'file' => '/sons/buzzer-fim.wav'],
                'rest_end' => ['type' => 'builtin', 'file' => '/sons/bip-fim-descanso.wav'],
            ],
        ]);
    }

    public function store(StoreMatchRequest $request): \Symfony\Component\HttpFoundation\Response
    {
        $data = $request->validated();
        $match = DB::transaction(function () use ($request, $data): HockeyMatch {
            $match = $request->user()->matches()->create([
                'home_score' => $data['home_score'], 'away_score' => $data['away_score'],
                'settings' => $request->settings(), 'roster' => $request->roster(),
                'timeline' => $data['timeline'], 'started_at' => $data['started_at'] ?? null,
                'finished_at' => $data['finished_at'] ?? now(),
            ]);
            $match->goals()->createMany($request->goalAttributes());

            return $match;
        });

        return Inertia::location(route('matches.show', $match));
    }

    public function show(HockeyMatch $match): Response
    {
        return Inertia::render('matches/Show', [
            'match' => $match,
            'goals' => $match->goals()->orderBy('recorded_at')->get(),
        ]);
    }
}
