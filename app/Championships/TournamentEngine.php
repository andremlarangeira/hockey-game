<?php

namespace App\Championships;

use App\Championships\Data\ChampionshipRules;
use App\Championships\Data\ChampionshipSummary;
use App\Championships\Data\GameScheduleCommand;
use App\Championships\Data\GoalCommand;
use App\Championships\Data\GroupStandings;
use App\Championships\Data\PlayerLeaderRow;
use App\Championships\Data\Podium;
use App\Championships\Data\ShootoutAttemptCommand;
use App\Championships\Enums\ChampionshipStatus;
use App\Championships\Enums\GameEventType;
use App\Championships\Enums\GamePhase;
use App\Championships\Enums\GameStatus;
use App\Championships\Enums\KnockoutTieResolution;
use App\Championships\Enums\PlayerPosition;
use App\Championships\Enums\TeamSide;
use App\Models\Championship;
use App\Models\ChampionshipAuditLog;
use App\Models\ChampionshipGame;
use App\Models\ChampionshipGameEvent;
use App\Models\ChampionshipPlayer;
use App\Models\ChampionshipTeam;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final readonly class TournamentEngine
{
    public function __construct(
        private StandingsCalculator $standingsCalculator = new StandingsCalculator,
        private BracketPlanner $bracketPlanner = new BracketPlanner,
    ) {}

    public function createChampionship(
        User $user,
        string $name,
        string $city,
        ?string $venue = null,
        ?CarbonImmutable $startsOn = null,
        ?CarbonImmutable $endsOn = null,
        ChampionshipRules $rules = new ChampionshipRules,
    ): Championship {
        $trimmedName = trim($name);
        $trimmedCity = trim($city);
        $trimmedVenue = $venue !== null && trim($venue) !== '' ? trim($venue) : null;

        if ($trimmedName === '')
        {
            throw new ChampionshipException('Informe o nome do campeonato.');
        }

        if ($trimmedCity === '')
        {
            throw new ChampionshipException('Informe a cidade do campeonato.');
        }

        $normalizedStartsOn = $startsOn?->startOfDay();
        $normalizedEndsOn = $endsOn?->startOfDay();

        if ($normalizedStartsOn !== null && $normalizedEndsOn !== null && $normalizedEndsOn->lt($normalizedStartsOn))
        {
            throw new ChampionshipException('A data de encerramento não pode ser anterior à data de início.');
        }

        $this->validateRules($rules);

        return DB::transaction(function () use (
            $user,
            $trimmedName,
            $trimmedCity,
            $trimmedVenue,
            $normalizedStartsOn,
            $normalizedEndsOn,
            $rules,
        ): Championship {
            $championship = Championship::withoutGlobalScope('team')->create([
                'user_id' => (int) $user->id,
                'name' => $trimmedName,
                'city' => $trimmedCity,
                'venue' => $trimmedVenue,
                'starts_on' => $normalizedStartsOn?->toDateString(),
                'ends_on' => $normalizedEndsOn?->toDateString(),
                'status' => ChampionshipStatus::Draft,
                'rules' => $rules,
                'manual_tie_resolutions' => [],
            ]);

            return $this->lockChampionship($championship);
        });
    }

    public function updateDetails(
        Championship $championship,
        string $name,
        string $city,
        ?string $venue = null,
        ?CarbonImmutable $startsOn = null,
        ?CarbonImmutable $endsOn = null,
    ): Championship {
        return DB::transaction(function () use ($championship, $name, $city, $venue, $startsOn, $endsOn): Championship {
            $locked = $this->lockChampionship($championship);

            $trimmedName = trim($name);
            $trimmedCity = trim($city);
            $trimmedVenue = $venue !== null && trim($venue) !== '' ? trim($venue) : null;
            $normalizedStartsOn = $startsOn?->startOfDay() ?? $locked->starts_on;
            $normalizedEndsOn = $endsOn?->startOfDay() ?? $locked->ends_on;

            if ($trimmedName === '')
            {
                throw new ChampionshipException('Informe o nome do campeonato.');
            }

            if ($trimmedCity === '')
            {
                throw new ChampionshipException('Informe a cidade do campeonato.');
            }

            if ($normalizedStartsOn !== null && $normalizedEndsOn !== null && $normalizedEndsOn->lt($normalizedStartsOn))
            {
                throw new ChampionshipException('A data de encerramento não pode ser anterior à data de início.');
            }

            $locked->name = $trimmedName;
            $locked->city = $trimmedCity;
            $locked->venue = $trimmedVenue;
            $locked->starts_on = $normalizedStartsOn;
            $locked->ends_on = $normalizedEndsOn;
            $locked->save();

            $this->syncModel($championship, $locked);

            return $locked;
        });
    }

    public function updateRules(Championship $championship, ChampionshipRules $rules): Championship
    {
        return DB::transaction(function () use ($championship, $rules): Championship {
            $locked = $this->lockChampionship($championship);

            if ($locked->status !== ChampionshipStatus::Draft)
            {
                throw new ChampionshipException('As regras do campeonato estão travadas e só podem ser editadas antes do sorteio dos grupos.');
            }

            $this->validateRules($rules);

            $locked->rules = $rules;
            $locked->save();

            $this->syncModel($championship, $locked);

            return $locked;
        });
    }

    public function enrollTeam(
        Championship $championship,
        string $name,
        ?string $shortName = null,
        string $primaryColor = '#2563eb',
        string $secondaryColor = '#ffffff',
        ?UploadedFile $logo = null,
        ?int $seedPot = null,
    ): ChampionshipTeam {
        return DB::transaction(function () use (
            $championship,
            $name,
            $shortName,
            $primaryColor,
            $secondaryColor,
            $logo,
            $seedPot,
        ): ChampionshipTeam {
            $locked = $this->lockChampionship($championship);

            if ($locked->status !== ChampionshipStatus::Draft)
            {
                throw new ChampionshipException('Não é possível inscrever novas equipes após o sorteio dos grupos.');
            }

            $trimmedName = trim($name);
            if ($trimmedName === '')
            {
                throw new ChampionshipException('Informe o nome da equipe.');
            }

            $trimmedShortName = $shortName !== null && trim($shortName) !== ''
                ? trim($shortName)
                : null;

            $this->validateHexColor($primaryColor, 'primária');
            $this->validateHexColor($secondaryColor, 'secundária');

            if ($seedPot !== null && $seedPot < 1)
            {
                throw new ChampionshipException('O pote de sorteio deve ser um número inteiro positivo.');
            }

            $duplicateExists = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->where('name', $trimmedName)
                ->exists();

            if ($duplicateExists)
            {
                throw new ChampionshipException("Já existe uma equipe inscrita com o nome \"{$trimmedName}\" neste campeonato.");
            }

            $logoPath = null;
            if ($logo !== null)
            {
                $this->assertRasterLogo($logo);
                $stored = $logo->store("championships/{$locked->user_id}/logos", 'public');
                if ($stored === false)
                {
                    throw new ChampionshipException('Não foi possível salvar o escudo da equipe.');
                }
                $logoPath = $stored;
            }

            return ChampionshipTeam::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'name' => $trimmedName,
                'short_name' => $trimmedShortName,
                'primary_color' => $primaryColor,
                'secondary_color' => $secondaryColor,
                'logo_path' => $logoPath,
                'seed_pot' => $seedPot,
            ]);
        });
    }

    public function updateTeam(
        ChampionshipTeam $team,
        ?string $name = null,
        ?string $shortName = null,
        ?string $primaryColor = null,
        ?string $secondaryColor = null,
        ?UploadedFile $logo = null,
        ?int $seedPot = null,
        bool $removeLogo = false,
    ): ChampionshipTeam {
        return DB::transaction(function () use (
            $team,
            $name,
            $shortName,
            $primaryColor,
            $secondaryColor,
            $logo,
            $seedPot,
            $removeLogo,
        ): ChampionshipTeam {
            $locked = $this->lockChampionship((int) $team->championship_id);

            /** @var ChampionshipTeam|null $lockedTeam */
            $lockedTeam = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereKey($team->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTeam === null)
            {
                throw new ChampionshipException('A equipe informada não pertence a este campeonato.');
            }

            $resolvedName = $name ?? $lockedTeam->name;
            $resolvedShortName = $shortName ?? $lockedTeam->short_name;
            $resolvedPrimaryColor = $primaryColor ?? $lockedTeam->primary_color;
            $resolvedSecondaryColor = $secondaryColor ?? $lockedTeam->secondary_color;

            $trimmedName = trim($resolvedName);
            if ($trimmedName === '')
            {
                throw new ChampionshipException('Informe o nome da equipe.');
            }

            $trimmedShortName = $resolvedShortName !== null && trim($resolvedShortName) !== ''
                ? trim($resolvedShortName)
                : null;

            $this->validateHexColor($resolvedPrimaryColor, 'primária');
            $this->validateHexColor($resolvedSecondaryColor, 'secundária');

            if ($seedPot !== null && $seedPot < 1)
            {
                throw new ChampionshipException('O pote de sorteio deve ser um número inteiro positivo.');
            }

            $duplicateExists = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->where('name', $trimmedName)
                ->whereKeyNot($lockedTeam->id)
                ->exists();

            if ($duplicateExists)
            {
                throw new ChampionshipException("Já existe outra equipe inscrita com o nome \"{$trimmedName}\" neste campeonato.");
            }

            $logoPath = $lockedTeam->logo_path;
            if ($logo !== null)
            {
                $this->assertRasterLogo($logo);
                $stored = $logo->store("championships/{$locked->user_id}/logos", 'public');
                if ($stored === false)
                {
                    throw new ChampionshipException('Não foi possível salvar o escudo da equipe.');
                }

                if ($lockedTeam->logo_path !== null && $lockedTeam->logo_path !== '')
                {
                    Storage::disk('public')->delete($lockedTeam->logo_path);
                }

                $logoPath = $stored;
            }
            elseif ($removeLogo && $lockedTeam->logo_path !== null && $lockedTeam->logo_path !== '')
            {
                Storage::disk('public')->delete($lockedTeam->logo_path);
                $logoPath = null;
            }

            $lockedTeam->name = $trimmedName;
            $lockedTeam->short_name = $trimmedShortName;
            $lockedTeam->primary_color = $resolvedPrimaryColor;
            $lockedTeam->secondary_color = $resolvedSecondaryColor;
            $lockedTeam->logo_path = $logoPath;
            if ($locked->status === ChampionshipStatus::Draft && $seedPot !== null)
            {
                $lockedTeam->seed_pot = $seedPot;
            }
            $lockedTeam->save();

            $this->syncModel($team, $lockedTeam);

            return $lockedTeam;
        });
    }

    public function removeTeam(ChampionshipTeam $team): void
    {
        DB::transaction(function () use ($team): void {
            $locked = $this->lockChampionship((int) $team->championship_id);

            if ($locked->status !== ChampionshipStatus::Draft)
            {
                throw new ChampionshipException('Equipes não podem ser removidas após o sorteio dos grupos.');
            }

            /** @var ChampionshipTeam|null $lockedTeam */
            $lockedTeam = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereKey($team->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTeam === null)
            {
                throw new ChampionshipException('A equipe informada não pertence a este campeonato.');
            }

            if ($lockedTeam->logo_path !== null && $lockedTeam->logo_path !== '')
            {
                Storage::disk('public')->delete($lockedTeam->logo_path);
            }

            ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_team_id', $lockedTeam->id)
                ->delete();

            $lockedTeam->delete();
        });
    }

    public function addPlayer(
        ChampionshipTeam $team,
        string $name,
        int $jerseyNumber,
        PlayerPosition $position = PlayerPosition::Universal,
        bool $isGoalkeeper = false,
    ): ChampionshipPlayer {
        return DB::transaction(function () use (
            $team,
            $name,
            $jerseyNumber,
            $position,
            $isGoalkeeper,
        ): ChampionshipPlayer {
            $locked = $this->lockChampionship((int) $team->championship_id);

            if ($locked->status === ChampionshipStatus::Completed)
            {
                throw new ChampionshipException('Não é possível inscrever jogadores em um campeonato já encerrado.');
            }

            /** @var ChampionshipTeam|null $lockedTeam */
            $lockedTeam = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereKey($team->id)
                ->first();

            if ($lockedTeam === null)
            {
                throw new ChampionshipException('A equipe informada não pertence a este campeonato.');
            }

            $trimmedName = trim($name);
            if ($trimmedName === '')
            {
                throw new ChampionshipException('Informe o nome do jogador.');
            }

            if ($jerseyNumber < 0 || $jerseyNumber > 999)
            {
                throw new ChampionshipException('O número da camisa deve estar entre 0 e 999.');
            }

            $jerseyInUse = ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_team_id', $lockedTeam->id)
                ->where('jersey_number', $jerseyNumber)
                ->exists();

            if ($jerseyInUse)
            {
                throw new ChampionshipException("A camisa #{$jerseyNumber} já está em uso nesta equipe.");
            }

            return ChampionshipPlayer::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_team_id' => $lockedTeam->id,
                'name' => $trimmedName,
                'jersey_number' => $jerseyNumber,
                'position' => $position,
                'is_goalkeeper' => $isGoalkeeper || $position === PlayerPosition::Goalkeeper,
            ]);
        });
    }

    public function updatePlayer(
        ChampionshipPlayer $player,
        ?string $name = null,
        ?int $jerseyNumber = null,
        ?PlayerPosition $position = null,
        ?bool $isGoalkeeper = null,
    ): ChampionshipPlayer {
        return DB::transaction(function () use (
            $player,
            $name,
            $jerseyNumber,
            $position,
            $isGoalkeeper,
        ): ChampionshipPlayer {
            $locked = $this->lockChampionship((int) $player->championship_id);

            /** @var ChampionshipPlayer|null $lockedPlayer */
            $lockedPlayer = ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereKey($player->id)
                ->lockForUpdate()
                ->first();

            if ($lockedPlayer === null)
            {
                throw new ChampionshipException('O jogador informado não pertence a este campeonato.');
            }

            $resolvedName = $name ?? $lockedPlayer->name;
            $resolvedJersey = $jerseyNumber ?? $lockedPlayer->jersey_number;
            $resolvedPosition = $position ?? $lockedPlayer->position;
            $resolvedGoalkeeper = $isGoalkeeper ?? ($resolvedPosition === PlayerPosition::Goalkeeper ? true : $lockedPlayer->is_goalkeeper);

            $trimmedName = trim($resolvedName);
            if ($trimmedName === '')
            {
                throw new ChampionshipException('Informe o nome do jogador.');
            }

            if ($resolvedJersey < 0 || $resolvedJersey > 999)
            {
                throw new ChampionshipException('O número da camisa deve estar entre 0 e 999.');
            }

            $jerseyInUse = ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_team_id', $lockedPlayer->championship_team_id)
                ->where('jersey_number', $resolvedJersey)
                ->whereKeyNot($lockedPlayer->id)
                ->exists();

            if ($jerseyInUse)
            {
                throw new ChampionshipException("A camisa #{$resolvedJersey} já está em uso nesta equipe.");
            }

            $lockedPlayer->name = $trimmedName;
            $lockedPlayer->jersey_number = $resolvedJersey;
            $lockedPlayer->position = $resolvedPosition;
            $lockedPlayer->is_goalkeeper = $resolvedGoalkeeper;
            $lockedPlayer->save();

            $this->syncModel($player, $lockedPlayer);

            return $lockedPlayer;
        });
    }

    public function removePlayer(ChampionshipPlayer $player): void
    {
        DB::transaction(function () use ($player): void {
            $locked = $this->lockChampionship((int) $player->championship_id);

            /** @var ChampionshipPlayer|null $lockedPlayer */
            $lockedPlayer = ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereKey($player->id)
                ->lockForUpdate()
                ->first();

            if ($lockedPlayer === null)
            {
                throw new ChampionshipException('O jogador informado não pertence a este campeonato.');
            }

            $hasActiveEvents = ChampionshipGameEvent::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereNull('deleted_at')
                ->where(function ($query) use ($lockedPlayer): void {
                    $query->where('primary_player_id', $lockedPlayer->id)
                        ->orWhere('first_assist_player_id', $lockedPlayer->id)
                        ->orWhere('second_assist_player_id', $lockedPlayer->id);
                })
                ->exists();

            if ($hasActiveEvents)
            {
                throw new ChampionshipException('Não é possível remover um jogador que possui gols, assistências ou cobranças registrados em partidas.');
            }

            $lockedPlayer->delete();
        });
    }

    /**
     * Randomly (with optional seed pots) or explicitly assigns teams to groups and creates
     * single/double round-robin group games with deterministic slot keys.
     *
     * @param  array<string, list<int>>|null  $explicitGroups
     */
    public function drawGroups(
        Championship $championship,
        User $actor,
        bool $redraw = false,
        ?string $reason = null,
        ?array $explicitGroups = null,
    ): Championship {
        return DB::transaction(function () use (
            $championship,
            $actor,
            $redraw,
            $reason,
            $explicitGroups,
        ): Championship {
            $locked = $this->lockChampionship($championship);

            $rules = $locked->rules;
            $this->validateRules($rules);

            /** @var list<ChampionshipTeam> $teams */
            $teams = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all();

            $teamCount = count($teams);
            $minRequiredTeams = max($rules->groupsCount * 2, $rules->totalQualifiers());

            if ($teamCount < $minRequiredTeams)
            {
                throw new ChampionshipException(
                    "O sorteio exige pelo menos {$minRequiredTeams} equipes inscritas para {$rules->groupsCount} grupo(s).",
                );
            }

            $existingGames = ChampionshipGame::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->lockForUpdate()
                ->get();

            $isAlreadyDrawn = $locked->status !== ChampionshipStatus::Draft
                || $existingGames->where('phase', GamePhase::Group)->isNotEmpty();

            if ($isAlreadyDrawn)
            {
                if (! $redraw)
                {
                    if ($explicitGroups === null || $this->matchesCurrentGroupAssignment($teams, $explicitGroups))
                    {
                        $this->syncModel($championship, $locked);

                        return $locked;
                    }

                    throw new ChampionshipException('Os grupos já foram sorteados. Confirme a opção de refazer sorteio e informe o motivo.');
                }

                $hasStartedOrKnockoutGames = $locked->status !== ChampionshipStatus::GroupStage
                    || $locked->bracket_generated_at !== null
                    || $existingGames->contains(
                        static fn (ChampionshipGame $game): bool => $game->phase !== GamePhase::Group
                            || $game->status !== GameStatus::Scheduled,
                    );

                $hasRecordedEvents = ChampionshipGameEvent::withoutGlobalScope('team')
                    ->where('championship_id', $locked->id)
                    ->withTrashed()
                    ->exists();

                if ($hasStartedOrKnockoutGames || $hasRecordedEvents)
                {
                    throw new ChampionshipException('Não é possível refazer o sorteio após o início dos jogos.');
                }

                if ($reason === null || trim($reason) === '')
                {
                    throw new ChampionshipException('Informe o motivo para refazer o sorteio dos grupos.');
                }
            }

            $beforeGroups = $this->currentGroupMap($teams);

            $assignedGroups = $explicitGroups !== null
                ? $this->validateAndNormalizeExplicitGroups($teams, $explicitGroups, $rules)
                : $this->performRandomSeededGroupDraw($teams, $rules->groupsCount);

            $minGroupSize = PHP_INT_MAX;
            foreach ($assignedGroups as $groupTeams)
            {
                $minGroupSize = min($minGroupSize, count($groupTeams));
            }

            if ($minGroupSize < 2 || $rules->qualifiersPerGroup > $minGroupSize)
            {
                throw new ChampionshipException('O número de classificados por grupo excede o tamanho do menor grupo.');
            }

            if ($isAlreadyDrawn)
            {
                ChampionshipGame::withoutGlobalScope('team')
                    ->where('championship_id', $locked->id)
                    ->where('phase', GamePhase::Group)
                    ->delete();

                ChampionshipTeam::withoutGlobalScope('team')
                    ->where('championship_id', $locked->id)
                    ->update([
                        'group_code' => null,
                        'group_draw_order' => null,
                    ]);

                foreach ($teams as $teamModel)
                {
                    $teamModel->group_code = null;
                    $teamModel->group_draw_order = null;
                    $teamModel->syncOriginal();
                }
            }

            $afterGroups = [];
            $createdGamesCount = 0;

            foreach ($assignedGroups as $groupCode => $groupTeams)
            {
                $afterGroups[$groupCode] = [];

                foreach ($groupTeams as $idx => $teamModel)
                {
                    $drawOrder = $idx + 1;
                    $teamModel->group_code = $groupCode;
                    $teamModel->group_draw_order = $drawOrder;
                    $teamModel->save();

                    $afterGroups[$groupCode][] = (int) $teamModel->id;
                }

                $fixtures = $this->buildRoundRobinFixtures($groupCode, $groupTeams, $rules->doubleRoundRobin);

                foreach ($fixtures as $fixture)
                {
                    ChampionshipGame::withoutGlobalScope('team')->create([
                        'user_id' => $locked->user_id,
                        'championship_id' => $locked->id,
                        'phase' => GamePhase::Group,
                        'group_code' => $groupCode,
                        'round_number' => $fixture['round_number'],
                        'leg_number' => $fixture['leg_number'],
                        'slot_key' => $fixture['slot_key'],
                        'bracket_position' => null,
                        'home_source' => $fixture['home_source'],
                        'away_source' => $fixture['away_source'],
                        'home_team_id' => $fixture['home_team_id'],
                        'away_team_id' => $fixture['away_team_id'],
                        'venue' => $locked->venue,
                        'scheduled_at' => null,
                        'status' => GameStatus::Scheduled,
                        'is_bye' => false,
                    ]);

                    $createdGamesCount++;
                }
            }

            $now = CarbonImmutable::now();
            $locked->status = ChampionshipStatus::GroupStage;
            $locked->drawn_at = $now;
            if ($isAlreadyDrawn)
            {
                $locked->manual_tie_resolutions = [];
            }
            $locked->save();

            ChampionshipAuditLog::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => null,
                'actor_user_id' => (int) $actor->id,
                'action' => $isAlreadyDrawn ? 'groups_redrawn' : 'groups_drawn',
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'before_snapshot' => $isAlreadyDrawn ? ['groups' => $beforeGroups] : null,
                'after_snapshot' => [
                    'status' => $locked->status->value,
                    'groups' => $afterGroups,
                    'games_created' => $createdGamesCount,
                ],
                'recorded_at' => $now,
            ]);

            $this->syncModel($championship, $locked);

            return $locked;
        });
    }

    public function scheduleGame(
        ChampionshipGame $game,
        GameScheduleCommand $command,
    ): ChampionshipGame {
        return DB::transaction(function () use ($game, $command): ChampionshipGame {
            $locked = $this->lockChampionship((int) $game->championship_id);
            $lockedGame = $this->lockGame($locked, $game);

            if ($lockedGame->is_bye)
            {
                throw new ChampionshipException('Não é possível agendar um slot de avanço automático (bye).');
            }

            if ($lockedGame->status === GameStatus::Finished)
            {
                throw new ChampionshipException('Não é possível alterar o agendamento de uma partida já encerrada.');
            }

            $lockedGame->venue = $command->venue !== null && trim($command->venue) !== ''
                ? trim($command->venue)
                : null;
            $lockedGame->scheduled_at = $command->scheduledAt;
            $lockedGame->save();

            $this->syncModel($game, $lockedGame);

            return $lockedGame;
        });
    }

    public function startGame(
        ChampionshipGame $game,
        User $actor,
        ?CarbonImmutable $startedAt = null,
    ): ChampionshipGame {
        return DB::transaction(function () use ($game, $startedAt): ChampionshipGame {
            $locked = $this->lockChampionship((int) $game->championship_id);
            $lockedGame = $this->lockGame($locked, $game);

            if ($lockedGame->is_bye)
            {
                throw new ChampionshipException('Slots de avanço automático (bye) não podem ser iniciados.');
            }

            if ($lockedGame->home_team_id === null || $lockedGame->away_team_id === null)
            {
                throw new ChampionshipException('A partida precisa ter as duas equipes definidas antes de ser iniciada.');
            }

            if ($lockedGame->status === GameStatus::Finished)
            {
                throw new ChampionshipException('Esta partida já foi encerrada. Reabra a partida caso precise retificar a súmula.');
            }

            if ($lockedGame->status === GameStatus::Scheduled)
            {
                $lockedGame->status = GameStatus::Live;
                $lockedGame->started_at = $lockedGame->started_at ?? ($startedAt ?? CarbonImmutable::now());
                $lockedGame->save();
            }

            $this->syncModel($game, $lockedGame);

            return $lockedGame;
        });
    }

    public function recordGoal(
        ChampionshipGame $game,
        GoalCommand $command,
    ): ChampionshipGameEvent {
        return DB::transaction(function () use ($game, $command): ChampionshipGameEvent {
            $locked = $this->lockChampionship((int) $game->championship_id);
            $lockedGame = $this->lockGame($locked, $game);

            if ($lockedGame->is_bye)
            {
                throw new ChampionshipException('Não é possível registrar gols em um slot de bye.');
            }

            if ($lockedGame->status !== GameStatus::Live)
            {
                throw new ChampionshipException('A partida precisa estar em andamento (ao vivo) para registrar gols.');
            }

            if ($lockedGame->home_team_id === null || $lockedGame->away_team_id === null)
            {
                throw new ChampionshipException('A partida não possui as duas equipes definidas.');
            }

            $rules = $locked->rules;

            if ($command->overtime)
            {
                if ($lockedGame->phase === GamePhase::Group && ! $rules->allowGroupOvertimeOrShootout)
                {
                    throw new ChampionshipException('As regras deste campeonato não permitem prorrogação na fase de grupos.');
                }

                if ($lockedGame->phase->isKnockout() && $rules->knockoutTieResolution === KnockoutTieResolution::ShootoutOnly)
                {
                    throw new ChampionshipException('As regras deste campeonato definem desempate direto nos pênaltis/shootout, sem prorrogação.');
                }
            }

            if ($command->period < 1)
            {
                throw new ChampionshipException('O período do gol deve ser maior ou igual a 1.');
            }

            $teamId = $command->side === TeamSide::Home
                ? (int) $lockedGame->home_team_id
                : (int) $lockedGame->away_team_id;

            $maxAssists = $rules->maxAssistsPerGoal;
            $assistIds = array_values($command->assistIds);

            if (count($assistIds) > $maxAssists)
            {
                if ($maxAssists === 0)
                {
                    throw new ChampionshipException('As regras deste campeonato não permitem assistências.');
                }

                throw new ChampionshipException("As regras deste campeonato permitem no máximo {$maxAssists} assistência(s) por gol.");
            }

            if (in_array($command->scorerId, $assistIds, true))
            {
                throw new ChampionshipException('O autor do gol não pode dar assistência para o próprio gol.');
            }

            if (count($assistIds) !== count(array_unique($assistIds)))
            {
                throw new ChampionshipException('As assistências de um gol devem ser de jogadores distintos.');
            }

            $playerIdsToValidate = [$command->scorerId, ...$assistIds];

            $validPlayersCount = ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->where('championship_team_id', $teamId)
                ->whereIn('id', $playerIdsToValidate)
                ->count();

            if ($validPlayersCount !== count($playerIdsToValidate))
            {
                throw new ChampionshipException('O autor do gol e os assistentes devem pertencer ao elenco da equipe na partida.');
            }

            $event = ChampionshipGameEvent::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => $lockedGame->id,
                'type' => GameEventType::Goal,
                'side' => $command->side,
                'team_id' => $teamId,
                'primary_player_id' => $command->scorerId,
                'first_assist_player_id' => $assistIds[0] ?? null,
                'second_assist_player_id' => $assistIds[1] ?? null,
                'period' => $command->period,
                'is_overtime' => $command->overtime,
                'elapsed_seconds' => max(0, $command->elapsedSeconds),
                'remaining_seconds' => max(0, $command->remainingSeconds),
                'recorded_at' => CarbonImmutable::now(),
            ]);

            $this->recomputeGameScores($lockedGame);
            $this->syncModel($game, $lockedGame);

            return $event;
        });
    }

    public function recordShootoutAttempt(
        ChampionshipGame $game,
        ShootoutAttemptCommand $command,
    ): ChampionshipGameEvent {
        return DB::transaction(function () use ($game, $command): ChampionshipGameEvent {
            $locked = $this->lockChampionship((int) $game->championship_id);
            $lockedGame = $this->lockGame($locked, $game);

            if ($lockedGame->is_bye)
            {
                throw new ChampionshipException('Não é possível registrar cobranças em um slot de bye.');
            }

            if ($lockedGame->status !== GameStatus::Live)
            {
                throw new ChampionshipException('A partida precisa estar em andamento (ao vivo) para registrar cobranças de shootout.');
            }

            if ($lockedGame->home_team_id === null || $lockedGame->away_team_id === null)
            {
                throw new ChampionshipException('A partida não possui as duas equipes definidas.');
            }

            if ($lockedGame->phase === GamePhase::Group && ! $locked->rules->allowGroupOvertimeOrShootout)
            {
                throw new ChampionshipException('As regras deste campeonato permitem empate na fase de grupos e não utilizam disputa de shootout.');
            }

            if ($lockedGame->home_regular_score !== $lockedGame->away_regular_score)
            {
                throw new ChampionshipException('A disputa de shootout só é permitida quando o placar regulamentar/prorrogação está empatado.');
            }

            if ($command->round < 1)
            {
                throw new ChampionshipException('A rodada do shootout deve ser maior ou igual a 1.');
            }

            $teamId = $command->side === TeamSide::Home
                ? (int) $lockedGame->home_team_id
                : (int) $lockedGame->away_team_id;

            $shooterBelongsToTeam = ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->where('championship_team_id', $teamId)
                ->whereKey($command->shooterId)
                ->exists();

            if (! $shooterBelongsToTeam)
            {
                throw new ChampionshipException('O cobrador deve pertencer ao elenco da equipe na partida.');
            }

            $event = ChampionshipGameEvent::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => $lockedGame->id,
                'type' => GameEventType::ShootoutAttempt,
                'side' => $command->side,
                'team_id' => $teamId,
                'primary_player_id' => $command->shooterId,
                'first_assist_player_id' => null,
                'second_assist_player_id' => null,
                'period' => null,
                'is_overtime' => false,
                'elapsed_seconds' => 0,
                'remaining_seconds' => 0,
                'shootout_round' => $command->round,
                'shootout_scored' => $command->scored,
                'recorded_at' => CarbonImmutable::now(),
            ]);

            $this->recomputeGameScores($lockedGame);
            $this->syncModel($game, $lockedGame);

            return $event;
        });
    }

    public function removeEvent(
        ChampionshipGameEvent $event,
        User $actor,
        ?string $reason = null,
    ): void {
        DB::transaction(function () use ($event, $actor, $reason): void {
            $locked = $this->lockChampionship((int) $event->championship_id);

            /** @var ChampionshipGame|null $lockedGame */
            $lockedGame = ChampionshipGame::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereKey($event->championship_game_id)
                ->lockForUpdate()
                ->first();

            if ($lockedGame === null)
            {
                throw new ChampionshipException('O evento informado não pertence a esta partida.');
            }

            if ($lockedGame->status !== GameStatus::Live)
            {
                throw new ChampionshipException('A partida precisa estar em andamento ou reaberta para remover eventos da súmula.');
            }

            /** @var ChampionshipGameEvent|null $lockedEvent */
            $lockedEvent = ChampionshipGameEvent::withoutGlobalScope('team')
                ->withTrashed()
                ->where('championship_game_id', $lockedGame->id)
                ->whereKey($event->id)
                ->lockForUpdate()
                ->first();

            if ($lockedEvent === null)
            {
                throw new ChampionshipException('Evento não encontrado na súmula.');
            }

            if ($lockedEvent->trashed())
            {
                return;
            }

            $beforeSnapshot = [
                'event_id' => $lockedEvent->id,
                'type' => $lockedEvent->type->value,
                'side' => $lockedEvent->side?->value,
                'team_id' => $lockedEvent->team_id,
                'primary_player_id' => $lockedEvent->primary_player_id,
                'first_assist_player_id' => $lockedEvent->first_assist_player_id,
                'second_assist_player_id' => $lockedEvent->second_assist_player_id,
                'period' => $lockedEvent->period,
                'is_overtime' => $lockedEvent->is_overtime,
                'shootout_round' => $lockedEvent->shootout_round,
                'shootout_scored' => $lockedEvent->shootout_scored,
                'home_regular_score' => $lockedGame->home_regular_score,
                'away_regular_score' => $lockedGame->away_regular_score,
                'home_shootout_score' => $lockedGame->home_shootout_score,
                'away_shootout_score' => $lockedGame->away_shootout_score,
            ];

            $lockedEvent->delete();

            $this->recomputeGameScores($lockedGame);

            ChampionshipAuditLog::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => $lockedGame->id,
                'actor_user_id' => (int) $actor->id,
                'action' => 'event_removed',
                'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                'before_snapshot' => $beforeSnapshot,
                'after_snapshot' => [
                    'home_regular_score' => $lockedGame->home_regular_score,
                    'away_regular_score' => $lockedGame->away_regular_score,
                    'home_shootout_score' => $lockedGame->home_shootout_score,
                    'away_shootout_score' => $lockedGame->away_shootout_score,
                    'went_to_overtime' => $lockedGame->went_to_overtime,
                    'went_to_shootout' => $lockedGame->went_to_shootout,
                ],
                'recorded_at' => CarbonImmutable::now(),
            ]);

            $this->syncModel($event, $lockedEvent);
        });
    }

    public function finishGame(
        ChampionshipGame $game,
        User $actor,
        ?CarbonImmutable $finishedAt = null,
    ): ChampionshipGame {
        return DB::transaction(function () use ($game, $actor, $finishedAt): ChampionshipGame {
            $locked = $this->lockChampionship((int) $game->championship_id);
            $lockedGame = $this->lockGame($locked, $game);

            if ($lockedGame->status === GameStatus::Finished)
            {
                $this->syncModel($game, $lockedGame);

                return $lockedGame;
            }

            if ($lockedGame->home_team_id === null || $lockedGame->away_team_id === null)
            {
                throw new ChampionshipException('A partida precisa ter as duas equipes definidas para ser encerrada.');
            }

            $this->recomputeGameScores($lockedGame);

            $homeId = (int) $lockedGame->home_team_id;
            $awayId = (int) $lockedGame->away_team_id;
            $rules = $locked->rules;

            $winnerTeamId = null;
            $loserTeamId = null;

            if ($lockedGame->home_regular_score > $lockedGame->away_regular_score)
            {
                $winnerTeamId = $homeId;
                $loserTeamId = $awayId;
            }
            elseif ($lockedGame->away_regular_score > $lockedGame->home_regular_score)
            {
                $winnerTeamId = $awayId;
                $loserTeamId = $homeId;
            }
            else
            {
                $requiresWinnerOnTie = $lockedGame->phase->isKnockout() || $rules->allowGroupOvertimeOrShootout;

                if ($requiresWinnerOnTie)
                {
                    if (! $lockedGame->went_to_shootout || $lockedGame->home_shootout_score === $lockedGame->away_shootout_score)
                    {
                        throw new ChampionshipException(
                            $lockedGame->phase->isKnockout()
                                ? 'Jogos do mata-mata exigem um vencedor (no tempo normal, prorrogação ou disputa de shootout).'
                                : 'As regras deste campeonato não permitem empate na fase de grupos; defina o vencedor na prorrogação ou shootout.',
                        );
                    }

                    $winnerTeamId = $lockedGame->home_shootout_score > $lockedGame->away_shootout_score ? $homeId : $awayId;
                    $loserTeamId = $winnerTeamId === $homeId ? $awayId : $homeId;
                }
            }

            $now = $finishedAt ?? CarbonImmutable::now();

            $lockedGame->status = GameStatus::Finished;
            $lockedGame->winner_team_id = $winnerTeamId;
            $lockedGame->started_at = $lockedGame->started_at ?? $now;
            $lockedGame->finished_at = $now;
            $lockedGame->save();

            if ($lockedGame->phase->isKnockout() && $winnerTeamId !== null && $loserTeamId !== null)
            {
                $this->advanceKnockoutSlot($locked, $lockedGame->slot_key, $winnerTeamId, $loserTeamId);
                $this->checkAndCompleteChampionshipIfDone($locked, $now);
            }

            ChampionshipAuditLog::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => $lockedGame->id,
                'actor_user_id' => (int) $actor->id,
                'action' => 'game_finished',
                'reason' => null,
                'before_snapshot' => null,
                'after_snapshot' => $this->gameSnapshot($lockedGame, $locked->status),
                'recorded_at' => $now,
            ]);

            $this->syncModel($game, $lockedGame);

            return $lockedGame;
        });
    }

    public function reopenGame(
        ChampionshipGame $game,
        User $actor,
        string $reason,
        bool $resetBracket = false,
    ): ChampionshipGame {
        return DB::transaction(function () use ($game, $actor, $reason, $resetBracket): ChampionshipGame {
            $trimmedReason = trim($reason);
            if ($trimmedReason === '')
            {
                throw new ChampionshipException('O motivo é obrigatório para reabrir uma partida.');
            }

            $locked = $this->lockChampionship((int) $game->championship_id);
            $lockedGame = $this->lockGame($locked, $game);

            if ($lockedGame->is_bye)
            {
                throw new ChampionshipException('Slots de avanço automático (bye) não podem ser reabertos.');
            }

            if ($lockedGame->status !== GameStatus::Finished)
            {
                throw new ChampionshipException('Apenas partidas já encerradas podem ser reabertas.');
            }

            $actorId = (int) $actor->id;
            $now = CarbonImmutable::now();
            $previousChampionshipStatus = $locked->status;

            if ($lockedGame->phase === GamePhase::Group)
            {
                $knockoutGames = ChampionshipGame::withoutGlobalScope('team')
                    ->where('championship_id', $locked->id)
                    ->where('phase', '!=', GamePhase::Group)
                    ->lockForUpdate()
                    ->get();

                $knockoutIds = $knockoutGames->pluck('id')->all();
                $knockoutHasEvents = $knockoutIds !== []
                    && ChampionshipGameEvent::withoutGlobalScope('team')
                        ->withTrashed()
                        ->whereIn('championship_game_id', $knockoutIds)
                        ->exists();

                $knockoutStarted = $knockoutHasEvents || $knockoutGames->contains(
                    static fn (ChampionshipGame $koGame): bool => ! $koGame->is_bye
                        && $koGame->status !== GameStatus::Scheduled,
                );

                if ($knockoutStarted)
                {
                    throw new ChampionshipException('Não é possível reabrir um jogo da fase de grupos pois o mata-mata já foi iniciado.');
                }

                $bracketExists = $knockoutGames->isNotEmpty()
                    || $locked->bracket_generated_at !== null
                    || $locked->status === ChampionshipStatus::KnockoutStage;

                if ($bracketExists)
                {
                    if (! $resetBracket)
                    {
                        throw new ChampionshipException('A chave do mata-mata já foi gerada. Confirme o reinício da chave para reabrir este jogo.');
                    }

                    $bracketBeforeSnapshot = [
                        'status' => $locked->status->value,
                        'bracket_generated_at' => $locked->bracket_generated_at?->toIso8601String(),
                        'slots' => $knockoutGames->map(static fn (ChampionshipGame $ko): array => [
                            'slot_key' => $ko->slot_key,
                            'home_team_id' => $ko->home_team_id,
                            'away_team_id' => $ko->away_team_id,
                            'status' => $ko->status->value,
                        ])->values()->all(),
                    ];

                    ChampionshipGame::withoutGlobalScope('team')
                        ->where('championship_id', $locked->id)
                        ->where('phase', '!=', GamePhase::Group)
                        ->delete();

                    $locked->status = ChampionshipStatus::GroupStage;
                    $locked->bracket_generated_at = null;
                    $locked->completed_at = null;
                    $locked->save();

                    ChampionshipAuditLog::withoutGlobalScope('team')->create([
                        'user_id' => $locked->user_id,
                        'championship_id' => $locked->id,
                        'championship_game_id' => $lockedGame->id,
                        'actor_user_id' => $actorId,
                        'action' => 'bracket_reset',
                        'reason' => $trimmedReason,
                        'before_snapshot' => $bracketBeforeSnapshot,
                        'after_snapshot' => [
                            'status' => $locked->status->value,
                            'bracket_generated_at' => null,
                            'slots' => [],
                        ],
                        'recorded_at' => $now,
                    ]);
                }
            }
            else
            {
                $winnerSource = "winner:{$lockedGame->slot_key}";
                $loserSource = "loser:{$lockedGame->slot_key}";

                $dependentGames = ChampionshipGame::withoutGlobalScope('team')
                    ->where('championship_id', $locked->id)
                    ->where(function ($query) use ($winnerSource, $loserSource): void {
                        $query->whereIn('home_source', [$winnerSource, $loserSource])
                            ->orWhereIn('away_source', [$winnerSource, $loserSource]);
                    })
                    ->lockForUpdate()
                    ->get();

                $dependentIds = $dependentGames->pluck('id')->all();
                $dependentHasEvents = $dependentIds !== []
                    && ChampionshipGameEvent::withoutGlobalScope('team')
                        ->withTrashed()
                        ->whereIn('championship_game_id', $dependentIds)
                        ->exists();

                $dependentStarted = $dependentHasEvents || $dependentGames->contains(
                    static fn (ChampionshipGame $dep): bool => $dep->status !== GameStatus::Scheduled,
                );

                if ($dependentStarted)
                {
                    throw new ChampionshipException('Não é possível reabrir esta partida pois a fase seguinte já foi iniciada.');
                }

                foreach ($dependentGames as $dependentGame)
                {
                    if (in_array($dependentGame->home_source, [$winnerSource, $loserSource], true))
                    {
                        $dependentGame->home_team_id = null;
                    }
                    if (in_array($dependentGame->away_source, [$winnerSource, $loserSource], true))
                    {
                        $dependentGame->away_team_id = null;
                    }
                    $dependentGame->save();
                }

                if ($locked->status === ChampionshipStatus::Completed)
                {
                    $locked->status = ChampionshipStatus::KnockoutStage;
                    $locked->completed_at = null;
                    $locked->save();
                }
            }

            $beforeSnapshot = $this->gameSnapshot($lockedGame, $previousChampionshipStatus);

            $lockedGame->status = GameStatus::Live;
            $lockedGame->winner_team_id = null;
            $lockedGame->finished_at = null;
            $lockedGame->save();

            $afterSnapshot = $this->gameSnapshot($lockedGame, $locked->status);

            ChampionshipAuditLog::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => $lockedGame->id,
                'actor_user_id' => $actorId,
                'action' => 'game_reopened',
                'reason' => $trimmedReason,
                'before_snapshot' => $beforeSnapshot,
                'after_snapshot' => $afterSnapshot,
                'recorded_at' => $now,
            ]);

            $this->syncModel($game, $lockedGame);

            return $lockedGame;
        });
    }

    /**
     * @param  list<int>  $orderedTeamIds
     */
    public function resolveTie(
        Championship $championship,
        string $group,
        array $orderedTeamIds,
        User $actor,
        string $reason,
    ): Championship {
        return DB::transaction(function () use (
            $championship,
            $group,
            $orderedTeamIds,
            $actor,
            $reason,
        ): Championship {
            $locked = $this->lockChampionship($championship);

            $resolvedGroup = trim($group);
            if ($resolvedGroup === '')
            {
                throw new ChampionshipException('Informe o grupo para registrar o desempate manual.');
            }

            $trimmedReason = trim($reason);
            if ($trimmedReason === '')
            {
                throw new ChampionshipException('O motivo é obrigatório para registrar o desempate manual.');
            }

            $normalizedIds = array_values(array_map('intval', $orderedTeamIds));
            if (count($normalizedIds) < 2 || count($normalizedIds) !== count(array_unique($normalizedIds)))
            {
                throw new ChampionshipException('O desempate manual exige ao menos duas equipes distintas do grupo.');
            }

            $matchingCount = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->where('group_code', $resolvedGroup)
                ->whereIn('id', $normalizedIds)
                ->count();

            if ($matchingCount !== count($normalizedIds))
            {
                throw new ChampionshipException("Todas as equipes informadas no desempate devem pertencer ao grupo {$resolvedGroup}.");
            }

            $currentResolutions = is_array($locked->manual_tie_resolutions)
                ? $locked->manual_tie_resolutions
                : [];
            $beforeResolutions = $currentResolutions;

            $existingForGroup = $currentResolutions[$resolvedGroup] ?? null;
            if (! is_array($existingForGroup) || $existingForGroup === [])
            {
                $currentResolutions[$resolvedGroup] = $normalizedIds;
            }
            else
            {
                $firstElem = reset($existingForGroup);
                if (is_int($firstElem))
                {
                    /** @var list<int> $existingFlat */
                    $existingFlat = array_values(array_map('intval', $existingForGroup));
                    if (array_intersect($existingFlat, $normalizedIds) !== [])
                    {
                        $currentResolutions[$resolvedGroup] = $normalizedIds;
                    }
                    else
                    {
                        $currentResolutions[$resolvedGroup] = [$existingFlat, $normalizedIds];
                    }
                }
                else
                {
                    $updatedBuckets = [];
                    foreach ($existingForGroup as $bucket)
                    {
                        if (is_array($bucket))
                        {
                            $bucketIds = array_values(array_map('intval', $bucket));
                            if (array_intersect($bucketIds, $normalizedIds) === [])
                            {
                                $updatedBuckets[] = $bucketIds;
                            }
                        }
                    }
                    $updatedBuckets[] = $normalizedIds;
                    $currentResolutions[$resolvedGroup] = count($updatedBuckets) === 1
                        ? $updatedBuckets[0]
                        : $updatedBuckets;
                }
            }

            $locked->manual_tie_resolutions = $currentResolutions;
            $locked->save();

            ChampionshipAuditLog::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => null,
                'actor_user_id' => (int) $actor->id,
                'action' => 'tie_break_resolved',
                'reason' => $trimmedReason,
                'before_snapshot' => [
                    'group_code' => $resolvedGroup,
                    'manual_tie_resolutions' => $beforeResolutions[$resolvedGroup] ?? null,
                ],
                'after_snapshot' => [
                    'group_code' => $resolvedGroup,
                    'manual_tie_resolutions' => $currentResolutions[$resolvedGroup],
                ],
                'recorded_at' => CarbonImmutable::now(),
            ]);

            $this->syncModel($championship, $locked);

            return $locked;
        });
    }

    public function generateBracket(
        Championship $championship,
        User $actor,
    ): Championship {
        return DB::transaction(function () use ($championship, $actor): Championship {
            $locked = $this->lockChampionship($championship);

            if ($locked->status === ChampionshipStatus::Draft)
            {
                throw new ChampionshipException('Realize o sorteio dos grupos e conclua a fase de grupos antes de gerar o mata-mata.');
            }

            $existingKnockoutGames = ChampionshipGame::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->where('phase', '!=', GamePhase::Group)
                ->lockForUpdate()
                ->get();

            $knockoutIds = $existingKnockoutGames->pluck('id')->all();
            $hasKnockoutEvents = $knockoutIds !== []
                && ChampionshipGameEvent::withoutGlobalScope('team')
                    ->withTrashed()
                    ->whereIn('championship_game_id', $knockoutIds)
                    ->exists();

            $hasStartedKnockoutGame = $hasKnockoutEvents || $existingKnockoutGames->contains(
                static fn (ChampionshipGame $koGame): bool => ! $koGame->is_bye
                    && $koGame->status !== GameStatus::Scheduled,
            );

            if ($hasStartedKnockoutGame)
            {
                throw new ChampionshipException('A chave do mata-mata já possui partidas iniciadas e não pode ser gerada novamente.');
            }

            $standings = $this->standingsForLocked($locked);

            if ($standings === [])
            {
                throw new ChampionshipException('Não há grupos disponíveis para gerar a chave do mata-mata.');
            }

            foreach ($standings as $groupCode => $groupStandings)
            {
                if (! $groupStandings->allGamesFinished)
                {
                    throw new ChampionshipException("Todas as partidas da fase de grupos devem estar encerradas antes de gerar o mata-mata (grupo {$groupCode} pendente).");
                }
            }

            if ($locked->rules->extraBestQualifiers > 0)
            {
                $extraTier = $locked->rules->qualifiersPerGroup + 1;
                foreach ($standings as $groupCode => $groupStandings)
                {
                    $extraCandidateIds = [];
                    foreach ($groupStandings->rows as $row)
                    {
                        if ($row->position === $extraTier)
                        {
                            $extraCandidateIds[] = $row->teamId;
                        }
                    }

                    foreach ($groupStandings->unresolvedTieGroups as $tieGroup)
                    {
                        if (array_intersect($tieGroup, $extraCandidateIds) !== [])
                        {
                            throw new ChampionshipException("Há um empate técnico pendente de definição manual no grupo {$groupCode}.");
                        }
                    }
                }
            }

            $slotSpecs = $this->bracketPlanner->buildKnockoutSlots($locked->rules, $standings);
            $now = CarbonImmutable::now();

            $expectedSlotKeys = array_map(
                static fn (array $spec): string => $spec['slot_key'],
                $slotSpecs,
            );

            ChampionshipGame::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->where('phase', '!=', GamePhase::Group)
                ->whereNotIn('slot_key', $expectedSlotKeys)
                ->delete();

            /** @var array<string, ChampionshipGame> $upsertedBySlotKey */
            $upsertedBySlotKey = [];

            foreach ($slotSpecs as $spec)
            {
                $existingSlot = $existingKnockoutGames->firstWhere('slot_key', $spec['slot_key']);
                $isBye = $spec['is_bye'];

                $attributes = [
                    'user_id' => $locked->user_id,
                    'championship_id' => $locked->id,
                    'phase' => $spec['phase'],
                    'group_code' => null,
                    'round_number' => $spec['round_number'],
                    'leg_number' => 1,
                    'slot_key' => $spec['slot_key'],
                    'bracket_position' => $spec['bracket_position'],
                    'home_source' => $spec['home_source'],
                    'away_source' => $spec['away_source'],
                    'home_team_id' => $spec['home_team_id'],
                    'away_team_id' => $spec['away_team_id'],
                    'venue' => $existingSlot !== null ? $existingSlot->venue : $locked->venue,
                    'scheduled_at' => $existingSlot?->scheduled_at,
                    'status' => $isBye ? GameStatus::Finished : GameStatus::Scheduled,
                    'is_bye' => $isBye,
                    'home_regular_score' => 0,
                    'away_regular_score' => 0,
                    'went_to_overtime' => false,
                    'went_to_shootout' => false,
                    'home_shootout_score' => 0,
                    'away_shootout_score' => 0,
                    'winner_team_id' => $isBye ? $spec['home_team_id'] : null,
                    'started_at' => $isBye ? $now : null,
                    'finished_at' => $isBye ? $now : null,
                ];

                if ($existingSlot !== null)
                {
                    $existingSlot->fill($attributes);
                    $existingSlot->save();
                    $upsertedBySlotKey[$spec['slot_key']] = $existingSlot;
                }
                else
                {
                    $upsertedBySlotKey[$spec['slot_key']] = ChampionshipGame::withoutGlobalScope('team')->create($attributes);
                }
            }

            foreach ($upsertedBySlotKey as $slotKey => $slotGame)
            {
                if ($slotGame->is_bye && $slotGame->winner_team_id !== null)
                {
                    $this->advanceKnockoutSlot($locked, $slotKey, (int) $slotGame->winner_team_id, null);
                }
            }

            $locked->status = ChampionshipStatus::KnockoutStage;
            $locked->bracket_generated_at = $now;
            $locked->completed_at = null;
            $locked->save();

            ChampionshipAuditLog::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => null,
                'actor_user_id' => (int) $actor->id,
                'action' => 'bracket_generated',
                'reason' => null,
                'before_snapshot' => null,
                'after_snapshot' => [
                    'status' => $locked->status->value,
                    'slots_count' => count($slotSpecs),
                    'slots' => array_map(
                        static fn (array $s): array => [
                            'slot_key' => $s['slot_key'],
                            'phase' => $s['phase']->value,
                            'home_source' => $s['home_source'],
                            'away_source' => $s['away_source'],
                            'home_team_id' => $s['home_team_id'],
                            'away_team_id' => $s['away_team_id'],
                            'is_bye' => $s['is_bye'],
                        ],
                        $slotSpecs,
                    ),
                ],
                'recorded_at' => $now,
            ]);

            $this->syncModel($championship, $locked);

            return $locked;
        });
    }

    /**
     * @return array<string, GroupStandings>
     */
    public function standings(Championship $championship): array
    {
        $this->assertTenantAccess($championship);

        return $this->standingsForLocked($championship);
    }

    public function summary(Championship $championship): ChampionshipSummary
    {
        $this->assertTenantAccess($championship);

        $standingsByGroup = $this->standingsForLocked($championship);

        $teams = ChampionshipTeam::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->get()
            ->keyBy('id');

        $players = ChampionshipPlayer::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->orderBy('name')
            ->get();

        $allGames = ChampionshipGame::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->get();

        $playableGames = $allGames->where('is_bye', false)->values();
        $finishedPlayableGames = $playableGames->where('status', GameStatus::Finished)->values();

        $totalGames = $playableGames->count();
        $finishedGames = $finishedPlayableGames->count();

        $gamesPlayedByTeam = [];
        foreach ($finishedPlayableGames as $game)
        {
            if ($game->home_team_id !== null)
            {
                $homeId = (int) $game->home_team_id;
                $gamesPlayedByTeam[$homeId] = ($gamesPlayedByTeam[$homeId] ?? 0) + 1;
            }
            if ($game->away_team_id !== null)
            {
                $awayId = (int) $game->away_team_id;
                $gamesPlayedByTeam[$awayId] = ($gamesPlayedByTeam[$awayId] ?? 0) + 1;
            }
        }

        $finishedGameIds = $finishedPlayableGames->pluck('id')->all();

        $goalEvents = $finishedGameIds === []
            ? collect()
            : ChampionshipGameEvent::withoutGlobalScope('team')
                ->where('championship_id', $championship->id)
                ->whereIn('championship_game_id', $finishedGameIds)
                ->where('type', GameEventType::Goal)
                ->whereNull('deleted_at')
                ->get();

        $totalGoals = $goalEvents->count();

        /** @var array<int, int> $goalsByPlayer */
        $goalsByPlayer = [];
        /** @var array<int, int> $assistsByPlayer */
        $assistsByPlayer = [];

        foreach ($goalEvents as $event)
        {
            if ($event->primary_player_id !== null)
            {
                $scorerId = (int) $event->primary_player_id;
                $goalsByPlayer[$scorerId] = ($goalsByPlayer[$scorerId] ?? 0) + 1;
            }

            if ($event->first_assist_player_id !== null)
            {
                $a1 = (int) $event->first_assist_player_id;
                $assistsByPlayer[$a1] = ($assistsByPlayer[$a1] ?? 0) + 1;
            }

            if ($event->second_assist_player_id !== null)
            {
                $a2 = (int) $event->second_assist_player_id;
                $assistsByPlayer[$a2] = ($assistsByPlayer[$a2] ?? 0) + 1;
            }
        }

        /** @var list<PlayerLeaderRow> $leaderRows */
        $leaderRows = [];

        foreach ($players as $player)
        {
            $playerId = (int) $player->id;
            $teamId = (int) $player->championship_team_id;
            $goals = $goalsByPlayer[$playerId] ?? 0;
            $assists = $assistsByPlayer[$playerId] ?? 0;
            $points = $goals + $assists;

            if ($points === 0)
            {
                continue;
            }

            /** @var ChampionshipTeam|null $team */
            $team = $teams->get($teamId);

            $leaderRows[] = new PlayerLeaderRow(
                playerId: $playerId,
                playerName: $player->name,
                jerseyNumber: (int) $player->jersey_number,
                teamId: $teamId,
                teamName: $team !== null ? $team->name : '',
                teamLogoUrl: $team?->logo_url,
                goals: $goals,
                assists: $assists,
                points: $points,
                gamesPlayed: $gamesPlayedByTeam[$teamId] ?? 0,
            );
        }

        $topScorers = array_values(array_filter(
            $leaderRows,
            static fn (PlayerLeaderRow $row): bool => $row->goals > 0,
        ));
        usort(
            $topScorers,
            static fn (PlayerLeaderRow $a, PlayerLeaderRow $b): int => [
                $b->goals,
                $b->assists,
                -$b->gamesPlayed,
            ] <=> [
                $a->goals,
                $a->assists,
                -$a->gamesPlayed,
            ] ?: strcmp($a->playerName, $b->playerName),
        );

        $topAssists = array_values(array_filter(
            $leaderRows,
            static fn (PlayerLeaderRow $row): bool => $row->assists > 0,
        ));
        usort(
            $topAssists,
            static fn (PlayerLeaderRow $a, PlayerLeaderRow $b): int => [
                $b->assists,
                $b->goals,
                -$b->gamesPlayed,
            ] <=> [
                $a->assists,
                $a->goals,
                -$a->gamesPlayed,
            ] ?: strcmp($a->playerName, $b->playerName),
        );

        $topPointLeaders = $leaderRows;
        usort(
            $topPointLeaders,
            static fn (PlayerLeaderRow $a, PlayerLeaderRow $b): int => [
                $b->points,
                $b->goals,
                $b->assists,
                -$b->gamesPlayed,
            ] <=> [
                $a->points,
                $a->goals,
                $a->assists,
                -$a->gamesPlayed,
            ] ?: strcmp($a->playerName, $b->playerName),
        );

        $podium = $this->resolvePodium($allGames->all());

        return new ChampionshipSummary(
            standingsByGroup: $standingsByGroup,
            podium: $podium,
            totalGames: $totalGames,
            finishedGames: $finishedGames,
            totalGoals: $totalGoals,
            topScorers: $topScorers,
            topAssists: $topAssists,
            topPointLeaders: $topPointLeaders,
        );
    }

    /**
     * @param  list<ChampionshipGame>  $allGames
     */
    private function resolvePodium(array $allGames): Podium
    {
        $finalGame = null;
        $thirdPlaceGame = null;

        foreach ($allGames as $game)
        {
            if ($game->phase === GamePhase::Final || $game->slot_key === 'FINAL')
            {
                $finalGame = $game;
            }
            elseif ($game->phase === GamePhase::ThirdPlace || $game->slot_key === '3RD')
            {
                $thirdPlaceGame = $game;
            }
        }

        $championTeamId = null;
        $runnerUpTeamId = null;
        $thirdPlaceTeamId = null;

        if (
            $finalGame !== null
            && $finalGame->status === GameStatus::Finished
            && $finalGame->winner_team_id !== null
            && $finalGame->home_team_id !== null
            && $finalGame->away_team_id !== null
        ) {
            $championTeamId = (int) $finalGame->winner_team_id;
            $runnerUpTeamId = $championTeamId === (int) $finalGame->home_team_id
                ? (int) $finalGame->away_team_id
                : (int) $finalGame->home_team_id;
        }

        if (
            $thirdPlaceGame !== null
            && $thirdPlaceGame->status === GameStatus::Finished
            && $thirdPlaceGame->winner_team_id !== null
        ) {
            $thirdPlaceTeamId = (int) $thirdPlaceGame->winner_team_id;
        }

        return new Podium(
            championTeamId: $championTeamId,
            runnerUpTeamId: $runnerUpTeamId,
            thirdPlaceTeamId: $thirdPlaceTeamId,
        );
    }

    /**
     * @return array<string, GroupStandings>
     */
    private function standingsForLocked(Championship $championship): array
    {
        $teams = ChampionshipTeam::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->whereNotNull('group_code')
            ->get();

        $games = ChampionshipGame::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->where('phase', GamePhase::Group)
            ->get();

        $manualResolutions = is_array($championship->manual_tie_resolutions)
            ? $championship->manual_tie_resolutions
            : [];

        return $this->standingsCalculator->calculate(
            rules: $championship->rules,
            teams: $teams,
            games: $games,
            manualResolutions: $manualResolutions,
        );
    }

    private function recomputeGameScores(ChampionshipGame $game): void
    {
        $activeEvents = ChampionshipGameEvent::withoutGlobalScope('team')
            ->where('championship_game_id', $game->id)
            ->whereNull('deleted_at')
            ->get();

        $homeRegular = 0;
        $awayRegular = 0;
        $homeShootout = 0;
        $awayShootout = 0;
        $wentToOvertime = false;
        $wentToShootout = false;

        foreach ($activeEvents as $event)
        {
            if ($event->is_overtime || $event->period_mark_type === 'overtime_start')
            {
                $wentToOvertime = true;
            }

            if ($event->type === GameEventType::Goal)
            {
                if ($event->side === TeamSide::Home)
                {
                    $homeRegular++;
                }
                elseif ($event->side === TeamSide::Away)
                {
                    $awayRegular++;
                }
            }
            elseif ($event->type === GameEventType::ShootoutAttempt)
            {
                $wentToShootout = true;

                if ($event->shootout_scored)
                {
                    if ($event->side === TeamSide::Home)
                    {
                        $homeShootout++;
                    }
                    elseif ($event->side === TeamSide::Away)
                    {
                        $awayShootout++;
                    }
                }
            }
            elseif ($event->period_mark_type === 'shootout_start')
            {
                $wentToShootout = true;
            }
        }

        $game->home_regular_score = $homeRegular;
        $game->away_regular_score = $awayRegular;
        $game->went_to_overtime = $wentToOvertime;
        $game->went_to_shootout = $wentToShootout;
        $game->home_shootout_score = $homeShootout;
        $game->away_shootout_score = $awayShootout;
        $game->save();
    }

    private function advanceKnockoutSlot(
        Championship $championship,
        string $slotKey,
        int $winnerTeamId,
        ?int $loserTeamId,
    ): void {
        $winnerSource = "winner:{$slotKey}";

        ChampionshipGame::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->where('home_source', $winnerSource)
            ->update(['home_team_id' => $winnerTeamId]);

        ChampionshipGame::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->where('away_source', $winnerSource)
            ->update(['away_team_id' => $winnerTeamId]);

        if ($loserTeamId !== null)
        {
            $loserSource = "loser:{$slotKey}";

            ChampionshipGame::withoutGlobalScope('team')
                ->where('championship_id', $championship->id)
                ->where('home_source', $loserSource)
                ->update(['home_team_id' => $loserTeamId]);

            ChampionshipGame::withoutGlobalScope('team')
                ->where('championship_id', $championship->id)
                ->where('away_source', $loserSource)
                ->update(['away_team_id' => $loserTeamId]);
        }
    }

    private function checkAndCompleteChampionshipIfDone(Championship $championship, CarbonImmutable $now): void
    {
        $knockoutGames = ChampionshipGame::withoutGlobalScope('team')
            ->where('championship_id', $championship->id)
            ->where('phase', '!=', GamePhase::Group)
            ->get();

        if ($knockoutGames->isEmpty())
        {
            return;
        }

        $finalGame = $knockoutGames->first(
            static fn (ChampionshipGame $g): bool => $g->phase === GamePhase::Final || $g->slot_key === 'FINAL',
        );

        if ($finalGame === null || $finalGame->status !== GameStatus::Finished)
        {
            return;
        }

        if ($championship->rules->hasThirdPlaceMatch)
        {
            $thirdGame = $knockoutGames->first(
                static fn (ChampionshipGame $g): bool => $g->phase === GamePhase::ThirdPlace || $g->slot_key === '3RD',
            );

            if ($thirdGame !== null && $thirdGame->status !== GameStatus::Finished)
            {
                return;
            }
        }

        $allFinished = $knockoutGames->every(
            static fn (ChampionshipGame $g): bool => $g->status === GameStatus::Finished,
        );

        if ($allFinished)
        {
            $championship->status = ChampionshipStatus::Completed;
            $championship->completed_at = $now;
            $championship->save();
        }
    }

    /**
     * @param  list<ChampionshipTeam>  $teams
     * @param  array<string, list<int>>  $explicitGroups
     * @return array<string, list<ChampionshipTeam>>
     */
    private function validateAndNormalizeExplicitGroups(
        array $teams,
        array $explicitGroups,
        ChampionshipRules $rules,
    ): array {
        if (count($explicitGroups) !== $rules->groupsCount)
        {
            throw new ChampionshipException("O sorteio exige exatamente {$rules->groupsCount} grupo(s).");
        }

        $teamById = [];
        foreach ($teams as $team)
        {
            $teamById[(int) $team->id] = $team;
        }

        $seenIds = [];
        $result = [];

        ksort($explicitGroups);

        foreach ($explicitGroups as $groupCode => $teamIds)
        {
            $normalizedCode = trim((string) $groupCode);
            if ($normalizedCode === '' || ! is_array($teamIds))
            {
                throw new ChampionshipException('Estrutura de grupos inválida.');
            }

            $groupTeamModels = [];
            foreach ($teamIds as $rawId)
            {
                $teamId = (int) $rawId;
                if (! isset($teamById[$teamId]))
                {
                    throw new ChampionshipException("A equipe #{$teamId} não pertence a este campeonato.");
                }
                if (isset($seenIds[$teamId]))
                {
                    throw new ChampionshipException("A equipe #{$teamId} foi atribuída a mais de um grupo.");
                }

                $seenIds[$teamId] = true;
                $groupTeamModels[] = $teamById[$teamId];
            }

            $result[$normalizedCode] = $groupTeamModels;
        }

        if (count($seenIds) !== count($teams))
        {
            throw new ChampionshipException('Todas as equipes inscritas devem participar do sorteio dos grupos.');
        }

        return $result;
    }

    /**
     * @param  list<ChampionshipTeam>  $teams
     * @return array<string, list<ChampionshipTeam>>
     */
    private function performRandomSeededGroupDraw(array $teams, int $groupsCount): array
    {
        /** @var array<int, list<ChampionshipTeam>> $pots */
        $pots = [];
        foreach ($teams as $team)
        {
            $potKey = $team->seed_pot !== null && $team->seed_pot > 0 ? (int) $team->seed_pot : PHP_INT_MAX;
            $pots[$potKey][] = $team;
        }

        ksort($pots, SORT_NUMERIC);

        /** @var list<ChampionshipTeam> $orderedPool */
        $orderedPool = [];
        foreach ($pots as $potTeams)
        {
            $shuffled = $this->cryptoShuffle($potTeams);
            foreach ($shuffled as $team)
            {
                $orderedPool[] = $team;
            }
        }

        $groupCodes = [];
        for ($i = 0; $i < $groupsCount; $i++)
        {
            $groupCodes[] = chr(65 + $i);
        }

        /** @var array<string, list<ChampionshipTeam>> $groups */
        $groups = [];
        foreach ($groupCodes as $code)
        {
            $groups[$code] = [];
        }

        foreach ($orderedPool as $index => $team)
        {
            $pass = intdiv($index, $groupsCount);
            $offset = $index % $groupsCount;
            $groupIndex = ($pass % 2 === 0) ? $offset : ($groupsCount - 1 - $offset);
            $groups[$groupCodes[$groupIndex]][] = $team;
        }

        return $groups;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    private function cryptoShuffle(array $items): array
    {
        $copy = array_values($items);
        $count = count($copy);

        for ($i = $count - 1; $i > 0; $i--)
        {
            $j = random_int(0, $i);
            $tmp = $copy[$i];
            $copy[$i] = $copy[$j];
            $copy[$j] = $tmp;
        }

        return $copy;
    }

    /**
     * Circle method (Berger tables) round-robin generator.
     *
     * @param  list<ChampionshipTeam>  $groupTeams
     * @return list<array{
     *     round_number: int,
     *     leg_number: int,
     *     slot_key: string,
     *     home_source: string,
     *     away_source: string,
     *     home_team_id: int,
     *     away_team_id: int
     * }>
     */
    private function buildRoundRobinFixtures(string $groupCode, array $groupTeams, bool $doubleRoundRobin): array
    {
        /** @var list<ChampionshipTeam|null> $rotation */
        $rotation = array_values($groupTeams);
        if (count($rotation) % 2 === 1)
        {
            $rotation[] = null;
        }

        $n = count($rotation);
        $roundsPerLeg = $n - 1;
        $half = intdiv($n, 2);

        $leg1Fixtures = [];

        for ($round = 1; $round <= $roundsPerLeg; $round++)
        {
            for ($i = 0; $i < $half; $i++)
            {
                $first = $rotation[$i];
                $second = $rotation[$n - 1 - $i];

                if ($first === null || $second === null)
                {
                    continue;
                }

                $swapHomeAway = ($i === 0 && $round % 2 === 0) || ($i > 0 && ($round + $i) % 2 === 1);
                $home = $swapHomeAway ? $second : $first;
                $away = $swapHomeAway ? $first : $second;

                $homeId = (int) $home->id;
                $awayId = (int) $away->id;
                $minId = min($homeId, $awayId);
                $maxId = max($homeId, $awayId);

                $leg1Fixtures[] = [
                    'round_number' => $round,
                    'leg_number' => 1,
                    'slot_key' => "G-{$groupCode}-L1-{$minId}-{$maxId}",
                    'home_source' => "team:{$homeId}",
                    'away_source' => "team:{$awayId}",
                    'home_team_id' => $homeId,
                    'away_team_id' => $awayId,
                ];
            }

            $last = array_pop($rotation);
            array_splice($rotation, 1, 0, [$last]);
        }

        if (! $doubleRoundRobin)
        {
            return $leg1Fixtures;
        }

        $allFixtures = $leg1Fixtures;

        foreach ($leg1Fixtures as $leg1)
        {
            $homeId = $leg1['away_team_id'];
            $awayId = $leg1['home_team_id'];
            $minId = min($homeId, $awayId);
            $maxId = max($homeId, $awayId);

            $allFixtures[] = [
                'round_number' => $roundsPerLeg + $leg1['round_number'],
                'leg_number' => 2,
                'slot_key' => "G-{$groupCode}-L2-{$minId}-{$maxId}",
                'home_source' => "team:{$homeId}",
                'away_source' => "team:{$awayId}",
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
            ];
        }

        return $allFixtures;
    }

    /**
     * @param  list<ChampionshipTeam>  $teams
     * @param  array<string, list<int>>  $explicitGroups
     */
    private function matchesCurrentGroupAssignment(array $teams, array $explicitGroups): bool
    {
        $current = $this->currentGroupMap($teams);

        ksort($current);
        $normalizedExplicit = [];
        foreach ($explicitGroups as $code => $ids)
        {
            $normalizedExplicit[trim((string) $code)] = array_values(array_map('intval', $ids));
        }
        ksort($normalizedExplicit);

        return $current === $normalizedExplicit;
    }

    /**
     * @param  list<ChampionshipTeam>  $teams
     * @return array<string, list<int>>
     */
    private function currentGroupMap(array $teams): array
    {
        $byGroup = [];
        foreach ($teams as $team)
        {
            if ($team->group_code === null || $team->group_code === '')
            {
                continue;
            }
            $byGroup[$team->group_code][] = $team;
        }

        ksort($byGroup);

        $result = [];
        foreach ($byGroup as $code => $groupTeams)
        {
            usort(
                $groupTeams,
                static fn (ChampionshipTeam $a, ChampionshipTeam $b): int => [
                    $a->group_draw_order ?? PHP_INT_MAX,
                    $a->id,
                ] <=> [
                    $b->group_draw_order ?? PHP_INT_MAX,
                    $b->id,
                ],
            );

            $result[$code] = array_values(array_map(
                static fn (ChampionshipTeam $t): int => (int) $t->id,
                $groupTeams,
            ));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function gameSnapshot(ChampionshipGame $game, ChampionshipStatus $championshipStatus): array
    {
        return [
            'game_id' => $game->id,
            'slot_key' => $game->slot_key,
            'phase' => $game->phase->value,
            'status' => $game->status->value,
            'home_team_id' => $game->home_team_id,
            'away_team_id' => $game->away_team_id,
            'home_regular_score' => $game->home_regular_score,
            'away_regular_score' => $game->away_regular_score,
            'went_to_overtime' => $game->went_to_overtime,
            'went_to_shootout' => $game->went_to_shootout,
            'home_shootout_score' => $game->home_shootout_score,
            'away_shootout_score' => $game->away_shootout_score,
            'winner_team_id' => $game->winner_team_id,
            'finished_at' => $game->finished_at?->toIso8601String(),
            'championship_status' => $championshipStatus->value,
        ];
    }

    private function lockChampionship(Championship|int $championship): Championship
    {
        $id = $championship instanceof Championship ? $championship->id : $championship;

        /** @var Championship|null $locked */
        $locked = Championship::withoutGlobalScope('team')
            ->whereKey($id)
            ->lockForUpdate()
            ->first();

        if ($locked === null)
        {
            throw new ChampionshipException('Campeonato não encontrado.');
        }

        $this->assertTenantAccess($locked);

        return $locked;
    }

    private function lockGame(Championship $lockedChampionship, ChampionshipGame $game): ChampionshipGame
    {
        /** @var ChampionshipGame|null $lockedGame */
        $lockedGame = ChampionshipGame::withoutGlobalScope('team')
            ->where('championship_id', $lockedChampionship->id)
            ->whereKey($game->id)
            ->lockForUpdate()
            ->first();

        if ($lockedGame === null)
        {
            throw new ChampionshipException('A partida informada não pertence a este campeonato.');
        }

        return $lockedGame;
    }

    private function assertTenantAccess(Championship $championship): void
    {
        $authId = auth()->id();
        if ($authId !== null && (int) $championship->user_id !== (int) $authId)
        {
            throw new ChampionshipException('Este campeonato pertence a outra conta.');
        }
    }

    private function validateRules(ChampionshipRules $rules): void
    {
        if ($rules->groupsCount < 1 || $rules->groupsCount > 8)
        {
            throw new ChampionshipException('O número de grupos deve estar entre 1 e 8.');
        }

        if ($rules->qualifiersPerGroup < 1 || $rules->qualifiersPerGroup > 16)
        {
            throw new ChampionshipException('O número de classificados por grupo deve ser pelo menos 1.');
        }

        if ($rules->extraBestQualifiers < 0)
        {
            throw new ChampionshipException('O número de melhores classificados extras não pode ser negativo.');
        }

        $totalQualifiers = $rules->totalQualifiers();
        if ($totalQualifiers < 2 || $totalQualifiers > 16)
        {
            throw new ChampionshipException('O total de equipes classificadas para o mata-mata deve estar entre 2 e 16.');
        }

        if ($rules->maxAssistsPerGoal < 0 || $rules->maxAssistsPerGoal > 2)
        {
            throw new ChampionshipException('O máximo de assistências por gol deve ser entre 0 e 2.');
        }

        if ($rules->periodMinutes < 1 || $rules->periodsCount < 1 || $rules->overtimeMinutes < 1)
        {
            throw new ChampionshipException('As configurações de tempo e períodos devem ser maiores que zero.');
        }
    }

    private function validateHexColor(string $color, string $label): void
    {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1)
        {
            throw new ChampionshipException("Cor {$label} inválida. Use o formato hexadecimal #RRGGBB.");
        }
    }

    private function assertRasterLogo(UploadedFile $logo): void
    {
        $clientExt = strtolower($logo->getClientOriginalExtension());
        $guessedExt = strtolower((string) $logo->extension());
        $mime = strtolower((string) $logo->getMimeType());
        $clientMime = strtolower((string) $logo->getClientMimeType());

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

        if (
            $clientExt === 'svg'
            || $guessedExt === 'svg'
            || str_contains($mime, 'svg')
            || str_contains($clientMime, 'svg')
        ) {
            throw new ChampionshipException('Arquivos SVG não são permitidos para o escudo da equipe. Envie JPG, PNG ou WEBP.');
        }

        $extToCheck = $clientExt !== '' ? $clientExt : $guessedExt;
        if (! in_array($extToCheck, $allowedExtensions, true))
        {
            throw new ChampionshipException('Formato de escudo inválido. Envie apenas imagens JPG, PNG ou WEBP.');
        }

        if ($mime !== '' && ! in_array($mime, $allowedMimes, true))
        {
            throw new ChampionshipException('Formato de imagem inválido para o escudo. Envie apenas JPG, PNG ou WEBP.');
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  TModel  $target
     * @param  TModel  $source
     */
    private function syncModel(object $target, object $source): void
    {
        if ($target !== $source && $target instanceof Model && $source instanceof Model)
        {
            $target->setRawAttributes($source->getAttributes(), true);
        }
    }
}
