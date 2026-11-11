<?php

namespace App\Models;

use App\Casts\ChampionshipRulesCast;
use App\Championships\Data\ChampionshipRules;
use App\Championships\Enums\ChampionshipStatus;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Database\Factories\ChampionshipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $city
 * @property string|null $venue
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property ChampionshipStatus $status
 * @property ChampionshipRules $rules
 * @property array<string, mixed>|null $manual_tie_resolutions
 * @property CarbonImmutable|null $drawn_at
 * @property CarbonImmutable|null $bracket_generated_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $public_token
 * @property CarbonImmutable|null $public_enabled_at
 */
#[Fillable([
    'id',
    'user_id',
    'name',
    'city',
    'venue',
    'starts_on',
    'ends_on',
    'status',
    'rules',
    'manual_tie_resolutions',
    'drawn_at',
    'bracket_generated_at',
    'completed_at',
    'public_token',
    'public_enabled_at',
])]
class Championship extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<ChampionshipFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'draft',
        'manual_tie_resolutions' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'status' => ChampionshipStatus::class,
            'rules' => ChampionshipRulesCast::class,
            'manual_tie_resolutions' => 'array',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'drawn_at' => 'immutable_datetime',
            'bracket_generated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'public_enabled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ChampionshipTeam, $this> */
    public function teams(): HasMany
    {
        return $this->hasMany(ChampionshipTeam::class, 'championship_id');
    }

    /** @return HasMany<ChampionshipPlayer, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(ChampionshipPlayer::class, 'championship_id');
    }

    /** @return HasMany<ChampionshipGame, $this> */
    public function games(): HasMany
    {
        return $this->hasMany(ChampionshipGame::class, 'championship_id');
    }

    /** @return HasMany<ChampionshipGameEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ChampionshipGameEvent::class, 'championship_id');
    }

    /** @return HasMany<ChampionshipAuditLog, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(ChampionshipAuditLog::class, 'championship_id');
    }
}
