<?php

use App\Models\Player;
use Inertia\Testing\AssertableInertia as Assert;

test('abrir o sorteio não cria equipes e retorna apenas jogadores presentes', function () {
    [$team, $present] = $this->teamWithPlayer();
    $absent = Player::factory()->for($team)->create(['name' => 'Ausente', 'present' => false]);

    $this->actingAs($team)->get('/sorteio')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('draw/Index')
        ->has('players', 1)
        ->where('players.0.id', $present->id)
        ->missing('result'));

    expect($absent->present)->toBeFalse();
});

test('sorteio por post retorna equipes e visitante é redirecionado', function () {
    [$team] = $this->teamWithPlayer();
    Player::factory()->for($team)->count(3)->create();

    $this->get('/sorteio')->assertRedirect('/login');
    $this->post('/sorteio')->assertRedirect('/login');
    $this->actingAs($team)->postJson('/sorteio', ['mode' => 'players', 'size' => 2])
        ->assertOk()
        ->assertJsonCount(2, 'teams')
        ->assertJsonPath('teams.0.color', '#166534')
        ->assertJsonPath('teams.1.color', '#f59e0b');
});

test('sorteio usa as cores customizadas de Meu time e cai para azul e vermelho quando inválidas', function () {
    [$team] = $this->teamWithPlayer([
        'primary_color' => '#0f766e',
        'secondary_color' => '#eab308',
    ]);
    Player::factory()->for($team)->count(5)->create();

    $this->actingAs($team)
        ->postJson('/sorteio', ['mode' => 'teams', 'size' => 3])
        ->assertOk()
        ->assertJsonPath('teams.0.id', 'equipe-1')
        ->assertJsonPath('teams.0.color', '#0f766e')
        ->assertJsonPath('teams.1.id', 'equipe-2')
        ->assertJsonPath('teams.1.color', '#eab308')
        ->assertJsonPath('teams.2.id', 'equipe-3')
        ->assertJsonPath('teams.2.color', '#16a34a');

    $team->forceFill(['primary_color' => '', 'secondary_color' => 'invalida']);

    $this->actingAs($team)
        ->postJson('/sorteio', ['mode' => 'players', 'size' => 2])
        ->assertOk()
        ->assertJsonPath('teams.0.color', '#2563eb')
        ->assertJsonPath('teams.1.color', '#dc2626');
});
