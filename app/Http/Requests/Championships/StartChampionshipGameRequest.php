<?php

namespace App\Http\Requests\Championships;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StartChampionshipGameRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'started_at' => ['nullable', 'date'],
        ];
    }

    public function startedAt(): ?CarbonImmutable
    {
        $startedAt = $this->validated('started_at');

        return is_string($startedAt) && trim($startedAt) !== ''
            ? CarbonImmutable::parse($startedAt)
            : null;
    }
}
