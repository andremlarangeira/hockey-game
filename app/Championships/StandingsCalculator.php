<?php

namespace App\Championships;

use App\Championships\Data\ChampionshipRules;
use App\Championships\Data\GroupStandings;
use App\Championships\Data\StandingRow;
use App\Championships\Enums\GamePhase;
use App\Championships\Enums\GameStatus;
use App\Championships\Enums\TieBreakCriterion;
use App\Models\ChampionshipGame;
use App\Models\ChampionshipTeam;

final class StandingsCalculator
{
    /**
     * Pure calculation of group standings from rules, enrolled teams, group games, and manual tie resolutions.
     *
     * @param  iterable<int, ChampionshipTeam>  $teams
     * @param  iterable<int, ChampionshipGame>  $games
     * @param  array<string, mixed>  $manualResolutions
     * @return array<string, GroupStandings>
     */
    public function calculate(
        ChampionshipRules $rules,
        iterable $teams = [],
        iterable $games = [],
        array $manualResolutions = [],
    ): array {

        /** @var array<string, list<ChampionshipTeam>> $teamsByGroup */
        $teamsByGroup = [];
        foreach ($teams as $team)
        {
            $groupCode = $team->group_code;
            if ($groupCode === null || $groupCode === '')
            {
                continue;
            }

            $teamsByGroup[$groupCode][] = $team;
        }

        ksort($teamsByGroup);

        /** @var array<string, list<ChampionshipGame>> $groupGamesByGroup */
        $groupGamesByGroup = [];
        foreach ($games as $game)
        {
            if ($game->phase !== GamePhase::Group || $game->is_bye)
            {
                continue;
            }

            $groupCode = $game->group_code;
            if ($groupCode === null || $groupCode === '')
            {
                continue;
            }

            $groupGamesByGroup[$groupCode][] = $game;
        }

        /** @var array<string, GroupStandings> $standingsByGroup */
        $standingsByGroup = [];

        foreach ($teamsByGroup as $groupCode => $groupTeams)
        {
            usort(
                $groupTeams,
                static fn (ChampionshipTeam $a, ChampionshipTeam $b): int => [
                    $a->group_draw_order ?? PHP_INT_MAX,
                    $a->id,
                ] <=> [
                    $b->group_draw_order ?? PHP_INT_MAX,
                    $b->id,
                ],
            );

            $allGroupGames = $groupGamesByGroup[$groupCode] ?? [];
            $finishedGames = array_values(array_filter(
                $allGroupGames,
                static fn (ChampionshipGame $game): bool => $game->status === GameStatus::Finished,
            ));

            $teamCount = count($groupTeams);
            $expectedGamesPerLeg = $teamCount >= 2 ? intdiv($teamCount * ($teamCount - 1), 2) : 0;
            $expectedTotalGames = $expectedGamesPerLeg * ($rules->doubleRoundRobin ? 2 : 1);

            $hasUnfinishedScheduledGames = count($finishedGames) < count($allGroupGames);
            $allGamesFinished = $teamCount >= 2
                && ! $hasUnfinishedScheduledGames
                && count($finishedGames) >= $expectedTotalGames;

            $teamMap = [];
            foreach ($groupTeams as $team)
            {
                $teamMap[(int) $team->id] = $team;
            }

            $overallStats = $this->accumulateStats(array_keys($teamMap), $finishedGames, $rules);

            // Start with all teams in the group and partition by Points first, then walk configured tie-break criteria
            $criteriaSequence = $this->normalizedCriteria($rules, isset($manualResolutions[$groupCode]));

            /** @var list<array{teamIds: list<int>, resolvedBy: string|null}> $buckets */
            $buckets = [
                [
                    'teamIds' => array_keys($teamMap),
                    'resolvedBy' => null,
                ],
            ];

            foreach ($criteriaSequence as $criterion)
            {
                $nextBuckets = [];
                foreach ($buckets as $bucket)
                {
                    if (count($bucket['teamIds']) <= 1)
                    {
                        $nextBuckets[] = $bucket;

                        continue;
                    }

                    $refined = $this->refineBucket(
                        teamIds: $bucket['teamIds'],
                        currentResolvedBy: $bucket['resolvedBy'],
                        criterion: $criterion,
                        overallStats: $overallStats,
                        finishedGroupGames: $finishedGames,
                        rules: $rules,
                        groupManualResolution: $manualResolutions[$groupCode] ?? null,
                    );

                    foreach ($refined as $subBucket)
                    {
                        $nextBuckets[] = $subBucket;
                    }
                }

                $buckets = $nextBuckets;
            }

            /** @var list<StandingRow> $rows */
            $rows = [];
            /** @var list<list<int>> $unresolvedTieGroups */
            $unresolvedTieGroups = [];
            $position = 1;

            foreach ($buckets as $bucket)
            {
                $bucketTeamIds = $bucket['teamIds'];
                $isUnresolved = count($bucketTeamIds) > 1;

                if ($isUnresolved)
                {
                    usort(
                        $bucketTeamIds,
                        static fn (int $idA, int $idB): int => [
                            $teamMap[$idA]->group_draw_order ?? PHP_INT_MAX,
                            $idA,
                        ] <=> [
                            $teamMap[$idB]->group_draw_order ?? PHP_INT_MAX,
                            $idB,
                        ],
                    );

                    $unresolvedTieGroups[] = $bucketTeamIds;
                }

                foreach ($bucketTeamIds as $teamId)
                {
                    $team = $teamMap[$teamId];
                    $stats = $overallStats[$teamId];

                    $rows[] = new StandingRow(
                        position: $position,
                        teamId: $teamId,
                        teamName: $team->name,
                        teamLogoUrl: $team->logo_url,
                        primaryColor: $team->primary_color,
                        groupCode: (string) $groupCode,
                        points: $stats['points'],
                        played: $stats['played'],
                        wins: $stats['wins'],
                        draws: $stats['draws'],
                        losses: $stats['losses'],
                        goalsFor: $stats['goalsFor'],
                        goalsAgainst: $stats['goalsAgainst'],
                        goalDifference: $stats['goalDifference'],
                        qualifies: $position <= $rules->qualifiersPerGroup,
                        tiedWithNext: $isUnresolved,
                        resolvedByCriterion: $isUnresolved ? null : $bucket['resolvedBy'],
                    );

                    $position++;
                }
            }

            $standingsByGroup[(string) $groupCode] = new GroupStandings(
                groupCode: (string) $groupCode,
                rows: $rows,
                allGamesFinished: $allGamesFinished,
                unresolvedTieGroups: $unresolvedTieGroups,
            );
        }

        if ($rules->extraBestQualifiers > 0 && $standingsByGroup !== [])
        {
            $standingsByGroup = $this->applyExtraBestQualifiers($standingsByGroup, $rules);
        }

        return $standingsByGroup;
    }

