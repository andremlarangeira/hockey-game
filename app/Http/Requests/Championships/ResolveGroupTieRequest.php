<?php

namespace App\Http\Requests\Championships;

use Illuminate\Foundation\Http\FormRequest;

class ResolveGroupTieRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('group_code'))
        {
            if ($this->has('group'))
            {
                $this->merge(['group_code' => $this->input('group')]);
            }
            elseif ($this->has('grupo'))
            {
                $this->merge(['group_code' => $this->input('grupo')]);
            }
        }

        if (! $this->has('ordered_team_ids'))
        {
            if ($this->has('team_ids'))
            {
                $this->merge(['ordered_team_ids' => $this->input('team_ids')]);
            }
            elseif ($this->has('ordem_times'))
            {
                $this->merge(['ordered_team_ids' => $this->input('ordem_times')]);
            }
        }

        if (! $this->has('motivo') && $this->has('reason'))
        {
            $this->merge(['motivo' => $this->input('reason')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'group_code' => ['required', 'string', 'max:8'],
            'ordered_team_ids' => ['required', 'array', 'min:2'],
            'ordered_team_ids.*' => ['required', 'integer', 'distinct'],
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }

    public function groupCode(): string
    {
        return (string) $this->validated('group_code');
    }

    /** @return list<int> */
    public function orderedTeamIds(): array
    {
        /** @var array<int, mixed> $ids */
        $ids = $this->validated('ordered_team_ids') ?? [];

        return array_values(array_map('intval', $ids));
    }

    public function reason(): string
    {
        return (string) $this->validated('motivo');
    }
}
