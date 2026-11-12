<?php

namespace App\Models;

use App\Casts\MatchRosterCast;
use App\Casts\MatchSettingsCast;
use App\Data\MatchRoster;
use App\Data\MatchSettings;
use App\Models\Concerns\BelongsToTeam;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $home_score
 * @property int $away_score
 * @property MatchSettings $settings
 * @property MatchRoster $roster
 * @property array<int, array<string, mixed>> $timeline
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $started_at
 * @property int $goals_count
 */
#[Fillable(['client_uuid', 'home_score', 'away_score', 'settings', 'roster', 'timeline', 'started_at', 'finished_at'])]
class HockeyMatch extends Model
{
    use BelongsToTeam;

    protected $table = 'matches';

    protected function casts(): array
    {
        return [
            'settings' => MatchSettingsCast::class, 'roster' => MatchRosterCast::class, 'timeline' => 'array',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }

    /** @return HasMany<Goal, $this> */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class, 'match_id');
    }
}
