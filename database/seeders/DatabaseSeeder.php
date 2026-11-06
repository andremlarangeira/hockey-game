<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $team = User::factory()->create([
            'name' => 'Hockey da Vila',
            'email' => 'demo@naquadra.test',
            'password' => 'hockey123',
            'primary_color' => '#166534',
            'secondary_color' => '#f59e0b',
        ]);
        $players = [
            ['André Silva', 10, 5], ['Bruno Costa', 7, 4.5], ['Carlos Rocha', 23, 4],
            ['Diego Martins', 91, 4.5], ['Eduardo Lima', 3, 3], ['Fábio Nunes', 18, 3.5],
            ['Gustavo Alves', 27, 4], ['Henrique Dias', 88, 2.5], ['Igor Santos', 14, 3.5],
            ['João Pedro', 5, 2.5], ['Kleber Souza', 44, 2], ['Lucas Ferreira', 9, 4],
            ['Marcos Vinícius', 31, 1.5], ['Nicolas Barros', 77, 3], ['Otávio Ribeiro', 12, 2.5],
            ['Paulo César', 1, 3.5],
        ];
        foreach ($players as $index => [$name, $number, $level])
        {
            $team->players()->create([
                'name' => $name, 'jersey_number' => $number, 'level' => $level,
                'present' => ! in_array($index, [13, 15], true),
            ]);
        }
    }
}
