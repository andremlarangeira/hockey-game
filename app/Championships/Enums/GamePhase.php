<?php

namespace App\Championships\Enums;

enum GamePhase: string
{
    case Group = 'group';
    case RoundOf16 = 'round_of_16';
    case QuarterFinal = 'quarter_final';
    case SemiFinal = 'semi_final';
    case ThirdPlace = 'third_place';
    case Final = 'final';

    public function isKnockout(): bool
    {
        return $this !== self::Group;
    }

    public function label(): string
    {
        return match ($this)
        {
            self::Group => 'Fase de grupos',
            self::RoundOf16 => 'Oitavas de final',
            self::QuarterFinal => 'Quartas de final',
            self::SemiFinal => 'Semifinal',
            self::ThirdPlace => 'Disputa de 3º lugar',
            self::Final => 'Final',
        };
    }
}
