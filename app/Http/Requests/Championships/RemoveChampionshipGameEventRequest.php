<?php

namespace App\Http\Requests\Championships;

use Illuminate\Foundation\Http\FormRequest;

class RemoveChampionshipGameEventRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('motivo') && $this->has('reason'))
        {
            $this->merge(['motivo' => $this->input('reason')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('motivo');

        return is_string($reason) ? $reason : null;
    }
}
