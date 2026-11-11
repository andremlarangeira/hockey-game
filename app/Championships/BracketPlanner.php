<?php

namespace App\Championships;

use App\Championships\Data\ChampionshipRules;
use App\Championships\Data\GroupStandings;
use App\Championships\Data\StandingRow;
use App\Championships\Enums\GamePhase;
use DomainException;

final class BracketPlanner
{
    /**
     * Builds the complete list of knockout slot specifications (R16, QF, SF, 3RD, FINAL)
     * with source references and initial resolved team IDs.
     *
     * @param  array<string, GroupStandings>  $standingsByGroup
     * @return list<array{
     *     phase: GamePhase,
     *     round_number: int,
     *     slot_key: string,
     *     bracket_position: int,
     *     home_source: string,
     *     away_source: string,
     *     home_team_id: int|null,
     *     away_team_id: int|null,
     *     is_bye: bool
     * }>
     */
    public function buildKnockoutSlots(ChampionshipRules $rules, array $standingsByGroup): array
    {
        $expectedQualifiers = $rules->totalQualifiers();

        if ($expectedQualifiers < 2 || $expectedQualifiers > 16)
        {
            throw new DomainException('O mata-mata exige entre 2 e 16 equipes classificadas.');
        }

        if ($standingsByGroup === [])
        {
            throw new DomainException('Não há grupos disponíveis para gerar a chave do mata-mata.');
        }

        ksort($standingsByGroup);

        /** @var array<string, array<int, StandingRow>> $directByGroupAndPos */
        $directByGroupAndPos = [];
        /** @var list<StandingRow> $directRows */
        $directRows = [];
        /** @var list<StandingRow> $extraRows */
        $extraRows = [];

        foreach ($standingsByGroup as $groupCode => $groupStandings)
        {
            $qualifiedIdsInGroup = [];
            foreach ($groupStandings->rows as $row)
            {
                if ($row->qualifies)
                {
                    $qualifiedIdsInGroup[] = $row->teamId;

                    if ($row->position <= $rules->qualifiersPerGroup)
                    {
                        $directByGroupAndPos[$groupCode][$row->position] = $row;
                        $directRows[] = $row;
                    }
                    else
                    {
                        $extraRows[] = $row;
                    }
                }
            }

            foreach ($groupStandings->unresolvedTieGroups as $tieGroup)
            {
                if (array_intersect($tieGroup, $qualifiedIdsInGroup) !== [])
                {
                    throw new DomainException("Há um empate técnico pendente de definição manual no grupo {$groupCode}.");
                }
            }
        }

        $totalQualifiedCount = count($directRows) + count($extraRows);

        if ($totalQualifiedCount < 2 || $totalQualifiedCount > 16)
        {
            throw new DomainException('O mata-mata exige entre 2 e 16 equipes classificadas.');
        }

        if ($totalQualifiedCount !== $expectedQualifiers)
        {
            throw new DomainException('O número de equipes classificadas nos grupos não corresponde às regras do campeonato.');
        }

        $bracketSize = match (true)
        {
            $totalQualifiedCount <= 2 => 2,
            $totalQualifiedCount <= 4 => 4,
            $totalQualifiedCount <= 8 => 8,
            default => 16,
        };

        $firstRoundPairings = $this->planFirstRoundPairings(
            rules: $rules,
            bracketSize: $bracketSize,
            groupCodes: array_keys($standingsByGroup),
            directByGroupAndPos: $directByGroupAndPos,
            directRows: $directRows,
            extraRows: $extraRows,
        );

        $phases = $this->phasesForBracketSize($bracketSize);
        $firstPhase = $phases[0];

        /** @var list<array{phase: GamePhase, round_number: int, slot_key: string, bracket_position: int, home_source: string, away_source: string, home_team_id: int|null, away_team_id: int|null, is_bye: bool}> $allSlots */
        $allSlots = [];
        /** @var list<array{slot_key: string}> $previousRoundSlots */
        $previousRoundSlots = [];

        foreach ($firstRoundPairings as $idx => $pair)
        {
            $position = $idx + 1;
            $slotKey = $this->slotKeyFor($firstPhase, $position);

            $slot = [
                'phase' => $firstPhase,
                'round_number' => 1,
                'slot_key' => $slotKey,
                'bracket_position' => $position,
                'home_source' => $pair['home_source'],
                'away_source' => $pair['away_source'],
                'home_team_id' => $pair['home_team_id'],
                'away_team_id' => $pair['away_team_id'],
                'is_bye' => $pair['is_bye'],
            ];

            $allSlots[] = $slot;
            $previousRoundSlots[] = ['slot_key' => $slotKey];
        }

        $roundCount = count($phases);
        for ($roundIndex = 1; $roundIndex < $roundCount; $roundIndex++)
        {
            $phase = $phases[$roundIndex];
            $roundNumber = $roundIndex + 1;

            if ($phase === GamePhase::Final)
            {
                $sf1Key = $previousRoundSlots[0]['slot_key'];
                $sf2Key = $previousRoundSlots[1]['slot_key'];

                if ($rules->hasThirdPlaceMatch)
                {
                    $allSlots[] = [
                        'phase' => GamePhase::ThirdPlace,
                        'round_number' => $roundNumber,
                        'slot_key' => '3RD',
                        'bracket_position' => 1,
                        'home_source' => "loser:{$sf1Key}",
                        'away_source' => "loser:{$sf2Key}",
                        'home_team_id' => null,
                        'away_team_id' => null,
                        'is_bye' => false,
                    ];
                }

                $allSlots[] = [
                    'phase' => GamePhase::Final,
                    'round_number' => $roundNumber,
                    'slot_key' => 'FINAL',
                    'bracket_position' => $rules->hasThirdPlaceMatch ? 2 : 1,
                    'home_source' => "winner:{$sf1Key}",
                    'away_source' => "winner:{$sf2Key}",
                    'home_team_id' => null,
                    'away_team_id' => null,
                    'is_bye' => false,
                ];

                break;
            }

            $nextRoundSlots = [];
            $matchesInRound = intdiv(count($previousRoundSlots), 2);

            for ($i = 1; $i <= $matchesInRound; $i++)
            {
                $prevHomeKey = $previousRoundSlots[(2 * $i) - 2]['slot_key'];
                $prevAwayKey = $previousRoundSlots[(2 * $i) - 1]['slot_key'];
                $slotKey = $this->slotKeyFor($phase, $i);

                $allSlots[] = [
                    'phase' => $phase,
                    'round_number' => $roundNumber,
                    'slot_key' => $slotKey,
                    'bracket_position' => $i,
                    'home_source' => "winner:{$prevHomeKey}",
                    'away_source' => "winner:{$prevAwayKey}",
                    'home_team_id' => null,
                    'away_team_id' => null,
                    'is_bye' => false,
                ];

                $nextRoundSlots[] = ['slot_key' => $slotKey];
            }

            $previousRoundSlots = $nextRoundSlots;
        }

        return $allSlots;
    }

