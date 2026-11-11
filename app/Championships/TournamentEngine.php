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
use Carbon\CarbonInterface;
use DomainException;
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

    /**
     * @param  User|int|array<string, mixed>  $user
     * @param  string|array<string, mixed>  $name
     * @param  ChampionshipRules|array<string, mixed>|null  $rules
     */
    public function createChampionship(
        User|int|array $user,
        string|array $name = '',
        string $city = '',
        ?string $venue = null,
        CarbonInterface|string|null $startsOn = null,
        CarbonInterface|string|null $endsOn = null,
        ChampionshipRules|array|null $rules = null,
    ): Championship {
        if (is_array($user))
        {
            $payload = $user;
            $resolvedUserId = isset($payload['user_id'])
                ? (int) $payload['user_id']
                : (int) (auth()->id() ?? 0);
            $name = (string) ($payload['name'] ?? '');
            $city = (string) ($payload['city'] ?? '');
            $venue = isset($payload['venue']) ? (string) $payload['venue'] : null;
            $startsOn = $payload['starts_on'] ?? $payload['startsOn'] ?? null;
            $endsOn = $payload['ends_on'] ?? $payload['endsOn'] ?? null;
            $rules = $payload['rules'] ?? null;
        }
        elseif (is_array($name))
        {
            $payload = $name;
            $resolvedUserId = $user instanceof User ? (int) $user->id : $user;
            $name = (string) ($payload['name'] ?? '');
            $city = (string) ($payload['city'] ?? $city);
            $venue = array_key_exists('venue', $payload) ? ($payload['venue'] !== null ? (string) $payload['venue'] : null) : $venue;
            $startsOn = $payload['starts_on'] ?? $payload['startsOn'] ?? $startsOn;
            $endsOn = $payload['ends_on'] ?? $payload['endsOn'] ?? $endsOn;
            $rules = $payload['rules'] ?? $rules;
        }
        else
        {
            $resolvedUserId = $user instanceof User ? (int) $user->id : $user;
        }

        if ($resolvedUserId <= 0)
        {
            throw new DomainException('Usuário organizador inválido para criação do campeonato.');
        }

        $trimmedName = trim($name);
        $trimmedCity = trim($city);
        $trimmedVenue = $venue !== null && trim($venue) !== '' ? trim($venue) : null;

        if ($trimmedName === '')
        {
            throw new DomainException('Informe o nome do campeonato.');
        }

        if ($trimmedCity === '')
        {
            throw new DomainException('Informe a cidade do campeonato.');
        }

        $parsedStartsOn = $this->parseDate($startsOn);
        $parsedEndsOn = $this->parseDate($endsOn);

        if ($parsedStartsOn !== null && $parsedEndsOn !== null && $parsedEndsOn->lt($parsedStartsOn))
        {
            throw new DomainException('A data de encerramento não pode ser anterior à data de início.');
        }

        $resolvedRules = $this->normalizeRules($rules);
        $this->validateRules($resolvedRules);

        return DB::transaction(function () use (
            $resolvedUserId,
            $trimmedName,
            $trimmedCity,
            $trimmedVenue,
            $parsedStartsOn,
            $parsedEndsOn,
            $resolvedRules,
        ): Championship {
            $championship = Championship::withoutGlobalScope('team')->create([
                'user_id' => $resolvedUserId,
                'name' => $trimmedName,
                'city' => $trimmedCity,
                'venue' => $trimmedVenue,
                'starts_on' => $parsedStartsOn?->toDateString(),
                'ends_on' => $parsedEndsOn?->toDateString(),
                'status' => ChampionshipStatus::Draft,
                'rules' => $resolvedRules,
                'manual_tie_resolutions' => [],
            ]);

            return $this->lockChampionship($championship);
        });
    }

    /**
     * @param  string|array<string, mixed>  $name
     */
    public function updateDetails(
        Championship $championship,
        string|array $name,
        ?string $city = null,
        ?string $venue = null,
        CarbonInterface|string|null $startsOn = null,
        CarbonInterface|string|null $endsOn = null,
    ): Championship {
        return DB::transaction(function () use ($championship, $name, $city, $venue, $startsOn, $endsOn): Championship {
            $locked = $this->lockChampionship($championship);

            if (is_array($name))
            {
                $payload = $name;
                $resolvedName = array_key_exists('name', $payload) ? (string) $payload['name'] : $locked->name;
                $resolvedCity = array_key_exists('city', $payload) ? (string) $payload['city'] : $locked->city;
                $resolvedVenue = array_key_exists('venue', $payload)
                    ? ($payload['venue'] !== null ? (string) $payload['venue'] : null)
                    : $locked->venue;
                $resolvedStartsOn = array_key_exists('starts_on', $payload) || array_key_exists('startsOn', $payload)
                    ? $this->parseDate($payload['starts_on'] ?? $payload['startsOn'] ?? null)
                    : $locked->starts_on;
                $resolvedEndsOn = array_key_exists('ends_on', $payload) || array_key_exists('endsOn', $payload)
                    ? $this->parseDate($payload['ends_on'] ?? $payload['endsOn'] ?? null)
                    : $locked->ends_on;
            }
            else
            {
                $resolvedName = $name;
                $resolvedCity = $city ?? $locked->city;
                $resolvedVenue = $venue;
                $resolvedStartsOn = $startsOn !== null ? $this->parseDate($startsOn) : $locked->starts_on;
                $resolvedEndsOn = $endsOn !== null ? $this->parseDate($endsOn) : $locked->ends_on;
            }

            $trimmedName = trim($resolvedName);
            $trimmedCity = trim($resolvedCity);
            $trimmedVenue = $resolvedVenue !== null && trim($resolvedVenue) !== '' ? trim($resolvedVenue) : null;

            if ($trimmedName === '')
            {
                throw new DomainException('Informe o nome do campeonato.');
            }

            if ($trimmedCity === '')
            {
                throw new DomainException('Informe a cidade do campeonato.');
            }

            if ($resolvedStartsOn !== null && $resolvedEndsOn !== null && $resolvedEndsOn->lt($resolvedStartsOn))
            {
                throw new DomainException('A data de encerramento não pode ser anterior à data de início.');
            }

            $locked->name = $trimmedName;
            $locked->city = $trimmedCity;
            $locked->venue = $trimmedVenue;
            $locked->starts_on = $resolvedStartsOn;
            $locked->ends_on = $resolvedEndsOn;
            $locked->save();

            $this->syncModel($championship, $locked);

            return $locked;
        });
    }

    /**
     * @param  ChampionshipRules|array<string, mixed>  $rules
     */
    public function updateRules(Championship $championship, ChampionshipRules|array $rules): Championship
    {
        return DB::transaction(function () use ($championship, $rules): Championship {
            $locked = $this->lockChampionship($championship);

            if ($locked->status !== ChampionshipStatus::Draft)
            {
                throw new DomainException('As regras do campeonato estão travadas e só podem ser editadas antes do sorteio dos grupos.');
            }

            $resolvedRules = $this->normalizeRules($rules);
            $this->validateRules($resolvedRules);

            $locked->rules = $resolvedRules;
            $locked->save();

            $this->syncModel($championship, $locked);

            return $locked;
        });
    }

    /**
     * @param  string|array<string, mixed>  $name
     */
    public function enrollTeam(
        Championship $championship,
        string|array $name,
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
                throw new DomainException('Não é possível inscrever novas equipes após o sorteio dos grupos.');
            }

            if (is_array($name))
            {
                $payload = $name;
                $resolvedName = (string) ($payload['name'] ?? '');
                $resolvedShortName = isset($payload['short_name']) || isset($payload['shortName'])
                    ? (string) ($payload['short_name'] ?? $payload['shortName'])
                    : $shortName;
                $resolvedPrimaryColor = (string) ($payload['primary_color'] ?? $payload['primaryColor'] ?? $primaryColor);
                $resolvedSecondaryColor = (string) ($payload['secondary_color'] ?? $payload['secondaryColor'] ?? $secondaryColor);
                $resolvedLogo = ($payload['logo'] ?? null) instanceof UploadedFile ? $payload['logo'] : $logo;
                $resolvedSeedPot = isset($payload['seed_pot']) || isset($payload['seedPot'])
                    ? (int) ($payload['seed_pot'] ?? $payload['seedPot'])
                    : $seedPot;
            }
            else
            {
                $resolvedName = $name;
                $resolvedShortName = $shortName;
                $resolvedPrimaryColor = $primaryColor;
                $resolvedSecondaryColor = $secondaryColor;
                $resolvedLogo = $logo;
                $resolvedSeedPot = $seedPot;
            }

            $trimmedName = trim($resolvedName);
            if ($trimmedName === '')
            {
                throw new DomainException('Informe o nome da equipe.');
            }

            $trimmedShortName = $resolvedShortName !== null && trim($resolvedShortName) !== ''
                ? trim($resolvedShortName)
                : null;

            $this->validateHexColor($resolvedPrimaryColor, 'primária');
            $this->validateHexColor($resolvedSecondaryColor, 'secundária');

            if ($resolvedSeedPot !== null && $resolvedSeedPot < 1)
            {
                throw new DomainException('O pote de sorteio deve ser um número inteiro positivo.');
            }

            $duplicateExists = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->where('name', $trimmedName)
                ->exists();

            if ($duplicateExists)
            {
                throw new DomainException("Já existe uma equipe inscrita com o nome \"{$trimmedName}\" neste campeonato.");
            }

            $logoPath = null;
            if ($resolvedLogo !== null)
            {
                $this->assertRasterLogo($resolvedLogo);
                $stored = $resolvedLogo->store("championships/{$locked->user_id}/logos", 'public');
                if ($stored === false)
                {
                    throw new DomainException('Não foi possível salvar o escudo da equipe.');
                }
                $logoPath = $stored;
            }

            return ChampionshipTeam::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'name' => $trimmedName,
                'short_name' => $trimmedShortName,
                'primary_color' => $resolvedPrimaryColor,
                'secondary_color' => $resolvedSecondaryColor,
                'logo_path' => $logoPath,
                'seed_pot' => $resolvedSeedPot,
            ]);
        });
    }

    /**
     * @param  ChampionshipTeam|string|array<string, mixed>|null  $name
     * @param  string|array<string, mixed>|null  $shortName
     */
    public function updateTeam(
        ChampionshipTeam|Championship $team,
        ChampionshipTeam|string|array|null $name = null,
        string|array|null $shortName = null,
        ?string $primaryColor = null,
        ?string $secondaryColor = null,
        ?UploadedFile $logo = null,
        ?int $seedPot = null,
        bool $removeLogo = false,
        ?Championship $championship = null,
    ): ChampionshipTeam {
        $extraArgs = func_get_args();

        return DB::transaction(function () use (
            $team,
            $name,
            $shortName,
            $primaryColor,
            $secondaryColor,
            $logo,
            $seedPot,
            $removeLogo,
            $championship,
            $extraArgs,
        ): ChampionshipTeam {
            if ($team instanceof Championship)
            {
                if (! $name instanceof ChampionshipTeam)
                {
                    throw new DomainException('Equipe inválida para atualização.');
                }

                $targetChampionship = $team;
                $targetTeam = $name;
                $rawName = $shortName;
                $rawShortName = $primaryColor;
                $rawPrimaryColor = $secondaryColor;
                $rawSecondaryColor = isset($extraArgs[5]) && is_string($extraArgs[5]) ? $extraArgs[5] : null;
                $rawLogo = ($extraArgs[6] ?? null) instanceof UploadedFile ? $extraArgs[6] : $logo;
                $rawSeedPot = isset($extraArgs[7]) && is_int($extraArgs[7]) ? $extraArgs[7] : $seedPot;
                $rawRemoveLogo = isset($extraArgs[8]) && is_bool($extraArgs[8]) ? $extraArgs[8] : $removeLogo;
            }
            else
            {
                $targetTeam = $team;
                $targetChampionship = $championship;
                $rawName = $name;
                $rawShortName = is_string($shortName) ? $shortName : null;
                $rawPrimaryColor = $primaryColor;
                $rawSecondaryColor = $secondaryColor;
                $rawLogo = $logo;
                $rawSeedPot = $seedPot;
                $rawRemoveLogo = $removeLogo;
            }

            $locked = $this->lockChampionship($targetChampionship ?? (int) $targetTeam->championship_id);

            /** @var ChampionshipTeam|null $lockedTeam */
            $lockedTeam = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereKey($targetTeam->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTeam === null)
            {
                throw new DomainException('A equipe informada não pertence a este campeonato.');
            }

            $seedPotProvided = false;

            if (is_array($rawName))
            {
                $payload = $rawName;
                $resolvedName = array_key_exists('name', $payload) ? (string) $payload['name'] : $lockedTeam->name;
                $resolvedShortName = array_key_exists('short_name', $payload) || array_key_exists('shortName', $payload)
                    ? ($payload['short_name'] ?? $payload['shortName'])
                    : $lockedTeam->short_name;
                $resolvedPrimaryColor = (string) ($payload['primary_color'] ?? $payload['primaryColor'] ?? $lockedTeam->primary_color);
                $resolvedSecondaryColor = (string) ($payload['secondary_color'] ?? $payload['secondaryColor'] ?? $lockedTeam->secondary_color);
                $resolvedLogo = ($payload['logo'] ?? null) instanceof UploadedFile ? $payload['logo'] : $rawLogo;
                if (array_key_exists('seed_pot', $payload) || array_key_exists('seedPot', $payload))
                {
                    $seedPotProvided = true;
                    $rawPotVal = $payload['seed_pot'] ?? $payload['seedPot'];
                    $resolvedSeedPot = $rawPotVal !== null ? (int) $rawPotVal : null;
                }
                else
                {
                    $resolvedSeedPot = $lockedTeam->seed_pot;
                }
                $resolvedRemoveLogo = (bool) ($payload['remove_logo'] ?? $payload['removeLogo'] ?? $rawRemoveLogo);
            }
            else
            {
                $resolvedName = is_string($rawName) ? $rawName : $lockedTeam->name;
                $resolvedShortName = $rawShortName !== null ? $rawShortName : $lockedTeam->short_name;
                $resolvedPrimaryColor = $rawPrimaryColor ?? $lockedTeam->primary_color;
                $resolvedSecondaryColor = $rawSecondaryColor ?? $lockedTeam->secondary_color;
                $resolvedLogo = $rawLogo;
                if ($rawSeedPot !== null)
                {
                    $seedPotProvided = true;
                    $resolvedSeedPot = $rawSeedPot;
                }
                else
                {
                    $resolvedSeedPot = $lockedTeam->seed_pot;
                }
                $resolvedRemoveLogo = $rawRemoveLogo;
            }

            $trimmedName = trim($resolvedName);
            if ($trimmedName === '')
            {
                throw new DomainException('Informe o nome da equipe.');
            }

            $trimmedShortName = $resolvedShortName !== null && trim((string) $resolvedShortName) !== ''
                ? trim((string) $resolvedShortName)
                : null;

            $this->validateHexColor($resolvedPrimaryColor, 'primária');
            $this->validateHexColor($resolvedSecondaryColor, 'secundária');

            if ($seedPotProvided && $resolvedSeedPot !== null && $resolvedSeedPot < 1)
            {
                throw new DomainException('O pote de sorteio deve ser um número inteiro positivo.');
            }

            $duplicateExists = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->where('name', $trimmedName)
                ->whereKeyNot($lockedTeam->id)
                ->exists();

            if ($duplicateExists)
            {
                throw new DomainException("Já existe outra equipe inscrita com o nome \"{$trimmedName}\" neste campeonato.");
            }

            $logoPath = $lockedTeam->logo_path;
            if ($resolvedLogo !== null)
            {
                $this->assertRasterLogo($resolvedLogo);
                $stored = $resolvedLogo->store("championships/{$locked->user_id}/logos", 'public');
                if ($stored === false)
                {
                    throw new DomainException('Não foi possível salvar o escudo da equipe.');
                }

                if ($lockedTeam->logo_path !== null && $lockedTeam->logo_path !== '')
                {
                    Storage::disk('public')->delete($lockedTeam->logo_path);
                }

                $logoPath = $stored;
            }
            elseif ($resolvedRemoveLogo && $lockedTeam->logo_path !== null && $lockedTeam->logo_path !== '')
            {
                Storage::disk('public')->delete($lockedTeam->logo_path);
                $logoPath = null;
            }

            $lockedTeam->name = $trimmedName;
            $lockedTeam->short_name = $trimmedShortName;
            $lockedTeam->primary_color = $resolvedPrimaryColor;
            $lockedTeam->secondary_color = $resolvedSecondaryColor;
            $lockedTeam->logo_path = $logoPath;
            if ($locked->status === ChampionshipStatus::Draft && $seedPotProvided)
            {
                $lockedTeam->seed_pot = $resolvedSeedPot;
            }
            $lockedTeam->save();

            $this->syncModel($targetTeam, $lockedTeam);

            return $lockedTeam;
        });
    }

    public function removeTeam(
        ChampionshipTeam|Championship $team,
        ?ChampionshipTeam $targetTeam = null,
    ): void {
        DB::transaction(function () use ($team, $targetTeam): void {
            if ($team instanceof Championship)
            {
                if ($targetTeam === null)
                {
                    throw new DomainException('Equipe inválida para remoção.');
                }
                $championship = $team;
                $resolvedTeam = $targetTeam;
            }
            else
            {
                $championship = null;
                $resolvedTeam = $team;
            }

            $locked = $this->lockChampionship($championship ?? (int) $resolvedTeam->championship_id);

            if ($locked->status !== ChampionshipStatus::Draft)
            {
                throw new DomainException('Equipes não podem ser removidas após o sorteio dos grupos.');
            }

            /** @var ChampionshipTeam|null $lockedTeam */
            $lockedTeam = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereKey($resolvedTeam->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTeam === null)
            {
                throw new DomainException('A equipe informada não pertence a este campeonato.');
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

    /**
     * @param  ChampionshipTeam|string|array<string, mixed>  $name
     * @param  int|string|array<string, mixed>  $jerseyNumber
     */
    public function addPlayer(
        ChampionshipTeam|Championship $team,
        ChampionshipTeam|string|array $name = '',
        int|string|array $jerseyNumber = 0,
        PlayerPosition|string|int $position = PlayerPosition::Universal,
        bool|PlayerPosition|string $isGoalkeeper = false,
        bool $goalkeeperFlag = false,
        ?Championship $championship = null,
    ): ChampionshipPlayer {
        return DB::transaction(function () use (
            $team,
            $name,
            $jerseyNumber,
            $position,
            $isGoalkeeper,
            $goalkeeperFlag,
            $championship,
        ): ChampionshipPlayer {
            if ($team instanceof Championship)
            {
                if (! $name instanceof ChampionshipTeam)
                {
                    throw new DomainException('Equipe inválida para inscrição do jogador.');
                }

                $targetChampionship = $team;
                $targetTeam = $name;
                $rawName = $jerseyNumber;
                $rawJersey = is_int($position) ? $position : 0;
                $rawPosition = ($isGoalkeeper instanceof PlayerPosition || is_string($isGoalkeeper))
                    ? $isGoalkeeper
                    : PlayerPosition::Universal;
                $rawGoalkeeper = is_bool($isGoalkeeper) ? $isGoalkeeper : $goalkeeperFlag;
            }
            else
            {
                $targetTeam = $team;
                $targetChampionship = $championship;
                $rawName = $name;
                $rawJersey = is_int($jerseyNumber) ? $jerseyNumber : (is_numeric($jerseyNumber) ? (int) $jerseyNumber : 0);
                $rawPosition = $position instanceof PlayerPosition || is_string($position)
                    ? $position
                    : PlayerPosition::Universal;
                $rawGoalkeeper = is_bool($isGoalkeeper) ? $isGoalkeeper : $goalkeeperFlag;
            }

            $locked = $this->lockChampionship($targetChampionship ?? (int) $targetTeam->championship_id);

            if ($locked->status === ChampionshipStatus::Completed)
            {
                throw new DomainException('Não é possível inscrever jogadores em um campeonato já encerrado.');
            }

            /** @var ChampionshipTeam|null $lockedTeam */
            $lockedTeam = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereKey($targetTeam->id)
                ->first();

            if ($lockedTeam === null)
            {
                throw new DomainException('A equipe informada não pertence a este campeonato.');
            }

            if (is_array($rawName))
            {
                $payload = $rawName;
                $resolvedName = (string) ($payload['name'] ?? '');
                $resolvedJersey = (int) ($payload['jersey_number'] ?? $payload['jerseyNumber'] ?? 0);
                $posVal = $payload['position'] ?? PlayerPosition::Universal;
                $resolvedPosition = $posVal instanceof PlayerPosition
                    ? $posVal
                    : PlayerPosition::from((string) $posVal);
                $resolvedGoalkeeper = (bool) ($payload['is_goalkeeper'] ?? $payload['isGoalkeeper'] ?? ($resolvedPosition === PlayerPosition::Goalkeeper));
            }
            else
            {
                $resolvedName = (string) $rawName;
                $resolvedJersey = $rawJersey;
                $resolvedPosition = $rawPosition instanceof PlayerPosition
                    ? $rawPosition
                    : PlayerPosition::from((string) $rawPosition);
                $resolvedGoalkeeper = $rawGoalkeeper || $resolvedPosition === PlayerPosition::Goalkeeper;
            }

            $trimmedName = trim($resolvedName);
            if ($trimmedName === '')
            {
                throw new DomainException('Informe o nome do jogador.');
            }

            if ($resolvedJersey < 0 || $resolvedJersey > 999)
            {
                throw new DomainException('O número da camisa deve estar entre 0 e 999.');
            }

            $jerseyInUse = ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_team_id', $lockedTeam->id)
                ->where('jersey_number', $resolvedJersey)
                ->exists();

            if ($jerseyInUse)
            {
                throw new DomainException("A camisa #{$resolvedJersey} já está em uso nesta equipe.");
            }

            return ChampionshipPlayer::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_team_id' => $lockedTeam->id,
                'name' => $trimmedName,
                'jersey_number' => $resolvedJersey,
                'position' => $resolvedPosition,
                'is_goalkeeper' => $resolvedGoalkeeper,
            ]);
        });
    }

    /**
     * @param  ChampionshipTeam|string|array<string, mixed>  $team
     */
    public function enrollPlayer(
        Championship|ChampionshipTeam $championship,
        ChampionshipTeam|string|array $team = '',
        string|int $name = '',
        int|PlayerPosition|string $jerseyNumber = 0,
        PlayerPosition|string|bool $position = PlayerPosition::Universal,
        bool $isGoalkeeper = false,
    ): ChampionshipPlayer {
        if ($championship instanceof ChampionshipTeam)
        {
            return $this->addPlayer(
                team: $championship,
                name: $team,
                jerseyNumber: is_int($name) ? $name : 0,
                position: $jerseyNumber instanceof PlayerPosition || is_string($jerseyNumber) ? $jerseyNumber : PlayerPosition::Universal,
                isGoalkeeper: is_bool($position) ? $position : $isGoalkeeper,
            );
        }

        if (! $team instanceof ChampionshipTeam)
        {
            throw new DomainException('Equipe inválida para inscrição do jogador.');
        }

        return $this->addPlayer(
            team: $team,
            name: (string) $name,
            jerseyNumber: is_int($jerseyNumber) ? $jerseyNumber : 0,
            position: $position instanceof PlayerPosition || is_string($position) ? $position : PlayerPosition::Universal,
            isGoalkeeper: $isGoalkeeper,
            championship: $championship,
        );
    }

    /**
     * @param  ChampionshipPlayer|string|array<string, mixed>|null  $name
     * @param  int|string|array<string, mixed>|null  $jerseyNumber
     */
    public function updatePlayer(
        ChampionshipPlayer|Championship $player,
        ChampionshipPlayer|string|array|null $name = null,
        int|string|array|null $jerseyNumber = null,
        PlayerPosition|string|int|null $position = null,
        ?bool $isGoalkeeper = null,
        ?Championship $championship = null,
    ): ChampionshipPlayer {
        $extraArgs = func_get_args();

        return DB::transaction(function () use (
            $player,
            $name,
            $jerseyNumber,
            $position,
            $isGoalkeeper,
            $championship,
            $extraArgs,
        ): ChampionshipPlayer {
            if ($player instanceof Championship)
            {
                if (! $name instanceof ChampionshipPlayer)
                {
                    throw new DomainException('Jogador inválido para atualização.');
                }

                $targetChampionship = $player;
                $targetPlayer = $name;
                $rawName = $jerseyNumber;
                $rawJersey = is_int($position) ? $position : null;
                $rawPosition = isset($extraArgs[4]) && ($extraArgs[4] instanceof PlayerPosition || is_string($extraArgs[4]))
                    ? $extraArgs[4]
                    : null;
                $rawGoalkeeper = isset($extraArgs[5]) && is_bool($extraArgs[5]) ? $extraArgs[5] : $isGoalkeeper;
            }
            else
            {
                $targetPlayer = $player;
                $targetChampionship = $championship;
                $rawName = $name;
                $rawJersey = is_int($jerseyNumber) ? $jerseyNumber : (is_numeric($jerseyNumber) ? (int) $jerseyNumber : null);
                $rawPosition = $position instanceof PlayerPosition || is_string($position) ? $position : null;
                $rawGoalkeeper = $isGoalkeeper;
            }

            $locked = $this->lockChampionship($targetChampionship ?? (int) $targetPlayer->championship_id);

            /** @var ChampionshipPlayer|null $lockedPlayer */
            $lockedPlayer = ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereKey($targetPlayer->id)
                ->lockForUpdate()
                ->first();

            if ($lockedPlayer === null)
            {
                throw new DomainException('O jogador informado não pertence a este campeonato.');
            }

            if (is_array($rawName))
            {
                $payload = $rawName;
                $resolvedName = array_key_exists('name', $payload) ? (string) $payload['name'] : $lockedPlayer->name;
                $resolvedJersey = array_key_exists('jersey_number', $payload) || array_key_exists('jerseyNumber', $payload)
                    ? (int) ($payload['jersey_number'] ?? $payload['jerseyNumber'])
                    : $lockedPlayer->jersey_number;
                if (array_key_exists('position', $payload) && $payload['position'] !== null)
                {
                    $posVal = $payload['position'];
                    $resolvedPosition = $posVal instanceof PlayerPosition
                        ? $posVal
                        : PlayerPosition::from((string) $posVal);
                }
                else
                {
                    $resolvedPosition = $lockedPlayer->position;
                }
                $resolvedGoalkeeper = array_key_exists('is_goalkeeper', $payload) || array_key_exists('isGoalkeeper', $payload)
                    ? (bool) ($payload['is_goalkeeper'] ?? $payload['isGoalkeeper'])
                    : ($resolvedPosition === PlayerPosition::Goalkeeper ? true : $lockedPlayer->is_goalkeeper);
            }
            else
            {
                $resolvedName = is_string($rawName) ? $rawName : $lockedPlayer->name;
                $resolvedJersey = $rawJersey ?? $lockedPlayer->jersey_number;
                $resolvedPosition = $rawPosition !== null
                    ? ($rawPosition instanceof PlayerPosition ? $rawPosition : PlayerPosition::from((string) $rawPosition))
                    : $lockedPlayer->position;
                $resolvedGoalkeeper = $rawGoalkeeper ?? ($resolvedPosition === PlayerPosition::Goalkeeper ? true : $lockedPlayer->is_goalkeeper);
            }

            $trimmedName = trim($resolvedName);
            if ($trimmedName === '')
            {
                throw new DomainException('Informe o nome do jogador.');
            }

            if ($resolvedJersey < 0 || $resolvedJersey > 999)
            {
                throw new DomainException('O número da camisa deve estar entre 0 e 999.');
            }

            $jerseyInUse = ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_team_id', $lockedPlayer->championship_team_id)
                ->where('jersey_number', $resolvedJersey)
                ->whereKeyNot($lockedPlayer->id)
                ->exists();

            if ($jerseyInUse)
            {
                throw new DomainException("A camisa #{$resolvedJersey} já está em uso nesta equipe.");
            }

            $lockedPlayer->name = $trimmedName;
            $lockedPlayer->jersey_number = $resolvedJersey;
            $lockedPlayer->position = $resolvedPosition;
            $lockedPlayer->is_goalkeeper = $resolvedGoalkeeper;
            $lockedPlayer->save();

            $this->syncModel($targetPlayer, $lockedPlayer);

            return $lockedPlayer;
        });
    }

    public function removePlayer(
        ChampionshipPlayer|Championship $player,
        ?ChampionshipPlayer $targetPlayer = null,
    ): void {
        DB::transaction(function () use ($player, $targetPlayer): void {
            if ($player instanceof Championship)
            {
                if ($targetPlayer === null)
                {
                    throw new DomainException('Jogador inválido para remoção.');
                }
                $championship = $player;
                $resolvedPlayer = $targetPlayer;
            }
            else
            {
                $championship = null;
                $resolvedPlayer = $player;
            }

            $locked = $this->lockChampionship($championship ?? (int) $resolvedPlayer->championship_id);

            /** @var ChampionshipPlayer|null $lockedPlayer */
            $lockedPlayer = ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereKey($resolvedPlayer->id)
                ->lockForUpdate()
                ->first();

            if ($lockedPlayer === null)
            {
                throw new DomainException('O jogador informado não pertence a este campeonato.');
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
                throw new DomainException('Não é possível remover um jogador que possui gols, assistências ou cobranças registrados em partidas.');
            }

            $lockedPlayer->delete();
        });
    }

    /**
     * Randomly (with optional seed pots) or explicitly assigns teams to groups and creates
     * single/double round-robin group games with deterministic slot keys.
     *
     * @param  User|int|array<string, list<int>>|null  $actor
     * @param  array<string, list<int>>|string|null  $explicitGroups
     */
    public function drawGroups(
        Championship $championship,
        User|int|array|null $actor = null,
        bool|int|User $redraw = false,
        string|bool|null $reason = null,
        array|string|null $explicitGroups = null,
        User|int|null $actorId = null,
        ?bool $forceRedraw = null,
        ?string $redrawReason = null,
    ): Championship {
        return DB::transaction(function () use (
            $championship,
            $actor,
            $redraw,
            $reason,
            $explicitGroups,
            $actorId,
            $forceRedraw,
            $redrawReason,
        ): Championship {
            $locked = $this->lockChampionship($championship);

            if (is_array($actor))
            {
                $resolvedExplicitGroups = $actor;
                $resolvedActorId = $this->resolveActorId(
                    ($redraw instanceof User || is_int($redraw)) ? $redraw : $actorId,
                    $locked->user_id,
                );
                $resolvedRedraw = $forceRedraw ?? (is_bool($reason) ? $reason : (is_bool($redraw) ? $redraw : false));
                $resolvedReason = $redrawReason
                    ?? (is_string($explicitGroups) ? $explicitGroups : (is_string($reason) ? $reason : null));
            }
            else
            {
                $resolvedExplicitGroups = is_array($explicitGroups) ? $explicitGroups : null;
                $resolvedActorId = $this->resolveActorId($actor ?? $actorId, $locked->user_id);
                $resolvedRedraw = $forceRedraw ?? (is_bool($redraw) ? $redraw : false);
                $resolvedReason = $reason !== null && is_string($reason) ? $reason : $redrawReason;
            }

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
                throw new DomainException(
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
                if (! $resolvedRedraw)
                {
                    if ($resolvedExplicitGroups === null || $this->matchesCurrentGroupAssignment($teams, $resolvedExplicitGroups))
                    {
                        $this->syncModel($championship, $locked);

                        return $locked;
                    }

                    throw new DomainException('Os grupos já foram sorteados. Confirme a opção de refazer sorteio e informe o motivo.');
                }

                // Redraw requested: verify no game started and bracket not generated
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
                    throw new DomainException('Não é possível refazer o sorteio após o início dos jogos.');
                }

                if ($resolvedReason === null || trim($resolvedReason) === '')
                {
                    throw new DomainException('Informe o motivo para refazer o sorteio dos grupos.');
                }
            }

            $beforeGroups = $this->currentGroupMap($teams);

            $assignedGroups = $resolvedExplicitGroups !== null
                ? $this->validateAndNormalizeExplicitGroups($teams, $resolvedExplicitGroups, $rules)
                : $this->performRandomSeededGroupDraw($teams, $rules->groupsCount);

            // Validate minimum group size vs qualifiersPerGroup
            $minGroupSize = PHP_INT_MAX;
            foreach ($assignedGroups as $groupTeams)
            {
                $minGroupSize = min($minGroupSize, count($groupTeams));
            }

            if ($minGroupSize < 2 || $rules->qualifiersPerGroup > $minGroupSize)
            {
                throw new DomainException('O número de classificados por grupo excede o tamanho do menor grupo.');
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
                'actor_user_id' => $resolvedActorId,
                'action' => $isAlreadyDrawn ? 'groups_redrawn' : 'groups_drawn',
                'reason' => $resolvedReason !== null && trim($resolvedReason) !== '' ? trim($resolvedReason) : null,
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
        ChampionshipGame|Championship $game,
        ChampionshipGame|GameScheduleCommand|string|null $venue = null,
        GameScheduleCommand|CarbonInterface|string|null $datetime = null,
        CarbonInterface|string|null $scheduledAt = null,
        ?Championship $championship = null,
        ?GameScheduleCommand $command = null,
    ): ChampionshipGame {
        return DB::transaction(function () use ($game, $venue, $datetime, $scheduledAt, $championship, $command): ChampionshipGame {
            if ($game instanceof Championship)
            {
                if (! $venue instanceof ChampionshipGame)
                {
                    throw new DomainException('Partida inválida para agendamento.');
                }

                $targetChampionship = $game;
                $targetGame = $venue;

                if ($datetime instanceof GameScheduleCommand)
                {
                    $resolvedVenue = $datetime->venue;
                    $resolvedScheduledAt = $datetime->scheduledAt;
                }
                else
                {
                    $resolvedVenue = is_string($datetime) ? $datetime : null;
                    $resolvedScheduledAt = $this->parseDateTime($scheduledAt);
                }
            }
            else
            {
                $targetGame = $game;
                $targetChampionship = $championship;

                if ($venue instanceof GameScheduleCommand)
                {
                    $resolvedVenue = $venue->venue;
                    $resolvedScheduledAt = $venue->scheduledAt;
                }
                elseif ($command instanceof GameScheduleCommand)
                {
                    $resolvedVenue = $command->venue;
                    $resolvedScheduledAt = $command->scheduledAt;
                }
                else
                {
                    $resolvedVenue = is_string($venue) ? $venue : null;
                    $resolvedScheduledAt = $this->parseDateTime($datetime ?? $scheduledAt);
                }
            }

            $locked = $this->lockChampionship($targetChampionship ?? (int) $targetGame->championship_id);
            $lockedGame = $this->lockGame($locked, $targetGame);

            if ($lockedGame->is_bye)
            {
                throw new DomainException('Não é possível agendar um slot de avanço automático (bye).');
            }

            if ($lockedGame->status === GameStatus::Finished)
            {
                throw new DomainException('Não é possível alterar o agendamento de uma partida já encerrada.');
            }

            $lockedGame->venue = $resolvedVenue !== null && trim($resolvedVenue) !== '' ? trim($resolvedVenue) : null;
            $lockedGame->scheduled_at = $resolvedScheduledAt;
            $lockedGame->save();

            $this->syncModel($targetGame, $lockedGame);

            return $lockedGame;
        });
    }

    public function startGame(
        ChampionshipGame|Championship $game,
        ChampionshipGame|User|int|null $actor = null,
        ?CarbonInterface $startedAt = null,
        ?Championship $championship = null,
    ): ChampionshipGame {
        return DB::transaction(function () use ($game, $actor, $startedAt, $championship): ChampionshipGame {
            if ($game instanceof Championship)
            {
                if (! $actor instanceof ChampionshipGame)
                {
                    throw new DomainException('Partida inválida para iniciar.');
                }
                $targetChampionship = $game;
                $targetGame = $actor;
            }
            else
            {
                $targetGame = $game;
                $targetChampionship = $championship;
            }

            $locked = $this->lockChampionship($targetChampionship ?? (int) $targetGame->championship_id);
            $lockedGame = $this->lockGame($locked, $targetGame);

            if ($lockedGame->is_bye)
            {
                throw new DomainException('Slots de avanço automático (bye) não podem ser iniciados.');
            }

            if ($lockedGame->home_team_id === null || $lockedGame->away_team_id === null)
            {
                throw new DomainException('A partida precisa ter as duas equipes definidas antes de ser iniciada.');
            }

            if ($lockedGame->status === GameStatus::Finished)
            {
                throw new DomainException('Esta partida já foi encerrada. Reabra a partida caso precise retificar a súmula.');
            }

            if ($lockedGame->status === GameStatus::Scheduled)
            {
                $lockedGame->status = GameStatus::Live;
                $lockedGame->started_at = $lockedGame->started_at
                    ?? ($startedAt !== null ? CarbonImmutable::instance($startedAt) : CarbonImmutable::now());
                $lockedGame->save();
            }

            $this->syncModel($targetGame, $lockedGame);

            return $lockedGame;
        });
    }

    public function recordGoal(
        ChampionshipGame|Championship $game,
        ChampionshipGame|GoalCommand $command,
        ?GoalCommand $goalCommand = null,
    ): ChampionshipGameEvent {
        return DB::transaction(function () use ($game, $command, $goalCommand): ChampionshipGameEvent {
            if ($game instanceof Championship)
            {
                if (! $command instanceof ChampionshipGame || ! $goalCommand instanceof GoalCommand)
                {
                    throw new DomainException('Parâmetros inválidos para registro de gol.');
                }
                $targetChampionship = $game;
                $targetGame = $command;
                $resolvedCommand = $goalCommand;
            }
            else
            {
                if (! $command instanceof GoalCommand)
                {
                    throw new DomainException('Comando de gol inválido.');
                }
                $targetChampionship = null;
                $targetGame = $game;
                $resolvedCommand = $command;
            }

            $locked = $this->lockChampionship($targetChampionship ?? (int) $targetGame->championship_id);
            $lockedGame = $this->lockGame($locked, $targetGame);

            if ($lockedGame->is_bye)
            {
                throw new DomainException('Não é possível registrar gols em um slot de bye.');
            }

            if ($lockedGame->status !== GameStatus::Live)
            {
                throw new DomainException('A partida precisa estar em andamento (ao vivo) para registrar gols.');
            }

            if ($lockedGame->home_team_id === null || $lockedGame->away_team_id === null)
            {
                throw new DomainException('A partida não possui as duas equipes definidas.');
            }

            $rules = $locked->rules;

            if ($resolvedCommand->isOvertime)
            {
                if ($lockedGame->phase === GamePhase::Group && ! $rules->allowGroupOvertimeOrShootout)
                {
                    throw new DomainException('As regras deste campeonato não permitem prorrogação na fase de grupos.');
                }

                if ($lockedGame->phase->isKnockout() && $rules->knockoutTieResolution === KnockoutTieResolution::ShootoutOnly)
                {
                    throw new DomainException('As regras deste campeonato definem desempate direto nos pênaltis/shootout, sem prorrogação.');
                }
            }

            if ($resolvedCommand->period < 1)
            {
                throw new DomainException('O período do gol deve ser maior ou igual a 1.');
            }

            $teamId = $resolvedCommand->side === TeamSide::Home
                ? (int) $lockedGame->home_team_id
                : (int) $lockedGame->away_team_id;

            $maxAssists = $rules->maxAssistsPerGoal;
            $assistIds = $resolvedCommand->assistIds;
            $firstAssistId = $resolvedCommand->firstAssistId;
            $secondAssistId = $resolvedCommand->secondAssistId;

            if (count($assistIds) > $maxAssists)
            {
                throw new DomainException("As regras deste campeonato permitem no máximo {$maxAssists} assistência(s) por gol.");
            }

            if ($maxAssists < 1 && ($firstAssistId !== null || $secondAssistId !== null))
            {
                throw new DomainException('As regras deste campeonato não permitem assistências.');
            }

            if ($maxAssists < 2 && $secondAssistId !== null)
            {
                throw new DomainException('As regras deste campeonato permitem no máximo 1 assistência por gol.');
            }

            if ($firstAssistId === null && $secondAssistId !== null)
            {
                $firstAssistId = $secondAssistId;
                $secondAssistId = null;
            }

            if (
                $firstAssistId === $resolvedCommand->scorerId
                || $secondAssistId === $resolvedCommand->scorerId
                || in_array($resolvedCommand->scorerId, $assistIds, true)
            ) {
                throw new DomainException('O autor do gol não pode dar assistência para o próprio gol.');
            }

            if (
                ($firstAssistId !== null && $secondAssistId !== null && $firstAssistId === $secondAssistId)
                || count($assistIds) !== count(array_unique($assistIds))
            ) {
                throw new DomainException('As assistências de um gol devem ser de jogadores distintos.');
            }

            $playerIdsToValidate = [$resolvedCommand->scorerId];
            if ($firstAssistId !== null)
            {
                $playerIdsToValidate[] = $firstAssistId;
            }
            if ($secondAssistId !== null)
            {
                $playerIdsToValidate[] = $secondAssistId;
            }

            $validPlayersCount = ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->where('championship_team_id', $teamId)
                ->whereIn('id', $playerIdsToValidate)
                ->count();

            if ($validPlayersCount !== count(array_unique($playerIdsToValidate)))
            {
                throw new DomainException('O autor do gol e os assistentes devem pertencer ao elenco da equipe na partida.');
            }

            $event = ChampionshipGameEvent::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => $lockedGame->id,
                'type' => GameEventType::Goal,
                'side' => $resolvedCommand->side,
                'team_id' => $teamId,
                'primary_player_id' => $resolvedCommand->scorerId,
                'first_assist_player_id' => $firstAssistId,
                'second_assist_player_id' => $secondAssistId,
                'period' => $resolvedCommand->period,
                'is_overtime' => $resolvedCommand->isOvertime,
                'elapsed_seconds' => max(0, $resolvedCommand->elapsedSeconds),
                'remaining_seconds' => max(0, $resolvedCommand->remainingSeconds),
                'recorded_at' => CarbonImmutable::now(),
            ]);

            $this->recomputeGameScores($lockedGame);
            $this->syncModel($targetGame, $lockedGame);

            return $event;
        });
    }

    public function recordShootoutAttempt(
        ChampionshipGame|Championship $game,
        ChampionshipGame|ShootoutAttemptCommand $command,
        ?ShootoutAttemptCommand $shootoutCommand = null,
    ): ChampionshipGameEvent {
        return DB::transaction(function () use ($game, $command, $shootoutCommand): ChampionshipGameEvent {
            if ($game instanceof Championship)
            {
                if (! $command instanceof ChampionshipGame || ! $shootoutCommand instanceof ShootoutAttemptCommand)
                {
                    throw new DomainException('Parâmetros inválidos para cobrança de shootout.');
                }
                $targetChampionship = $game;
                $targetGame = $command;
                $resolvedCommand = $shootoutCommand;
            }
            else
            {
                if (! $command instanceof ShootoutAttemptCommand)
                {
                    throw new DomainException('Comando de cobrança de shootout inválido.');
                }
                $targetChampionship = null;
                $targetGame = $game;
                $resolvedCommand = $command;
            }

            $locked = $this->lockChampionship($targetChampionship ?? (int) $targetGame->championship_id);
            $lockedGame = $this->lockGame($locked, $targetGame);

            if ($lockedGame->is_bye)
            {
                throw new DomainException('Não é possível registrar cobranças em um slot de bye.');
            }

            if ($lockedGame->status !== GameStatus::Live)
            {
                throw new DomainException('A partida precisa estar em andamento (ao vivo) para registrar cobranças de shootout.');
            }

            if ($lockedGame->home_team_id === null || $lockedGame->away_team_id === null)
            {
                throw new DomainException('A partida não possui as duas equipes definidas.');
            }

            if ($lockedGame->phase === GamePhase::Group && ! $locked->rules->allowGroupOvertimeOrShootout)
            {
                throw new DomainException('As regras deste campeonato permitem empate na fase de grupos e não utilizam disputa de shootout.');
            }

            if ($lockedGame->home_regular_score !== $lockedGame->away_regular_score)
            {
                throw new DomainException('A disputa de shootout só é permitida quando o placar regulamentar/prorrogação está empatado.');
            }

            if ($resolvedCommand->roundNumber < 1)
            {
                throw new DomainException('A rodada do shootout deve ser maior ou igual a 1.');
            }

            $teamId = $resolvedCommand->side === TeamSide::Home
                ? (int) $lockedGame->home_team_id
                : (int) $lockedGame->away_team_id;

            $shooterBelongsToTeam = ChampionshipPlayer::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->where('championship_team_id', $teamId)
                ->whereKey($resolvedCommand->shooterId)
                ->exists();

            if (! $shooterBelongsToTeam)
            {
                throw new DomainException('O cobrador deve pertencer ao elenco da equipe na partida.');
            }

            $event = ChampionshipGameEvent::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => $lockedGame->id,
                'type' => GameEventType::ShootoutAttempt,
                'side' => $resolvedCommand->side,
                'team_id' => $teamId,
                'primary_player_id' => $resolvedCommand->shooterId,
                'first_assist_player_id' => null,
                'second_assist_player_id' => null,
                'period' => null,
                'is_overtime' => false,
                'elapsed_seconds' => 0,
                'remaining_seconds' => 0,
                'shootout_round' => $resolvedCommand->roundNumber,
                'shootout_scored' => $resolvedCommand->scored,
                'recorded_at' => CarbonImmutable::now(),
            ]);

            $this->recomputeGameScores($lockedGame);
            $this->syncModel($targetGame, $lockedGame);

            return $event;
        });
    }

    public function removeEvent(
        ChampionshipGameEvent|ChampionshipGame|Championship $event,
        ChampionshipGameEvent|ChampionshipGame|User|int|string|null $actor = null,
        ChampionshipGameEvent|User|int|string|null $reason = null,
        User|int|string|null $actorId = null,
        ?string $removalReason = null,
    ): void {
        DB::transaction(function () use ($event, $actor, $reason, $actorId, $removalReason): void {
            $targetChampionship = null;
            $targetGame = null;
            $targetEvent = null;
            $rawActor = null;
            $rawReason = null;

            if ($event instanceof Championship)
            {
                $targetChampionship = $event;
                if ($actor instanceof ChampionshipGame && $reason instanceof ChampionshipGameEvent)
                {
                    $targetGame = $actor;
                    $targetEvent = $reason;
                    $rawActor = $actorId;
                    $rawReason = $removalReason;
                }
                elseif ($actor instanceof ChampionshipGameEvent)
                {
                    $targetEvent = $actor;
                    $rawActor = $reason;
                    $rawReason = is_string($actorId) ? $actorId : $removalReason;
                }
            }
            elseif ($event instanceof ChampionshipGame)
            {
                $targetGame = $event;
                if ($actor instanceof ChampionshipGameEvent)
                {
                    $targetEvent = $actor;
                    $rawActor = $reason ?? $actorId;
                    $rawReason = is_string($actorId) ? $actorId : (is_string($reason) ? $reason : $removalReason);
                }
            }
            else
            {
                $targetEvent = $event;
                if ($actor instanceof User || is_int($actor))
                {
                    $rawActor = $actor;
                    $rawReason = is_string($reason) ? $reason : $removalReason;
                }
                elseif (is_string($actor))
                {
                    $rawActor = $actorId;
                    $rawReason = $actor;
                }
                else
                {
                    $rawActor = $actorId;
                    $rawReason = is_string($reason) ? $reason : $removalReason;
                }
            }

            if (! $targetEvent instanceof ChampionshipGameEvent)
            {
                throw new DomainException('Evento de súmula inválido para remoção.');
            }

            $locked = $this->lockChampionship($targetChampionship ?? (int) $targetEvent->championship_id);

            /** @var ChampionshipGame|null $lockedGame */
            $lockedGame = ChampionshipGame::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->whereKey($targetGame !== null ? $targetGame->id : $targetEvent->championship_game_id)
                ->lockForUpdate()
                ->first();

            if ($lockedGame === null || (int) $targetEvent->championship_game_id !== (int) $lockedGame->id)
            {
                throw new DomainException('O evento informado não pertence a esta partida.');
            }

            if ($lockedGame->status !== GameStatus::Live)
            {
                throw new DomainException('A partida precisa estar em andamento ou reaberta para remover eventos da súmula.');
            }

            /** @var ChampionshipGameEvent|null $lockedEvent */
            $lockedEvent = ChampionshipGameEvent::withoutGlobalScope('team')
                ->withTrashed()
                ->where('championship_game_id', $lockedGame->id)
                ->whereKey($targetEvent->id)
                ->lockForUpdate()
                ->first();

            if ($lockedEvent === null)
            {
                throw new DomainException('Evento não encontrado na súmula.');
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

            $resolvedActorId = $this->resolveActorId(
                ($rawActor instanceof User || is_int($rawActor)) ? $rawActor : null,
                $locked->user_id,
            );

            ChampionshipAuditLog::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => $lockedGame->id,
                'actor_user_id' => $resolvedActorId,
                'action' => 'event_removed',
                'reason' => $rawReason !== null && trim($rawReason) !== '' ? trim($rawReason) : null,
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

            if ($targetGame !== null)
            {
                $this->syncModel($targetGame, $lockedGame);
            }
            $this->syncModel($targetEvent, $lockedEvent);
        });
    }

    public function removeGameEvent(
        Championship|ChampionshipGame|ChampionshipGameEvent $championship,
        ChampionshipGame|ChampionshipGameEvent|User|int|null $game = null,
        ChampionshipGameEvent|User|int|string|null $event = null,
        User|int|string|null $actorId = null,
        ?string $reason = null,
    ): void {
        $this->removeEvent($championship, $game, $event, $actorId, $reason);
    }

    public function finishGame(
        ChampionshipGame|Championship $game,
        ChampionshipGame|User|int|CarbonInterface|null $actor = null,
        CarbonInterface|User|int|null $finishedAt = null,
        ?CarbonInterface $explicitFinishedAt = null,
    ): ChampionshipGame {
        return DB::transaction(function () use ($game, $actor, $finishedAt, $explicitFinishedAt): ChampionshipGame {
            if ($game instanceof Championship)
            {
                if (! $actor instanceof ChampionshipGame)
                {
                    throw new DomainException('Partida inválida para encerramento.');
                }
                $targetChampionship = $game;
                $targetGame = $actor;
                $rawActor = ($finishedAt instanceof User || is_int($finishedAt)) ? $finishedAt : null;
                $rawFinishedAt = $finishedAt instanceof CarbonInterface ? $finishedAt : $explicitFinishedAt;
            }
            else
            {
                $targetChampionship = null;
                $targetGame = $game;
                $rawActor = ($actor instanceof User || is_int($actor)) ? $actor : null;
                $rawFinishedAt = $actor instanceof CarbonInterface
                    ? $actor
                    : ($finishedAt instanceof CarbonInterface ? $finishedAt : $explicitFinishedAt);
            }

            $locked = $this->lockChampionship($targetChampionship ?? (int) $targetGame->championship_id);
            $lockedGame = $this->lockGame($locked, $targetGame);

            if ($lockedGame->status === GameStatus::Finished)
            {
                $this->syncModel($targetGame, $lockedGame);
                if ($targetChampionship !== null)
                {
                    $this->syncModel($targetChampionship, $locked);
                }

                return $lockedGame;
            }

            if ($lockedGame->home_team_id === null || $lockedGame->away_team_id === null)
            {
                throw new DomainException('A partida precisa ter as duas equipes definidas para ser encerrada.');
            }

            $hasAnyEventsInTable = ChampionshipGameEvent::withoutGlobalScope('team')
                ->withTrashed()
                ->where('championship_game_id', $lockedGame->id)
                ->exists();

            if ($hasAnyEventsInTable)
            {
                $this->recomputeGameScores($lockedGame);
            }

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
                        throw new DomainException(
                            $lockedGame->phase->isKnockout()
                                ? 'Jogos do mata-mata exigem um vencedor (no tempo normal, prorrogação ou disputa de shootout).'
                                : 'As regras deste campeonato não permitem empate na fase de grupos; defina o vencedor na prorrogação ou shootout.',
                        );
                    }

                    $winnerTeamId = $lockedGame->home_shootout_score > $lockedGame->away_shootout_score ? $homeId : $awayId;
                    $loserTeamId = $winnerTeamId === $homeId ? $awayId : $homeId;
                }
            }

            $now = $rawFinishedAt !== null ? CarbonImmutable::instance($rawFinishedAt) : CarbonImmutable::now();

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

            $resolvedActorId = $this->resolveActorId($rawActor, $locked->user_id);

            ChampionshipAuditLog::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => $lockedGame->id,
                'actor_user_id' => $resolvedActorId,
                'action' => 'game_finished',
                'reason' => null,
                'before_snapshot' => null,
                'after_snapshot' => $this->gameSnapshot($lockedGame, $locked->status),
                'recorded_at' => $now,
            ]);

            $this->syncModel($targetGame, $lockedGame);
            if ($targetChampionship !== null)
            {
                $this->syncModel($targetChampionship, $locked);
            }

            return $lockedGame;
        });
    }

    public function reopenGame(
        ChampionshipGame|Championship $game,
        ChampionshipGame|User|int|string|null $actor = null,
        string|User|int|null $reason = null,
        bool|string $resetBracket = false,
        bool $resetUnstartedBracket = false,
        ?Championship $championship = null,
        User|int|null $actorId = null,
        ?bool $resetar_chave = null,
    ): ChampionshipGame {
        return DB::transaction(function () use (
            $game,
            $actor,
            $reason,
            $resetBracket,
            $resetUnstartedBracket,
            $championship,
            $actorId,
            $resetar_chave,
        ): ChampionshipGame {
            if ($game instanceof Championship)
            {
                if (! $actor instanceof ChampionshipGame)
                {
                    throw new DomainException('Partida inválida para reabertura.');
                }
                $targetChampionship = $game;
                $targetGame = $actor;
                $rawActor = ($reason instanceof User || is_int($reason)) ? $reason : $actorId;
                $rawReason = is_string($resetBracket) ? $resetBracket : (is_string($reason) ? $reason : '');
                $resolvedResetBracket = (is_bool($resetBracket) && $resetBracket)
                    || $resetUnstartedBracket
                    || ($resetar_chave ?? false);
            }
            else
            {
                $targetChampionship = $championship;
                $targetGame = $game;
                if (is_string($actor) && ($reason === null || is_bool($reason)))
                {
                    $rawActor = $actorId;
                    $rawReason = $actor;
                    $resolvedResetBracket = (is_bool($reason) && $reason)
                        || (is_bool($resetBracket) && $resetBracket)
                        || $resetUnstartedBracket
                        || ($resetar_chave ?? false);
                }
                else
                {
                    $rawActor = ($actor instanceof User || is_int($actor)) ? $actor : $actorId;
                    $rawReason = is_string($reason) ? $reason : '';
                    $resolvedResetBracket = (is_bool($resetBracket) && $resetBracket)
                        || $resetUnstartedBracket
                        || ($resetar_chave ?? false);
                }
            }

            $trimmedReason = trim($rawReason);
            if ($trimmedReason === '')
            {
                throw new DomainException('O motivo é obrigatório para reabrir uma partida.');
            }

            $locked = $this->lockChampionship($targetChampionship ?? (int) $targetGame->championship_id);
            $lockedGame = $this->lockGame($locked, $targetGame);

            if ($lockedGame->is_bye)
            {
                throw new DomainException('Slots de avanço automático (bye) não podem ser reabertos.');
            }

            if ($lockedGame->status !== GameStatus::Finished)
            {
                throw new DomainException('Apenas partidas já encerradas podem ser reabertas.');
            }

            $resolvedActorId = $this->resolveActorId($rawActor, $locked->user_id);
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
                    throw new DomainException('Não é possível reabrir um jogo da fase de grupos pois o mata-mata já foi iniciado.');
                }

                $bracketExists = $knockoutGames->isNotEmpty()
                    || $locked->bracket_generated_at !== null
                    || $locked->status === ChampionshipStatus::KnockoutStage;

                if ($bracketExists)
                {
                    if (! $resolvedResetBracket)
                    {
                        throw new DomainException('A chave do mata-mata já foi gerada. Confirme o reinício da chave para reabrir este jogo.');
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
                        'actor_user_id' => $resolvedActorId,
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
                    throw new DomainException('Não é possível reabrir esta partida pois a fase seguinte já foi iniciada.');
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
                'actor_user_id' => $resolvedActorId,
                'action' => 'game_reopened',
                'reason' => $trimmedReason,
                'before_snapshot' => $beforeSnapshot,
                'after_snapshot' => $afterSnapshot,
                'recorded_at' => $now,
            ]);

            $this->syncModel($targetGame, $lockedGame);
            if ($targetChampionship !== null)
            {
                $this->syncModel($targetChampionship, $locked);
            }

            return $lockedGame;
        });
    }

    /**
     * @param  list<int>  $orderedTeamIds
     */
    public function resolveTie(
        Championship $championship,
        ?string $group = null,
        array $orderedTeamIds = [],
        User|int|null $actor = null,
        ?string $reason = null,
        ?string $groupCode = null,
        User|int|null $actorId = null,
    ): Championship {
        return DB::transaction(function () use (
            $championship,
            $group,
            $orderedTeamIds,
            $actor,
            $reason,
            $groupCode,
            $actorId,
        ): Championship {
            $locked = $this->lockChampionship($championship);

            $resolvedGroup = trim((string) ($group ?? $groupCode ?? ''));
            if ($resolvedGroup === '')
            {
                throw new DomainException('Informe o grupo para registrar o desempate manual.');
            }

            $trimmedReason = trim((string) $reason);
            if ($trimmedReason === '')
            {
                throw new DomainException('O motivo é obrigatório para registrar o desempate manual.');
            }

            $normalizedIds = array_values(array_map('intval', $orderedTeamIds));
            if (count($normalizedIds) < 2 || count($normalizedIds) !== count(array_unique($normalizedIds)))
            {
                throw new DomainException('O desempate manual exige ao menos duas equipes distintas do grupo.');
            }

            $matchingCount = ChampionshipTeam::withoutGlobalScope('team')
                ->where('championship_id', $locked->id)
                ->where('group_code', $resolvedGroup)
                ->whereIn('id', $normalizedIds)
                ->count();

            if ($matchingCount !== count($normalizedIds))
            {
                throw new DomainException("Todas as equipes informadas no desempate devem pertencer ao grupo {$resolvedGroup}.");
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

            $resolvedActorId = $this->resolveActorId($actor ?? $actorId, $locked->user_id);

            ChampionshipAuditLog::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => null,
                'actor_user_id' => $resolvedActorId,
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

    /**
     * @param  list<int>  $orderedTeamIds
     */
    public function resolveGroupTieManually(
        Championship $championship,
        string $groupCode,
        array $orderedTeamIds,
        User|int|null $actorId = null,
        string $reason = '',
        User|int|null $actor = null,
    ): Championship {
        return $this->resolveTie(
            championship: $championship,
            group: $groupCode,
            orderedTeamIds: $orderedTeamIds,
            actor: $actor ?? $actorId,
            reason: $reason,
        );
    }

    public function generateBracket(
        Championship $championship,
        User|int|null $actor = null,
        bool $forceResetUnstarted = false,
        ?string $resetReason = null,
        User|int|null $actorId = null,
    ): Championship {
        return DB::transaction(function () use (
            $championship,
            $actor,
            $resetReason,
            $actorId,
        ): Championship {
            $locked = $this->lockChampionship($championship);

            if ($locked->status === ChampionshipStatus::Draft)
            {
                throw new DomainException('Realize o sorteio dos grupos e conclua a fase de grupos antes de gerar o mata-mata.');
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
                throw new DomainException('A chave do mata-mata já possui partidas iniciadas e não pode ser gerada novamente.');
            }

            $standings = $this->computeStandingsForLocked($locked);

            if ($standings === [])
            {
                throw new DomainException('Não há grupos disponíveis para gerar a chave do mata-mata.');
            }

            foreach ($standings as $groupCode => $groupStandings)
            {
                if (! $groupStandings->allGamesFinished)
                {
                    throw new DomainException("Todas as partidas da fase de grupos devem estar encerradas antes de gerar o mata-mata (grupo {$groupCode} pendente).");
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
                            throw new DomainException("Há um empate técnico pendente de definição manual no grupo {$groupCode}.");
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

            // Auto-advance first-round bye slots into their downstream winner:{slot_key} slots
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

            $resolvedActorId = $this->resolveActorId($actor ?? $actorId, $locked->user_id);

            ChampionshipAuditLog::withoutGlobalScope('team')->create([
                'user_id' => $locked->user_id,
                'championship_id' => $locked->id,
                'championship_game_id' => null,
                'actor_user_id' => $resolvedActorId,
                'action' => 'bracket_generated',
                'reason' => $resetReason !== null && trim($resetReason) !== '' ? trim($resetReason) : null,
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

    public function generateKnockoutBracket(
        Championship $championship,
        User|int|null $actorId = null,
        bool $forceResetUnstarted = false,
        ?string $resetReason = null,
        User|int|null $actor = null,
    ): Championship {
        return $this->generateBracket(
            championship: $championship,
            actor: $actor ?? $actorId,
            forceResetUnstarted: $forceResetUnstarted,
            resetReason: $resetReason,
        );
    }

    /**
     * @return array<string, GroupStandings>
     */
    public function standings(Championship $championship): array
    {
        $this->assertTenantAccess($championship);

        return $this->computeStandingsForLocked($championship);
    }

    /**
     * @return array<string, GroupStandings>
     */
    public function computeStandings(Championship $championship): array
    {
        return $this->standings($championship);
    }

    public function summary(Championship $championship): ChampionshipSummary
    {
        $this->assertTenantAccess($championship);

        $standingsByGroup = $this->computeStandingsForLocked($championship);

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

        $podium = $this->resolvePodium($championship, $allGames->all());

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

    public function computeSummary(Championship $championship): ChampionshipSummary
    {
        return $this->summary($championship);
    }

    /**
     * @param  list<ChampionshipGame>  $allGames
     */
    private function resolvePodium(Championship $championship, array $allGames): Podium
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
    private function computeStandingsForLocked(Championship $championship): array
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
            throw new DomainException("O sorteio exige exatamente {$rules->groupsCount} grupo(s).");
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
                throw new DomainException('Estrutura de grupos inválida.');
            }

            $groupTeamModels = [];
            foreach ($teamIds as $rawId)
            {
                $teamId = (int) $rawId;
                if (! isset($teamById[$teamId]))
                {
                    throw new DomainException("A equipe #{$teamId} não pertence a este campeonato.");
                }
                if (isset($seenIds[$teamId]))
                {
                    throw new DomainException("A equipe #{$teamId} foi atribuída a mais de um grupo.");
                }

                $seenIds[$teamId] = true;
                $groupTeamModels[] = $teamById[$teamId];
            }

            $result[$normalizedCode] = $groupTeamModels;
        }

        if (count($seenIds) !== count($teams))
        {
            throw new DomainException('Todas as equipes inscritas devem participar do sorteio dos grupos.');
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

            // Rotate all except the fixed element at index 0
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
            throw new DomainException('Campeonato não encontrado.');
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
            throw new DomainException('A partida informada não pertence a este campeonato.');
        }

        return $lockedGame;
    }

    private function assertTenantAccess(Championship $championship): void
    {
        $authId = auth()->id();
        if ($authId !== null && (int) $championship->user_id !== (int) $authId)
        {
            throw new DomainException('Este campeonato pertence a outra conta.');
        }
    }

    private function resolveActorId(User|int|null $actor, int $fallbackUserId): int
    {
        if ($actor instanceof User)
        {
            return (int) $actor->id;
        }

        if (is_int($actor) && $actor > 0)
        {
            return $actor;
        }

        $authId = auth()->id();
        if ($authId !== null)
        {
            return (int) $authId;
        }

        return $fallbackUserId;
    }

    /**
     * @param  ChampionshipRules|array<string, mixed>|null  $rules
     */
    private function normalizeRules(ChampionshipRules|array|null $rules): ChampionshipRules
    {
        if ($rules instanceof ChampionshipRules)
        {
            return $rules;
        }

        if (is_array($rules))
        {
            return ChampionshipRules::fromArray($rules);
        }

        return ChampionshipRules::defaults();
    }

    private function validateRules(ChampionshipRules $rules): void
    {
        if ($rules->groupsCount < 1 || $rules->groupsCount > 8)
        {
            throw new DomainException('O número de grupos deve estar entre 1 e 8.');
        }

        if ($rules->qualifiersPerGroup < 1 || $rules->qualifiersPerGroup > 16)
        {
            throw new DomainException('O número de classificados por grupo deve ser pelo menos 1.');
        }

        if ($rules->extraBestQualifiers < 0)
        {
            throw new DomainException('O número de melhores classificados extras não pode ser negativo.');
        }

        $totalQualifiers = $rules->totalQualifiers();
        if ($totalQualifiers < 2 || $totalQualifiers > 16)
        {
            throw new DomainException('O total de equipes classificadas para o mata-mata deve estar entre 2 e 16.');
        }

        if ($rules->maxAssistsPerGoal < 0 || $rules->maxAssistsPerGoal > 2)
        {
            throw new DomainException('O máximo de assistências por gol deve ser entre 0 e 2.');
        }

        if ($rules->periodMinutes < 1 || $rules->periodsCount < 1 || $rules->overtimeMinutes < 1)
        {
            throw new DomainException('As configurações de tempo e períodos devem ser maiores que zero.');
        }
    }

    private function validateHexColor(string $color, string $label): void
    {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1)
        {
            throw new DomainException("Cor {$label} inválida. Use o formato hexadecimal #RRGGBB.");
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
            throw new DomainException('Arquivos SVG não são permitidos para o escudo da equipe. Envie JPG, PNG ou WEBP.');
        }

        $extToCheck = $clientExt !== '' ? $clientExt : $guessedExt;
        if (! in_array($extToCheck, $allowedExtensions, true))
        {
            throw new DomainException('Formato de escudo inválido. Envie apenas imagens JPG, PNG ou WEBP.');
        }

        if ($mime !== '' && ! in_array($mime, $allowedMimes, true))
        {
            throw new DomainException('Formato de imagem inválido para o escudo. Envie apenas JPG, PNG ou WEBP.');
        }
    }

    private function parseDate(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '')
        {
            return null;
        }

        if ($value instanceof CarbonImmutable)
        {
            return $value->startOfDay();
        }

        if ($value instanceof CarbonInterface)
        {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    private function parseDateTime(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '')
        {
            return null;
        }

        if ($value instanceof CarbonImmutable)
        {
            return $value;
        }

        if ($value instanceof CarbonInterface)
        {
            return CarbonImmutable::instance($value);
        }

        return CarbonImmutable::parse($value);
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
