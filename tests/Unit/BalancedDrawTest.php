<?php

use App\Services\BalancedDraw;
use Illuminate\Support\Collection;

function drawPlayers(int $count = 18): Collection
{
    return collect(range(1, $count))->map(fn (int $id): array => [
        'id' => $id, 'name' => "Jogador $id", 'jersey_number' => $id, 'level' => $id % 5 + 1,
        'photo_url' => null, 'present' => true,
    ]);
}

test('o modo padrão respeita o tamanho e envia a sobra para reservas', function () {
    $result = (new BalancedDraw)->draw(drawPlayers(), 'players', 4);
    expect($result['teams'])->toHaveCount(4)->and($result['reserves'])->toHaveCount(2);
    foreach ($result['teams'] as $team)
    {
        expect($team['players'])->toHaveCount(4);
    }
    expect(collect($result['teams'])->pluck('players')->flatten(1)->pluck('id')->unique())->toHaveCount(16);
    $totals = collect($result['teams'])->pluck('total');
    expect($totals->max() - $totals->min())->toBeLessThanOrEqual(3);
});

test('o número de equipes é respeitado com diferença de no máximo um jogador', function () {
    $result = (new BalancedDraw)->draw(drawPlayers(11), 'teams', 3);
    expect($result['teams'])->toHaveCount(3)->and($result['reserves'])->toBeEmpty();
    $counts = collect($result['teams'])->map(fn (array $team): int => count($team['players']));
    expect($counts->max() - $counts->min())->toBeLessThanOrEqual(1);
    expect(collect($result['teams'])->pluck('players')->flatten(1)->pluck('id')->unique())->toHaveCount(11);
});

test('rejeita uma equipe incompleta no modo por quantidade', function () {
    expect(fn () => (new BalancedDraw)->draw(drawPlayers(5), 'teams', 4))->toThrow(InvalidArgumentException::class);
});

test('cada presente aparece uma única vez entre equipes e reservas', function () {
    foreach ([[14, 'players', 4], [18, 'players', 4], [15, 'teams', 3]] as [$count, $mode, $size])
    {
        $players = drawPlayers($count);
        $result = (new BalancedDraw)->draw($players, $mode, $size);
        $distributed = collect($result['teams'])->pluck('players')->flatten(1)->concat($result['reserves']);
        expect($distributed->pluck('id')->all())
            ->each->toBeIn($players->where('present', true)->pluck('id')->all());
        expect($distributed->count())->toBe($players->count());
        expect($distributed->pluck('id')->unique()->count())->toBe($players->count());
    }
});

test('sorteios repetidos têm grupos diferentes', function () {
    $service = new BalancedDraw;
    $first = collect($service->draw(drawPlayers(), 'players', 4)['teams'])->pluck('players')->flatten(1)->pluck('id')->all();
    $different = false;
    for ($attempt = 0; $attempt < 4; $attempt++)
    {
        $next = collect($service->draw(drawPlayers(), 'players', 4)['teams'])->pluck('players')->flatten(1)->pluck('id')->all();
        if ($first !== $next)
        {
            $different = true;
            break;
        }
    }
    expect($different)->toBeTrue();
});

test('apenas presentes participam e grupos inválidos são rejeitados', function () {
    $players = drawPlayers(10)->map(fn (array $player, int $index): array => [...$player, 'present' => $index < 8]);
    $result = (new BalancedDraw)->draw($players, 'players', 4);
    expect(collect($result['teams'])->pluck('players')->flatten(1)->pluck('id')->all())->each->toBeLessThanOrEqual(8);
    expect(fn () => (new BalancedDraw)->draw(drawPlayers(3), 'teams', 3))->toThrow(InvalidArgumentException::class);
});
