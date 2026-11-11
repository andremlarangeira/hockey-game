<?php

namespace App\Http\Requests\Championships;

use App\Championships\Data\ChampionshipRules;
use App\Championships\Enums\KnockoutTieResolution;
use App\Championships\Enums\TieBreakCriterion;
use App\Models\Championship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChampionshipRulesRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('rules') && is_array($this->input('rules')))
        {
            $this->merge($this->input('rules'));
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'groups_count' => ['sometimes', 'integer', 'between:1,8'],
            'qualifiers_per_group' => ['sometimes', 'integer', 'between:1,8'],
            'extra_best_qualifiers' => ['sometimes', 'integer', 'between:0,8'],
            'double_round_robin' => ['sometimes', 'boolean'],
            'points_win' => ['sometimes', 'integer', 'between:0,10'],
            'points_draw' => ['sometimes', 'integer', 'between:0,10'],
            'points_loss' => ['sometimes', 'integer', 'between:0,10'],
            'allow_group_overtime_or_shootout' => ['sometimes', 'boolean'],
            'group_tie_mode' => ['sometimes', Rule::in(['allow_draw', 'overtime_shootout'])],
            'points_overtime_win' => ['sometimes', 'integer', 'between:0,10'],
            'points_overtime_loss' => ['sometimes', 'integer', 'between:0,10'],
            'tie_break_order' => ['sometimes', 'array', 'min:1'],
            'tie_break_order.*' => ['required', 'distinct', Rule::enum(TieBreakCriterion::class)],
            'period_minutes' => ['sometimes', 'integer', 'between:1,120'],
            'periods_count' => ['sometimes', 'integer', 'between:1,6'],
            'rest_seconds' => ['sometimes', 'integer', 'between:0,3600'],
            'countdown_seconds' => ['sometimes', 'integer', 'between:0,120'],
            'overtime_minutes' => ['sometimes', 'integer', 'between:1,60'],
            'knockout_tie_resolution' => ['sometimes', Rule::enum(KnockoutTieResolution::class)],
            'has_third_place_match' => ['sometimes', 'boolean'],
            'max_assists_per_goal' => ['sometimes', 'integer', 'between:0,2'],
        ];
    }

    public function championshipRules(?Championship $championship = null): ChampionshipRules
    {
        $base = $championship instanceof Championship ? $championship->rules->toArray() : [];

        return ChampionshipRules::fromArray(array_merge($base, $this->validated()));
    }
}
