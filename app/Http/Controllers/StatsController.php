<?php

namespace App\Http\Controllers;

use App\Models\HockeyMatch;
use App\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class StatsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $period = $this->period($request);
        $userId = (int) $request->user()->id;
        $showAttendance = $request->query('aba') === 'presenca';

        return Inertia::render('stats/Index', [
            'players' => $this->ranking($request, $period),
            'attendance' => $showAttendance ? $this->attendance($request, $period) : ['total_games' => 0, 'players' => [], 'missing' => []],
            'period' => $period,
            'availableYears' => $this->availableYears($userId, $period['key'] !== 'all' || $showAttendance),
        ]);
    }

    public function showPlayer(Request $request, Player $player): Response
    {
        $period = $this->period($request);
        $userId = (int) $request->user()->id;
        $totals = $this->playerTotals($userId, $player->id, $period);
        $playerAttendance = $this->playerAttendance($userId, $player->id, $period);

        return Inertia::render('stats/Player', [
            'player' => [
                ...$player->toArray(),
                'archived' => $player->isArchived(),
                ...$totals,
                'points_per_game' => $totals['games'] > 0 ? round($totals['points'] / $totals['games'], 2) : null,
                'frequency' => $playerAttendance['percentage'],
                'last_match_at' => $playerAttendance['last_match_at'],
            ],
            'matches' => $this->playerMatches($userId, $player, $period),
            'trend' => $this->playerTrend($userId, $player->id, $period),
            'period' => $period,
            'availableYears' => $this->availableYears($userId),
        ]);
    }

    /** @return array{key: string, year: int|null, month: string|null, from: string|null, to: string|null, label: string, utc_from: string|null, utc_to: string|null} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:all,year,month,custom'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $key = $data['period'] ?? 'all';
        $timezone = $this->displayTimezone();
        $start = null;
        $end = null;
        $label = 'Tudo';

        if ($key === 'year')
        {
            abort_unless(isset($data['year']), 422, 'Informe a temporada.');
            $start = CarbonImmutable::create((int) $data['year'], 1, 1, 0, 0, 0, $timezone);
            $end = $start->endOfYear();
            $label = 'Temporada '.$data['year'];
        }
        elseif ($key === 'month')
        {
            abort_unless(isset($data['month']), 422, 'Informe o mês.');
            $start = CarbonImmutable::createFromFormat('!Y-m', $data['month'], $timezone)->startOfMonth();
            $end = $start->endOfMonth();
            $label = $this->monthLabel($start);
        }
        elseif ($key === 'custom')
        {
            abort_unless(isset($data['from'], $data['to']), 422, 'Informe o início e o fim do intervalo.');
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $data['from'], $timezone)->startOfDay();
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $data['to'], $timezone)->endOfDay();
            $label = $start->format('d/m/Y').' a '.$end->format('d/m/Y');
        }

        return [
            'key' => $key, 'year' => isset($data['year']) ? (int) $data['year'] : null,
            'month' => $data['month'] ?? null, 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null,
            'label' => $label, 'utc_from' => $start?->utc()->toDateTimeString(), 'utc_to' => $end?->utc()->toDateTimeString(),
        ];
    }

    /** @param array{utc_from: string|null, utc_to: string|null} $period */
    private function applyPeriod(Builder $query, array $period, string $table = 'matches'): Builder
    {
        if ($period['utc_from'] !== null && $period['utc_to'] !== null)
        {
            $expression = match ($table)
            {
                'matches' => 'coalesce(matches.finished_at, matches.started_at, matches.created_at)',
                'stat_matches' => 'coalesce(stat_matches.finished_at, stat_matches.started_at, stat_matches.created_at)',
                default => throw new \InvalidArgumentException('Tabela de partidas inválida.'),
            };
            $query->whereBetween(DB::raw($expression), [$period['utc_from'], $period['utc_to']]);
        }

        return $query;
    }

    /** @param array{utc_from: string|null, utc_to: string|null} $period
     * @return array<int, array<string, mixed>>
     */
    private function ranking(Request $request, array $period): array
    {
        $userId = (int) $request->user()->id;
        $goals = $this->goalCounts($userId, 'scorer_id', $period);
        $assists = $this->goalCounts($userId, 'assist_id', $period);
        $games = $this->appearanceCounts($userId, $period);

        $rows = $request->user()->players()
            ->leftJoinSub($goals, 'gols', fn ($join) => $join->on('players.id', '=', 'gols.player_id'))
            ->leftJoinSub($assists, 'passes', fn ($join) => $join->on('players.id', '=', 'passes.player_id'))
            ->leftJoinSub($games, 'jogos', fn ($join) => $join->on('players.id', '=', 'jogos.player_id'))
            ->orderByDesc(DB::raw('coalesce(gols.total, 0) + coalesce(passes.total, 0)'))
            ->orderByDesc(DB::raw('coalesce(gols.total, 0)'))
            ->orderByDesc(DB::raw('coalesce(passes.total, 0)'))
            ->orderBy('players.name')->select('players.*')
            ->selectRaw('coalesce(gols.total, 0) as goals, coalesce(passes.total, 0) as assists, coalesce(gols.total, 0) + coalesce(passes.total, 0) as points, coalesce(jogos.total, 0) as games')
            ->get()->map(fn (Player $player): array => [
                ...$player->toArray(), 'goals' => (int) $player->getAttribute('goals'),
                'assists' => (int) $player->getAttribute('assists'), 'points' => (int) $player->getAttribute('points'),
                'games' => (int) $player->getAttribute('games'),
            ])->all();

        $orphanGoals = $this->orphanCounts($userId, 'scorer_name', 'scorer_id', $period);
        $orphanAssists = $this->orphanCounts($userId, 'assist_name', 'assist_id', $period);
        $orphans = [];
        foreach ($orphanGoals as $row)
        {
            $name = trim($row['player_name']);
            if ($name === '')
            {
                continue;
            }
            $key = mb_strtolower($name);
            $orphans[$key] ??= ['name' => $name, 'goals' => 0, 'assists' => 0];
            $orphans[$key]['goals'] += $row['total'];
        }
        foreach ($orphanAssists as $row)
        {
            $name = trim($row['player_name']);
            if ($name === '')
            {
                continue;
            }
            $key = mb_strtolower($name);
            $orphans[$key] ??= ['name' => $name, 'goals' => 0, 'assists' => 0];
            $orphans[$key]['assists'] += $row['total'];
        }
        if ($orphans !== [])
        {
            $orphanGames = DB::table('match_players')
                ->selectRaw('trim(match_players.player_name) as player_name, count(*) as total')->where('match_players.user_id', $userId)
                ->where('match_players.present', true)->whereNull('match_players.player_id');
            if ($period['utc_from'] !== null)
            {
                $orphanGames->join('matches as stat_matches', 'stat_matches.id', '=', 'match_players.match_id')->where('stat_matches.user_id', $userId);
                $this->applyPeriod($orphanGames, $period, 'stat_matches');
            }
            $gamesByName = $orphanGames->groupByRaw('trim(match_players.player_name)')->pluck('total', 'player_name');
            $id = -1;
            foreach ($orphans as $key => $orphan)
            {
                $rows[] = ['id' => $id--, 'name' => $orphan['name'], 'jersey_number' => 0, 'level' => '3.0', 'present' => false,
                    'photo_url' => null, 'goals' => $orphan['goals'], 'assists' => $orphan['assists'], 'points' => $orphan['goals'] + $orphan['assists'],
                    'games' => (int) ($gamesByName[$orphan['name']] ?? 1)];
            }
            usort($rows, static fn (array $a, array $b): int => [$b['points'], $b['goals'], $b['assists'], mb_strtolower((string) ($a['name'] ?? ''))] <=> [$a['points'], $a['goals'], $a['assists'], mb_strtolower((string) ($b['name'] ?? ''))]);
        }

        return $rows;
    }

    /** @param array{utc_from: string|null, utc_to: string|null} $period
     * @return Collection<int, array{player_name: string, total: int}>
     */
    private function orphanCounts(int $userId, string $nameColumn, string $idColumn, array $period): Collection
    {
        if ($nameColumn === 'scorer_name' && $idColumn === 'scorer_id')
        {
            $query = DB::table('goals')->selectRaw('trim(goals.scorer_name) as player_name, count(*) as total')
                ->whereNull('goals.scorer_id')->whereNotNull('goals.scorer_name');
        }
        else
        {
            $query = DB::table('goals')->selectRaw('trim(goals.assist_name) as player_name, count(*) as total')
                ->whereNull('goals.assist_id')->whereNotNull('goals.assist_name');
        }
        $query->where('goals.user_id', $userId);
        if ($period['utc_from'] !== null)
        {
            $query->join('matches as stat_matches', 'stat_matches.id', '=', 'goals.match_id')->where('stat_matches.user_id', $userId);
            $this->applyPeriod($query, $period, 'stat_matches');
        }

        return $query->groupByRaw($nameColumn === 'scorer_name' ? 'trim(goals.scorer_name)' : 'trim(goals.assist_name)')
            ->get()
            ->map(static fn (\stdClass $row): array => [
                'player_name' => (string) $row->player_name,
                'total' => (int) $row->total,
            ]);
    }

    /** @param array{utc_from: string|null, utc_to: string|null} $period */
    private function goalCounts(int $userId, string $column, array $period, ?int $playerId = null): Builder
    {
        if ($column === 'scorer_id')
        {
            $query = DB::table('goals')->selectRaw('goals.scorer_id as player_id, count(*) as total')->whereNotNull('goals.scorer_id');
        }
        else
        {
            $query = DB::table('goals')->selectRaw('goals.assist_id as player_id, count(*) as total')->whereNotNull('goals.assist_id');
        }
        $query->where('goals.user_id', $userId);
        if ($period['utc_from'] !== null)
        {
            $query->join('matches as stat_matches', 'stat_matches.id', '=', 'goals.match_id')->where('stat_matches.user_id', $userId);
            $this->applyPeriod($query, $period, 'stat_matches');
        }
        if ($playerId !== null)
        {
            $query->where($column === 'scorer_id' ? 'goals.scorer_id' : 'goals.assist_id', $playerId);
        }

        return $query->groupBy($column === 'scorer_id' ? 'goals.scorer_id' : 'goals.assist_id');
    }

    /** @param array{utc_from: string|null, utc_to: string|null} $period */
    private function appearanceCounts(int $userId, array $period, ?int $playerId = null): Builder
    {
        $query = DB::table('match_players')
            ->selectRaw('match_players.player_id, count(*) as total')->where('match_players.user_id', $userId)
            ->where('match_players.present', true)->whereNotNull('match_players.player_id');
        if ($period['utc_from'] !== null)
        {
            $query->join('matches as stat_matches', 'stat_matches.id', '=', 'match_players.match_id')->where('stat_matches.user_id', $userId);
            $this->applyPeriod($query, $period, 'stat_matches');
        }
        if ($playerId !== null)
        {
            $query->where('match_players.player_id', $playerId);
        }

        return $query->groupBy('match_players.player_id');
    }

    /** @param array{utc_from: string|null, utc_to: string|null} $period
     * @return array{goals: int, assists: int, points: int, games: int}
     */
    private function playerTotals(int $userId, int $playerId, array $period): array
    {
        $goals = (int) ($this->goalCounts($userId, 'scorer_id', $period, $playerId)->value('total') ?? 0);
        $assists = (int) ($this->goalCounts($userId, 'assist_id', $period, $playerId)->value('total') ?? 0);
        $games = (int) ($this->appearanceCounts($userId, $period, $playerId)->value('total') ?? 0);

        return ['goals' => $goals, 'assists' => $assists, 'points' => $goals + $assists, 'games' => $games];
    }

    /** @param array{utc_from: string|null, utc_to: string|null} $period
     * @return array{percentage: float, last_match_at: string|null}
     */
    private function playerAttendance(int $userId, int $playerId, array $period): array
    {
        $matches = DB::table('matches')->where('user_id', $userId);
        $this->applyPeriod($matches, $period);
        $total = (int) $matches->count();
        $games = (int) ($this->appearanceCounts($userId, $period, $playerId)->value('total') ?? 0);
        $last = DB::table('match_players')->join('matches', 'matches.id', '=', 'match_players.match_id')
            ->where('match_players.user_id', $userId)->where('match_players.player_id', $playerId)->where('match_players.present', true)
            ->where('matches.user_id', $userId)->selectRaw('max(coalesce(matches.finished_at, matches.started_at, matches.created_at)) as last_played_at');
        $this->applyPeriod($last, $period);
        $date = $last->value('last_played_at');

        return [
            'percentage' => $total > 0 ? round(($games / $total) * 100, 1) : 0.0,
            'last_match_at' => $date ? CarbonImmutable::parse($date)->timezone($this->displayTimezone())->format('d/m/Y H:i') : null,
        ];
    }

    /** @param array{utc_from: string|null, utc_to: string|null} $period
     * @return array{total_games: int, players: array<int, array<string, mixed>>, missing: array<int, array<string, mixed>>}
     */
    private function attendance(Request $request, array $period): array
    {
        $userId = (int) $request->user()->id;
        $matches = DB::table('matches')->where('user_id', $userId);
        $this->applyPeriod($matches, $period);
        $totalGames = (int) $matches->count();
        $counts = $this->appearanceCounts($userId, $period)->pluck('total', 'player_id')->map(fn ($value): int => (int) $value)->all();
        $lastPlayed = DB::table('match_players')->join('matches', 'matches.id', '=', 'match_players.match_id')
            ->where('match_players.user_id', $userId)->where('match_players.present', true)->whereNotNull('match_players.player_id')
            ->where('matches.user_id', $userId)->selectRaw('match_players.player_id, max(coalesce(matches.finished_at, matches.started_at, matches.created_at)) as last_played_at');
        $this->applyPeriod($lastPlayed, $period);
        $lastPlayed = $lastPlayed->groupBy('match_players.player_id')->pluck('last_played_at', 'player_id');
        $now = now($this->displayTimezone())->startOfDay();
        $players = $request->user()->players()->active()->orderBy('name')->get()->map(function (Player $player) use ($counts, $lastPlayed, $totalGames, $now): array {
            $last = isset($lastPlayed[$player->id]) ? CarbonImmutable::parse($lastPlayed[$player->id])->timezone($this->displayTimezone()) : null;
            $games = $counts[$player->id] ?? 0;

            return ['id' => $player->id, 'name' => $player->name, 'jersey_number' => $player->jersey_number, 'photo_url' => $player->photo_url,
                'games' => $games, 'total_games' => $totalGames, 'percentage' => $totalGames > 0 ? round(($games / $totalGames) * 100, 1) : 0,
                'last_match_at' => $last?->format('d/m/Y H:i'), 'days_since' => $last ? $last->startOfDay()->diffInDays($now) : null,
                'games_since' => max(0, $totalGames - $games)];
        })->values()->all();

        return ['total_games' => $totalGames, 'players' => $players,
            'missing' => collect($players)->sortBy([['last_match_at', 'asc'], ['name', 'asc']])->values()->take(8)->all()];
    }

    /**
     * @param  array{utc_from: string|null, utc_to: string|null}  $period
     * @return LengthAwarePaginator<int, array{id: int, date: string|null, home_name: string, away_name: string, home_score: int, away_score: int, side: string, goals: int, assists: int, url: string}>
     */
    private function playerMatches(int $userId, Player $player, array $period): LengthAwarePaginator
    {
        $goals = DB::table('goals')->selectRaw('match_id, sum(case when scorer_id = ? then 1 else 0 end) as player_goals, sum(case when assist_id = ? then 1 else 0 end) as player_assists', [$player->id, $player->id])->groupBy('match_id');
        $query = HockeyMatch::query()->join('match_players', 'match_players.match_id', '=', 'matches.id')
            ->leftJoinSub($goals, 'personal_goals', fn ($join) => $join->on('matches.id', '=', 'personal_goals.match_id'))
            ->where('matches.user_id', $userId)->where('match_players.player_id', $player->id)->where('match_players.present', true)
            ->select('matches.*', 'match_players.team as player_side')->selectRaw('coalesce(personal_goals.player_goals, 0) as player_goals, coalesce(personal_goals.player_assists, 0) as player_assists')
            ->orderByDesc(DB::raw('coalesce(matches.finished_at, matches.started_at, matches.created_at)'));
        $this->applyPeriod($query->getQuery(), $period);

        return $query->paginate(12)->withQueryString()->through(
            fn (HockeyMatch $match) => $this->playerMatchRow($match),
        );
    }

    /**
     * @return array{id: int, date: string|null, home_name: string, away_name: string, home_score: int, away_score: int, side: 'home'|'away', goals: int, assists: int, url: string}
     */
    private function playerMatchRow(HockeyMatch $match): array
    {
        $row = [
            'id' => $match->id,
            'date' => $this->formatMatchDate($match),
            'home_name' => $match->roster->home->name,
            'away_name' => $match->roster->away->name,
            'home_score' => $match->home_score,
            'away_score' => $match->away_score,
            'goals' => (int) $match->getAttribute('player_goals'),
            'assists' => (int) $match->getAttribute('player_assists'),
            'url' => route('matches.show', $match),
        ];

        if ($match->getAttribute('player_side') === 'away')
        {
            return [...$row, 'side' => 'away'];
        }

        return [...$row, 'side' => 'home'];
    }

    /** @param array{utc_from: string|null, utc_to: string|null} $period
     * @return array<int, array{month: string, label: string, points: int}>
     */
    private function playerTrend(int $userId, int $playerId, array $period): array
    {
        $rows = DB::table('matches')->join('goals', 'goals.match_id', '=', 'matches.id')->where('matches.user_id', $userId)->where('goals.user_id', $userId)
            ->where(fn ($query) => $query->where('goals.scorer_id', $playerId)->orWhere('goals.assist_id', $playerId))
            ->select('goals.scorer_id', 'goals.assist_id', 'matches.finished_at', 'matches.started_at', 'matches.created_at')->get();
        $buckets = [];
        foreach ($rows as $row)
        {
            $at = CarbonImmutable::parse($row->finished_at ?? $row->started_at ?? $row->created_at)->timezone($this->displayTimezone());
            if ($period['utc_from'] && ($at->utc()->toDateTimeString() < $period['utc_from'] || $at->utc()->toDateTimeString() > $period['utc_to']))
            {
                continue;
            }
            $month = $at->format('Y-m');
            $buckets[$month] = ($buckets[$month] ?? 0) + ((int) $row->scorer_id === $playerId ? 1 : 0) + ((int) $row->assist_id === $playerId ? 1 : 0);
        }
        ksort($buckets);

        return collect($buckets)->map(fn (int $points, string $month): array => ['month' => $month,
            'label' => $this->monthLabel(CarbonImmutable::createFromFormat('!Y-m', $month, $this->displayTimezone()) ?: CarbonImmutable::now()), 'points' => $points])->values()->all();
    }

    /** @return array<int, int> */
    private function availableYears(int $userId, bool $load = true): array
    {
        $dates = $load
            ? DB::table('matches')->where('user_id', $userId)->select(['finished_at', 'started_at', 'created_at'])->get()
            : DB::table('match_players')->where('user_id', $userId)->select(['created_at'])->get();

        return $dates
            ->map(fn ($match): int => CarbonImmutable::parse($match->finished_at ?? $match->started_at ?? $match->created_at)->timezone($this->displayTimezone())->year)
            ->unique()->sortDesc()->values()->all();
    }

    private function formatMatchDate(HockeyMatch $match): ?string
    {
        $date = $match->finished_at ?? $match->started_at ?? $match->created_at;

        return $date?->timezone($this->displayTimezone())->format('d/m/Y H:i');
    }

    private function displayTimezone(): string
    {
        return (string) config('app.display_timezone', 'America/Sao_Paulo');
    }

    private function monthLabel(CarbonImmutable $date): string
    {
        $months = [1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho', 7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro'];

        return $months[$date->month].' de '.$date->year;
    }
}
