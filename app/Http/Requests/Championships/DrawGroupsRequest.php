<?php

namespace App\Http\Requests\Championships;

use Illuminate\Foundation\Http\FormRequest;

class DrawGroupsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('refazer') && ($this->has('redraw') || $this->has('force_redraw')))
        {
            $this->merge([
                'refazer' => $this->boolean('redraw') || $this->boolean('force_redraw'),
            ]);
        }

        if (! $this->has('motivo') && $this->has('reason'))
        {
            $this->merge([
                'motivo' => $this->input('reason'),
            ]);
        }

        if (! $this->has('groups') && $this->has('explicit_groups'))
        {
            $this->merge([
                'groups' => $this->input('explicit_groups'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'refazer' => ['sometimes', 'boolean'],
            'motivo' => ['nullable', 'string', 'max:500'],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['array'],
            'groups.*.*' => ['integer'],
        ];
    }

    public function redraw(): bool
    {
        return $this->boolean('refazer');
    }

    public function reason(): ?string
    {
        $reason = $this->validated('motivo');

        return is_string($reason) ? $reason : null;
    }

    /**
     * @return array<string, list<int>>|null
     */
    public function explicitGroups(): ?array
    {
        $groups = $this->validated('groups');
        if (! is_array($groups) || $groups === [])
        {
            return null;
        }

        $normalized = [];
        foreach ($groups as $code => $teamIds)
        {
            if (is_array($teamIds))
            {
                $normalized[(string) $code] = array_values(array_map('intval', $teamIds));
            }
        }

        return $normalized;
    }
}
