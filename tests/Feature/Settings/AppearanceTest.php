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

test('sem cookie ou com valor inválido usa sistema e aplica o tema do aparelho antes da pintura', function (?string $cookie) {
    $team = User::factory()->create();

    $request = $this->actingAs($team);
    if ($cookie !== null)
    {
        $request = $request->withUnencryptedCookie('appearance', $cookie);
    }

    $request->get('/campeonatos')
        ->assertOk()
        ->assertDontSee('<html lang="pt-BR" class="dark">', false)
        ->assertSee("const appearance = 'system';", false)
        ->assertDontSee('<script>alert', false);
})->with([null, 'system', '<script>alert(1)</script>']);

test('o tema escuro vale também na tela de campeonatos e na de login', function () {
    $team = User::factory()->create();

    $this->actingAs($team)->withUnencryptedCookie('appearance', 'dark')->get('/campeonatos')
        ->assertOk()->assertSee('<html lang="pt-BR" class="dark">', false);

    auth()->logout();

    $this->withUnencryptedCookie('appearance', 'dark')->get('/login')
        ->assertOk()->assertSee('<html lang="pt-BR" class="dark">', false);
});
