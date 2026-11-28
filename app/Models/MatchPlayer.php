<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $match_id
 * @property int $user_id
 * @property int|null $player_id
 * @property string $player_name
 * @property string $team
 * @property bool $present
 */
#[Fillable(['match_id', 'user_id', 'player_id', 'player_name', 'team', 'present'])]
class MatchPlayer extends Model
{
    use BelongsToTeam;

    protected $table = 'match_players';

    /** @return BelongsTo<HockeyMatch, $this> */
    public function hockeyMatch(): BelongsTo
    {
        return $this->belongsTo(HockeyMatch::class, 'match_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    protected function casts(): array
    {
        return [
            'present' => 'boolean',
        ];
    }
}
