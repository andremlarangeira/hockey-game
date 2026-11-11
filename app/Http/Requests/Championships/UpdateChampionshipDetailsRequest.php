<?php

namespace App\Http\Requests\Championships;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChampionshipDetailsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:100'],
            'venue' => ['nullable', 'string', 'max:140'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];
    }

    public function startsOn(): ?CarbonImmutable
    {
        $value = $this->validated('starts_on');

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }

    public function endsOn(): ?CarbonImmutable
    {
        $value = $this->validated('ends_on');

        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
