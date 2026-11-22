<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('faz upload do escudo do time quando o usuário ainda não possui logo', function () {
    Storage::fake('public');

    $user = User::factory()->create(['logo_path' => null]);

    $this->actingAs($user)->patch('/time', [
        'name' => 'Time Sem Escudo',
        'email' => $user->email,
        'primary_color' => '#166534',
        'secondary_color' => '#f59e0b',
        'logo' => UploadedFile::fake()->image('escudo.png'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->logo_path)->not->toBeNull();
    Storage::disk('public')->assertExists($user->logo_path);
});

test('substitui o escudo do time e remove o arquivo anterior quando já existe logo', function () {
    Storage::fake('public');

    $user = User::factory()->create(['logo_path' => null]);
    $oldPath = UploadedFile::fake()->image('antigo.png')->store('logos/'.$user->id, 'public');
    $user->update(['logo_path' => $oldPath]);
    Storage::disk('public')->assertExists($oldPath);

    $this->actingAs($user)->patch('/time', [
        'name' => 'Time Com Escudo Novo',
        'email' => $user->email,
        'primary_color' => '#1d4ed8',
        'secondary_color' => '#ffffff',
        'logo' => UploadedFile::fake()->image('novo.png'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->logo_path)->not->toBeNull()
        ->and($user->logo_path)->not->toBe($oldPath);
    Storage::disk('public')->assertMissing($oldPath);
    Storage::disk('public')->assertExists($user->logo_path);
});
