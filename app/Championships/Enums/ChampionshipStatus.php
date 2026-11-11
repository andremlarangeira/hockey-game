<?php

namespace App\Championships\Enums;

enum ChampionshipStatus: string
{
    case Draft = 'draft';
    case GroupStage = 'group_stage';
    case KnockoutStage = 'knockout_stage';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this)
        {
            self::Draft => 'Rascunho',
            self::GroupStage => 'Fase de grupos',
            self::KnockoutStage => 'Mata-mata',
            self::Completed => 'Encerrado',
        };
    }
}
