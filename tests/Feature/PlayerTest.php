<?php

use App\Models\Player;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('cadastra, edita e valida um jogador com meia estrela', function () {
    Storage::fake('public');
    $team = User::factory()->create();
    $this->actingAs($team)->post('/jogadores', [
        'name' => 'Camisa Dez', 'jersey_number' => 10, 'level' => 4.5, 'present' => true,
        'photo' => UploadedFile::fake()->image('dez.jpg'),
    ])->assertRedirect();
    $player = Player::firstOrFail();
    expect((float) $player->level)->toBe(4.5)->and($player->photo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($player->photo_path);
    $this->patch("/jogadores/$player->id", ['name' => 'Camisa Onze', 'jersey_number' => 12, 'level' => 3.5, 'present' => false])->assertRedirect();
    expect($player->fresh()->name)->toBe('Camisa Onze')->and((bool) $player->fresh()->present)->toBeFalse();
    $this->post('/jogadores', ['name' => 'Repetido', 'jersey_number' => 12, 'level' => 4.1, 'present' => true])->assertSessionHasErrors(['jersey_number', 'level']);
});

test('presença em massa e individual funcionam apenas no time conectado', function () {
    [$team, $player] = $this->teamWithPlayer();
    $this->actingAs($team)->post('/presenca', ['present' => false]);
    expect($player->fresh()->present)->toBeFalse();
    $this->patch("/jogadores/$player->id/presenca", ['present' => true]);
    expect($player->fresh()->present)->toBeTrue();
});

test('uploads rejeitam executáveis disfarçados e imagens grandes', function () {
    $team = User::factory()->create();
    $this->actingAs($team)->post('/jogadores', [
        'name' => 'Foto inválida', 'jersey_number' => 3, 'level' => 3, 'present' => true,
        'photo' => UploadedFile::fake()->create('foto.php', 10, 'application/x-php'),
    ])->assertSessionHasErrors('photo');
});
