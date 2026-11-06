<?php

namespace App\Http\Requests;

use App\Models\Player;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlayerRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'jersey_number' => ['required', 'integer', 'between:0,999', Rule::unique('players')->where('user_id', $this->user()->id)->ignore($this->player()?->id)],
            'level' => ['required', 'numeric', 'between:1,5', 'multiple_of:0.5'],
            'present' => ['required', 'boolean'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'remove_photo' => ['sometimes', 'boolean'],
        ];
    }

    public function player(): ?Player
    {
        $player = $this->route('player');

        return $player instanceof Player ? $player : null;
    }

    public function messages(): array
    {
        return ['jersey_number.unique' => 'Já existe um jogador com esse número.'];
    }
}
