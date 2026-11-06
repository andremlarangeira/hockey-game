<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'match_id', 'team', 'scorer_id', 'assist_id', 'scorer_name', 'assist_name', 'period', 'elapsed_seconds', 'remaining_seconds', 'recorded_at'])]
/** @mixin BelongsToTeam */
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
