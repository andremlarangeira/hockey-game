<?php

namespace App\Http\Requests\Championships;

use Illuminate\Foundation\Http\FormRequest;

class ReopenChampionshipGameRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('motivo') && $this->has('reason'))
        {
            $this->merge(['motivo' => $this->input('reason')]);
        }

        if (! $this->has('resetar_chave') && ($this->has('reset_bracket') || $this->has('reset_unstarted_bracket')))
        {
            $this->merge([
                'resetar_chave' => $this->boolean('reset_bracket') || $this->boolean('reset_unstarted_bracket'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'max:500'],
            'resetar_chave' => ['sometimes', 'boolean'],
        ];
    }

    public function reason(): string
    {
        return (string) $this->validated('motivo');
    }

    public function resetBracket(): bool
    {
        return $this->boolean('resetar_chave');
    }
}
