<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $championship_id
 * @property int|null $championship_game_id
 * @property int $actor_user_id
 * @property string $action
 * @property string|null $reason
 * @property array<string, mixed>|null $before_snapshot
 * @property array<string, mixed>|null $after_snapshot
 * @property CarbonImmutable $recorded_at
 */
#[Fillable([
    'id',
    'user_id',
    'championship_id',
    'championship_game_id',
    'actor_user_id',
    'action',
    'reason',
    'before_snapshot',
    'after_snapshot',
    'recorded_at',
])]
class ChampionshipAuditLog extends Model
{
    use BelongsToTeam;

    protected function casts(): array
    {
        return [
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Championship, $this> */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class, 'championship_id');
    }

    /** @return BelongsTo<ChampionshipGame, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(ChampionshipGame::class, 'championship_game_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
