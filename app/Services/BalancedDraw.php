<?php

namespace App\Services;

use Illuminate\Support\Collection;
use InvalidArgumentException;

class BalancedDraw
{
    private const COLORS = ['#2563eb', '#dc2626', '#eab308', '#16a34a', '#9333ea', '#ea580c', '#0891b2', '#db2777'];

    /**
     * @param  Collection<int, array{id: int, name: string, jersey_number: int, level: float, photo_url: string|null, present: bool}>  $players
     * @return array{teams: array<int, array{id: string, name: string, color: string, players: array<int, array{id: int, name: string, jersey_number: int, level: float, photo_url: string|null, present: bool}>, total: float|int, average: float}>, reserves: array<int, array{id: int, name: string, jersey_number: int, level: float, photo_url: string|null, present: bool}>}
     */
    public function draw(Collection $players, string $mode = 'players', int $size = 4): array
    {
        $present = $players->filter(fn (array $player): bool => $player['present'] === true)->values();
        if ($present->count() < 2 || ! in_array($mode, ['players', 'teams'], true) || $size < 1)
        {
            throw new InvalidArgumentException('Marque pelo menos 2 jogadores presentes e escolha um tamanho válido.');
        }

        if ($mode === 'players')
        {
            $teamCount = intdiv($present->count(), $size);
            if ($teamCount < 2)
            {
                throw new InvalidArgumentException('São necessários jogadores para pelo menos 2 equipes completas.');
            }
            $participantCount = $teamCount * $size;
        }
        else
        {
            $teamCount = $size;
            $minimum = intdiv($present->count(), $teamCount);
            $participantCount = $minimum === 1 ? $teamCount : $present->count();
        }

        if ($teamCount < 2 || $teamCount > 8 || $teamCount > intdiv($present->count(), 2))
        {
            throw new InvalidArgumentException('Escolha de 2 a 8 equipes, com pelo menos 2 jogadores em cada. Ajuste o número de equipes ou use o modo por jogadores.');
        }

        $best = null;
        $bestScore = PHP_FLOAT_MAX;
        for ($attempt = 0; $attempt < 240; $attempt++)
        {
            $participants = $present->shuffle()->take($participantCount)
                ->sortByDesc(fn (array $player): float => ((float) $player['level']) + random_int(0, 100) / 100000)->values();
            $teams = array_fill(0, $teamCount, []);
            $direction = 1;
            $index = 0;
            foreach ($participants as $player)
            {
                $teams[$index][] = $player;
                if ($direction === 1 && $index === $teamCount - 1)
                {
                    $direction = -1;
                }
                elseif ($direction === -1 && $index === 0)
                {
                    $direction = 1;
                }
                else
                {
                    $index += $direction;
                }
            }
            $totals = array_map(fn (array $team): float => array_sum(array_column($team, 'level')), $teams);
            $score = max($totals) - min($totals);
            if ($score < $bestScore)
            {
                $shuffle = range(0, $teamCount - 1);
                shuffle($shuffle);
                $best = array_map(fn (int $index): array => $teams[$index], $shuffle);
                $bestScore = $score;
            }
        }

        if ($best === null)
        {
            throw new InvalidArgumentException('Não foi possível sortear as equipes.');
        }

        return [
            'teams' => collect($best)->map(fn (array $team, int $index): array => [
                'id' => 'equipe-'.($index + 1),
                'name' => 'Equipe '.($index + 1),
                'color' => self::COLORS[$index],
                'players' => $team,
                'total' => (float) array_sum(array_column($team, 'level')),
                'average' => round(array_sum(array_column($team, 'level')) / count($team), 2),
            ])->values()->all(),
            'reserves' => $present->shuffle()->take($present->count() - $participantCount)->values()->all(),
        ];
    }
}
