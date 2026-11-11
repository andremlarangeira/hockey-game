<?php

namespace App\Models;

use App\Championships\Enums\PlayerPosition;
use App\Models\Concerns\BelongsToTeam;
use Database\Factories\ChampionshipPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $championship_id
 * @property int $championship_team_id
 * @property string $name
 * @property int $jersey_number
 * @property PlayerPosition $position
 * @property bool $is_goalkeeper
 */
#[Fillable([
    'id',
    'user_id',
    'championship_id',
    'championship_team_id',
    'name',
    'jersey_number',
    'position',
    'is_goalkeeper',
])]
class ChampionshipPlayer extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<ChampionshipPlayerFactory> */
    use HasFactory;

    protected $attributes = [
        'position' => 'universal',
        'is_goalkeeper' => false,
    ];

    protected function casts(): array
    {
        return [
            'position' => PlayerPosition::class,
            'is_goalkeeper' => 'boolean',
        ];
    }

    /** @return BelongsTo<Championship, $this> */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class, 'championship_id');
    }

    /** @return BelongsTo<ChampionshipTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(ChampionshipTeam::class, 'championship_team_id');
    }
}
