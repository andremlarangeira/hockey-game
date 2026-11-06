<?php

namespace App\Casts;

use App\Data\MatchSettings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<MatchSettings, MatchSettings> */
class MatchSettingsCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): MatchSettings
    {
        return MatchSettings::fromJson($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
