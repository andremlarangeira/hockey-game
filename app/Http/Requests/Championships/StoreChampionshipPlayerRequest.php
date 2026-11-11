<?php

namespace App\Http\Requests\Championships;

use App\Championships\Enums\PlayerPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChampionshipPlayerRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'jersey_number' => ['required', 'integer', 'between:0,999'],
            'position' => ['nullable', Rule::enum(PlayerPosition::class)],
            'is_goalkeeper' => ['sometimes', 'boolean'],
        ];
    }

    public function positionEnum(): PlayerPosition
    {
        $position = $this->validated('position');

        return is_string($position) && $position !== ''
            ? PlayerPosition::from($position)
            : PlayerPosition::Universal;
    }
}
