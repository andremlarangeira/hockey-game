<?php

require $argv[1].'/vendor/autoload.php';

use App\Services\BalancedDraw;

/** @return array<int, array{id: int, name: string, jersey_number: int, level: float, photo_url: null, present: bool}> */
function players(int $count, bool $withReserves = false, bool $decimalLevels = false): array
{
    return array_map(fn (int $id): array => [
        'id' => $id,
        'name' => "Jogador $id",
        'jersey_number' => $id,
        'level' => $decimalLevels ? 2.5 + ($id % 3) / 2 : (float) ($id % 5 + 1),
        'photo_url' => null,
        'present' => ! ($withReserves && $id > $count - 2),
    ], range(1, $count));
}

$scenarios = [
    ['players' => players(14), 'mode' => 'players', 'size' => 4, 'seed' => 123456],
    ['players' => players(15, true), 'mode' => 'players', 'size' => 3, 'seed' => 51],
    ['players' => players(11), 'mode' => 'teams', 'size' => 3, 'seed' => 912],
    ['players' => players(16, false, true), 'mode' => 'players', 'size' => 4, 'seed' => 314],
    ['players' => players(20, false, true), 'mode' => 'players', 'size' => 5, 'seed' => 808],
];

$draw = new BalancedDraw;
$results = array_map(
    function (array $scenario) use ($draw): array {
        $state = $scenario['seed'];
        $random = function (int $min, int $max) use (&$state): int {
            $state = ($state * 73 + 19) % 1000003;

            return $min + ($state % ($max - $min + 1));
        };

        return [...$scenario, 'result' => $draw->draw(collect($scenario['players']), $scenario['mode'], $scenario['size'], $random)];
    },
    $scenarios,
);

echo json_encode($results, JSON_THROW_ON_ERROR);
