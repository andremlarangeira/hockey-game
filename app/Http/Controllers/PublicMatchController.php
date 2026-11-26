<?php

namespace App\Http\Controllers;

use App\Models\HockeyMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class PublicMatchController extends Controller
{
    public function __construct(
        private readonly MatchPresenter $presenter,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $match = HockeyMatch::withoutGlobalScope('team')
            ->where('public_token', $token)
            ->whereNotNull('public_enabled_at')
            ->firstOrFail();

        $response = Inertia::render('public/matches/Show', $this->presenter->publicProps($match))
            ->toResponse($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