    /**
     * @param  list<int>  $teamIds
     * @param  list<ChampionshipGame>  $finishedGames
     * @return array<int, array{
     *     points: int,
     *     played: int,
     *     wins: int,
     *     draws: int,
     *     losses: int,
     *     goalsFor: int,
     *     goalsAgainst: int,
     *     goalDifference: int
     * }>
     */
    private function accumulateStats(array $teamIds, array $finishedGames, ChampionshipRules $rules): array
    {
        $stats = [];

        foreach ($teamIds as $teamId)
        {
            $stats[$teamId] = [
                'points' => 0,
                'played' => 0,
                'wins' => 0,
                'draws' => 0,
                'losses' => 0,
                'goalsFor' => 0,
                'goalsAgainst' => 0,
                'goalDifference' => 0,
            ];
        }

        foreach ($finishedGames as $game)
        {
            $homeId = $game->home_team_id !== null ? (int) $game->home_team_id : null;
            $awayId = $game->away_team_id !== null ? (int) $game->away_team_id : null;

            if ($homeId === null || $awayId === null || $homeId === $awayId)
            {
                continue;
            }

            if (! isset($stats[$homeId], $stats[$awayId]))
            {
                continue;
            }

            $home = $stats[$homeId];
            $away = $stats[$awayId];

            $homeGoals = (int) $game->home_regular_score;
            $awayGoals = (int) $game->away_regular_score;

            $home['played']++;
            $away['played']++;

            $home['goalsFor'] += $homeGoals;
            $home['goalsAgainst'] += $awayGoals;
            $home['goalDifference'] = $home['goalsFor'] - $home['goalsAgainst'];

            $away['goalsFor'] += $awayGoals;
            $away['goalsAgainst'] += $homeGoals;
            $away['goalDifference'] = $away['goalsFor'] - $away['goalsAgainst'];

            $decidedInOvertimeOrShootout = $rules->allowGroupOvertimeOrShootout
                && ($game->went_to_overtime || $game->went_to_shootout);

            if ($decidedInOvertimeOrShootout)
            {
                $winnerId = $this->resolveOvertimeOrShootoutWinner($game, $homeId, $awayId);

                if ($winnerId === $homeId)
                {
                    $home['wins']++;
                    $home['points'] += $rules->pointsOvertimeWin;
                    $away['losses']++;
                    $away['points'] += $rules->pointsOvertimeLoss;
                    $stats[$homeId] = $home;
                    $stats[$awayId] = $away;

                    continue;
                }

                if ($winnerId === $awayId)
                {
                    $away['wins']++;
                    $away['points'] += $rules->pointsOvertimeWin;
                    $home['losses']++;
                    $home['points'] += $rules->pointsOvertimeLoss;
                    $stats[$homeId] = $home;
                    $stats[$awayId] = $away;

                    continue;
                }
            }

            if ($homeGoals > $awayGoals)
            {
                $home['wins']++;
                $home['points'] += $rules->pointsWin;
                $away['losses']++;
                $away['points'] += $rules->pointsLoss;
            }
            elseif ($awayGoals > $homeGoals)
            {
                $away['wins']++;
                $away['points'] += $rules->pointsWin;
                $home['losses']++;
                $home['points'] += $rules->pointsLoss;
            }
            else
            {
                $home['draws']++;
                $home['points'] += $rules->pointsDraw;
                $away['draws']++;
                $away['points'] += $rules->pointsDraw;
            }

            $stats[$homeId] = $home;
            $stats[$awayId] = $away;
        }

        return $stats;
    }

