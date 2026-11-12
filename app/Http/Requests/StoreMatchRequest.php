<?php

namespace App\Http\Requests;

use App\Data\MatchRoster;
use App\Data\MatchSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMatchRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_uuid' => ['nullable', 'uuid'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'home_score' => ['required', 'integer', 'min:0', 'max:255'],
            'away_score' => ['required', 'integer', 'min:0', 'max:255'],
            'settings' => ['required', 'array'],
            'settings.minutes' => ['required', 'integer', 'between:1,120'],
            'settings.periods' => ['required', 'integer', 'between:1,6'],
            'settings.rest_seconds' => ['required', 'integer', 'between:1,3600'],
            'settings.countdown_seconds' => ['required', 'integer', 'between:0,120'],
            'roster' => ['required', 'array'],
            'roster.home' => ['required', 'array'],
            'roster.away' => ['required', 'array'],
            'roster.home.name' => ['required', 'string', 'max:100'],
            'roster.away.name' => ['required', 'string', 'max:100'],
            'roster.home.color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'roster.away.color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'roster.*.players' => ['required', 'array', 'min:1', 'max:100'],
            'roster.*.players.*.id' => ['required', 'integer'],
            'roster.*.players.*.name' => ['required', 'string', 'max:100'],
            'roster.*.players.*.jersey_number' => ['required', 'integer'],
            'roster.*.players.*.level' => ['required', 'numeric', 'between:1,5'],
            'timeline' => ['present', 'array', 'max:10000'],
            'timeline.*.at' => ['required', 'date'],
            'timeline.*.type' => ['required', Rule::in(['pre_start', 'start', 'pause', 'resume', 'period_start', 'period_end', 'rest_start', 'rest_end', 'finish', 'goal_added', 'goal_removed'])],
            'timeline.*.period' => ['nullable', 'integer', 'between:1,6'],
            'timeline.*.elapsed_seconds' => ['required', 'integer', 'min:0'],
            'settings.sounds' => ['sometimes', 'array'],
            'settings.sounds.start' => ['sometimes', 'array'],
            'settings.sounds.end' => ['sometimes', 'array'],
            'settings.sounds.rest_end' => ['sometimes', 'array'],
            'settings.sounds.*.type' => ['required', Rule::in(['builtin', 'custom', 'none'])],
            'settings.sounds.*.audio_url' => ['nullable', 'url', 'max:2048'],
            'settings.sounds.*.file' => ['nullable', 'string', 'max:255'],
            'timeline.*.details' => ['sometimes'],
            'goals' => ['present', 'array', 'max:500'],
            'goals.*.id' => ['required'],
            'goals.*.team' => ['required', Rule::in(['home', 'away'])],
            'goals.*.scorer_id' => ['required', 'integer'],
            'goals.*.scorer_name' => ['required', 'string', 'max:100'],
            'goals.*.assist_id' => ['nullable', 'integer'],
            'goals.*.assist_name' => ['nullable', 'string', 'max:100'],
            'goals.*.period' => ['required', 'integer', 'between:1,6'],
            'goals.*.elapsed_seconds' => ['required', 'integer', 'min:0'],
            'goals.*.remaining_seconds' => ['required', 'integer', 'min:0'],
            'goals.*.recorded_at' => ['required', 'date'],
        ];
    }

    public function settings(): MatchSettings
    {
        return MatchSettings::fromArray($this->validated('settings'));
    }

    public function roster(): MatchRoster
    {
        return MatchRoster::fromArray($this->validated('roster'));
    }

    /** @return list<array<string, mixed>> */
    public function goalAttributes(): array
    {
        $attributes = [];
        foreach ($this->validated('goals') as $goal)
        {
            $attributes[] = [
                'user_id' => $this->user()->id,
                'scorer_id' => $goal['scorer_id'], 'assist_id' => $goal['assist_id'] ?? null,
                'scorer_name' => $goal['scorer_name'], 'assist_name' => $goal['assist_name'] ?? null,
                'team' => $goal['team'], 'period' => $goal['period'],
                'elapsed_seconds' => $goal['elapsed_seconds'], 'remaining_seconds' => $goal['remaining_seconds'],
                'recorded_at' => $goal['recorded_at'],
            ];
        }

        return $attributes;
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $team = $this->user();
            $knownPlayerIds = $team->players()->pluck('id');
            $roster = $this->roster();
            $ids = [];
            foreach ($roster->home->players as $player)
            {
                $ids[] = $player['id'];
            }
            foreach ($roster->away->players as $player)
            {
                $ids[] = $player['id'];
            }
            if (count($ids) !== count(array_unique($ids)))
            {
                $validator->errors()->add('roster', 'Um jogador não pode estar nas duas equipes.');
            }
            foreach ($ids as $id)
            {
                if (! $knownPlayerIds->contains($id))
                {
                    $validator->errors()->add('roster', 'O elenco contém um jogador que não pertence ao seu time.');
                }
            }
            $homeGoals = 0;
            $awayGoals = 0;
            foreach ($this->validated('goals') as $index => $goal)
            {
                $side = $roster->team($goal['team']);
                $scorer = collect($side->players)->firstWhere('id', $goal['scorer_id']);
                $assist = collect($side->players)->firstWhere('id', $goal['assist_id'] ?? null);
                if (! $scorer)
                {
                    $validator->errors()->add("goals.$index.scorer_id", 'O autor do gol não está nessa equipe.');
                }
                if (($goal['assist_id'] ?? null) !== null && (! $assist || $goal['assist_id'] === $goal['scorer_id']))
                {
                    $validator->errors()->add("goals.$index.assist_id", 'A assistência precisa ser de outro jogador da mesma equipe.');
                }
                if ($goal['team'] === 'home')
                {
                    $homeGoals++;
                }
                if ($goal['team'] === 'away')
                {
                    $awayGoals++;
                }
            }
            if ($homeGoals !== (int) $this->input('home_score') || $awayGoals !== (int) $this->input('away_score'))
            {
                $validator->errors()->add('goals', 'O placar precisa ser igual ao número de gols registrados.');
            }
        }];
    }
}
