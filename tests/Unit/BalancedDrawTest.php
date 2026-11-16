<?php

use App\Services\BalancedDraw;
use App\Support\TeamColors;
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

test('usa as cores de Meu time nas duas primeiras equipes e mantém o restante da paleta sem duplicar', function () {
    $service = new BalancedDraw;

    $defaults = $service->draw(drawPlayers(16), 'teams', 4, null, '#166534', '#f59e0b');
    expect(array_column($defaults['teams'], 'color'))
        ->toBe(['#166534', '#f59e0b', '#eab308', '#16a34a']);

    $deduplicated = $service->draw(drawPlayers(16), 'teams', 4, null, '#eab308', '#16a34a');
    expect(array_column($deduplicated['teams'], 'color'))
        ->toBe(['#eab308', '#16a34a', '#9333ea', '#ea580c']);
});

test('usa azul e vermelho como fallback quando as cores não são informadas ou são inválidas', function () {
    $service = new BalancedDraw;

    $withoutCustom = $service->draw(drawPlayers(12), 'teams', 3);
    expect(array_column($withoutCustom['teams'], 'color'))
        ->toBe(['#2563eb', '#dc2626', '#eab308']);

    $invalidBoth = $service->draw(drawPlayers(12), 'teams', 3, null, '', 'nao-hex');
    expect(array_column($invalidBoth['teams'], 'color'))
        ->toBe(['#2563eb', '#dc2626', '#eab308']);

    $partialValid = $service->draw(drawPlayers(8), 'teams', 2, null, '#0f766e', '#123');
    expect(array_column($partialValid['teams'], 'color'))
        ->toBe(['#0f766e', '#dc2626']);
});

test('calcula texto legível por contraste e protege utilitários contra hex inválido', function () {
    expect(TeamColors::readableText('#166534'))->toBe('#ffffff')
        ->and(TeamColors::readableText('#2563eb'))->toBe('#ffffff')
        ->and(TeamColors::readableText('#dc2626'))->toBe('#ffffff')
        ->and(TeamColors::readableText('#f59e0b'))->toBe('#0f172a')
        ->and(TeamColors::readableText('#eab308'))->toBe('#0f172a')
        ->and(TeamColors::readableText('#ffffff'))->toBe('#0f172a')
        ->and(TeamColors::readableText('invalida'))->toBe('#ffffff')
        ->and(TeamColors::adjustForLightText('invalida'))->toBe('#2563eb');
});
