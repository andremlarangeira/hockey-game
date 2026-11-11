<?php

namespace App\Championships\Enums;

enum TieBreakCriterion: string
{
    case Points = 'points';
    case HeadToHead = 'head_to_head';
    case Wins = 'wins';
    case GoalDifference = 'goal_difference';
    case GoalsFor = 'goals_for';
    case FewestGoalsAgainst = 'fewest_goals_against';
    case ManualDraw = 'manual_draw';

    public function label(): string
    {
        return match ($this)
        {
            self::Points => 'Pontos',
            self::HeadToHead => 'Confronto direto',
            self::Wins => 'Vitórias',
            self::GoalDifference => 'Saldo de gols',
            self::GoalsFor => 'Gols pró',
            self::FewestGoalsAgainst => 'Menos gols contra',
            self::ManualDraw => 'Sorteio manual',
        };
    }
}
