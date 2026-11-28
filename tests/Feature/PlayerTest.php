<?php

use App\Models\HockeyMatch;
use App\Models\Player;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

test('cadastra, edita e valida um jogador com meia estrela', function () {
    $disk = config('filesystems.media_disk');
    Storage::fake($disk);
    $team = User::factory()->create();
    $this->actingAs($team)->post('/jogadores', [
        'name' => 'Camisa Dez', 'jersey_number' => 10, 'level' => 4.5, 'present' => true,
        'photo' => UploadedFile::fake()->image('dez.jpg'),
    ])->assertRedirect();
    $player = Player::firstOrFail();
    expect((float) $player->level)->toBe(4.5)->and($player->photo_path)->not->toBeNull();
    Storage::disk($disk)->assertExists($player->photo_path);
    $this->patch("/jogadores/$player->id", ['name' => 'Camisa Onze', 'jersey_number' => 12, 'level' => 3.5, 'present' => false])->assertRedirect();
    expect($player->fresh()->name)->toBe('Camisa Onze')->and((bool) $player->fresh()->present)->toBeFalse();
    $this->post('/jogadores', ['name' => 'Repetido', 'jersey_number' => 12, 'level' => 4.1, 'present' => true])->assertSessionHasErrors(['jersey_number', 'level']);
});

test('atualiza a foto de um jogador que ainda não tinha foto', function () {
    $disk = config('filesystems.media_disk');
    Storage::fake($disk);
    $team = User::factory()->create();

    $this->actingAs($team)->post('/jogadores', [
        'name' => 'Sem Foto', 'jersey_number' => 7, 'level' => 3.5, 'present' => true,
    ])->assertRedirect();

    $player = Player::firstOrFail();
    expect($player->photo_path)->toBeNull();

    $this->actingAs($team)->patch("/jogadores/$player->id", [
        'name' => 'Com Foto Agora',
        'jersey_number' => 7,
        'level' => 3.5,
        'present' => true,
        'photo' => UploadedFile::fake()->image('sete.jpg'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $player->refresh();
    expect($player->photo_path)->not->toBeNull();
    Storage::disk($disk)->assertExists($player->photo_path);
});

test('remove um jogador que não possui foto sem erro', function () {
    $disk = config('filesystems.media_disk');
    Storage::fake($disk);
    $team = User::factory()->create();

    $this->actingAs($team)->post('/jogadores', [
        'name' => 'Para Remover', 'jersey_number' => 19, 'level' => 3.0, 'present' => true,
    ])->assertRedirect();

    $player = Player::firstOrFail();
    expect($player->photo_path)->toBeNull();

    $this->actingAs($team)->delete("/jogadores/$player->id")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $player->refresh();
    expect($player->archived_at)->not->toBeNull()
        ->and($player->present)->toBeFalse()
        ->and(Player::active()->find($player->id))->toBeNull();
});

test('arquiva jogador preservando a foto no bucket e permite restaurar', function () {
    $disk = config('filesystems.media_disk');
    Storage::fake($disk);
    $team = User::factory()->create();

    $this->actingAs($team)->post('/jogadores', [
        'name' => 'Goleiro Histórico',
        'jersey_number' => 1,
        'level' => 4.0,
        'present' => true,
        'photo' => UploadedFile::fake()->image('goleiro.jpg'),
    ])->assertRedirect();

    $player = Player::firstOrFail();
    $photoPath = $player->photo_path;
    expect($photoPath)->not->toBeNull();
    Storage::disk($disk)->assertExists($photoPath);

    $this->actingAs($team)->delete("/jogadores/{$player->id}")
        ->assertRedirect()
        ->assertSessionHas('success', 'Jogador arquivado.');

    $player->refresh();
    expect($player->isArchived())->toBeTrue()
        ->and($player->archived_at)->not->toBeNull()
        ->and($player->present)->toBeFalse()
        ->and($player->photo_path)->toBe($photoPath);
    Storage::disk($disk)->assertExists($photoPath);

    $this->actingAs($team)->get('/jogadores')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('players/Index')
            ->has('players', 0)
            ->has('archivedPlayers', 1)
            ->where('archivedPlayers.0.id', $player->id)
            ->where('presentCount', 0));

    $this->actingAs($team)->patch("/jogadores/{$player->id}/restaurar")
        ->assertRedirect()
        ->assertSessionHas('success', 'Jogador restaurado.');

    $player->refresh();
    expect($player->isArchived())->toBeFalse()
        ->and($player->archived_at)->toBeNull();
    Storage::disk($disk)->assertExists($photoPath);

    $this->actingAs($team)->get('/jogadores')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('players/Index')
            ->has('players', 1)
            ->where('players.0.id', $player->id)
            ->has('archivedPlayers', 0));
});

test('jogador arquivado não aparece na lista de presença, no sorteio nem pode ser escalado em nova partida', function () {
    require_once __DIR__.'/../matchHelpers.php';

    [$team, $activeOne] = $this->teamWithPlayer();
    $activeTwo = Player::factory()->for($team)->create(['name' => 'Ativo Dois', 'jersey_number' => 22, 'present' => true]);
    $activeThree = Player::factory()->for($team)->create(['name' => 'Ativo Três', 'jersey_number' => 33, 'present' => true]);
    $activeFour = Player::factory()->for($team)->create(['name' => 'Ativo Quatro', 'jersey_number' => 44, 'present' => true]);
    $archived = Player::factory()->for($team)->create(['name' => 'Veterano Arquivado', 'jersey_number' => 99, 'present' => true]);

    $this->actingAs($team)->patch("/jogadores/{$archived->id}/arquivar")->assertRedirect();
    expect($archived->fresh()->isArchived())->toBeTrue();

    // Presença em massa e individual não reativam a presença de arquivado
    $this->actingAs($team)->post('/presenca', ['present' => true])->assertRedirect();
    $this->actingAs($team)->patch("/jogadores/{$archived->id}/presenca", ['present' => true])->assertRedirect();
    $this->actingAs($team)->postJson('/jogadores/presenca/sincronizar', [
        'changes' => [
            ['player_id' => $archived->id, 'present' => true, 'changed_at' => now()->addMinute()->toISOString()],
        ],
    ])->assertOk()->assertJsonPath('applied', []);

    expect($archived->fresh()->present)->toBeFalse();

    // Sorteio (GET e POST) só enxerga jogadores ativos presentes
    $this->actingAs($team)->get('/sorteio')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('draw/Index')
            ->has('players', 4));

    $drawResponse = $this->actingAs($team)->postJson('/sorteio', ['mode' => 'players', 'size' => 2])->assertOk();
    $drawnPlayerIds = collect($drawResponse->json('teams'))
        ->flatMap(fn (array $t) => collect($t['players'])->pluck('id'))
        ->merge(collect($drawResponse->json('reserves'))->pluck('id'))
        ->all();

    expect($drawnPlayerIds)->toContain($activeOne->id, $activeTwo->id, $activeThree->id, $activeFour->id)
        ->and($drawnPlayerIds)->not->toContain($archived->id);

    // Criação de partida rejeita jogador arquivado na escalação
    $payload = matchPayload($team, $activeOne, $archived);
    $this->actingAs($team)->post('/partidas', $payload)->assertSessionHasErrors('roster');
});

