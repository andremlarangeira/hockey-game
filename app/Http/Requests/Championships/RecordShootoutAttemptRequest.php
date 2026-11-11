<?php

namespace App\Http\Requests\Championships;

use App\Championships\Data\ShootoutAttemptCommand;
use App\Championships\Enums\TeamSide;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordShootoutAttemptRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('shooter_id') && $this->has('player_id'))
        {
            $this->merge(['shooter_id' => $this->input('player_id')]);
        }

        if (! $this->has('scored') && $this->has('convertido'))
        {
            $this->merge(['scored' => $this->input('convertido')]);
        }

        if (! $this->has('round'))
        {
            if ($this->has('round_number'))
            {
                $this->merge(['round' => $this->input('round_number')]);
            }
            elseif ($this->has('rodada'))
            {
                $this->merge(['round' => $this->input('rodada')]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'side' => ['required', Rule::enum(TeamSide::class)],
            'shooter_id' => ['required', 'integer'],
            'scored' => ['required', 'boolean'],
            'round' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function toCommand(): ShootoutAttemptCommand
    {
        return new ShootoutAttemptCommand(
            side: TeamSide::from((string) $this->validated('side')),
            shooterId: (int) $this->validated('shooter_id'),
            scored: $this->boolean('scored'),
            round: (int) ($this->validated('round') ?? 1),
        );
    }
}
