<?php

namespace App\Models;

use App\Championships\Enums\GameEventType;
use App\Championships\Enums\TeamSide;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property int $championship_id
 * @property int $championship_game_id
 * @property GameEventType $type
 * @property TeamSide|null $side
 * @property int|null $team_id
 * @property int|null $primary_player_id
 * @property int|null $first_assist_player_id
 * @property int|null $second_assist_player_id
 * @property int|null $period
 * @property bool $is_overtime
 * @property int $elapsed_seconds
 * @property int $remaining_seconds
 * @property int|null $shootout_round
 * @property bool|null $shootout_scored
 * @property string|null $period_mark_type
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([
    'id',
    'user_id',
    'championship_id',
    'championship_game_id',
    'type',
    'side',
    'team_id',
    'primary_player_id',
    'first_assist_player_id',
    'second_assist_player_id',
    'period',
    'is_overtime',
    'elapsed_seconds',
    'remaining_seconds',
    'shootout_round',
    'shootout_scored',
    'period_mark_type',
    'recorded_at',
])]
class ChampionshipGameEvent extends Model
{
    use BelongsToTeam;
    use SoftDeletes;

    protected $attributes = [
        'is_overtime' => false,
        'elapsed_seconds' => 0,
        'remaining_seconds' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => GameEventType::class,
            'side' => TeamSide::class,
            'is_overtime' => 'boolean',
            'shootout_scored' => 'boolean',
            'recorded_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
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

    /** @return BelongsTo<ChampionshipTeam, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(ChampionshipTeam::class, 'team_id');
    }

    /** @return BelongsTo<ChampionshipPlayer, $this> */
    public function primaryPlayer(): BelongsTo
    {
        return $this->belongsTo(ChampionshipPlayer::class, 'primary_player_id');
    }

    /** @return BelongsTo<ChampionshipPlayer, $this> */
    public function firstAssistPlayer(): BelongsTo
    {
        return $this->belongsTo(ChampionshipPlayer::class, 'first_assist_player_id');
    }

    /** @return BelongsTo<ChampionshipPlayer, $this> */
    public function secondAssistPlayer(): BelongsTo
    {
        return $this->belongsTo(ChampionshipPlayer::class, 'second_assist_player_id');
    }
}
