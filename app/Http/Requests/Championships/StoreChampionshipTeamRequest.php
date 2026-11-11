<?php

namespace App\Http\Requests\Championships;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreChampionshipTeamRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'short_name' => ['nullable', 'string', 'max:24'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'seed_pot' => ['nullable', 'integer', 'min:1', 'max:16'],
        ];
    }

    public function primaryColor(): string
    {
        $color = $this->validated('primary_color');

        return is_string($color) && $color !== '' ? $color : '#2563eb';
    }

    public function secondaryColor(): string
    {
        $color = $this->validated('secondary_color');

        return is_string($color) && $color !== '' ? $color : '#ffffff';
    }

    public function logoFile(): ?UploadedFile
    {
        $file = $this->file('logo');

        return $file instanceof UploadedFile ? $file : null;
    }

    public function seedPot(): ?int
    {
        $pot = $this->validated('seed_pot');

        return $pot !== null ? (int) $pot : null;
    }
}
