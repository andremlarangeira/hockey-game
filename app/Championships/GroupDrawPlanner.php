<?php

namespace App\Championships;

use App\Championships\Data\ChampionshipRules;
use App\Models\ChampionshipTeam;

/**
 * Pure group-draw rules: validates assignments and produces group fixtures.
 */
final class GroupDrawPlanner
{
    /**
     * @param  list<ChampionshipTeam>  $teams
     * @param  array<string, list<int>>  $explicitGroups
     * @return array<string, list<ChampionshipTeam>>
     */
    public function validateExplicitGroups(array $teams, array $explicitGroups, ChampionshipRules $rules): array
    {
        if (count($explicitGroups) !== $rules->groupsCount)
        {
            throw new ChampionshipException("O sorteio exige exatamente {$rules->groupsCount} grupo(s).");
        }

        $teamById = [];
        foreach ($teams as $team)
        {
            $teamById[(int) $team->id] = $team;
        }

        $seenIds = [];
        $result = [];
        ksort($explicitGroups);

        foreach ($explicitGroups as $groupCode => $teamIds)
        {
            $normalizedCode = trim((string) $groupCode);
            if ($normalizedCode === '')
            {
                throw new ChampionshipException('Estrutura de grupos inválida.');
            }

            $groupTeamModels = [];
            foreach ($teamIds as $rawId)
            {
                $teamId = (int) $rawId;
                if (! isset($teamById[$teamId]))
                {
                    throw new ChampionshipException("A equipe #{$teamId} não pertence a este campeonato.");
                }
                if (isset($seenIds[$teamId]))
                {
                    throw new ChampionshipException("A equipe #{$teamId} foi atribuída a mais de um grupo.");
                }

                $seenIds[$teamId] = true;
                $groupTeamModels[] = $teamById[$teamId];
            }

            $result[$normalizedCode] = $groupTeamModels;
        }

        if (count($seenIds) !== count($teams))
        {
            throw new ChampionshipException('Todas as equipes inscritas devem participar do sorteio dos grupos.');
        }

        return $result;
    }

    /**
     * @param  list<ChampionshipTeam>  $teams
     * @return array<string, list<ChampionshipTeam>>
     */
    public function randomSeededDraw(array $teams, int $groupsCount): array
    {
        /** @var array<int, list<ChampionshipTeam>> $pots */
        $pots = [];
        foreach ($teams as $team)
        {
            $potKey = $team->seed_pot !== null && $team->seed_pot > 0 ? (int) $team->seed_pot : PHP_INT_MAX;
            $pots[$potKey][] = $team;
        }

        ksort($pots, SORT_NUMERIC);
        /** @var list<ChampionshipTeam> $orderedPool */
        $orderedPool = [];
        foreach ($pots as $potTeams)
        {
            foreach ($this->cryptoShuffle($potTeams) as $team)
            {
                $orderedPool[] = $team;
            }
        }

        $groupCodes = [];
        for ($i = 0; $i < $groupsCount; $i++)
        {
            $groupCodes[] = chr(65 + ($i % 26));
        }

        /** @var array<string, list<ChampionshipTeam>> $groups */
        $groups = [];
        foreach ($groupCodes as $code)
        {
            $groups[$code] = [];
        }

        foreach ($orderedPool as $index => $team)
        {
            $pass = intdiv($index, $groupsCount);
            $offset = $index % $groupsCount;
            $groupIndex = $pass % 2 === 0 ? $offset : $groupsCount - 1 - $offset;
            $groups[$groupCodes[$groupIndex]][] = $team;
        }

        return $groups;
    }

