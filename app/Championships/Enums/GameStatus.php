<?php

namespace App\Championships\Enums;

enum GameStatus: string
{
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Finished = 'finished';

    public function label(): string
    {
        return match ($this)
        {
            self::Scheduled => 'Agendado',
            self::Live => 'Em andamento',
            self::Finished => 'Encerrado',
        };
    }
}
