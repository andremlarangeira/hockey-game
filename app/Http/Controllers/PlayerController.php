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
        $players = $request->user()->players()->active()->orderBy('name')->get();
        $archivedPlayers = $request->user()->players()->archived()->orderBy('name')->get();

        return Inertia::render('players/Index', [
            'players' => $players,
            'archivedPlayers' => $archivedPlayers,
            'presentCount' => $players->where('present', true)->count(),
        ]);
    }

    public function store(PlayerRequest $request): RedirectResponse
    {
        $data = $request->safe()->except(['photo', 'remove_photo']);
        if ($request->hasFile('photo'))
        {
            $data['photo_path'] = $request->file('photo')->store('jogadores/'.$request->user()->id, config('filesystems.media_disk'));
        }
        $request->user()->players()->create($data);

        return back()->with('success', 'Jogador cadastrado.');
    }

    public function update(PlayerRequest $request, Player $player): RedirectResponse
    {
        $data = $request->safe()->except(['photo', 'remove_photo']);
        if ($request->hasFile('photo'))
        {
            if ($player->photo_path !== null && $player->photo_path !== '')
            {
                Storage::disk(config('filesystems.media_disk'))->delete($player->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('jogadores/'.$request->user()->id, config('filesystems.media_disk'));
        }
        elseif ($request->boolean('remove_photo'))
        {
            if ($player->photo_path !== null && $player->photo_path !== '')
            {
                Storage::disk(config('filesystems.media_disk'))->delete($player->photo_path);
            }
            $data['photo_path'] = null;
        }
        $player->update($data);

        return back()->with('success', 'Jogador atualizado.');
    }

    public function presence(Request $request, Player $player): RedirectResponse
    {
        if ($player->isArchived())
        {
            return back();
        }

        $player->update([
            'present' => $request->validate(['present' => ['required', 'boolean']])['present'],
            'presence_changed_at' => now(),
        ]);

        return back();
    }

    public function presenceAll(Request $request): RedirectResponse
    {
        $present = $request->validate(['present' => ['required', 'boolean']])['present'];
        $request->user()->players()->active()->update(['present' => $present, 'presence_changed_at' => now()]);

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
        $changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];
        $players = $request->user()->players()->active()->whereIn('id', array_column($changes, 'player_id'))->get()->keyBy('id');
        $applied = [];
        $processed = [];
        foreach ($changes as $change)
        {
            if (! is_array($change))
            {
                continue;
            }
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
        $player->update([
            'archived_at' => now(),
            'present' => false,
            'presence_changed_at' => now(),
        ]);

        return back()->with('success', 'Jogador arquivado.');
    }

    public function restore(Player $player): RedirectResponse
    {
        $player->update([
            'archived_at' => null,
            'presence_changed_at' => now(),
        ]);

        return back()->with('success', 'Jogador restaurado.');
    }
}
