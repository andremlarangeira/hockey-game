<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTeam
{
    protected static function bootBelongsToTeam(): void
    {
        static::creating(function ($model): void {
            if ($model->getAttribute('user_id') === null)
            {
                $model->setAttribute('user_id', auth()->id());
            }
        });

        static::addGlobalScope('team', function (Builder $builder): void {
            $builder->where($builder->getModel()->qualifyColumn('user_id'), auth()->id());
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
