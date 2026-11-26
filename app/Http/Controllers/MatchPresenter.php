<?php

namespace App\Http\Controllers;

use App\Data\RosterTeam;
use App\Models\Goal;
use App\Models\HockeyMatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

final readonly class MatchPresenter
{
    private const REMOVED_PLAYER_LABEL = 'Jogador removido';

    /**
     * @return array<string, mixed>
     */
    public function showProps(HockeyMatch $match): array
    {
        $publicProps = $this->publicProps($match);
        $enabled = $match->public_enabled_at !== null && $match->public_token !== null;

        /** @var array<string, mixed> $matchData */
        $matchData = $publicProps['match'];

        return [
            ...$publicProps,
            'match' => [
                'id' => $match->id,
                ...$matchData,
            ],
            'public_link' => [
                'enabled' => $enabled,
                'url' => $enabled
                    ? route('public.matches.show', ['token' => $match->public_token])
                    : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function publicProps(HockeyMatch $match): array
    {
        $goals = Goal::withoutGlobalScope('team')
            ->where('match_id', $match->id)
            ->where('user_id', $match->user_id)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        $rosterLookup = [
            'home' => $this->buildRosterLookup($match->roster->home),
            'away' => $this->buildRosterLookup($match->roster->away),
        ];

        return [
            'match' => $this->formatMatch($match),
            'goals' => $this->formatGoals($goals, $rosterLookup),
            'player_stats' => $this->buildPlayerStats($goals, $rosterLookup),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMatch(HockeyMatch $match): array
    {
        return [
            'home_score' => (int) $match->home_score,
            'away_score' => (int) $match->away_score,
            'roster' => [
                'home' => $this->formatRosterTeam($match->roster->home),
                'away' => $this->formatRosterTeam($match->roster->away),
            ],
            'timeline' => $this->formatTimeline($match->timeline),
            'started_at' => $this->formatDateTime($match->started_at),
            'finished_at' => $this->formatDateTime($match->finished_at),
        ];
    }

    /**
     * @return array{name: string, color: string, players: list<array{name: string, jersey_number: int}>}
     */
    private function formatRosterTeam(RosterTeam $team): array
    {
        return [
            'name' => $team->name,
            'color' => $team->color,
            'players' => array_map(
                fn (array $player): array => [
                    'name' => $this->sanitizeName($player['name']) ?? self::REMOVED_PLAYER_LABEL,
                    'jersey_number' => (int) $player['jersey_number'],
                ],
                $team->players,
            ),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $timeline
     * @return list<array{at: string, type: string, period: int|null, elapsed_seconds: int}>
     */
    private function formatTimeline(?array $timeline): array
    {
        if (! is_array($timeline))
        {
            return [];
        }

        $result = [];
        foreach ($timeline as $event)
        {
            $rawAt = isset($event['at']) ? (string) $event['at'] : '';

            $result[] = [
                'at' => $this->formatTimeFromIso($rawAt),
                'type' => isset($event['type']) ? (string) $event['type'] : '',
                'period' => isset($event['period']) ? (int) $event['period'] : null,
                'elapsed_seconds' => isset($event['elapsed_seconds']) ? (int) $event['elapsed_seconds'] : 0,
            ];
        }

        return $result;
    }

    /**
     * @param  Collection<int, Goal>  $goals
     * @param  array{home: array{by_id: array<int, array{name: string|null, jersey_number: int}>, by_name: array<string, array{name: string|null, jersey_number: int}>}, away: array{by_id: array<int, array{name: string|null, jersey_number: int}>, by_name: array<string, array{name: string|null, jersey_number: int}>}}  $rosterLookup
     * @return list<array<string, mixed>>
     */
    private function formatGoals(Collection $goals, array $rosterLookup): array
    {
        $timezone = $this->displayTimezone();

        return array_values($goals->map(function (Goal $goal) use ($rosterLookup, $timezone): array {
            $side = $goal->team === 'away' ? 'away' : 'home';
            $scorerId = $goal->scorer_id !== null ? (int) $goal->scorer_id : null;
            $assistId = $goal->assist_id !== null ? (int) $goal->assist_id : null;

            $scorer = $this->resolvePlayerInfo($scorerId, $goal->scorer_name, $rosterLookup[$side]);

            $hasAssist = $assistId !== null || $this->sanitizeName($goal->assist_name) !== null;
            $assistName = null;
            if ($hasAssist)
            {
                $assist = $this->resolvePlayerInfo($assistId, $goal->assist_name, $rosterLookup[$side]);
                $assistName = $assist['name'];
            }

            return [
                'id' => $goal->id,
                'team' => $side,
                'scorer_name' => $scorer['name'],
                'assist_name' => $assistName,
                'period' => (int) $goal->period,
                'elapsed_seconds' => (int) $goal->elapsed_seconds,
                'remaining_seconds' => (int) $goal->remaining_seconds,
                'recorded_at' => $goal->recorded_at?->timezone($timezone)->format('H:i'),
            ];
        })->all());
    }

    /**
     * @param  Collection<int, Goal>  $goals
     * @param  array{home: array{by_id: array<int, array{name: string|null, jersey_number: int}>, by_name: array<string, array{name: string|null, jersey_number: int}>}, away: array{by_id: array<int, array{name: string|null, jersey_number: int}>, by_name: array<string, array{name: string|null, jersey_number: int}>}}  $rosterLookup
     * @return array{home: list<array{name: string, jersey_number: int|null, team: 'home'|'away', goals: int, assists: int, points: int}>, away: list<array{name: string, jersey_number: int|null, team: 'home'|'away', goals: int, assists: int, points: int}>}
     */
    private function buildPlayerStats(Collection $goals, array $rosterLookup): array
    {
        /** @var array{home: array<string, array{name: string, jersey_number: int|null, team: 'home'|'away', goals: int, assists: int, points: int}>, away: array<string, array{name: string, jersey_number: int|null, team: 'home'|'away', goals: int, assists: int, points: int}>} $buckets */
        $buckets = [
            'home' => [],
            'away' => [],
        ];

        foreach ($goals as $goal)
        {
            $side = $goal->team === 'away' ? 'away' : 'home';
            $scorerId = $goal->scorer_id !== null ? (int) $goal->scorer_id : null;
            $scorer = $this->resolvePlayerInfo($scorerId, $goal->scorer_name, $rosterLookup[$side]);
            $scorerKey = $this->playerAggregationKey($side, $scorerId, $scorer['name']);

            $this->accumulateStat($buckets[$side], $scorerKey, $side, $scorer['name'], $scorer['jersey_number'], goals: 1, assists: 0);

            $assistId = $goal->assist_id !== null ? (int) $goal->assist_id : null;
            $hasAssist = $assistId !== null || $this->sanitizeName($goal->assist_name) !== null;
            if ($hasAssist)
            {
                $assist = $this->resolvePlayerInfo($assistId, $goal->assist_name, $rosterLookup[$side]);
                $assistKey = $this->playerAggregationKey($side, $assistId, $assist['name']);

                $this->accumulateStat($buckets[$side], $assistKey, $side, $assist['name'], $assist['jersey_number'], goals: 0, assists: 1);
            }
        }

        return [
            'home' => $this->sortTeamStats(array_values($buckets['home'])),
            'away' => $this->sortTeamStats(array_values($buckets['away'])),
        ];
    }

    /**
     * @param  array<string, array{name: string, jersey_number: int|null, team: 'home'|'away', goals: int, assists: int, points: int}>  $teamBucket
     * @param  'home'|'away'  $side
     */
    private function accumulateStat(
        array &$teamBucket,
        string $key,
        string $side,
        string $name,
        ?int $jerseyNumber,
        int $goals,
        int $assists,
    ): void {
        if (! isset($teamBucket[$key]))
        {
            $teamBucket[$key] = [
                'name' => $name,
                'jersey_number' => $jerseyNumber,
                'team' => $side,
                'goals' => 0,
                'assists' => 0,
                'points' => 0,
            ];
        }
        else
        {
            if ($teamBucket[$key]['name'] === self::REMOVED_PLAYER_LABEL && $name !== self::REMOVED_PLAYER_LABEL)
            {
                $teamBucket[$key]['name'] = $name;
            }

            if ($teamBucket[$key]['jersey_number'] === null && $jerseyNumber !== null)
            {
                $teamBucket[$key]['jersey_number'] = $jerseyNumber;
            }
        }

        $teamBucket[$key]['goals'] += $goals;
        $teamBucket[$key]['assists'] += $assists;
        $teamBucket[$key]['points'] = $teamBucket[$key]['goals'] + $teamBucket[$key]['assists'];
    }

    /**
     * @param  list<array{name: string, jersey_number: int|null, team: 'home'|'away', goals: int, assists: int, points: int}>  $rows
     * @return list<array{name: string, jersey_number: int|null, team: 'home'|'away', goals: int, assists: int, points: int}>
     */
    private function sortTeamStats(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [
            $b['points'],
            $b['goals'],
            $b['assists'],
            mb_strtolower($a['name']),
            $a['name'],
        ] <=> [
            $a['points'],
            $a['goals'],
            $a['assists'],
            mb_strtolower($b['name']),
            $b['name'],
        ]);

        return $rows;
    }

    /**
     * @return array{by_id: array<int, array{name: string|null, jersey_number: int}>, by_name: array<string, array{name: string|null, jersey_number: int}>}
     */
    private function buildRosterLookup(RosterTeam $team): array
    {
        $byId = [];
        $byName = [];

        foreach ($team->players as $player)
        {
            $cleanName = $this->sanitizeName($player['name']);
            $jersey = (int) $player['jersey_number'];
            $id = (int) $player['id'];

            $entry = [
                'name' => $cleanName,
                'jersey_number' => $jersey,
            ];

            $byId[$id] = $entry;

            if ($cleanName !== null)
            {
                $byName[$this->normalizeName($cleanName)] = $entry;
            }
        }

        return [
            'by_id' => $byId,
            'by_name' => $byName,
        ];
    }

    /**
     * @param  array{by_id: array<int, array{name: string|null, jersey_number: int}>, by_name: array<string, array{name: string|null, jersey_number: int}>}  $lookup
     * @return array{name: string, jersey_number: int|null}
     */
    private function resolvePlayerInfo(?int $playerId, ?string $rawName, array $lookup): array
    {
        $cleanName = $this->sanitizeName($rawName);
        $rosterEntry = null;

        if ($playerId !== null && isset($lookup['by_id'][$playerId]))
        {
            $rosterEntry = $lookup['by_id'][$playerId];
        }
        elseif ($cleanName !== null && isset($lookup['by_name'][$this->normalizeName($cleanName)]))
        {
            $rosterEntry = $lookup['by_name'][$this->normalizeName($cleanName)];
        }

        $resolvedName = $cleanName ?? $rosterEntry['name'] ?? self::REMOVED_PLAYER_LABEL;
        $jerseyNumber = $rosterEntry['jersey_number'] ?? null;

        return [
            'name' => $resolvedName,
            'jersey_number' => $jerseyNumber,
        ];
    }

    private function playerAggregationKey(string $side, ?int $playerId, string $resolvedName): string
    {
        if ($playerId !== null)
        {
            return "{$side}:id:{$playerId}";
        }

        return "{$side}:name:".$this->normalizeName($resolvedName);
    }

    private function sanitizeName(mixed $name): ?string
    {
        if (! is_string($name))
        {
            return null;
        }

        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $name));

        return $collapsed !== '' ? $collapsed : null;
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    private function formatDateTime(?CarbonImmutable $dateTime): ?string
    {
        return $dateTime?->timezone($this->displayTimezone())->format('d/m/Y H:i');
    }

    private function formatTimeFromIso(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '')
        {
            return '';
        }

        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $trimmed) === 1)
        {
            return $trimmed;
        }

        try
        {
            return CarbonImmutable::parse($trimmed)
                ->timezone($this->displayTimezone())
                ->format('H:i:s');
        }
        catch (Throwable)
        {
            return $trimmed;
        }
    }

    private function displayTimezone(): string
    {
        $timezone = config('app.display_timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'America/Sao_Paulo';
    }
}
