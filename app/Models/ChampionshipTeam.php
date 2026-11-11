<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Database\Factories\ChampionshipTeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $user_id
 * @property int $championship_id
 * @property string $name
 * @property string|null $short_name
 * @property string $primary_color
 * @property string $secondary_color
 * @property string|null $logo_path
 * @property string|null $group_code
 * @property int|null $group_draw_order
 * @property int|null $seed_pot
 * @property-read string|null $logo_url
 */
#[Fillable([
    'id',
    'user_id',
    'championship_id',
    'name',
    'short_name',
    'primary_color',
    'secondary_color',
    'logo_path',
    'group_code',
    'group_draw_order',
    'seed_pot',
])]
class ChampionshipTeam extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<ChampionshipTeamFactory> */
    use HasFactory;

    protected $appends = ['logo_url'];

    protected $attributes = [
        'primary_color' => '#2563eb',
        'secondary_color' => '#ffffff',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path)
        {
            return null;
        }

        if (app()->bound('filesystem'))
        {
            return Storage::disk('public')->url($this->logo_path);
        }

        return '/storage/'.ltrim($this->logo_path, '/');
    }

    /** @return BelongsTo<Championship, $this> */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class, 'championship_id');
    }

    /** @return HasMany<ChampionshipPlayer, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(ChampionshipPlayer::class, 'championship_team_id');
    }
}
