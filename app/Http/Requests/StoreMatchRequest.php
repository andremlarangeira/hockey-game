<?php

namespace App\Http\Requests;

use App\Data\MatchRoster;
use App\Data\MatchSettings;
use App\Models\Player;
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
        $team = $this->user();
        $ownPlayerIds = $team->players()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ownPlayerLookup = array_flip($ownPlayerIds);
        $roster = $this->roster();

        $attributes = [];
        foreach ($this->validated('goals') as $goal)
        {
            $side = $roster->team($goal['team']);
            $rosterPlayers = collect($side !== null ? $side->players : [])->keyBy('id');

            $rawScorerId = (int) $goal['scorer_id'];
            $scorerExists = isset($ownPlayerLookup[$rawScorerId]);
            /** @var array{id: int, name: string, jersey_number: int, level: float|numeric-string}|null $rosterScorer */
            $rosterScorer = $rosterPlayers->get($rawScorerId);
            $scorerName = ! $scorerExists && $rosterScorer !== null && trim($rosterScorer['name']) !== ''
                ? $rosterScorer['name']
                : $goal['scorer_name'];

            $rawAssistId = isset($goal['assist_id']) ? (int) $goal['assist_id'] : null;
            $assistExists = $rawAssistId !== null && isset($ownPlayerLookup[$rawAssistId]);
            /** @var array{id: int, name: string, jersey_number: int, level: float|numeric-string}|null $rosterAssist */
            $rosterAssist = $rawAssistId !== null ? $rosterPlayers->get($rawAssistId) : null;
            $assistName = $rawAssistId !== null
                ? (! $assistExists && $rosterAssist !== null && trim($rosterAssist['name']) !== ''
                    ? $rosterAssist['name']
                    : ($goal['assist_name'] ?? $rosterAssist['name'] ?? null))
                : ($goal['assist_name'] ?? null);

            $attributes[] = [
                'user_id' => $team->id,
                'scorer_id' => $scorerExists ? $rawScorerId : null,
                'assist_id' => $assistExists ? $rawAssistId : null,
                'scorer_name' => $scorerName,
                'assist_name' => $assistName,
                'team' => $goal['team'],
                'period' => $goal['period'],
                'elapsed_seconds' => $goal['elapsed_seconds'],
                'remaining_seconds' => $goal['remaining_seconds'],
                'recorded_at' => $goal['recorded_at'],
            ];
        }

        return $attributes;
    }

    /** @return list<array{user_id: int, player_id: int|null, player_name: string, team: string, present: bool}> */
    public function matchPlayerAttributes(): array
    {
        $team = $this->user();
        $ownPlayerIds = $team->players()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $ownPlayerLookup = array_flip($ownPlayerIds);
        $roster = $this->roster();

        $attributes = [];
        $seenPlayerIds = [];

        foreach (['home' => $roster->home->players, 'away' => $roster->away->players] as $side => $players)
        {
            foreach ($players as $player)
            {
                $rawPlayerId = (int) $player['id'];
                $playerExists = isset($ownPlayerLookup[$rawPlayerId]);
                $playerId = $playerExists ? $rawPlayerId : null;

                if ($playerId !== null)
                {
                    if (isset($seenPlayerIds[$playerId]))
                    {
                        continue;
                    }
                    $seenPlayerIds[$playerId] = true;
                }

                $name = trim((string) $player['name']);

                $attributes[] = [
                    'user_id' => $team->id,
                    'player_id' => $playerId,
                    'player_name' => $name !== '' ? $name : 'Jogador removido',
                    'team' => $side,
                    'present' => true,
                ];
            }
        }

        return $attributes;
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $team = $this->user();
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
            $foreignPlayerIds = Player::withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->where('user_id', '!=', $team->id)
                ->pluck('id');
            foreach ($ids as $id)
            {
                if ($foreignPlayerIds->contains($id))
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
