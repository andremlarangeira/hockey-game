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
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function clientUuid(): ?string
    {
        $uuid = $this->validated('client_uuid');

        return is_string($uuid) && trim($uuid) !== '' ? trim($uuid) : null;
    }

    public function finishedAt(): ?CarbonImmutable
    {
        $finishedAt = $this->validated('finished_at');

        return is_string($finishedAt) && trim($finishedAt) !== ''
            ? CarbonImmutable::parse($finishedAt)
            : null;
    }
}
