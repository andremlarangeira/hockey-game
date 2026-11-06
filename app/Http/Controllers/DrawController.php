<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Services\BalancedDraw;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DrawController extends Controller
{
    public function index(Request $request, BalancedDraw $draw): Response
    {
        $players = $request->user()->players()->where('present', true)->orderBy('name')->get()
            ->map(fn (Player $player): array => $player->toDrawPlayer())
            ->values();
        $mode = $request->session()->get('draw_mode', 'players');
        $size = $request->session()->get('draw_size', 4);

        try
        {
            $result = $draw->draw($players, $mode, $size);
        }
        catch (\InvalidArgumentException)
        {
            $result = ['teams' => [], 'reserves' => []];
        }

        return Inertia::render('draw/Index', [
            'players' => $players,
            'result' => $result,
            'mode' => $mode,
            'size' => $size,
            'error' => $players->count() < 2 ? 'Marque pelo menos 2 jogadores presentes para sortear.' : null,
        ]);
    }

    public function store(Request $request, BalancedDraw $draw): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:players,teams'],
            'size' => ['required', 'integer', 'between:1,30'],
        ]);
        $players = $request->user()->players()->where('present', true)->get()
            ->map(fn (Player $player): array => $player->toDrawPlayer())
            ->values();

        try
        {
            $result = $draw->draw($players, $data['mode'], $data['size']);
        }
        catch (\InvalidArgumentException $exception)
        {
            throw ValidationException::withMessages(['size' => $exception->getMessage()]);
        }
        $request->session()->put('draw_mode', $data['mode']);
        $request->session()->put('draw_size', $data['size']);

        return response()->json($result);
    }
}
