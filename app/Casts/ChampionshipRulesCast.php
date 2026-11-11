<?php

namespace App\Casts;

use App\Championships\Data\ChampionshipRules;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<ChampionshipRules, ChampionshipRules|array<string, mixed>> */
class ChampionshipRulesCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ChampionshipRules
    {
        if ($value instanceof ChampionshipRules)
        {
            return $value;
        }

        if (is_array($value))
        {
            return ChampionshipRules::fromArray($value);
        }

        if (is_string($value) && $value !== '')
        {
            return ChampionshipRules::fromJson($value);
        }

        return ChampionshipRules::defaults();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $rules = match (true)
        {
            $value instanceof ChampionshipRules => $value,
            is_array($value) => ChampionshipRules::fromArray($value),
            default => ChampionshipRules::defaults(),
        };

        return json_encode($rules, JSON_THROW_ON_ERROR);
    }
}
