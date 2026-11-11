<?php

namespace App\Championships\Data;

use App\Championships\Enums\KnockoutTieResolution;
use App\Championships\Enums\TieBreakCriterion;
use JsonSerializable;

final readonly class ChampionshipRules implements JsonSerializable
{
    /**
     * @param  list<TieBreakCriterion>  $tieBreakOrder
     */
    public function __construct(
        public int $groupsCount = 2,
        public int $qualifiersPerGroup = 2,
        public int $extraBestQualifiers = 0,
        public bool $doubleRoundRobin = false,
        public int $pointsWin = 3,
        public int $pointsDraw = 1,
        public int $pointsLoss = 0,
        public bool $allowGroupOvertimeOrShootout = false,
        public int $pointsOvertimeWin = 2,
        public int $pointsOvertimeLoss = 1,
        public array $tieBreakOrder = [
            TieBreakCriterion::Points,
            TieBreakCriterion::HeadToHead,
            TieBreakCriterion::GoalDifference,
            TieBreakCriterion::GoalsFor,
            TieBreakCriterion::Wins,
            TieBreakCriterion::FewestGoalsAgainst,
            TieBreakCriterion::ManualDraw,
        ],
        public int $periodMinutes = 10,
        public int $periodsCount = 2,
        public int $restSeconds = 120,
        public int $countdownSeconds = 5,
        public int $overtimeMinutes = 5,
        public KnockoutTieResolution $knockoutTieResolution = KnockoutTieResolution::OvertimeThenShootout,
        public bool $hasThirdPlaceMatch = true,
        public int $maxAssistsPerGoal = 2,
    ) {}

    /**
     * @return list<TieBreakCriterion>
     */
    public static function defaultTieBreakOrder(): array
    {
        return [
            TieBreakCriterion::Points,
            TieBreakCriterion::HeadToHead,
            TieBreakCriterion::GoalDifference,
            TieBreakCriterion::GoalsFor,
            TieBreakCriterion::Wins,
            TieBreakCriterion::FewestGoalsAgainst,
            TieBreakCriterion::ManualDraw,
        ];
    }

    /**
     * @param  list<TieBreakCriterion>|null  $tieBreakOrder
     */
    public static function defaults(
        int $groupsCount = 2,
        int $qualifiersPerGroup = 2,
        bool $hasThirdPlaceMatch = true,
        int $maxAssistsPerGoal = 2,
        int $extraBestQualifiers = 0,
        bool $doubleRoundRobin = false,
        int $pointsWin = 3,
        int $pointsDraw = 1,
        int $pointsLoss = 0,
        bool $allowGroupOvertimeOrShootout = false,
        int $pointsOvertimeWin = 2,
        int $pointsOvertimeLoss = 1,
        ?array $tieBreakOrder = null,
        int $periodMinutes = 10,
        int $periodsCount = 2,
        int $restSeconds = 120,
        int $countdownSeconds = 5,
        int $overtimeMinutes = 5,
        KnockoutTieResolution $knockoutTieResolution = KnockoutTieResolution::OvertimeThenShootout,
    ): self {
        return new self(
            groupsCount: $groupsCount,
            qualifiersPerGroup: $qualifiersPerGroup,
            extraBestQualifiers: $extraBestQualifiers,
            doubleRoundRobin: $doubleRoundRobin,
            pointsWin: $pointsWin,
            pointsDraw: $pointsDraw,
            pointsLoss: $pointsLoss,
            allowGroupOvertimeOrShootout: $allowGroupOvertimeOrShootout,
            pointsOvertimeWin: $pointsOvertimeWin,
            pointsOvertimeLoss: $pointsOvertimeLoss,
            tieBreakOrder: $tieBreakOrder ?? self::defaultTieBreakOrder(),
            periodMinutes: $periodMinutes,
            periodsCount: $periodsCount,
            restSeconds: $restSeconds,
            countdownSeconds: $countdownSeconds,
            overtimeMinutes: $overtimeMinutes,
            knockoutTieResolution: $knockoutTieResolution,
            hasThirdPlaceMatch: $hasThirdPlaceMatch,
            maxAssistsPerGoal: $maxAssistsPerGoal,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawOrder = $data['tie_break_order'] ?? $data['tieBreakOrder'] ?? null;
        if (is_array($rawOrder) && $rawOrder !== [])
        {
            $tieBreakOrder = [];
            foreach ($rawOrder as $item)
            {
                $tieBreakOrder[] = $item instanceof TieBreakCriterion
                    ? $item
                    : TieBreakCriterion::from((string) $item);
            }
        }
        else
        {
            $tieBreakOrder = self::defaultTieBreakOrder();
        }

        $rawKnockoutResolution = $data['knockout_tie_resolution'] ?? $data['knockoutTieResolution'] ?? KnockoutTieResolution::OvertimeThenShootout;
        $knockoutTieResolution = $rawKnockoutResolution instanceof KnockoutTieResolution
            ? $rawKnockoutResolution
            : KnockoutTieResolution::from((string) $rawKnockoutResolution);

        $allowGroupOt = $data['allow_group_overtime_or_shootout']
            ?? $data['allowGroupOvertimeOrShootout']
            ?? (($data['group_tie_mode'] ?? null) === 'overtime_shootout');

        return new self(
            groupsCount: (int) ($data['groups_count'] ?? $data['groupsCount'] ?? 2),
            qualifiersPerGroup: (int) ($data['qualifiers_per_group'] ?? $data['qualifiersPerGroup'] ?? 2),
            extraBestQualifiers: (int) ($data['extra_best_qualifiers'] ?? $data['extraBestQualifiers'] ?? 0),
            doubleRoundRobin: (bool) ($data['double_round_robin'] ?? $data['doubleRoundRobin'] ?? false),
            pointsWin: (int) ($data['points_win'] ?? $data['pointsWin'] ?? 3),
            pointsDraw: (int) ($data['points_draw'] ?? $data['pointsDraw'] ?? 1),
            pointsLoss: (int) ($data['points_loss'] ?? $data['pointsLoss'] ?? 0),
            allowGroupOvertimeOrShootout: (bool) $allowGroupOt,
            pointsOvertimeWin: (int) ($data['points_overtime_win'] ?? $data['pointsOvertimeWin'] ?? 2),
            pointsOvertimeLoss: (int) ($data['points_overtime_loss'] ?? $data['pointsOvertimeLoss'] ?? 1),
            tieBreakOrder: $tieBreakOrder,
            periodMinutes: (int) ($data['period_minutes'] ?? $data['periodMinutes'] ?? 10),
            periodsCount: (int) ($data['periods_count'] ?? $data['periodsCount'] ?? 2),
            restSeconds: (int) ($data['rest_seconds'] ?? $data['restSeconds'] ?? 120),
            countdownSeconds: (int) ($data['countdown_seconds'] ?? $data['countdownSeconds'] ?? 5),
            overtimeMinutes: (int) ($data['overtime_minutes'] ?? $data['overtimeMinutes'] ?? 5),
            knockoutTieResolution: $knockoutTieResolution,
            hasThirdPlaceMatch: (bool) ($data['has_third_place_match'] ?? $data['hasThirdPlaceMatch'] ?? true),
            maxAssistsPerGoal: (int) ($data['max_assists_per_goal'] ?? $data['maxAssistsPerGoal'] ?? 2),
        );
    }

    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    public function totalQualifiers(): int
    {
        return ($this->groupsCount * $this->qualifiersPerGroup) + $this->extraBestQualifiers;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'groups_count' => $this->groupsCount,
            'qualifiers_per_group' => $this->qualifiersPerGroup,
            'extra_best_qualifiers' => $this->extraBestQualifiers,
            'double_round_robin' => $this->doubleRoundRobin,
            'points_win' => $this->pointsWin,
            'points_draw' => $this->pointsDraw,
            'points_loss' => $this->pointsLoss,
            'allow_group_overtime_or_shootout' => $this->allowGroupOvertimeOrShootout,
            'points_overtime_win' => $this->pointsOvertimeWin,
            'points_overtime_loss' => $this->pointsOvertimeLoss,
            'tie_break_order' => array_map(
                static fn (TieBreakCriterion $criterion): string => $criterion->value,
                $this->tieBreakOrder,
            ),
            'period_minutes' => $this->periodMinutes,
            'periods_count' => $this->periodsCount,
            'rest_seconds' => $this->restSeconds,
            'countdown_seconds' => $this->countdownSeconds,
            'overtime_minutes' => $this->overtimeMinutes,
            'knockout_tie_resolution' => $this->knockoutTieResolution->value,
            'has_third_place_match' => $this->hasThirdPlaceMatch,
            'max_assists_per_goal' => $this->maxAssistsPerGoal,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
