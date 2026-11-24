<?php

namespace App\Http\Controllers;

use App\Models\Player;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StatsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $goalCounts = DB::table('goals')->selectRaw('scorer_id as player_id, count(*) as total')
            ->where('user_id', $request->user()->id)->whereNotNull('scorer_id')->groupBy('scorer_id');
        $assistCounts = DB::table('goals')->selectRaw('assist_id as player_id, count(*) as total')
            ->where('user_id', $request->user()->id)->whereNotNull('assist_id')->groupBy('assist_id');

        $games = [];
        $rosters = DB::table('matches')
            ->where('user_id', $request->user()->id)
            ->pluck('roster');
        foreach ($rosters as $rawRoster)
        {
            $decoded = is_string($rawRoster) ? json_decode($rawRoster, true) : null;
            if (! is_array($decoded))
            {
                continue;
            }
            foreach (['home', 'away'] as $sideKey)
            {
                $players = $decoded[$sideKey]['players'] ?? null;
                if (! is_array($players))
                {
                    continue;
                }
                foreach ($players as $player)
                {
                    if (is_array($player) && isset($player['id']))
                    {
                        $playerId = (int) $player['id'];
                        $games[$playerId] = ($games[$playerId] ?? 0) + 1;
                    }
                }
            }
        }

        return Inertia::render('stats/Index', [
            'players' => $request->user()->players()->leftJoinSub($goalCounts, 'gols', fn (JoinClause $join) => $join->on('players.id', '=', 'gols.player_id'))
                ->leftJoinSub($assistCounts, 'passes', fn (JoinClause $join) => $join->on('players.id', '=', 'passes.player_id'))
                ->orderByDesc(DB::raw('coalesce(gols.total, 0) + coalesce(passes.total, 0)'))
                ->orderByDesc(DB::raw('coalesce(gols.total, 0)'))
                ->orderByDesc(DB::raw('coalesce(passes.total, 0)'))
                ->orderBy('players.name')
                ->select('players.*')->selectRaw('coalesce(gols.total, 0) as goals, coalesce(passes.total, 0) as assists, coalesce(gols.total, 0) + coalesce(passes.total, 0) as points')->get()
                ->map(fn (Player $player) => [...$player->toArray(), 'games' => $games[$player->id] ?? 0]),
        ]);
    }
}