    /**
     * @param  list<string>  $groupCodes
     * @param  array<string, array<int, StandingRow>>  $directByGroupAndPos
     * @param  list<StandingRow>  $directRows
     * @param  list<StandingRow>  $extraRows
     * @return list<array{
     *     home_source: string,
     *     away_source: string,
     *     home_team_id: int,
     *     away_team_id: int|null,
     *     is_bye: bool
     * }>
     */
    private function planFirstRoundPairings(
        ChampionshipRules $rules,
        int $bracketSize,
        array $groupCodes,
        array $directByGroupAndPos,
        array $directRows,
        array $extraRows,
    ): array {
        // Canonical cross-seeding patterns when no extra qualifiers and exact power-of-two qualifiers
        if ($rules->extraBestQualifiers === 0 && count($groupCodes) === $rules->groupsCount)
        {
            // 2 groups x 2 qualifiers = 4 -> SF1: 1A x 2B, SF2: 1B x 2A
            if ($rules->groupsCount === 2 && $rules->qualifiersPerGroup === 2 && $bracketSize === 4)
            {
                [$gA, $gB] = $groupCodes;
                if (
                    isset($directByGroupAndPos[$gA][1], $directByGroupAndPos[$gA][2], $directByGroupAndPos[$gB][1], $directByGroupAndPos[$gB][2])
                ) {
                    return [
                        $this->makeDirectPair($directByGroupAndPos[$gA][1], $directByGroupAndPos[$gB][2]),
                        $this->makeDirectPair($directByGroupAndPos[$gB][1], $directByGroupAndPos[$gA][2]),
                    ];
                }
            }

            // 2 groups x 4 qualifiers = 8 -> QF1: 1A x 4B, QF2: 2B x 3A, QF3: 1B x 4A, QF4: 2A x 3B
            if ($rules->groupsCount === 2 && $rules->qualifiersPerGroup === 4 && $bracketSize === 8)
            {
                [$gA, $gB] = $groupCodes;
                if (
                    isset(
                        $directByGroupAndPos[$gA][1],
                        $directByGroupAndPos[$gA][2],
                        $directByGroupAndPos[$gA][3],
                        $directByGroupAndPos[$gA][4],
                        $directByGroupAndPos[$gB][1],
                        $directByGroupAndPos[$gB][2],
                        $directByGroupAndPos[$gB][3],
                        $directByGroupAndPos[$gB][4],
                    )
                ) {
                    return [
                        $this->makeDirectPair($directByGroupAndPos[$gA][1], $directByGroupAndPos[$gB][4]),
                        $this->makeDirectPair($directByGroupAndPos[$gB][2], $directByGroupAndPos[$gA][3]),
                        $this->makeDirectPair($directByGroupAndPos[$gB][1], $directByGroupAndPos[$gA][4]),
                        $this->makeDirectPair($directByGroupAndPos[$gA][2], $directByGroupAndPos[$gB][3]),
                    ];
                }
            }

            // 4 groups x 2 qualifiers = 8 -> QF1: 1A x 2B, QF2: 1C x 2D, QF3: 1B x 2A, QF4: 1D x 2C
            if ($rules->groupsCount === 4 && $rules->qualifiersPerGroup === 2 && $bracketSize === 8)
            {
                [$gA, $gB, $gC, $gD] = $groupCodes;
                if (
                    isset(
                        $directByGroupAndPos[$gA][1],
                        $directByGroupAndPos[$gA][2],
                        $directByGroupAndPos[$gB][1],
                        $directByGroupAndPos[$gB][2],
                        $directByGroupAndPos[$gC][1],
                        $directByGroupAndPos[$gC][2],
                        $directByGroupAndPos[$gD][1],
                        $directByGroupAndPos[$gD][2],
                    )
                ) {
                    return [
                        $this->makeDirectPair($directByGroupAndPos[$gA][1], $directByGroupAndPos[$gB][2]),
                        $this->makeDirectPair($directByGroupAndPos[$gC][1], $directByGroupAndPos[$gD][2]),
                        $this->makeDirectPair($directByGroupAndPos[$gB][1], $directByGroupAndPos[$gA][2]),
                        $this->makeDirectPair($directByGroupAndPos[$gD][1], $directByGroupAndPos[$gC][2]),
                    ];
                }
            }
        }

        // General seeding: rank all qualifiers by group finish tier, then campaign
        /** @var list<array{row: StandingRow, source: string, tier: int}> $seeds */
        $seeds = [];

        for ($pos = 1; $pos <= $rules->qualifiersPerGroup; $pos++)
        {
            $tierRows = array_values(array_filter(
                $directRows,
                static fn (StandingRow $row): bool => $row->position === $pos,
            ));

            $this->sortRowsByCampaign($tierRows);

            foreach ($tierRows as $row)
            {
                $seeds[] = [
                    'row' => $row,
                    'source' => "group:{$row->groupCode}:{$row->position}",
                    'tier' => $pos,
                ];
            }
        }

        if ($extraRows !== [])
        {
            $sortedExtras = $extraRows;
            $this->sortRowsByCampaign($sortedExtras);

            foreach ($sortedExtras as $idx => $row)
            {
                $rank = $idx + 1;
                $seeds[] = [
                    'row' => $row,
                    'source' => "best:{$rank}",
                    'tier' => $rules->qualifiersPerGroup + 1,
                ];
            }
        }

        $matchesCount = intdiv($bracketSize, 2);

        /** @var list<array{home: array{row: StandingRow, source: string, tier: int}, away: array{row: StandingRow, source: string, tier: int}|null}> $pairs */
        $pairs = [];

        for ($seedNumber = 1; $seedNumber <= $matchesCount; $seedNumber++)
        {
            $homeSeed = $seeds[$seedNumber - 1];
            $opponentSeedNumber = $bracketSize + 1 - $seedNumber;

            $awaySeed = $opponentSeedNumber <= count($seeds)
                ? $seeds[$opponentSeedNumber - 1]
                : null;

            $pairs[] = [
                'home' => $homeSeed,
                'away' => $awaySeed,
            ];
        }

        // Avoid same-group clashes in first round when multiple groups exist
        if (count($groupCodes) > 1)
        {
            $pairs = $this->avoidSameGroupClashes($pairs);
        }

        $result = [];
        foreach ($pairs as $pair)
        {
            $home = $pair['home'];
            $away = $pair['away'];

            if ($away === null)
            {
                $result[] = [
                    'home_source' => $home['source'],
                    'away_source' => 'bye',
                    'home_team_id' => $home['row']->teamId,
                    'away_team_id' => null,
                    'is_bye' => true,
                ];
            }
            else
            {
                $result[] = [
                    'home_source' => $home['source'],
                    'away_source' => $away['source'],
                    'home_team_id' => $home['row']->teamId,
                    'away_team_id' => $away['row']->teamId,
                    'is_bye' => false,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array{
     *     home_source: string,
     *     away_source: string,
     *     home_team_id: int,
     *     away_team_id: int,
     *     is_bye: false
     * }
     */
    private function makeDirectPair(StandingRow $home, StandingRow $away): array
    {
        return [
            'home_source' => "group:{$home->groupCode}:{$home->position}",
            'away_source' => "group:{$away->groupCode}:{$away->position}",
            'home_team_id' => $home->teamId,
            'away_team_id' => $away->teamId,
            'is_bye' => false,
        ];
    }

    /**
     * @param  list<StandingRow>  $rows
     */
    private function sortRowsByCampaign(array &$rows): void
    {
        usort(
            $rows,
            static fn (StandingRow $a, StandingRow $b): int => [
                $b->points,
                $b->wins,
                $b->goalDifference,
                $b->goalsFor,
                -$b->goalsAgainst,
            ] <=> [
                $a->points,
                $a->wins,
                $a->goalDifference,
                $a->goalsFor,
                -$a->goalsAgainst,
            ] ?: strcmp($a->groupCode, $b->groupCode),
        );
    }

    /**
     * @param  list<array{home: array{row: StandingRow, source: string, tier: int}, away: array{row: StandingRow, source: string, tier: int}|null}>  $pairs
     * @return list<array{home: array{row: StandingRow, source: string, tier: int}, away: array{row: StandingRow, source: string, tier: int}|null}>
     */
    private function avoidSameGroupClashes(array $pairs): array
    {
        $count = count($pairs);

        for ($i = 0; $i < $count; $i++)
        {
            $awayI = $pairs[$i]['away'];
            if ($awayI === null)
            {
                continue;
            }

            $homeGroupI = $pairs[$i]['home']['row']->groupCode;
            if ($homeGroupI !== $awayI['row']->groupCode)
            {
                continue;
            }

            // Look for a swap candidate (prefer same tier first, then any non-bye slot)
            $swapped = false;
            foreach ([true, false] as $sameTierOnly)
            {
                for ($j = 0; $j < $count; $j++)
                {
                    if ($i === $j)
                    {
                        continue;
                    }

                    $awayJ = $pairs[$j]['away'];
                    if ($awayJ === null)
                    {
                        continue;
                    }

                    if ($sameTierOnly && $awayJ['tier'] !== $awayI['tier'])
                    {
                        continue;
                    }

                    $homeGroupJ = $pairs[$j]['home']['row']->groupCode;

                    if (
                        $homeGroupI !== $awayJ['row']->groupCode
                        && $homeGroupJ !== $awayI['row']->groupCode
                    ) {
                        $pairs[$i] = [
                            'home' => $pairs[$i]['home'],
                            'away' => $awayJ,
                        ];
                        $pairs[$j] = [
                            'home' => $pairs[$j]['home'],
                            'away' => $awayI,
                        ];
                        $swapped = true;

                        break;
                    }
                }

                if ($swapped)
                {
                    break;
                }
            }
        }

        return $pairs;
    }

    /**
     * @return list<GamePhase>
     */
    private function phasesForBracketSize(int $bracketSize): array
    {
        return match ($bracketSize)
        {
            16 => [GamePhase::RoundOf16, GamePhase::QuarterFinal, GamePhase::SemiFinal, GamePhase::Final],
            8 => [GamePhase::QuarterFinal, GamePhase::SemiFinal, GamePhase::Final],
            4 => [GamePhase::SemiFinal, GamePhase::Final],
            default => [GamePhase::Final],
        };
    }

    private function slotKeyFor(GamePhase $phase, int $position): string
    {
        return match ($phase)
        {
            GamePhase::RoundOf16 => "R16-{$position}",
            GamePhase::QuarterFinal => "QF{$position}",
            GamePhase::SemiFinal => "SF{$position}",
            GamePhase::ThirdPlace => '3RD',
            GamePhase::Final => 'FINAL',
            GamePhase::Group => "G-{$position}",
        };
    }
}