    private function resolveOvertimeOrShootoutWinner(ChampionshipGame $game, int $homeId, int $awayId): ?int
    {
        if ($game->home_regular_score > $game->away_regular_score)
        {
            return $homeId;
        }

        if ($game->away_regular_score > $game->home_regular_score)
        {
            return $awayId;
        }

        if ($game->went_to_shootout && $game->home_shootout_score !== $game->away_shootout_score)
        {
            return $game->home_shootout_score > $game->away_shootout_score ? $homeId : $awayId;
        }

        if ($game->winner_team_id !== null)
        {
            return (int) $game->winner_team_id;
        }

        return null;
    }

    /**
     * @return list<TieBreakCriterion>
     */
    private function normalizedCriteria(ChampionshipRules $rules, bool $hasManualResolutionForGroup): array
    {
        $criteria = [TieBreakCriterion::Points];

        foreach ($rules->tieBreakOrder as $criterion)
        {
            if (! in_array($criterion, $criteria, true))
            {
                $criteria[] = $criterion;
            }
        }

        if ($hasManualResolutionForGroup && ! in_array(TieBreakCriterion::ManualDraw, $criteria, true))
        {
            $criteria[] = TieBreakCriterion::ManualDraw;
        }

        return $criteria;
    }

    /**
     * @param  list<int>  $teamIds
     * @param  array<int, array{points: int, played: int, wins: int, draws: int, losses: int, goalsFor: int, goalsAgainst: int, goalDifference: int}>  $overallStats
     * @param  list<ChampionshipGame>  $finishedGroupGames
     * @return list<array{teamIds: list<int>, resolvedBy: string|null}>
     */
    private function refineBucket(
        array $teamIds,
        ?string $currentResolvedBy,
        TieBreakCriterion $criterion,
        array $overallStats,
        array $finishedGroupGames,
        ChampionshipRules $rules,
        mixed $groupManualResolution,
    ): array {
        return match ($criterion)
        {
            TieBreakCriterion::Points => $this->partitionByScalar(
                teamIds: $teamIds,
                currentResolvedBy: $currentResolvedBy,
                resolvedLabel: null,
                valueFn: static fn (int $id): int => $overallStats[$id]['points'],
            ),
            TieBreakCriterion::Wins => $this->partitionByScalar(
                teamIds: $teamIds,
                currentResolvedBy: $currentResolvedBy,
                resolvedLabel: TieBreakCriterion::Wins->value,
                valueFn: static fn (int $id): int => $overallStats[$id]['wins'],
            ),
            TieBreakCriterion::GoalDifference => $this->partitionByScalar(
                teamIds: $teamIds,
                currentResolvedBy: $currentResolvedBy,
                resolvedLabel: TieBreakCriterion::GoalDifference->value,
                valueFn: static fn (int $id): int => $overallStats[$id]['goalDifference'],
            ),
            TieBreakCriterion::GoalsFor => $this->partitionByScalar(
                teamIds: $teamIds,
                currentResolvedBy: $currentResolvedBy,
                resolvedLabel: TieBreakCriterion::GoalsFor->value,
                valueFn: static fn (int $id): int => $overallStats[$id]['goalsFor'],
            ),
            TieBreakCriterion::FewestGoalsAgainst => $this->partitionByScalar(
                teamIds: $teamIds,
                currentResolvedBy: $currentResolvedBy,
                resolvedLabel: TieBreakCriterion::FewestGoalsAgainst->value,
                valueFn: static fn (int $id): int => -$overallStats[$id]['goalsAgainst'],
            ),
            TieBreakCriterion::HeadToHead => $this->refineByHeadToHead(
                teamIds: $teamIds,
                currentResolvedBy: $currentResolvedBy,
                finishedGroupGames: $finishedGroupGames,
                rules: $rules,
            ),
            TieBreakCriterion::ManualDraw => $this->refineByManualDraw(
                teamIds: $teamIds,
                currentResolvedBy: $currentResolvedBy,
                groupManualResolution: $groupManualResolution,
            ),
        };
    }

