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
        $gamesByName = [];
        $rosterByName = [];
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
                        $rawName = isset($player['name']) && is_string($player['name']) ? trim($player['name']) : '';
                        if ($rawName !== '')
                        {
                            $norm = mb_strtolower($rawName);
                            $gamesByName[$norm] = ($gamesByName[$norm] ?? 0) + 1;
                            $rosterByName[$norm] = [
                                'id' => $playerId,
                                'name' => $rawName,
                                'jersey_number' => isset($player['jersey_number']) ? (int) $player['jersey_number'] : 0,
                                'level' => isset($player['level']) ? (string) $player['level'] : '3.0',
                            ];
                        }
                    }
                }
            }
        }

        $playerRows = $request->user()->players()
            ->leftJoinSub($goalCounts, 'gols', fn (JoinClause $join) => $join->on('players.id', '=', 'gols.player_id'))
            ->leftJoinSub($assistCounts, 'passes', fn (JoinClause $join) => $join->on('players.id', '=', 'passes.player_id'))
            ->orderByDesc(DB::raw('coalesce(gols.total, 0) + coalesce(passes.total, 0)'))
            ->orderByDesc(DB::raw('coalesce(gols.total, 0)'))
            ->orderByDesc(DB::raw('coalesce(passes.total, 0)'))
            ->orderBy('players.name')
            ->select('players.*')
            ->selectRaw('coalesce(gols.total, 0) as goals, coalesce(passes.total, 0) as assists, coalesce(gols.total, 0) + coalesce(passes.total, 0) as points')
            ->get()
            ->map(fn (Player $player): array => [
                ...$player->toArray(),
                'name' => $player->name,
                'goals' => (int) $player->getAttribute('goals'),
                'assists' => (int) $player->getAttribute('assists'),
                'points' => (int) $player->getAttribute('points'),
                'games' => $games[$player->id] ?? 0,
            ])
            ->all();

        $orphanGoals = DB::table('goals')
            ->where('user_id', $request->user()->id)
            ->where(function ($query): void {
                $query->whereNull('scorer_id')
                    ->orWhere(function ($q): void {
                        $q->whereNull('assist_id')->whereNotNull('assist_name');
                    });
            })
            ->get(['scorer_id', 'scorer_name', 'assist_id', 'assist_name']);

        if ($orphanGoals->isNotEmpty())
        {
            /** @var array<string, array{name: string, goals: int, assists: int}> $orphans */
            $orphans = [];
            foreach ($orphanGoals as $goal)
            {
                if ($goal->scorer_id === null)
                {
                    $name = trim((string) $goal->scorer_name);
                    if ($name !== '')
                    {
                        $norm = mb_strtolower($name);
                        $orphans[$norm] ??= ['name' => $rosterByName[$norm]['name'] ?? $name, 'goals' => 0, 'assists' => 0];
                        $orphans[$norm]['goals']++;
                    }
                }
                if ($goal->assist_id === null && $goal->assist_name !== null)
                {
                    $name = trim((string) $goal->assist_name);
                    if ($name !== '')
                    {
                        $norm = mb_strtolower($name);
                        $orphans[$norm] ??= ['name' => $rosterByName[$norm]['name'] ?? $name, 'goals' => 0, 'assists' => 0];
                        $orphans[$norm]['assists']++;
                    }
                }
            }

            $syntheticIndex = -1;
            foreach ($orphans as $norm => $orphan)
            {
                $rosterInfo = $rosterByName[$norm] ?? null;
                $points = $orphan['goals'] + $orphan['assists'];
                $playerRows[] = [
                    'id' => $rosterInfo['id'] ?? $syntheticIndex--,
                    'name' => $orphan['name'],
                    'jersey_number' => $rosterInfo['jersey_number'] ?? 0,
                    'level' => $rosterInfo['level'] ?? '3.0',
                    'present' => false,
                    'photo_url' => null,
                    'goals' => $orphan['goals'],
                    'assists' => $orphan['assists'],
                    'points' => $points,
                    'games' => $gamesByName[$norm] ?? 1,
                ];
            }

            usort($playerRows, static fn (array $a, array $b): int => [
                $b['points'],
                $b['goals'],
                $b['assists'],
                mb_strtolower((string) $a['name']),
                (string) $a['name'],
            ] <=> [
                $a['points'],
                $a['goals'],
                $a['assists'],
                mb_strtolower((string) $b['name']),
                (string) $b['name'],
            ]);
        }

        return Inertia::render('stats/Index', [
            'players' => array_values($playerRows),
        ]);
    }
}
