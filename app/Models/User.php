<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'email', 'password', 'logo_path', 'primary_color', 'secondary_color'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
/** @property int $id
 * @property string $name
 * @property string|null $primary_color
 * @property string|null $secondary_color
 * @property-read string|null $logo_url
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'two_factor_confirmed_at' => 'datetime'];
    }

    /** @return HasMany<Player, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /** @return HasMany<HockeyMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(HockeyMatch::class);
    }

    /** @return Attribute<string|null, never> */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null);
    }
}