    /**
     * @param  list<int>  $teamIds
     * @param  callable(int): int  $valueFn
     * @return list<array{teamIds: list<int>, resolvedBy: string|null}>
     */
    private function partitionByScalar(
        array $teamIds,
        ?string $currentResolvedBy,
        ?string $resolvedLabel,
        callable $valueFn,
    ): array {
        /** @var array<int, list<int>> $grouped */
        $grouped = [];
        foreach ($teamIds as $id)
        {
            $score = $valueFn($id);
            $grouped[$score][] = $id;
        }

        if (count($grouped) <= 1)
        {
            return [
                [
                    'teamIds' => $teamIds,
                    'resolvedBy' => $currentResolvedBy,
                ],
            ];
        }

        krsort($grouped, SORT_NUMERIC);

        $subBuckets = [];
        foreach ($grouped as $subTeamIds)
        {
            $subBuckets[] = [
                'teamIds' => $subTeamIds,
                'resolvedBy' => $resolvedLabel ?? $currentResolvedBy,
            ];
        }

        return $subBuckets;
    }

    /**
     * Mini-table evaluation among ONLY the tied teams in $teamIds.
     * If the mini-table splits the bucket into smaller sub-buckets (e.g. 3 teams -> 1st + 2 still tied),
     * recursively re-applies HeadToHead to any shrunk sub-bucket of size >= 2.
     *
     * @param  list<int>  $teamIds
     * @param  list<ChampionshipGame>  $finishedGroupGames
     * @return list<array{teamIds: list<int>, resolvedBy: string|null}>
     */
    private function refineByHeadToHead(
        array $teamIds,
        ?string $currentResolvedBy,
        array $finishedGroupGames,
        ChampionshipRules $rules,
    ): array {
        $teamSet = array_fill_keys($teamIds, true);

        $h2hGames = array_values(array_filter(
            $finishedGroupGames,
            static fn (ChampionshipGame $game): bool => $game->home_team_id !== null
                && $game->away_team_id !== null
                && isset($teamSet[(int) $game->home_team_id])
                && isset($teamSet[(int) $game->away_team_id]),
        ));

        if ($h2hGames === [])
        {
            return [
                [
                    'teamIds' => $teamIds,
                    'resolvedBy' => $currentResolvedBy,
                ],
            ];
        }

        $h2hStats = $this->accumulateStats($teamIds, $h2hGames, $rules);

        $sortedIds = $teamIds;
        usort(
            $sortedIds,
            static fn (int $a, int $b): int => [
                $h2hStats[$b]['points'],
                $h2hStats[$b]['goalDifference'],
                $h2hStats[$b]['goalsFor'],
                $h2hStats[$b]['wins'],
            ] <=> [
                $h2hStats[$a]['points'],
                $h2hStats[$a]['goalDifference'],
                $h2hStats[$a]['goalsFor'],
                $h2hStats[$a]['wins'],
            ],
        );

        /** @var list<list<int>> $groups */
        $groups = [];
        /** @var array<string, int> $keyIndex */
        $keyIndex = [];

        foreach ($sortedIds as $id)
        {
            $key = sprintf(
                '%d:%d:%d:%d',
                $h2hStats[$id]['points'],
                $h2hStats[$id]['goalDifference'],
                $h2hStats[$id]['goalsFor'],
                $h2hStats[$id]['wins'],
            );

            if (! isset($keyIndex[$key]))
            {
                $keyIndex[$key] = count($groups);
                $groups[] = [];
            }

            $groups[$keyIndex[$key]][] = $id;
        }

        if (count($groups) <= 1)
        {
            return [
                [
                    'teamIds' => $teamIds,
                    'resolvedBy' => $currentResolvedBy,
                ],
            ];
        }

        $result = [];
        foreach ($groups as $subGroup)
        {
            if (count($subGroup) === 1)
            {
                $result[] = [
                    'teamIds' => $subGroup,
                    'resolvedBy' => TieBreakCriterion::HeadToHead->value,
                ];

                continue;
            }

            // Sub-bucket shrank (count($subGroup) < count($teamIds)): recursively re-apply HeadToHead
            $recursivelyRefined = $this->refineByHeadToHead(
                teamIds: $subGroup,
                currentResolvedBy: TieBreakCriterion::HeadToHead->value,
                finishedGroupGames: $finishedGroupGames,
                rules: $rules,
            );

            foreach ($recursivelyRefined as $subBucket)
            {
                $result[] = $subBucket;
            }
        }

        return $result;
    }

