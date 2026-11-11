<?php

namespace App\Championships\Enums;

enum PlayerPosition: string
{
    case Goalkeeper = 'goalkeeper';
    case Defender = 'defender';
    case Forward = 'forward';
    case Universal = 'universal';

    public function label(): string
    {
        return match ($this)
        {
            self::Goalkeeper => 'Goleiro',
            self::Defender => 'Defensor',
            self::Forward => 'Atacante',
            self::Universal => 'Universal',
        };
    }
}
