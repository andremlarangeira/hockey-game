<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('faz upload do escudo do time quando o usuário ainda não possui logo', function () {
    $disk = config('filesystems.media_disk');
    Storage::fake($disk);

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
    Storage::disk($disk)->assertExists($user->logo_path);
});

test('substitui o escudo do time e remove o arquivo anterior quando já existe logo', function () {
    $disk = config('filesystems.media_disk');
    Storage::fake($disk);

    $user = User::factory()->create(['logo_path' => null]);
    $oldPath = UploadedFile::fake()->image('antigo.png')->store('logos/'.$user->id, $disk);
    $user->update(['logo_path' => $oldPath]);
    Storage::disk($disk)->assertExists($oldPath);

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
    Storage::disk($disk)->assertMissing($oldPath);
    Storage::disk($disk)->assertExists($user->logo_path);
});

test('armazena o escudo do time no disco configurado em filesystems.media_disk e não no public', function () {
    config(['filesystems.media_disk' => 's3']);
    Storage::fake('s3');
    Storage::fake('public');

    $user = User::factory()->create(['logo_path' => null]);

    $this->actingAs($user)->patch('/time', [
        'name' => 'Time Cloud S3',
        'email' => $user->email,
        'primary_color' => '#166534',
        'secondary_color' => '#f59e0b',
        'logo' => UploadedFile::fake()->image('cloud.png'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->logo_path)->not->toBeNull();
    Storage::disk('s3')->assertExists($user->logo_path);
    Storage::disk('public')->assertMissing($user->logo_path);
});