    /**
     * Manual resolution is applied ONLY when the manual resolution list contains
     * the exact same set of team IDs as the currently tied bucket.
     *
     * @param  list<int>  $teamIds
     * @return list<array{teamIds: list<int>, resolvedBy: string|null}>
     */
    private function refineByManualDraw(
        array $teamIds,
        ?string $currentResolvedBy,
        mixed $groupManualResolution,
    ): array {
        $candidateLists = $this->extractManualResolutionLists($groupManualResolution);

        $expectedSorted = $teamIds;
        sort($expectedSorted);

        foreach ($candidateLists as $orderedIds)
        {
            if (count($orderedIds) !== count($teamIds))
            {
                continue;
            }

            $candidateSorted = $orderedIds;
            sort($candidateSorted);

            if ($candidateSorted === $expectedSorted)
            {
                $resolved = [];
                foreach ($orderedIds as $teamId)
                {
                    $resolved[] = [
                        'teamIds' => [$teamId],
                        'resolvedBy' => TieBreakCriterion::ManualDraw->value,
                    ];
                }

                return $resolved;
            }
        }

        return [
            [
                'teamIds' => $teamIds,
                'resolvedBy' => $currentResolvedBy,
            ],
        ];
    }

    /**
     * @return list<list<int>>
     */
    private function extractManualResolutionLists(mixed $raw): array
    {
        if (! is_array($raw) || $raw === [])
        {
            return [];
        }

        if (isset($raw['ordered_team_ids']) && is_array($raw['ordered_team_ids']))
        {
            return [array_values(array_map('intval', $raw['ordered_team_ids']))];
        }

        $first = reset($raw);
        if (is_array($first))
        {
            $lists = [];
            foreach ($raw as $entry)
            {
                if (is_array($entry))
                {
                    $ids = isset($entry['ordered_team_ids']) && is_array($entry['ordered_team_ids'])
                        ? $entry['ordered_team_ids']
                        : $entry;
                    $lists[] = array_values(array_map('intval', $ids));
                }
            }

            return $lists;
        }

        return [array_values(array_map('intval', $raw))];
    }