test('estatísticas e histórico de partidas preservam gols, assistências e nome de jogador arquivado', function () {
    require_once __DIR__.'/../matchHelpers.php';

    [$team, $scorer] = $this->teamWithPlayer();
    $scorer->update(['name' => 'Artilheiro Eterno', 'jersey_number' => 10]);
    $assist = Player::factory()->for($team)->create(['name' => 'Garçom Ativo', 'jersey_number' => 8]);

    $payload = matchPayload($team, $scorer, $assist);
    $payload['goals'][0]['assist_id'] = $assist->id;
    $payload['goals'][0]['assist_name'] = $assist->name;

    $this->actingAs($team)->post('/partidas', $payload)->assertRedirect();
    $match = HockeyMatch::withoutGlobalScopes()->firstOrFail();

    // Arquivar o artilheiro
    $this->actingAs($team)->delete("/jogadores/{$scorer->id}")->assertRedirect();
    expect($scorer->fresh()->isArchived())->toBeTrue();

    // Ranking / Estatísticas continuam mostrando o jogador arquivado com seus gols e pontos
    $statsResponse = $this->actingAs($team)->get('/estatisticas')->assertOk();
    $statsPlayers = collect($statsResponse->viewData('page')['props']['players']);
    $scorerStats = $statsPlayers->firstWhere('id', $scorer->id);

    expect($scorerStats)->not->toBeNull()
        ->and($scorerStats['name'])->toBe('Artilheiro Eterno')
        ->and($scorerStats['goals'])->toBe(1)
        ->and($scorerStats['assists'])->toBe(0)
        ->and($scorerStats['points'])->toBe(1)
        ->and($scorerStats['games'])->toBe(1);

    // Histórico / Detalhe da partida continua exibindo o nome e gols do jogador arquivado
    $this->actingAs($team)->get("/partidas/{$match->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('matches/Show')
            ->where('goals.0.scorer_name', 'Artilheiro Eterno')
            ->where('goals.0.assist_name', 'Garçom Ativo')
            ->where('player_stats.home.0.name', 'Artilheiro Eterno')
            ->where('player_stats.home.0.goals', 1));
});

test('presença em massa e individual funcionam apenas no time conectado', function () {
    [$team, $player] = $this->teamWithPlayer();
    $this->actingAs($team)->post('/presenca', ['present' => false]);
    expect($player->fresh()->present)->toBeFalse();
    $this->patch("/jogadores/$player->id/presenca", ['present' => true]);
    expect($player->fresh()->present)->toBeTrue();
});

test('sincroniza presença por jogador usando a alteração mais recente e respeita a conta', function () {
    [$team, $player] = $this->teamWithPlayer();
    [$other, $otherPlayer] = $this->teamWithPlayer();
    $newer = now()->addMinute();
    $player->update(['present' => true, 'presence_changed_at' => $newer]);

    $this->actingAs($team)->postJson('/jogadores/presenca/sincronizar', [
        'changes' => [
            ['player_id' => $player->id, 'present' => false, 'changed_at' => now()->subMinute()->toISOString()],
            ['player_id' => $otherPlayer->id, 'present' => false, 'changed_at' => now()->addMinutes(2)->toISOString()],
        ],
    ])->assertOk()->assertJsonPath('processed', [$player->id]);

    expect($player->fresh()->present)->toBeTrue()
        ->and($otherPlayer->fresh()->present)->toBeTrue();

    $this->postJson('/jogadores/presenca/sincronizar', [
        'changes' => [['player_id' => $player->id, 'present' => false, 'changed_at' => now()->addMinutes(2)->toISOString()]],
    ])->assertOk()->assertJsonPath('applied', [$player->id]);
    expect($player->fresh()->present)->toBeFalse();
});

test('uploads rejeitam executáveis disfarçados e imagens grandes', function () {
    $team = User::factory()->create();
    $this->actingAs($team)->post('/jogadores', [
        'name' => 'Foto inválida', 'jersey_number' => 3, 'level' => 3, 'present' => true,
        'photo' => UploadedFile::fake()->create('foto.php', 10, 'application/x-php'),
    ])->assertSessionHasErrors('photo');
});
