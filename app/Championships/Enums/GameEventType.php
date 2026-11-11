<?php

namespace App\Championships\Enums;

enum GameEventType: string
{
    case Goal = 'goal';
    case ShootoutAttempt = 'shootout_attempt';
    case PeriodMark = 'period_mark';
}
