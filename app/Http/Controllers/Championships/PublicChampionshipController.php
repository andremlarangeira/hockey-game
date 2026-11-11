<?php

namespace App\Http\Controllers\Championships;

use App\Championships\PublicChampionshipResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class PublicChampionshipController extends Controller
{
    public function __construct(
        private readonly PublicChampionshipResolver $resolver,
        private readonly PublicChampionshipPresenter $presenter,
    ) {}

    public function show(Request $request, string $token): Response
    {
        $championship = $this->resolver->championship($token);

        return $this->withPrivacyHeaders(
            Inertia::render('public/championships/Show', $this->presenter->showProps($championship))
                ->toResponse($request),
        );
    }

    public function game(Request $request, string $token, int $game): Response
    {
        $championship = $this->resolver->championship($token);
        $championshipGame = $this->resolver->game($championship, $game);

        return $this->withPrivacyHeaders(
            Inertia::render('public/championships/Game', $this->presenter->gameProps($championship, $championshipGame))
                ->toResponse($request),
        );
    }

    private function withPrivacyHeaders(Response $response): Response
    {
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