    /**
     * @param  array<string, GroupStandings>  $standingsByGroup
     * @return array<string, GroupStandings>
     */
    private function applyExtraBestQualifiers(array $standingsByGroup, ChampionshipRules $rules): array
    {
        $maxGroupSize = 0;
        foreach ($standingsByGroup as $groupStandings)
        {
            $maxGroupSize = max($maxGroupSize, count($groupStandings->rows));
        }

        /** @var list<StandingRow> $candidates */
        $candidates = [];

        for ($tier = $rules->qualifiersPerGroup + 1; $tier <= $maxGroupSize; $tier++)
        {
            $tierRows = [];
            foreach ($standingsByGroup as $groupStandings)
            {
                foreach ($groupStandings->rows as $row)
                {
                    if ($row->position === $tier && ! $row->qualifies)
                    {
                        $tierRows[] = $row;
                    }
                }
            }

            usort(
                $tierRows,
                static fn (StandingRow $a, StandingRow $b): int => [
                    $b->points,
                    $b->wins,
                    $b->goalDifference,
                    $b->goalsFor,
                    -$b->goalsAgainst,
                    $b->played > 0 ? 1 : 0,
                ] <=> [
                    $a->points,
                    $a->wins,
                    $a->goalDifference,
                    $a->goalsFor,
                    -$a->goalsAgainst,
                    $a->played > 0 ? 1 : 0,
                ] ?: strcmp($a->groupCode, $b->groupCode),
            );

            foreach ($tierRows as $row)
            {
                $candidates[] = $row;
            }
        }

        $extraQualifiedTeamIds = [];
        foreach (array_slice($candidates, 0, $rules->extraBestQualifiers) as $extraRow)
        {
            $extraQualifiedTeamIds[$extraRow->teamId] = true;
        }

        if ($extraQualifiedTeamIds === [])
        {
            return $standingsByGroup;
        }

        $updated = [];
        foreach ($standingsByGroup as $groupCode => $groupStandings)
        {
            $newRows = [];
            foreach ($groupStandings->rows as $row)
            {
                $newRows[] = isset($extraQualifiedTeamIds[$row->teamId])
                    ? new StandingRow(
                        position: $row->position,
                        teamId: $row->teamId,
                        teamName: $row->teamName,
                        teamLogoUrl: $row->teamLogoUrl,
                        primaryColor: $row->primaryColor,
                        groupCode: $row->groupCode,
                        points: $row->points,
                        played: $row->played,
                        wins: $row->wins,
                        draws: $row->draws,
                        losses: $row->losses,
                        goalsFor: $row->goalsFor,
                        goalsAgainst: $row->goalsAgainst,
                        goalDifference: $row->goalDifference,
                        qualifies: true,
                        tiedWithNext: $row->tiedWithNext,
                        resolvedByCriterion: $row->resolvedByCriterion,
                    )
                    : $row;
            }

            $updated[$groupCode] = new GroupStandings(
                groupCode: $groupStandings->groupCode,
                rows: $newRows,
                allGamesFinished: $groupStandings->allGamesFinished,
                unresolvedTieGroups: $groupStandings->unresolvedTieGroups,
            );
        }

        return $updated;
    }
}
