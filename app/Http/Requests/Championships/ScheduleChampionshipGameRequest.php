<?php

namespace App\Http\Requests\Championships;

use App\Championships\Data\GameScheduleCommand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleChampionshipGameRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('venue') && $this->has('local'))
        {
            $this->merge(['venue' => $this->input('local')]);
        }

        if (! $this->has('scheduled_at') && $this->has('data_hora'))
        {
            $this->merge(['scheduled_at' => $this->input('data_hora')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'venue' => ['nullable', 'string', 'max:140'],
            'scheduled_at' => ['nullable', 'date'],
        ];
    }

    public function toCommand(): GameScheduleCommand
    {
        $venue = $this->validated('venue');
        $scheduledAt = $this->validated('scheduled_at');

        return new GameScheduleCommand(
            venue: is_string($venue) && trim($venue) !== '' ? trim($venue) : null,
            scheduledAt: is_string($scheduledAt) && trim($scheduledAt) !== ''
                ? CarbonImmutable::parse($scheduledAt)
                : null,
        );
    }
}
