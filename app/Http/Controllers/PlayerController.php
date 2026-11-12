<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlayerRequest;
use App\Models\Player;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $player->update([
            'present' => $request->validate(['present' => ['required', 'boolean']])['present'],
            'presence_changed_at' => now(),
        ]);

        return back();
    }

    public function presenceAll(Request $request): RedirectResponse
    {
        $present = $request->validate(['present' => ['required', 'boolean']])['present'];
        $request->user()->players()->update(['present' => $present, 'presence_changed_at' => now()]);

        return back();
    }

    public function syncPresence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'max:500'],
            'changes.*.player_id' => ['required', 'integer'],
            'changes.*.present' => ['required', 'boolean'],
            'changes.*.changed_at' => ['required', 'date'],
        ]);
        $players = $request->user()->players()->whereIn('id', collect($data['changes'])->pluck('player_id'))->get()->keyBy('id');
        $applied = [];
        $processed = [];
        foreach ($data['changes'] as $change)
        {
            $player = $players->get($change['player_id']);
            if (! $player)
            {
                continue;
            }
            $processed[] = $player->id;
            $changedAt = Carbon::parse($change['changed_at'])->utc();
            if ($player->presence_changed_at !== null && $player->presence_changed_at->greaterThanOrEqualTo($changedAt))
            {
                continue;
            }
            $player->update(['present' => $change['present'], 'presence_changed_at' => $changedAt]);
            $applied[] = $player->id;
        }

        return response()->json(['applied' => $applied, 'processed' => $processed]);
    }

    public function destroy(Player $player): RedirectResponse
    {
        Storage::disk('public')->delete($player->photo_path);
        $player->delete();

        return back()->with('success', 'Jogador removido.');
    }
}