    /**
     * @param  list<ChampionshipTeam>  $groupTeams
     * @return list<array{round_number: int, leg_number: int, slot_key: string, home_source: string, away_source: string, home_team_id: int, away_team_id: int}>
     */
    public function buildRoundRobinFixtures(string $groupCode, array $groupTeams, bool $doubleRoundRobin): array
    {
        /** @var list<ChampionshipTeam|null> $rotation */
        $rotation = $groupTeams;
        if (count($rotation) % 2 === 1)
        {
            $rotation[] = null;
        }

        $n = count($rotation);
        $roundsPerLeg = $n - 1;
        $half = intdiv($n, 2);
        $leg1Fixtures = [];

        for ($round = 1; $round <= $roundsPerLeg; $round++)
        {
            for ($i = 0; $i < $half; $i++)
            {
                $first = $rotation[$i];
                $second = $rotation[$n - 1 - $i];
                if ($first === null || $second === null)
                {
                    continue;
                }

                $swapHomeAway = ($i === 0 && $round % 2 === 0) || ($i > 0 && ($round + $i) % 2 === 1);
                $home = $swapHomeAway ? $second : $first;
                $away = $swapHomeAway ? $first : $second;
                $homeId = (int) $home->id;
                $awayId = (int) $away->id;
                $minId = min($homeId, $awayId);
                $maxId = max($homeId, $awayId);

                $leg1Fixtures[] = [
                    'round_number' => $round,
                    'leg_number' => 1,
                    'slot_key' => "G-{$groupCode}-L1-{$minId}-{$maxId}",
                    'home_source' => "team:{$homeId}",
                    'away_source' => "team:{$awayId}",
                    'home_team_id' => $homeId,
                    'away_team_id' => $awayId,
                ];
            }

            $last = array_pop($rotation);
            array_splice($rotation, 1, 0, [$last]);
        }

        if (! $doubleRoundRobin)
        {
            return $leg1Fixtures;
        }

        $allFixtures = $leg1Fixtures;
        foreach ($leg1Fixtures as $leg1)
        {
            $homeId = $leg1['away_team_id'];
            $awayId = $leg1['home_team_id'];
            $minId = min($homeId, $awayId);
            $maxId = max($homeId, $awayId);
            $allFixtures[] = [
                'round_number' => $roundsPerLeg + $leg1['round_number'],
                'leg_number' => 2,
                'slot_key' => "G-{$groupCode}-L2-{$minId}-{$maxId}",
                'home_source' => "team:{$homeId}",
                'away_source' => "team:{$awayId}",
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
            ];
        }

        return $allFixtures;
    }

    /**
     * @param  list<ChampionshipTeam>  $teams
     * @param  array<string, list<int>>  $explicitGroups
     */
    public function matchesCurrentAssignment(array $teams, array $explicitGroups): bool
    {
        $current = $this->currentGroupMap($teams);
        ksort($current);
        $normalizedExplicit = [];
        foreach ($explicitGroups as $code => $ids)
        {
            $normalizedExplicit[trim((string) $code)] = array_map('intval', $ids);
        }
        ksort($normalizedExplicit);

        return $current === $normalizedExplicit;
    }

    /** @param list<ChampionshipTeam> $teams
     * @return array<string, list<int>>
     */
    public function currentGroupMap(array $teams): array
    {
        $byGroup = [];
        foreach ($teams as $team)
        {
            if ($team->group_code === null || $team->group_code === '')
            {
                continue;
            }
            $byGroup[$team->group_code][] = $team;
        }
        ksort($byGroup);

        $result = [];
        foreach ($byGroup as $code => $groupTeams)
        {
            usort($groupTeams, static fn (ChampionshipTeam $a, ChampionshipTeam $b): int => [
                $a->group_draw_order ?? PHP_INT_MAX,
                $a->id,
            ] <=> [
                $b->group_draw_order ?? PHP_INT_MAX,
                $b->id,
            ]);
            $result[$code] = array_map(static fn (ChampionshipTeam $team): int => (int) $team->id, $groupTeams);
        }

        return $result;
    }

    /** @template T
     * @param  list<T>  $items
     * @return list<T>
     */
    private function cryptoShuffle(array $items): array
    {
        $copy = $items;
        for ($i = count($copy) - 1; $i > 0; $i--)
        {
            $j = random_int(0, $i);
            [$copy[$i], $copy[$j]] = [$copy[$j], $copy[$i]];
        }

        return array_values($copy);
    }
}
