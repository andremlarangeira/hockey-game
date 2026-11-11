<?php

use App\Models\User;

test('renderiza a classe dark no html quando o cookie appearance é dark', function () {
    $team = User::factory()->create();

    $response = $this->actingAs($team)
        ->withUnencryptedCookie('appearance', 'dark')
        ->get('/time');

    $response->assertOk()
        ->assertSee('<html lang="pt-BR" class="dark">', false);
});

test('não renderiza a classe dark no html quando o cookie appearance é light', function () {
    $team = User::factory()->create();

    $response = $this->actingAs($team)
        ->withUnencryptedCookie('appearance', 'light')
        ->get('/time');

    $response->assertOk()
        ->assertDontSee('<html lang="pt-BR" class="dark">', false)
        ->assertSee('<html lang="pt-BR"', false);
});
