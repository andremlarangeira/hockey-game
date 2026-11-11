<?php

namespace App\Championships\Enums;

enum KnockoutTieResolution: string
{
    case OvertimeThenShootout = 'overtime_then_shootout';
    case ShootoutOnly = 'shootout_only';

    public function label(): string
    {
        return match ($this)
        {
            self::OvertimeThenShootout => 'Prorrogação seguida de pênaltis/shootout',
            self::ShootoutOnly => 'Direto para pênaltis/shootout',
        };
    }
}
