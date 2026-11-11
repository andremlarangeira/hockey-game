<?php

namespace App\Models;

use App\Championships\Enums\GamePhase;
use App\Championships\Enums\GameStatus;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Database\Factories\ChampionshipGameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $championship_id
 * @property GamePhase $phase
 * @property string|null $group_code
 * @property int $round_number
 * @property int $leg_number
 * @property string $slot_key
 * @property int|null $bracket_position
 * @property string $home_source
 * @property string $away_source
 * @property int|null $home_team_id
 * @property int|null $away_team_id
 * @property string|null $venue
 * @property CarbonImmutable|null $scheduled_at
 * @property GameStatus $status
 * @property bool $is_bye
 * @property int $home_regular_score
 * @property int $away_regular_score
 * @property bool $went_to_overtime
 * @property bool $went_to_shootout
 * @property int $home_shootout_score
 * @property int $away_shootout_score
 * @property int|null $winner_team_id
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
#[Fillable([
    'id',
    'user_id',
    'championship_id',
    'phase',
    'group_code',
    'round_number',
    'leg_number',
    'slot_key',
    'bracket_position',
    'home_source',
    'away_source',
    'home_team_id',
    'away_team_id',
    'venue',
    'scheduled_at',
    'status',
    'is_bye',
    'home_regular_score',
    'away_regular_score',
    'went_to_overtime',
    'went_to_shootout',
    'home_shootout_score',
    'away_shootout_score',
    'winner_team_id',
    'started_at',
    'finished_at',
])]
class ChampionshipGame extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<ChampionshipGameFactory> */
    use HasFactory;

    protected $attributes = [
        'phase' => 'group',
        'round_number' => 1,
        'leg_number' => 1,
        'status' => 'scheduled',
        'is_bye' => false,
        'home_regular_score' => 0,
        'away_regular_score' => 0,
        'went_to_overtime' => false,
        'went_to_shootout' => false,
        'home_shootout_score' => 0,
        'away_shootout_score' => 0,
    ];

    protected function casts(): array
    {
        return [
            'phase' => GamePhase::class,
            'status' => GameStatus::class,
            'is_bye' => 'boolean',
            'went_to_overtime' => 'boolean',
            'went_to_shootout' => 'boolean',
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Championship, $this> */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class, 'championship_id');
    }

    /** @return BelongsTo<ChampionshipTeam, $this> */
    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(ChampionshipTeam::class, 'home_team_id');
    }

    /** @return BelongsTo<ChampionshipTeam, $this> */
    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(ChampionshipTeam::class, 'away_team_id');
    }

    /** @return BelongsTo<ChampionshipTeam, $this> */
    public function winnerTeam(): BelongsTo
    {
        return $this->belongsTo(ChampionshipTeam::class, 'winner_team_id');
    }

    /** @return HasMany<ChampionshipGameEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ChampionshipGameEvent::class, 'championship_game_id');
    }
}
