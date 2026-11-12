<?php

namespace App\Models;

/**
 * @property int $id
 * @property string $name
 * @property int $jersey_number
 * @property numeric-string $level
 * @property-read string|null $photo_url
 */

use App\Models\Concerns\BelongsToTeam;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'jersey_number', 'photo_path', 'level', 'present', 'presence_changed_at'])]
class Player extends Model
{
    use BelongsToTeam;

    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    protected $appends = ['photo_url', 'stats'];

    protected function casts(): array
    {
        return ['level' => 'decimal:1', 'present' => 'boolean', 'presence_changed_at' => 'datetime'];
    }

    /**
     * @return array{id: int, name: string, jersey_number: int, level: float, photo_url: string|null, present: bool}
     */
    public function toDrawPlayer(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'jersey_number' => $this->jersey_number,
            'level' => (float) $this->level, 'photo_url' => $this->photo_url, 'present' => $this->present,
        ];
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }

    /** @return array{goals: int, assists: int, games: int} */
    public function getStatsAttribute(): array
    {
        $goals = $this->getRelationValue('scored_goals_count');
        $assists = $this->getRelationValue('assisted_goals_count');
        $games = $this->getRelationValue('appearances_count');

        return [
            'goals' => is_numeric($goals) ? (int) $goals : 0,
            'assists' => is_numeric($assists) ? (int) $assists : 0,
            'games' => is_numeric($games) ? (int) $games : 0,
        ];
    }
}
