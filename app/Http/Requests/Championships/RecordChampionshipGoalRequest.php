<?php

namespace App\Http\Requests\Championships;

use App\Championships\Data\GoalCommand;
use App\Championships\Enums\TeamSide;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordChampionshipGoalRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'side' => ['required', Rule::enum(TeamSide::class)],
            'scorer_id' => ['required', 'integer'],
            'assist_ids' => ['nullable', 'array'],
            'assist_ids.*' => ['nullable', 'integer'],
            'first_assist_id' => ['nullable', 'integer'],
            'second_assist_id' => ['nullable', 'integer'],
            'assist_id' => ['nullable', 'integer'],
            'period' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'is_overtime' => ['sometimes', 'boolean'],
            'overtime' => ['sometimes', 'boolean'],
            'elapsed_seconds' => ['sometimes', 'integer', 'min:0'],
            'remaining_seconds' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function toCommand(): GoalCommand
    {
        $assistIds = [];

        if ($this->has('assist_ids') && is_array($this->input('assist_ids')))
        {
            foreach ($this->input('assist_ids') as $rawId)
            {
                if ($rawId !== null && $rawId !== '')
                {
                    $assistIds[] = (int) $rawId;
                }
            }
        }
        else
        {
            if ($this->filled('first_assist_id'))
            {
                $assistIds[] = (int) $this->input('first_assist_id');
            }
            elseif ($this->filled('assist_id'))
            {
                $assistIds[] = (int) $this->input('assist_id');
            }

            if ($this->filled('second_assist_id'))
            {
                $assistIds[] = (int) $this->input('second_assist_id');
            }
        }

        return new GoalCommand(
            side: TeamSide::from((string) $this->validated('side')),
            scorerId: (int) $this->validated('scorer_id'),
            assistIds: $assistIds,
            period: (int) ($this->validated('period') ?? 1),
            overtime: $this->boolean('is_overtime') || $this->boolean('overtime'),
            elapsedSeconds: (int) ($this->validated('elapsed_seconds') ?? 0),
            remainingSeconds: (int) ($this->validated('remaining_seconds') ?? 0),
        );
    }
}
