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
        $userId = (int) $request->user()->id;

        $goalCounts = DB::table('goals')
            ->selectRaw('scorer_id as player_id, count(*) as total')
            ->where('user_id', $userId)
            ->whereNotNull('scorer_id')
            ->groupBy('scorer_id');

        $assistCounts = DB::table('goals')
            ->selectRaw('assist_id as player_id, count(*) as total')
            ->where('user_id', $userId)
            ->whereNotNull('assist_id')
            ->groupBy('assist_id');

        $gameCounts = DB::table('match_players')
            ->selectRaw('player_id, count(*) as total')
            ->where('user_id', $userId)
            ->where('present', true)
            ->whereNotNull('player_id')
            ->groupBy('player_id');

        $playerRows = $request->user()->players()
            ->leftJoinSub($goalCounts, 'gols', fn (JoinClause $join) => $join->on('players.id', '=', 'gols.player_id'))
            ->leftJoinSub($assistCounts, 'passes', fn (JoinClause $join) => $join->on('players.id', '=', 'passes.player_id'))
            ->leftJoinSub($gameCounts, 'jogos', fn (JoinClause $join) => $join->on('players.id', '=', 'jogos.player_id'))
            ->orderByDesc(DB::raw('coalesce(gols.total, 0) + coalesce(passes.total, 0)'))
            ->orderByDesc(DB::raw('coalesce(gols.total, 0)'))
            ->orderByDesc(DB::raw('coalesce(passes.total, 0)'))
            ->orderBy('players.name')
            ->select('players.*')
            ->selectRaw('coalesce(gols.total, 0) as goals, coalesce(passes.total, 0) as assists, coalesce(gols.total, 0) + coalesce(passes.total, 0) as points, coalesce(jogos.total, 0) as games')
            ->get()
            ->map(fn (Player $player): array => [
                ...$player->toArray(),
                'name' => $player->name,
                'goals' => (int) $player->getAttribute('goals'),
                'assists' => (int) $player->getAttribute('assists'),
                'points' => (int) $player->getAttribute('points'),
                'games' => (int) $player->getAttribute('games'),
            ])
            ->all();

        $orphanScorerCounts = DB::table('goals')
            ->selectRaw('TRIM(scorer_name) as player_name, count(*) as total')
            ->where('user_id', $userId)
            ->whereNull('scorer_id')
            ->groupByRaw('TRIM(scorer_name)')
            ->get();

        $orphanAssistCounts = DB::table('goals')
            ->selectRaw('TRIM(assist_name) as player_name, count(*) as total')
            ->where('user_id', $userId)
            ->whereNull('assist_id')
            ->whereNotNull('assist_name')
            ->groupByRaw('TRIM(assist_name)')
            ->get();

        if ($orphanScorerCounts->isNotEmpty() || $orphanAssistCounts->isNotEmpty())
        {
            /** @var array<string, array{name: string, goals: int, assists: int}> $orphans */
            $orphans = [];

            foreach ($orphanScorerCounts as $row)
            {
                $name = trim((string) $row->player_name);
                if ($name === '')
                {
                    continue;
                }
                $norm = mb_strtolower($name);
                $orphans[$norm] ??= ['name' => $name, 'goals' => 0, 'assists' => 0];
                $orphans[$norm]['goals'] += (int) $row->total;
            }

            foreach ($orphanAssistCounts as $row)
            {
                $name = trim((string) $row->player_name);
                if ($name === '')
                {
                    continue;
                }
                $norm = mb_strtolower($name);
                $orphans[$norm] ??= ['name' => $name, 'goals' => 0, 'assists' => 0];
                $orphans[$norm]['assists'] += (int) $row->total;
            }

            if ($orphans !== [])
            {
                $orphanGameCounts = DB::table('match_players')
                    ->selectRaw('TRIM(player_name) as player_name, count(*) as total')
                    ->where('user_id', $userId)
                    ->where('present', true)
                    ->whereNull('player_id')
                    ->groupByRaw('TRIM(player_name)')
                    ->get();

                /** @var array<string, array{name: string, games: int}> $gamesByName */
                $gamesByName = [];
                foreach ($orphanGameCounts as $row)
                {
                    $name = trim((string) $row->player_name);
                    if ($name === '')
                    {
                        continue;
                    }
                    $norm = mb_strtolower($name);
                    $gamesByName[$norm] ??= ['name' => $name, 'games' => 0];
                    $gamesByName[$norm]['games'] += (int) $row->total;
                }

                $syntheticIndex = -1;
                foreach ($orphans as $norm => $orphan)
                {
                    $points = $orphan['goals'] + $orphan['assists'];
                    $playerRows[] = [
                        'id' => $syntheticIndex--,
                        'name' => $gamesByName[$norm]['name'] ?? $orphan['name'],
                        'jersey_number' => 0,
                        'level' => '3.0',
                        'present' => false,
                        'photo_url' => null,
                        'goals' => $orphan['goals'],
                        'assists' => $orphan['assists'],
                        'points' => $points,
                        'games' => $gamesByName[$norm]['games'] ?? 1,
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
        }

        return Inertia::render('stats/Index', [
            'players' => array_values($playerRows),
        ]);
    }
}
