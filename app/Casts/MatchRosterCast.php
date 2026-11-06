<?php

namespace App\Casts;

use App\Data\MatchRoster;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<MatchRoster, MatchRoster> */
class MatchRosterCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): MatchRoster
    {
        return MatchRoster::fromJson($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
