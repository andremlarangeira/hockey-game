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
        ->assertJsonCount(2, 'teams');
});
