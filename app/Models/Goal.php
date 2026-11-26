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
 * @property int $match_id
 * @property string $team
 * @property int|null $scorer_id
 * @property int|null $assist_id
 * @property string $scorer_name
 * @property string|null $assist_name
 * @property int $period
 * @property int $elapsed_seconds
 * @property int $remaining_seconds
 * @property CarbonImmutable|null $recorded_at
 */
#[Fillable(['user_id', 'match_id', 'team', 'scorer_id', 'assist_id', 'scorer_name', 'assist_name', 'period', 'elapsed_seconds', 'remaining_seconds', 'recorded_at'])]
class Goal extends Model
{
    use BelongsToTeam;

    /** @return BelongsTo<HockeyMatch, $this> */
    public function hockeyMatch(): BelongsTo
    {
        return $this->belongsTo(HockeyMatch::class, 'match_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function scorer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'scorer_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function assist(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'assist_id');
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
