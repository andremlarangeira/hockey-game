<?php

namespace App\Http\Requests\Championships;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class FinishChampionshipGameRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'finished_at' => ['nullable', 'date'],
        ];
    }

    public function finishedAt(): ?CarbonImmutable
    {
        $finishedAt = $this->validated('finished_at');

        return is_string($finishedAt) && trim($finishedAt) !== ''
            ? CarbonImmutable::parse($finishedAt)
            : null;
    }
}
