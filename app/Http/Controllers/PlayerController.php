<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlayerRequest;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    public function index(Request $request): Response
    {
        $players = $request->user()->players()->orderBy('name')->get();

        return Inertia::render('players/Index', [
            'players' => $players,
            'presentCount' => $players->where('present', true)->count(),
        ]);
    }

    public function store(PlayerRequest $request): RedirectResponse
    {
        $data = $request->safe()->except(['photo', 'remove_photo']);
        if ($request->hasFile('photo'))
        {
            $data['photo_path'] = $request->file('photo')->store('jogadores/'.$request->user()->id, 'public');
        }
        $request->user()->players()->create($data);

        return back()->with('success', 'Jogador cadastrado.');
    }

    public function update(PlayerRequest $request, Player $player): RedirectResponse
    {
        $data = $request->safe()->except(['photo', 'remove_photo']);
        if ($request->hasFile('photo'))
        {
            Storage::disk('public')->delete($player->photo_path);
            $data['photo_path'] = $request->file('photo')->store('jogadores/'.$request->user()->id, 'public');
        }
        elseif ($request->boolean('remove_photo'))
        {
            Storage::disk('public')->delete($player->photo_path);
            $data['photo_path'] = null;
        }
        $player->update($data);

        return back()->with('success', 'Jogador atualizado.');
    }

    public function presence(Request $request, Player $player): RedirectResponse
    {
        $player->update(['present' => $request->validate(['present' => ['required', 'boolean']])['present']]);

        return back();
    }

    public function presenceAll(Request $request): RedirectResponse
    {
        $present = $request->validate(['present' => ['required', 'boolean']])['present'];
        $request->user()->players()->update(['present' => $present]);

        return back();
    }

    public function destroy(Player $player): RedirectResponse
    {
        Storage::disk('public')->delete($player->photo_path);
        $player->delete();

        return back()->with('success', 'Jogador removido.');
    }
}
