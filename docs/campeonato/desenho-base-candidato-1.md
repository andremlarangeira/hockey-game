# Candidate 1 Architecture Design: Self-Contained Tournament Engine (`App\Championships`)

---

## 1. Problem

**Hockey Game** (`hockey-times`) currently supports a single-club pickup ("pelada") workflow: one account maintains its own `players`, draws balanced ad-hoc teams in memory (`/sorteio`), runs a 100% client-side scoreboard (`/partida`), and persists completed casual games in `matches` and `goals` (with at most 1 assist per goal and `StatsController` aggregating `SELECT count(*) FROM goals WHERE user_id = ?` across the entire table).

The owner now needs to organize **city-wide championships (`/campeonatos`)** inside the same account, featuring:
1. **External enrolled teams and rosters**: Teams with name, colors, logo upload, and players (name, jersey number, position, goalkeeper flag) that are **not** part of the user's pickup `players` pool and must never pollute `/jogadores` or `/estatisticas`.
2. **Configurable tournament rules before the draw**: Number of groups, qualifiers per group, extra best-placed qualifiers, points system (regulation win/draw/loss + optional overtime/shootout win/loss), ordered tie-break criteria (including head-to-head mini-table and audited manual lot drawing), period/clock settings, knockout tie resolution (`overtime_then_shootout` or `shootout_only`), third-place match toggle, and configurable max assists per goal (`0..2`, default `2`).
3. **Persistent group draw & round-robin schedule**: Single or double round-robin per group, persisted in the database so page refreshes never reshuffle groups, with venue and `scheduled_at` per game.
4. **Pure derived standings (`P, J, V, E, D, GP, GC, SG`)**: Computed deterministically from finished group games, honoring the configured tie-break chain, surfacing unresolved ties explicitly, and recording manual tie-break resolutions in an immutable audit log (`who, when, why`).
5. **Source-referenced knockout bracket**: Generated from group standings into power-of-two slots (with automatic byes if needed and sensible cross-seeding like `1A × 2B`), where each slot references its participant sources (`group:A:1`, `winner:QF1`, `loser:SF1`, `bye`) and advances winners/losers idempotently.
6. **Incremental live game scoring with existing clock & audio**: Reusing `MatchClockCore`, `useMatchClock`, `useMatchAudio`, and `useWakeLock` without touching `matches/Create.vue`, while persisting goals (with `0..2` assists), overtime goals, shootout attempts (tracked separately from regulation/OT goals and player goal stats), and period transitions to the server incrementally.
7. **Phase-locked finish, reopen, and rectification with audit log**: Protecting dependent phases (e.g., blocking modification of a group game once knockout play has started, or blocking reopen of a knockout game whose next-round slot has already started) and logging every sensitive state transition.

### Architectural Direction (Candidate 1)
We isolate all tournament concepts into a **self-contained tournament engine** with dedicated tables (`championships`, `championship_teams`, `championship_players`, `championship_games`, `championship_game_events`, `championship_audit_logs`) and a single deep domain facade: `App\Championships\TournamentEngine`.
- Casual `players`, `matches`, `goals`, `MatchController`, `StatsController`, and `resources/js/pages/matches/Create.vue` remain **100% untouched** (zero regression risk).
- **Standings are never stored**: `StandingsCalculator` is a pure function `f(teams, finishedGroupGames, rules, manualResolutions) -> map<groupCode, GroupStandings>`.
- **Bracket slots are self-describing**: Each knockout `ChampionshipGame` stores immutable `slot_key` (`QF1`, `SF1`, `3RD`, `FINAL`) and source descriptors (`home_source`, `away_source`) alongside materialized `home_team_id` and `away_team_id`.

---

## 2. Usage (Caller's View First)

Before defining schemas or internal classes, here is how callers interact with `TournamentEngine` across the three boundaries: HTTP Controllers, Pest Tests, and Vue Inertia Pages.

### 2.1 Call Site 1: Controller Action Calling the Domain

Controllers stay thin (≤15 lines per action). FormRequests validate HTTP input and construct typed command DTOs; `TournamentEngine` owns transactions, invariant checks, bracket advancement, and audit logging.

```php
<?php

namespace App\Http\Controllers\Championships;

use App\Championships\TournamentEngine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Championships\FinishChampionshipGameRequest;
use App\Http\Requests\Championships\RecordChampionshipGoalRequest;
use App\Http\Requests\Championships\ReopenChampionshipGameRequest;
use App\Models\Championship;
use App\Models\ChampionshipGame;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ChampionshipGameController extends Controller
{
    public function __construct(
        private readonly TournamentEngine $engine,
    ) {}

    public function showLive(Championship $championship, ChampionshipGame $game): Response
    {
        return Inertia::render('championships/GameLive', [
            'championship' => $this->engine->presentChampionshipHeader($championship),
            'game' => $this->engine->presentLiveGame($championship, $game),
            'sounds' => [
                'start' => ['type' => 'builtin', 'file' => '/sons/apito-inicio.wav'],
                'end' => ['type' => 'builtin', 'file' => '/sons/buzzer-fim.wav'],
                'rest_end' => ['type' => 'builtin', 'file' => '/sons/bip-fim-descanso.wav'],
            ],
        ]);
    }

    public function recordGoal(
        RecordChampionshipGoalRequest $request,
        Championship $championship,
        ChampionshipGame $game,
    ): RedirectResponse {
        $this->engine->recordGoal($championship, $game, $request->toCommand());

        return back()->with('success', 'Gol registrado na súmula.');
    }

    public function finish(
        FinishChampionshipGameRequest $request,
        Championship $championship,
        ChampionshipGame $game,
    ): RedirectResponse {
        $this->engine->finishGame($championship, $game, $request->finishedAt());

        return to_route('championships.show', $championship)
            ->with('success', 'Partida encerrada e classificação atualizada.');
    }

    public function reopen(
        ReopenChampionshipGameRequest $request,
        Championship $championship,
        ChampionshipGame $game,
    ): RedirectResponse {
        $this->engine->reopenGame(
            championship: $championship,
            game: $game,
            actorId: (int) $request->user()->id,
            reason: $request->reason(),
        );

        return back()->with('success', 'Partida reaberta para retificação.');
    }
}
```

### 2.2 Call Site 2: Pest Feature/Domain Test Calling the Domain Directly

Tests exercise the entire tournament lifecycle through `TournamentEngine` without mocking internal calculators:

```php
<?php

use App\Championships\Data\ChampionshipRules;
use App\Championships\Data\GoalCommand;
use App\Championships\Data\ShootoutAttemptCommand;
use App\Championships\Enums\ChampionshipStatus;
use App\Championships\Enums\GamePhase;
use App\Championships\Enums\GameStatus;
use App\Championships\Enums\TeamSide;
use App\Championships\TournamentEngine;
use App\Models\Championship;
use App\Models\User;

test('ciclo completo: sorteio de grupos, classificação derivada, mata-mata e pódio', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $engine = app(TournamentEngine::class);

    $championship = Championship::factory()->create([
        'name' => 'Copa Cidade de Hóquei',
        'rules' => ChampionshipRules::defaults(
            groupsCount: 2,
            qualifiersPerGroup: 2,
            hasThirdPlaceMatch: true,
            maxAssistsPerGoal: 2,
        ),
    ]);

    // 4 teams enrolled with 2 players each
    [$tigres, $lobos, $falcoes, $ursos] = seedFourTeamsWithPlayers($championship);

    // 1. Deterministic group draw (Group A: Tigres, Lobos; Group B: Falcões, Ursos)
    $engine->drawGroups($championship, [
        'A' => [$tigres->id, $lobos->id],
        'B' => [$falcoes->id, $ursos->id],
    ], actorId: $user->id);

    expect($championship->fresh()->status)->toBe(ChampionshipStatus::GroupStage)
        ->and($championship->games()->where('phase', GamePhase::Group)->count())->toBe(2);

    // 2. Play Group A game (Tigres 2 x 0 Lobos) with 2 assists on first goal
    $gameA = $championship->games()->where('group_code', 'A')->firstOrFail();
    $tigresPlayers = $tigres->players()->orderBy('jersey_number')->get();

    $engine->recordGoal($championship, $gameA, new GoalCommand(
        side: TeamSide::Home,
        scorerId: $tigresPlayers[0]->id,
        firstAssistId: $tigresPlayers[1]->id,
        secondAssistId: null,
        period: 1,
        isOvertime: false,
        elapsedSeconds: 120,
        remainingSeconds: 480,
    ));
    $engine->finishGame($championship, $gameA);

    // 3. Play Group B game (Falcões 1 x 0 Ursos)
    $gameB = $championship->games()->where('group_code', 'B')->firstOrFail();
    $falcoesScorer = $falcoes->players()->firstOrFail();
    $engine->recordGoal($championship, $gameB, new GoalCommand(
        side: TeamSide::Home,
        scorerId: $falcoesScorer->id,
        firstAssistId: null,
        secondAssistId: null,
        period: 2,
        isOvertime: false,
        elapsedSeconds: 300,
        remainingSeconds: 300,
    ));
    $engine->finishGame($championship, $gameB);

    // 4. Standings are derived on read
    $standings = $engine->computeStandings($championship);
    expect($standings['A']->rows[0]->teamId)->toBe($tigres->id)
        ->and($standings['A']->rows[0]->points)->toBe(3)
        ->and($standings['B']->rows[0]->teamId)->toBe($falcoes->id);

    // 5. Generate knockout bracket (SF1: 1A x 2B, SF2: 1B x 2A, 3RD, FINAL)
    $engine->generateKnockoutBracket($championship, actorId: $user->id);

    $sf1 = $championship->games()->where('slot_key', 'SF1')->firstOrFail();
    expect($sf1->home_team_id)->toBe($tigres->id)
        ->and($sf1->away_team_id)->toBe($ursos->id);

    // 6. SF1 tied 0x0 in regulation, decided in shootouts -> advances Tigres to FINAL and Ursos to 3RD
    $engine->recordShootoutAttempt($championship, $sf1, new ShootoutAttemptCommand(
        side: TeamSide::Home,
        shooterId: $tigresPlayers[0]->id,
        scored: true,
        roundNumber: 1,
    ));
    $engine->recordShootoutAttempt($championship, $sf1, new ShootoutAttemptCommand(
        side: TeamSide::Away,
        shooterId: $ursos->players()->firstOrFail()->id,
        scored: false,
        roundNumber: 1,
    ));
    $engine->finishGame($championship, $sf1);

    $final = $championship->games()->where('slot_key', 'FINAL')->firstOrFail();
    $third = $championship->games()->where('slot_key', '3RD')->firstOrFail();

    expect($final->home_team_id)->toBe($tigres->id)
        ->and($third->home_team_id)->toBe($ursos->id);
});
```

### 2.3 Call Site 3: Vue Page Consuming Typed Inertia Props (`GameLive.vue` & `Show.vue`)

`resources/js/pages/championships/GameLive.vue` reuses `useMatchClock`, `useMatchAudio`, and `useWakeLock` identically to `matches/Create.vue`, while posting goals, shootout attempts, and game finish to the server via Inertia `router.post`:

```vue
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useMatchClock } from '@/composables/useMatchClock';
import { useMatchAudio } from '@/composables/useMatchAudio';
import { useWakeLock } from '@/composables/useWakeLock';
import type { SoundSettings } from '@/types/hockey';
import type { ChampionshipHeaderProp, ChampionshipLiveGameProp, EnrolledPlayerProp } from '@/types/championship';

const props = defineProps<{
    championship: ChampionshipHeaderProp;
    game: ChampionshipLiveGameProp;
    sounds: SoundSettings;
}>();

const clockStorageKey = `campeonato-relogio:${props.game.id}`;
const wake = useWakeLock();
const sound = useMatchAudio();
const clock = useMatchClock(
    props.game.clock_settings,
    () => onClockTransition(),
    () => sound.countdown(),
);

// Supports 0, 1, or 2 assists according to props.championship.rules.max_assists_per_goal
const goalModal = ref<{
    side: 'home' | 'away';
    scorerId: number | null;
    firstAssistId: number | null;
    secondAssistId: number | null;
    isOvertime: boolean;
} | null>(null);

function submitGoal() {
    if (!goalModal.value || !goalModal.value.scorerId) return;
    router.post(
        `/campeonatos/${props.championship.id}/jogos/${props.game.id}/gols`,
        {
            side: goalModal.value.side,
            scorer_id: goalModal.value.scorerId,
            first_assist_id: goalModal.value.firstAssistId,
            second_assist_id: goalModal.value.secondAssistId,
            period: clock.state.period,
            is_overtime: goalModal.value.isOvertime,
            elapsed_seconds: clock.state.elapsed,
            remaining_seconds: clock.state.remaining,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                goalModal.value = null;
            },
        },
    );
}
</script>
```

---

## 3. Shape: Data Structures, Models, Enums & Domain API

### 3.1 Database Schema & Migrations

All 6 tables include `user_id` (`foreignIdFor(User::class)->constrained()->cascadeOnDelete()`) to enforce single-query tenant scoping via `BelongsToTeam`.

#### Migration 1: `create_championships_table`
| Column | Type | Constraints / Notes |
|---|---|---|
| `id` | `bigint unsigned` PK | Auto-increment |
| `user_id` | `bigint unsigned` FK | `constrained('users')->cascadeOnDelete()`, indexed |
| `name` | `varchar(120)` | Not null |
| `city` | `varchar(100)` | Not null |
| `venue` | `varchar(140)` | Nullable default venue |
| `starts_on` | `date` | Nullable |
| `ends_on` | `date` | Nullable |
| `status` | `varchar(24)` | Enum `ChampionshipStatus`: `draft`, `group_stage`, `knockout_stage`, `completed` |
| `rules` | `json` | Cast via `ChampionshipRulesCast` to `ChampionshipRules` VO |
| `manual_tie_resolutions` | `json` | Default `'[]'`. Keyed by group code: ordered team IDs when `sorteio_manual` is resolved |
| `drawn_at` | `timestamp` | Nullable |
| `bracket_generated_at` | `timestamp` | Nullable |
| `completed_at` | `timestamp` | Nullable |
| `timestamps` | `created_at`, `updated_at` | |

- **Indexes**: `INDEX(user_id, status)`

#### Migration 2: `create_championship_teams_table`
| Column | Type | Constraints / Notes |
|---|---|---|
| `id` | `bigint unsigned` PK | Auto-increment |
| `user_id` | `bigint unsigned` FK | `constrained('users')->cascadeOnDelete()` |
| `championship_id` | `bigint unsigned` FK | `constrained('championships')->cascadeOnDelete()` |
| `name` | `varchar(100)` | Not null |
| `short_name` | `varchar(24)` | Nullable |
| `primary_color` | `char(7)` | Default `'#2563eb'` |
| `secondary_color` | `char(7)` | Default `'#ffffff'` |
| `logo_path` | `varchar(255)` | Nullable (`public` disk under `championships/{user_id}/logos/`) |
| `group_code` | `varchar(8)` | Nullable (`'A'`, `'B'`, etc. assigned on draw) |
| `group_draw_order` | `smallint unsigned` | Nullable (position within group schedule table `1..N`) |
| `seed_pot` | `smallint unsigned` | Nullable (optional pot `1..N` for seeded draw) |
| `timestamps` | `created_at`, `updated_at` | |

- **Unique Constraints**:
  - `UNIQUE(championship_id, name)` — prevents duplicate team names in the same championship.
  - `UNIQUE(championship_id, group_code, group_draw_order)` — prevents two teams occupying the same draw slot in a group.

#### Migration 3: `create_championship_players_table`
| Column | Type | Constraints / Notes |
|---|---|---|
| `id` | `bigint unsigned` PK | Auto-increment |
| `user_id` | `bigint unsigned` FK | `constrained('users')->cascadeOnDelete()` |
| `championship_id` | `bigint unsigned` FK | `constrained('championships')->cascadeOnDelete()` |
| `championship_team_id` | `bigint unsigned` FK | `constrained('championship_teams')->cascadeOnDelete()` |
| `name` | `varchar(100)` | Not null |
| `jersey_number` | `smallint unsigned` | Not null (`0..999`) |
| `position` | `varchar(24)` | Enum `PlayerPosition`: `goalkeeper`, `defender`, `forward`, `universal` |
| `is_goalkeeper` | `boolean` | Default `false` |
| `timestamps` | `created_at`, `updated_at` | |

- **Unique Constraints**:
  - `UNIQUE(championship_team_id, jersey_number)` — no two players on the same enrolled team can share a jersey number.
- **Indexes**:
  - `INDEX(championship_id, championship_team_id)`

#### Migration 4: `create_championship_games_table`
| Column | Type | Constraints / Notes |
|---|---|---|
| `id` | `bigint unsigned` PK | Auto-increment |
| `user_id` | `bigint unsigned` FK | `constrained('users')->cascadeOnDelete()` |
| `championship_id` | `bigint unsigned` FK | `constrained('championships')->cascadeOnDelete()` |
| `phase` | `varchar(24)` | Enum `GamePhase`: `group`, `round_of_16`, `quarter_final`, `semi_final`, `third_place`, `final` |
| `group_code` | `varchar(8)` | Nullable (set only when `phase = 'group'`, e.g. `'A'`) |
| `round_number` | `smallint unsigned` | Not null (matchday `1..N` in group, or stage order `1..4` in knockout) |
| `leg_number` | `tinyint unsigned` | Default `1` (`1` for single round-robin / knockout, `2` for return leg in double round-robin) |
| `slot_key` | `varchar(32)` | Not null! For group games: `'GRP-A-L1-1-2'` (deterministic pair key). For knockout: `'R16-1'`, `'QF1'`, `'SF1'`, `'3RD'`, `'FINAL'` |
| `bracket_position` | `smallint unsigned` | Nullable (`1..8` ordering within knockout round) |
| `home_source` | `varchar(48)` | Not null (e.g. `'team:14'`, `'group:A:1'`, `'best_extra:1'`, `'winner:QF1'`, `'loser:SF1'`, `'bye'`) |
| `away_source` | `varchar(48)` | Not null |
| `home_team_id` | `bigint unsigned` FK | Nullable `constrained('championship_teams')->nullOnDelete()` |
| `away_team_id` | `bigint unsigned` FK | Nullable `constrained('championship_teams')->nullOnDelete()` |
| `venue` | `varchar(140)` | Nullable |
| `scheduled_at` | `datetime` | Nullable |
| `status` | `varchar(24)` | Enum `GameStatus`: `scheduled`, `live`, `finished` |
| `is_bye` | `boolean` | Default `false` |
| `home_regular_score` | `smallint unsigned` | Default `0` (includes regulation + overtime goals) |
| `away_regular_score` | `smallint unsigned` | Default `0` (includes regulation + overtime goals) |
| `went_to_overtime` | `boolean` | Default `false` |
| `went_to_shootout` | `boolean` | Default `false` |
| `home_shootout_score` | `smallint unsigned` | Default `0` |
| `away_shootout_score` | `smallint unsigned` | Default `0` |
| `winner_team_id` | `bigint unsigned` FK | Nullable `constrained('championship_teams')->nullOnDelete()` |
| `started_at` | `datetime` | Nullable |
| `finished_at` | `datetime` | Nullable |
| `timestamps` | `created_at`, `updated_at` | |

- **Unique Constraints**:
  - `UNIQUE(championship_id, slot_key)` — makes duplicate bracket slots and duplicate group games structurally impossible at the database level.
  - `UNIQUE(championship_id, phase, group_code, leg_number, home_team_id, away_team_id)` — guarantees a group pair `(home, away)` cannot be scheduled twice in the same leg.
- **Indexes**:
  - `INDEX(championship_id, phase, status)`
  - `INDEX(championship_id, group_code, status)`

#### Migration 5: `create_championship_game_events_table`
| Column | Type | Constraints / Notes |
|---|---|---|
| `id` | `bigint unsigned` PK | Auto-increment |
| `user_id` | `bigint unsigned` FK | `constrained('users')->cascadeOnDelete()` |
| `championship_id` | `bigint unsigned` FK | `constrained('championships')->cascadeOnDelete()` |
| `championship_game_id` | `bigint unsigned` FK | `constrained('championship_games')->cascadeOnDelete()` |
| `type` | `varchar(24)` | Enum `GameEventType`: `goal`, `shootout_attempt`, `period_mark` |
| `side` | `varchar(8)` | Nullable Enum `TeamSide`: `home`, `away` |
| `team_id` | `bigint unsigned` FK | Nullable `constrained('championship_teams')->nullOnDelete()` |
| `primary_player_id` | `bigint unsigned` FK | Nullable `constrained('championship_players')->nullOnDelete()` (scorer or shootout shooter) |
| `first_assist_player_id` | `bigint unsigned` FK | Nullable `constrained('championship_players')->nullOnDelete()` |
| `second_assist_player_id` | `bigint unsigned` FK | Nullable `constrained('championship_players')->nullOnDelete()` |
| `period` | `tinyint unsigned` | Nullable (`1..6`, or `99` for shootout) |
| `is_overtime` | `boolean` | Default `false` |
| `elapsed_seconds` | `integer unsigned` | Default `0` |
| `remaining_seconds` | `integer unsigned` | Default `0` |
| `shootout_round` | `tinyint unsigned` | Nullable (`1..50` when `type = 'shootout_attempt'`) |
| `shootout_scored` | `boolean` | Nullable (when `type = 'shootout_attempt'`) |
| `period_mark_type` | `varchar(24)` | Nullable (`start`, `period_start`, `period_end`, `overtime_start`, `shootout_start`, `finish`) |
| `recorded_at` | `datetime` | Not null |
| `deleted_at` | `datetime` | Nullable (SoftDeletes — when a goal/attempt is undone or rectified, it is soft-deleted rather than silently erased!) |
| `timestamps` | `created_at`, `updated_at` | |

- **Indexes**:
  - `INDEX(championship_game_id, type, deleted_at)`
  - `INDEX(championship_id, type, deleted_at, primary_player_id)`
  - `INDEX(championship_id, type, deleted_at, first_assist_player_id)`
  - `INDEX(championship_id, type, deleted_at, second_assist_player_id)`

#### Migration 6: `create_championship_audit_logs_table`
| Column | Type | Constraints / Notes |
|---|---|---|
| `id` | `bigint unsigned` PK | Auto-increment |
| `user_id` | `bigint unsigned` FK | `constrained('users')->cascadeOnDelete()` |
| `championship_id` | `bigint unsigned` FK | `constrained('championships')->cascadeOnDelete()` |
| `championship_game_id` | `bigint unsigned` FK | Nullable `constrained('championship_games')->nullOnDelete()` |
| `actor_user_id` | `bigint unsigned` FK | `constrained('users')->cascadeOnDelete()` |
| `action` | `varchar(48)` | e.g. `groups_drawn`, `groups_reset`, `tie_break_resolved`, `bracket_generated`, `bracket_reset`, `game_finished`, `game_reopened`, `event_removed` |
| `reason` | `varchar(500)` | Nullable (required for `tie_break_resolved`, `game_reopened`, `groups_reset`, `bracket_reset`) |
| `before_snapshot` | `json` | Nullable |
| `after_snapshot` | `json` | Nullable |
| `recorded_at` | `datetime` | Not null |

- **Indexes**:
  - `INDEX(championship_id, recorded_at)`

---

### 3.2 PHP 8.5 Backed Enums (`app/Championships/Enums/`)

```php
<?php

namespace App\Championships\Enums;

enum ChampionshipStatus: string
{
    case Draft = 'draft';
    case GroupStage = 'group_stage';
    case KnockoutStage = 'knockout_stage';
    case Completed = 'completed';
}

enum GamePhase: string
{
    case Group = 'group';
    case RoundOf16 = 'round_of_16';
    case QuarterFinal = 'quarter_final';
    case SemiFinal = 'semi_final';
    case ThirdPlace = 'third_place';
    case Final = 'final';

    public function isKnockout(): bool
    {
        return $this !== self::Group;
    }

    public function label(): string
    {
        return match ($this) {
            self::Group => 'Fase de grupos',
            self::RoundOf16 => 'Oitavas de final',
            self::QuarterFinal => 'Quartas de final',
            self::SemiFinal => 'Semifinal',
            self::ThirdPlace => 'Disputa de 3º lugar',
            self::Final => 'Final',
        };
    }
}

enum GameStatus: string
{
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Finished = 'finished';
}

enum GameEventType: string
{
    case Goal = 'goal';
    case ShootoutAttempt = 'shootout_attempt';
    case PeriodMark = 'period_mark';
}

enum TeamSide: string
{
    case Home = 'home';
    case Away = 'away';
}

enum PlayerPosition: string
{
    case Goalkeeper = 'goalkeeper';
    case Defender = 'defender';
    case Forward = 'forward';
    case Universal = 'universal';
}

enum TieBreakCriterion: string
{
    case Points = 'points';
    case HeadToHead = 'head_to_head';
    case Wins = 'wins';
    case GoalDifference = 'goal_difference';
    case GoalsFor = 'goals_for';
    case FewestGoalsAgainst = 'fewest_goals_against';
    case ManualDraw = 'manual_draw';
}

enum KnockoutTieResolution: string
{
    case OvertimeThenShootout = 'overtime_then_shootout';
    case ShootoutOnly = 'shootout_only';
}
```

---

### 3.3 Value Objects & DTOs (`app/Championships/Data/`)

```php
<?php

namespace App\Championships\Data;

use App\Championships\Enums\KnockoutTieResolution;
use App\Championships\Enums\TeamSide;
use App\Championships\Enums\TieBreakCriterion;
use JsonSerializable;

final readonly class ChampionshipRules implements JsonSerializable
{
    /**
     * @param list<TieBreakCriterion> $tieBreakOrder
     */
    public function __construct(
        public int $groupsCount,
        public int $qualifiersPerGroup,
        public int $extraBestQualifiers,
        public bool $doubleRoundRobin,
        public int $pointsWin,
        public int $pointsDraw,
        public int $pointsLoss,
        public bool $allowGroupOvertimeOrShootout,
        public int $pointsOvertimeWin,
        public int $pointsOvertimeLoss,
        public array $tieBreakOrder,
        public int $periodMinutes,
        public int $periodsCount,
        public int $restSeconds,
        public int $countdownSeconds,
        public int $overtimeMinutes,
        public KnockoutTieResolution $knockoutTieResolution,
        public bool $hasThirdPlaceMatch,
        public int $maxAssistsPerGoal,
    ) {}

    public static function defaults(
        int $groupsCount = 2,
        int $qualifiersPerGroup = 2,
        bool $hasThirdPlaceMatch = true,
        int $maxAssistsPerGoal = 2,
    ): self {
        throw new \LogicException('not implemented');
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        throw new \LogicException('not implemented');
    }

    public function totalQualifiers(): int
    {
        return ($this->groupsCount * $this->qualifiersPerGroup) + $this->extraBestQualifiers;
    }

    public function jsonSerialize(): mixed
    {
        throw new \LogicException('not implemented');
    }
}

final readonly class StandingRow
{
    public function __construct(
        public int $position,
        public int $teamId,
        public string $teamName,
        public ?string $teamLogoUrl,
        public string $primaryColor,
        public string $groupCode,
        public int $points,          // P
        public int $played,          // J
        public int $wins,            // V
        public int $draws,           // E
        public int $losses,          // D
        public int $goalsFor,        // GP
        public int $goalsAgainst,    // GC
        public int $goalDifference,  // SG
        public bool $qualifies,
        public bool $tiedWithNext,   // True when all criteria exhausted and manual draw not yet recorded
        public ?string $resolvedByCriterion,
    ) {}
}

final readonly class GroupStandings
{
    /**
     * @param list<StandingRow> $rows
     * @param list<list<int>> $unresolvedTieGroups Each inner list is a set of teamIds still tied
     */
    public function __construct(
        public string $groupCode,
        public array $rows,
        public bool $allGamesFinished,
        public array $unresolvedTieGroups,
    ) {}
}

final readonly class GoalCommand
{
    public function __construct(
        public TeamSide $side,
        public int $scorerId,
        public ?int $firstAssistId,
        public ?int $secondAssistId,
        public int $period,
        public bool $isOvertime,
        public int $elapsedSeconds,
        public int $remainingSeconds,
    ) {}
}

final readonly class ShootoutAttemptCommand
{
    public function __construct(
        public TeamSide $side,
        public int $shooterId,
        public bool $scored,
        public int $roundNumber,
    ) {}
}

final readonly class GameScheduleCommand
{
    public function __construct(
        public ?string $venue,
        public ?\Carbon\CarbonImmutable $scheduledAt,
    ) {}
}

final readonly class PlayerLeaderRow
{
    public function __construct(
        public int $playerId,
        public string $playerName,
        public int $jerseyNumber,
        public int $teamId,
        public string $teamName,
        public ?string $teamLogoUrl,
        public int $goals,
        public int $assists,
        public int $points, // G + A
        public int $gamesPlayed,
    ) {}
}

final readonly class Podium
{
    public function __construct(
        public ?int $championTeamId,
        public ?int $runnerUpTeamId,
        public ?int $thirdPlaceTeamId,
    ) {}
}

final readonly class ChampionshipSummary
{
    /**
     * @param array<string, GroupStandings> $standingsByGroup
     * @param list<PlayerLeaderRow> $topScorers
     * @param list<PlayerLeaderRow> $topAssists
     * @param list<PlayerLeaderRow> $topPointLeaders
     */
    public function __construct(
        public array $standingsByGroup,
        public Podium $podium,
        public int $totalGames,
        public int $finishedGames,
        public int $totalGoals,
        public array $topScorers,
        public array $topAssists,
        public array $topPointLeaders,
    ) {}
}
```

---

### 3.4 Eloquent Models (`app/Models/`)

All 6 models use `App\Models\Concerns\BelongsToTeam` for automatic tenant scoping (`where user_id = auth()->id()`) and automatic `user_id` assignment on creation.

- `App\Models\Championship`:
  - `casts()`: `'status' => ChampionshipStatus::class`, `'rules' => ChampionshipRulesCast::class`, `'manual_tie_resolutions' => 'array'`, `'starts_on' => 'immutable_date'`, `'ends_on' => 'immutable_date'`, `'drawn_at' => 'immutable_datetime'`, `'bracket_generated_at' => 'immutable_datetime'`, `'completed_at' => 'immutable_datetime'`.
  - Relations: `teams(): HasMany<ChampionshipTeam>`, `players(): HasMany<ChampionshipPlayer>`, `games(): HasMany<ChampionshipGame>`, `events(): HasMany<ChampionshipGameEvent>`, `auditLogs(): HasMany<ChampionshipAuditLog>`.
- `App\Models\ChampionshipTeam`:
  - Accessor `getLogoUrlAttribute(): ?string` returning `$this->logo_path ? Storage::disk('public')->url($this->logo_path) : null`.
  - Relations: `championship(): BelongsTo`, `players(): HasMany<ChampionshipPlayer>`.
- `App\Models\ChampionshipPlayer`:
  - `casts()`: `'position' => PlayerPosition::class`, `'is_goalkeeper' => 'boolean'`.
  - Relations: `championship(): BelongsTo`, `team(): BelongsTo<ChampionshipTeam>`.
- `App\Models\ChampionshipGame`:
  - `casts()`: `'phase' => GamePhase::class`, `'status' => GameStatus::class`, `'is_bye' => 'boolean'`, `'went_to_overtime' => 'boolean'`, `'went_to_shootout' => 'boolean'`, `'scheduled_at' => 'immutable_datetime'`, `'started_at' => 'immutable_datetime'`, `'finished_at' => 'immutable_datetime'`.
  - Relations: `championship(): BelongsTo`, `homeTeam(): BelongsTo<ChampionshipTeam>`, `awayTeam(): BelongsTo<ChampionshipTeam>`, `winnerTeam(): BelongsTo<ChampionshipTeam>`, `events(): HasMany<ChampionshipGameEvent>`.
- `App\Models\ChampionshipGameEvent`:
  - Uses `BelongsToTeam`, `SoftDeletes`.
  - `casts()`: `'type' => GameEventType::class`, `'side' => TeamSide::class`, `'is_overtime' => 'boolean'`, `'shootout_scored' => 'boolean'`, `'recorded_at' => 'immutable_datetime'`.
  - Relations: `game(): BelongsTo<ChampionshipGame>`, `primaryPlayer(): BelongsTo<ChampionshipPlayer>`, `firstAssistPlayer(): BelongsTo<ChampionshipPlayer>`, `secondAssistPlayer(): BelongsTo<ChampionshipPlayer>`.
- `App\Models\ChampionshipAuditLog`:
  - `casts()`: `'before_snapshot' => 'array'`, `'after_snapshot' => 'array'`, `'recorded_at' => 'immutable_datetime'`.

---

### 3.5 Tracing the 5 Dominant Access Patterns Through the Data Structure

1. **Render Group Standings (`StandingsCalculator::calculate`)**:
   - **Query**: 2 queries total (or eager-loaded on `Championship`):
     - `SELECT * FROM championship_teams WHERE championship_id = ? AND group_code IS NOT NULL`
     - `SELECT * FROM championship_games WHERE championship_id = ? AND phase = 'group' AND status = 'finished'` (covered by `INDEX(championship_id, group_code, status)`).
   - **Processing**: In-memory pass over finished games accumulates `P, J, V, E, D, GP, GC, SG` per team. Tie-breaks (including head-to-head mini-table among tied teams) filter the already-loaded `finishedGames` collection in memory—zero extra SQL queries.

2. **Render Knockout Bracket**:
   - **Query**: `SELECT * FROM championship_games WHERE championship_id = ? AND phase != 'group' ORDER BY round_number, bracket_position` with eager-loaded `homeTeam`, `awayTeam`, `winnerTeam` (covered by `INDEX(championship_id, phase, status)`).
   - **Processing**: Each slot already has `slot_key` (`QF1`, `SF1`, `3RD`, `FINAL`), `home_source` / `away_source` (human-readable labels like `"1º Grupo A"` or `"Vencedor QF1"` when a slot is still unresolved), and materialized `homeTeam`/`awayTeam` + scores when resolved.

3. **Advance Winner (and Loser to 3rd Place) on `finishGame`**:
   - **Query & Update inside transaction**:
     - When a knockout game with `slot_key = 'SF1'` finishes with `winner_team_id = W` and loser `L`:
     - Update downstream slots by matching source reference:
       - `UPDATE championship_games SET home_team_id = W WHERE championship_id = ? AND home_source = 'winner:SF1'`
       - `UPDATE championship_games SET away_team_id = W WHERE championship_id = ? AND away_source = 'winner:SF1'`
       - `UPDATE championship_games SET home_team_id = L WHERE championship_id = ? AND home_source = 'loser:SF1'`
       - `UPDATE championship_games SET away_team_id = L WHERE championship_id = ? AND away_source = 'loser:SF1'`
     - If `slot_key = 'FINAL'` finishes (and `3RD` is either disabled or also `finished`), transition `championship.status = Completed` and set `completed_at = now()`.

4. **Record Goal (with 0..2 Assists) or Shootout Attempt**:
   - **Validation & Insert inside transaction**:
     - Verify `game->status !== Finished` and `scorer` / `assists` belong to the active side's `championship_team_id`, are distinct player IDs, and assist count $\le$ `$championship->rules->maxAssistsPerGoal`.
     - Insert row into `championship_game_events` (`type = 'goal'` or `'shootout_attempt'`).
     - Recompute `home_regular_score` / `away_regular_score` (from non-deleted `type = 'goal'` events) and `home_shootout_score` / `away_shootout_score` (from non-deleted `type = 'shootout_attempt'` with `shootout_scored = true`) on `championship_games`, transitioning `status` from `scheduled` to `live` if needed.

5. **Summary Stats & Podium (`computeSummary`)**:
   - **Goals & Assists Leaderboard**:
     - Aggregates non-deleted `championship_game_events` where `type = 'goal'` (regulation + overtime only; `shootout_attempt` rows are automatically excluded by `type = 'goal'`) joined with `championship_games WHERE status = 'finished'` (covered by `INDEX(championship_id, type, deleted_at, primary_player_id)` and the assist indexes).
     - Each player's `goals` = count where `primary_player_id = player.id`; `assists` = count where `first_assist_player_id = player.id` + count where `second_assist_player_id = player.id`; `points = goals + assists`.
   - **Podium**:
     - Derived directly from the `FINAL` and `3RD` games:
       - If `FINAL` is `finished`, `championTeamId = final.winner_team_id`, `runnerUpTeamId = final.loser_team_id`. Otherwise `null` (never shown prematurely).
       - If `rules.hasThirdPlaceMatch` is true: `thirdPlaceTeamId = thirdPlaceGame.status === Finished ? thirdPlaceGame.winner_team_id : null`.
       - If `rules.hasThirdPlaceMatch` is false and `FINAL` is `finished`: `thirdPlaceTeamId` is the semi-final loser with the better overall tournament campaign (or `null` if not applicable).

---

### 3.6 Single Source of Truth & Idempotent State Transitions

| Concept | Stored or Derived? | Single Source of Truth & Justification |
|---|---|---|
| **Group Standings (`P, J, V, E, D, GP, GC, SG`)** | **Derived on read** | Calculated by `StandingsCalculator` from `championship_games WHERE phase = 'group' AND status = 'finished'`. Eliminates dual-write drift when a game is reopened or rectified. A city championship has 10–60 group games, which takes `< 1 ms` to compute in PHP. |
| **Manual Tie-Break Resolution** | **Stored on `championships.manual_tie_resolutions` + `championship_audit_logs`** | Because drawing lots is an external human decision, the chosen relative ordering for a tied tuple in group `G` is stored as `{"A": [12, 15]}` alongside an immutable audit log entry (`actor, timestamp, reason`). If the teams' points or goals later change (e.g. a game is reopened), the calculator only consults `manual_tie_resolutions` if those exact teams are still tied after all objective criteria. |
| **Game Score (`home_regular_score`, `away_regular_score`, `home_shootout_score`, `away_shootout_score`)** | **Materialized on `championship_games` from `championship_game_events`** | Recomputed inside the same DB transaction whenever a `ChampionshipGameEvent` is added or soft-deleted (`COUNT(*) WHERE type = 'goal' AND deleted_at IS NULL`). Readers of standings and brackets never need to `GROUP BY` events just to display game scores. |
| **Knockout Bracket Slots & Advancement** | **Stored slots with source references (`home_source`, `away_source`) + materialized `home_team_id` / `away_team_id`** | The bracket topology is fixed at generation time (`slot_key` unique per championship). `home_team_id` and `away_team_id` are materialized from `home_source` / `away_source` when the source resolves (at bracket generation for `group:X:N` or `bye`, and on `finishGame` for `winner:SLOT` / `loser:SLOT`). |
| **Player Statistics & Podium** | **Derived on read** | Aggregated from `championship_game_events (type = 'goal', deleted_at IS NULL)` of finished games and the `FINAL`/`3RD` slots. Casual `goals` and `matches` tables are untouched. |

#### Idempotency & Phase-Lock Rules
1. **`drawGroups` called twice**:
   - If the passed group assignment is identical to the current `group_code`/`group_draw_order` and no group game has started, returns cleanly (no-op).
   - If a re-draw is requested while `status === GroupStage` and **zero** group games have `status !== Scheduled` or any events, `drawGroups(..., forceRedraw: true, reason: '...')` deletes the unstarted group games, writes a `groups_reset` audit log, and creates the new round-robin schedule.
   - If **any** group game is `live` or `finished`, or knockout bracket exists, throws `DomainException('Não é possível refazer o sorteio após o início dos jogos.')`.
2. **`generateKnockoutBracket` called twice**:
   - First checks that all group games are `finished` and no group has `unresolvedTieGroups` among qualifying boundary positions.
   - Uses `UNIQUE(championship_id, slot_key)` upserts inside a transaction. If the bracket already exists and **no** knockout game has started (`status === Scheduled` and 0 events), re-running `generateKnockoutBracket` idempotently updates the slots' `home_team_id`/`away_team_id` (and auto-advances `bye` slots) without creating duplicate games.
   - If any knockout game is `live` or `finished`, throws `DomainException('A chave mata-mata já possui jogos iniciados.')`.
3. **`finishGame` called twice**:
   - If `$game->status === GameStatus::Finished`, returns `$game` immediately (idempotent no-op).
   - Validates that a knockout game is not tied (if regulation/OT score is tied, shootout score must have a winner).
4. **`reopenGame` phase-lock guard**:
   - **Group game**: Cannot be reopened if any knockout game is `live` or `finished`. If knockout bracket was generated (`bracket_generated_at !== null`) and all knockout games are still `scheduled` (except auto-byes), reopening a group game requires `resetBracketIfUnstarted: true` (confirmed in UI), which removes the unstarted knockout slots, clears `bracket_generated_at`, reverts `championship.status` to `GroupStage`, and logs both `bracket_reset` and `game_reopened` in `championship_audit_logs`.
   - **Knockout game**: Cannot be reopened if any downstream game referencing `winner:{$game->slot_key}` or `loser:{$game->slot_key}` has `status !== Scheduled` or any recorded events. If downstream games are still `scheduled`, reopening clears `winner_team_id` on `$game`, sets `$game->status = GameStatus::Live`, and resets the corresponding `home_team_id` / `away_team_id` on downstream slots back to `null`, recording a `game_reopened` audit log with `actor_user_id`, `reason`, and before/after snapshots.

---

### 3.7 Domain Service Signatures & Pseudocode (`app/Championships/`)

The public domain surface is **`App\Championships\TournamentEngine`**, which delegates pure computation to internal collaborators **`StandingsCalculator`** and **`BracketPlanner`**.

```php
<?php

namespace App\Championships;

use App\Championships\Data\ChampionshipRules;
use App\Championships\Data\ChampionshipSummary;
use App\Championships\Data\GameScheduleCommand;
use App\Championships\Data\GoalCommand;
use App\Championships\Data\GroupStandings;
use App\Championships\Data\ShootoutAttemptCommand;
use App\Models\Championship;
use App\Models\ChampionshipGame;
use App\Models\ChampionshipGameEvent;
use App\Models\ChampionshipPlayer;
use App\Models\ChampionshipTeam;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

final readonly class TournamentEngine
{
    public function __construct(
        private StandingsCalculator $standingsCalculator,
        private BracketPlanner $bracketPlanner,
    ) {}

    public function createChampionship(
        int $userId,
        string $name,
        string $city,
        ?string $venue,
        ?CarbonImmutable $startsOn,
        ?CarbonImmutable $endsOn,
        ChampionshipRules $rules,
    ): Championship {
        throw new \LogicException('not implemented');
    }

    public function updateRules(Championship $championship, ChampionshipRules $rules): Championship
    {
        // Guard: rules can only be changed while $championship->status === ChampionshipStatus::Draft
        throw new \LogicException('not implemented');
    }

    public function enrollTeam(
        Championship $championship,
        string $name,
        ?string $shortName,
        string $primaryColor,
        string $secondaryColor,
        ?UploadedFile $logo = null,
        ?int $seedPot = null,
    ): ChampionshipTeam {
        // Guard: teams can only be added/removed while status === Draft
        // Stores logo on 'public' disk under "championships/{$championship->user_id}/logos"
        throw new \LogicException('not implemented');
    }

    public function enrollPlayer(
        Championship $championship,
        ChampionshipTeam $team,
        string $name,
        int $jerseyNumber,
        \App\Championships\Enums\PlayerPosition $position,
        bool $isGoalkeeper,
    ): ChampionshipPlayer {
        throw new \LogicException('not implemented');
    }

    /**
     * Randomly (or via seeded pots) assigns enrolled teams to groups A..N, or accepts an explicit map,
     * persists team group assignments, and generates the round-robin schedule.
     *
     * @param array<string, list<int>>|null $explicitGroups Map of groupCode => list of teamIds, or null to draw randomly
     */
    public function drawGroups(
        Championship $championship,
        ?array $explicitGroups,
        int $actorId,
        bool $forceRedraw = false,
        ?string $redrawReason = null,
    ): Championship {
        // TODO pseudocode:
        // 1. Validate $championship->teams()->count() >= $rules->groupsCount * 2
        //    and $rules->qualifiersPerGroup <= minGroupSize.
        // 2. If $championship->status !== Draft:
        //      - If $explicitGroups matches current team groups and schedule exists, return $championship (idempotent).
        //      - If !$forceRedraw or any game has status != Scheduled or bracket_generated_at != null:
        //          throw DomainException('Não é possível sortear novamente após o início das partidas.');
        //      - Delete existing scheduled group games and log 'groups_reset' with $redrawReason.
        // 3. If $explicitGroups is null:
        //      - Group teams by seed_pot (null pot last), shuffle within each pot, snake-distribute across groups 'A', 'B', ...
        // 4. Inside DB::transaction:
        //      - Update each ChampionshipTeam with group_code and group_draw_order (1..K).
        //      - For each group, run Circle Method (Berger tables) to generate round-robin pairings:
        //          - Leg 1: slot_key = "GRP-{$group}-L1-{$t1}-{$t2}", home_source = "team:{$t1}", away_source = "team:{$t2}"
        //          - If $rules->doubleRoundRobin: Leg 2 swaps home/away with slot_key = "GRP-{$group}-L2-{$t2}-{$t1}"
        //      - Set championship->status = GroupStage, drawn_at = now().
        //      - Write ChampionshipAuditLog('groups_drawn').
        throw new \LogicException('not implemented');
    }

    /**
     * Records an explicit manual tie-break ordering (drawing lots / "sorteio") for tied teams in a group.
     *
     * @param list<int> $orderedTeamIds
     */
    public function resolveGroupTieManually(
        Championship $championship,
        string $groupCode,
        array $orderedTeamIds,
        int $actorId,
        string $reason,
    ): Championship {
        // Validates that $orderedTeamIds belong to $groupCode and that $reason is non-empty.
        // Saves $championship->manual_tie_resolutions[$groupCode] = $orderedTeamIds
        // and records ChampionshipAuditLog('tie_break_resolved', reason: $reason, before, after).
        throw new \LogicException('not implemented');
    }

    /**
     * Generates (or idempotently refreshes before kickoff) the knockout bracket slots from finished group standings.
     */
    public function generateKnockoutBracket(
        Championship $championship,
        int $actorId,
        bool $forceResetUnstarted = false,
        ?string $resetReason = null,
    ): Championship {
        // TODO pseudocode:
        // 1. Compute standings via $this->standingsCalculator->calculate($championship).
        // 2. Assert every group has allGamesFinished === true.
        // 3. Assert no group has unresolvedTieGroups that cross or affect qualified positions.
        //    If any exist, throw DomainException('Há um empate técnico no grupo X que exige sorteio de desempate antes de gerar o mata-mata.').
        // 4. Check existing knockout games:
        //    - If any knockout game has status != Scheduled (and is_bye == false), throw DomainException.
        // 5. Build slot specs via $this->bracketPlanner->buildKnockoutSlots($championship->rules, $standings).
        // 6. Inside DB::transaction:
        //    - Upsert each slot by (championship_id, slot_key).
        //    - For any first-round slot with is_bye == true (where away_source === 'bye' and home_team_id !== null):
        //        mark status = Finished, winner_team_id = home_team_id, finished_at = now(),
        //        and propagate winner_team_id into downstream slots where home_source/away_source === "winner:{$slotKey}".
        //    - Update championship->status = KnockoutStage, bracket_generated_at = now().
        //    - Write ChampionshipAuditLog('bracket_generated').
        throw new \LogicException('not implemented');
    }

    public function scheduleGame(
        Championship $championship,
        ChampionshipGame $game,
        GameScheduleCommand $command,
    ): ChampionshipGame {
        throw new \LogicException('not implemented');
    }

    public function recordGoal(
        Championship $championship,
        ChampionshipGame $game,
        GoalCommand $command,
    ): ChampionshipGameEvent {
        // TODO pseudocode:
        // 1. Assert $game->championship_id === $championship->id, !$game->is_bye, $game->status !== Finished.
        // 2. Resolve active teamId = $command->side === Home ? $game->home_team_id : $game->away_team_id (must not be null).
        // 3. Validate $command->scorerId belongs to $teamId.
        // 4. Validate assist count <= $championship->rules->maxAssistsPerGoal:
        //    - If maxAssistsPerGoal < 1 and $command->firstAssistId !== null -> throw DomainException
        //    - If maxAssistsPerGoal < 2 and $command->secondAssistId !== null -> throw DomainException
        //    - If secondAssistId !== null and firstAssistId === null -> normalize or reject
        //    - Assert scorerId, firstAssistId, secondAssistId are pairwise distinct and all belong to $teamId.
        // 5. Inside DB::transaction:
        //    - Create ChampionshipGameEvent(type: Goal, ...).
        //    - Recalculate $game->home_regular_score and $game->away_regular_score from active Goal events.
        //    - If $command->isOvertime, set $game->went_to_overtime = true.
        //    - If $game->status === Scheduled, set $game->status = Live and $game->started_at ??= now().
        //    - Save $game.
        throw new \LogicException('not implemented');
    }

    public function recordShootoutAttempt(
        Championship $championship,
        ChampionshipGame $game,
        ShootoutAttemptCommand $command,
    ): ChampionshipGameEvent {
        // Validates that $game is in a state that permits shootouts (knockout game tied after regulation/OT,
        // or group game when rules->allowGroupOvertimeOrShootout is true and regular score is tied).
        // Validates shooterId belongs to side's team.
        // Inserts ChampionshipGameEvent(type: ShootoutAttempt, period: 99, ...) and updates home/away_shootout_score.
        throw new \LogicException('not implemented');
    }

    public function removeGameEvent(
        Championship $championship,
        ChampionshipGame $game,
        ChampionshipGameEvent $event,
        int $actorId,
        ?string $reason = null,
    ): void {
        // Guard: $game->status !== Finished (must reopen first if finished).
        // Soft-deletes $event, recalculates $game scores, and writes ChampionshipAuditLog('event_removed').
        throw new \LogicException('not implemented');
    }

    public function finishGame(
        Championship $championship,
        ChampionshipGame $game,
        ?CarbonImmutable $finishedAt = null,
    ): ChampionshipGame {
        // TODO pseudocode:
        // 1. If $game->status === Finished: return $game (idempotent).
        // 2. Assert $game->home_team_id !== null && $game->away_team_id !== null.
        // 3. Recompute regular & shootout scores from active events.
        // 4. Determine winner_team_id:
        //    - If home_regular_score > away_regular_score -> winner = home_team_id
        //    - Else if away_regular_score > home_regular_score -> winner = away_team_id
        //    - Else (regular score tied):
        //        - If $game->phase->isKnockout() or $championship->rules->allowGroupOvertimeOrShootout:
        //            - Assert $game->went_to_shootout && $game->home_shootout_score !== $game->away_shootout_score
        //              (else throw DomainException('Empate no mata-mata exige definição na prorrogação ou pênaltis/shootout.')).
        //            - winner = home_shootout_score > away_shootout_score ? home_team_id : away_team_id
        //        - Else (group draw allowed):
        //            - winner = null
        // 5. Inside DB::transaction:
        //    - Update $game: status = Finished, winner_team_id = $winner, started_at ??= now(), finished_at = $finishedAt ?? now().
        //    - If $game->phase->isKnockout():
        //        - $loser = ($winner === $game->home_team_id) ? $game->away_team_id : $game->home_team_id;
        //        - Advance winner: update knockout slots where home_source = "winner:{$game->slot_key}" -> home_team_id = $winner
        //          and away_source = "winner:{$game->slot_key}" -> away_team_id = $winner.
        //        - Advance loser (for 3RD place): update slots where home_source = "loser:{$game->slot_key}" -> home_team_id = $loser
        //          and away_source = "loser:{$game->slot_key}" -> away_team_id = $loser.
        //        - If all knockout games in $championship are now Finished:
        //            set $championship->status = Completed, completed_at = now().
        //    - Write ChampionshipAuditLog('game_finished').
        throw new \LogicException('not implemented');
    }

    public function reopenGame(
        Championship $championship,
        ChampionshipGame $game,
        int $actorId,
        string $reason,
        bool $resetUnstartedBracket = false,
    ): ChampionshipGame {
        // TODO pseudocode (Reopen Guard):
        // 1. Assert $game->status === Finished && trim($reason) !== ''.
        // 2. If $game->phase === Group:
        //    - Query knockout games of $championship.
        //    - If any non-bye knockout game has status !== Scheduled or has events:
        //        throw DomainException('Não é possível reabrir um jogo da fase de grupos pois o mata-mata já foi iniciado.');
        //    - If knockout games exist (all unstarted):
        //        if (!$resetUnstartedBracket) {
        //            throw DomainException('A chave mata-mata já foi gerada. Confirme o reinício da chave para reabrir este jogo.');
        //        }
        //        Delete knockout games, set bracket_generated_at = null, championship->status = GroupStage,
        //        and log 'bracket_reset'.
        // 3. If $game->phase->isKnockout():
        //    - Find downstream games where home_source or away_source IN ("winner:{$game->slot_key}", "loser:{$game->slot_key}").
        //    - If any downstream game has status !== Scheduled or has events:
        //        throw DomainException('Não é possível reabrir esta partida pois a fase seguinte já foi iniciada.');
        //    - Clear home_team_id / away_team_id on those downstream games where the source matches $game->slot_key.
        // 4. Inside DB::transaction:
        //    - Snapshot $game before state.
        //    - Set $game->status = Live, $game->winner_team_id = null, $game->finished_at = null.
        //    - If $championship->status === Completed, revert to KnockoutStage (or GroupStage) and clear completed_at.
        //    - Write ChampionshipAuditLog('game_reopened', reason: $reason, before, after).
        throw new \LogicException('not implemented');
    }

    /** @return array<string, GroupStandings> */
    public function computeStandings(Championship $championship): array
    {
        return $this->standingsCalculator->calculate($championship);
    }

    public function computeSummary(Championship $championship): ChampionshipSummary
    {
        throw new \LogicException('not implemented');
    }
}
```

---

### 3.8 Pure Standings & Tie-Break Calculator (`app/Championships/StandingsCalculator.php`)

```php
<?php

namespace App\Championships;

use App\Championships\Data\ChampionshipRules;
use App\Championships\Data\GroupStandings;
use App\Championships\Data\StandingRow;
use App\Championships\Enums\GamePhase;
use App\Championships\Enums\GameStatus;
use App\Championships\Enums\TieBreakCriterion;
use App\Models\Championship;
use App\Models\ChampionshipGame;
use App\Models\ChampionshipTeam;
use Illuminate\Support\Collection;

final class StandingsCalculator
{
    /**
     * Pure calculation of group standings from enrolled teams and finished group games.
     *
     * @return array<string, GroupStandings>
     */
    public function calculate(Championship $championship): array
    {
        // TODO pseudocode:
        // 1. Load teams grouped by group_code, and all group games for $championship.
        // 2. For each groupCode ('A', 'B', ...):
        //    a. Filter finishedGames = groupGames->where('status', GameStatus::Finished).
        //    b. Build overall stats per teamId:
        //       - played (J), wins (V), draws (E), losses (D), goalsFor (GP), goalsAgainst (GC), goalDifference (SG = GP - GC)
        //       - points (P):
        //           * Regulation win: $rules->pointsWin
        //           * Regulation draw (when !allowGroupOvertimeOrShootout): $rules->pointsDraw
        //           * Overtime/shootout win (when went_to_overtime || went_to_shootout): $rules->pointsOvertimeWin
        //           * Overtime/shootout loss: $rules->pointsOvertimeLoss
        //           * Regulation loss: $rules->pointsLoss
        //    c. Partition teams into buckets by primary points (or walk the configured $rules->tieBreakOrder sequentially):
        //       - Start with a single bucket of all teams in the group, and refine buckets through each criterion in $rules->tieBreakOrder:
        //         * TieBreakCriterion::Points -> sort/split by overall P DESC
        //         * TieBreakCriterion::Wins -> sort/split by overall V DESC
        //         * TieBreakCriterion::GoalDifference -> sort/split by overall SG DESC
        //         * TieBreakCriterion::GoalsFor -> sort/split by overall GP DESC
        //         * TieBreakCriterion::FewestGoalsAgainst -> sort/split by overall GC ASC
        //         * TieBreakCriterion::HeadToHead ->
        //             Only evaluated when bucket size >= 2:
        //             Build a mini-table using ONLY finishedGames where BOTH home_team_id and away_team_id are in the current tied bucket.
        //             Rank teams in the bucket by (h2h_points DESC, h2h_goal_difference DESC, h2h_goals_for DESC).
        //             If the mini-table separates the bucket into sub-buckets (e.g. 3-way tie reduced to 1st + 2-way tie),
        //             recursively re-apply HeadToHead on any remaining sub-bucket whose size shrank, then continue to the next criterion!
        //         * TieBreakCriterion::ManualDraw ->
        //             Look up $championship->manual_tie_resolutions[$groupCode] ?? [].
        //             If all teamIds in the current bucket appear in the manual resolution list, split them in that exact order
        //             and mark resolvedByCriterion = 'manual_draw'.
        //    d. Any bucket that STILL has > 1 team after exhausting $rules->tieBreakOrder:
        //       - Record the bucket's teamIds in $unresolvedTieGroups.
        //       - Sort deterministically by group_draw_order ASC for display stability, and set tiedWithNext = true on affected rows.
        //    e. Assign 1-based positions and set qualifies = ($pos <= $rules->qualifiersPerGroup).
        // 3. If $rules->extraBestQualifiers > 0 (e.g., best 3rd-placed teams across groups):
        //    - Collect the ($rules->qualifiersPerGroup + 1)-th row from each group, rank them by (P, V, SG, GP, -GC),
        //      and mark the top $rules->extraBestQualifiers rows with qualifies = true.
        throw new \LogicException('not implemented');
    }
}
```

---

### 3.9 Knockout Bracket Seeding, Cross-Seeding & Byes (`app/Championships/BracketPlanner.php`)

```php
<?php

namespace App\Championships;

use App\Championships\Data\ChampionshipRules;
use App\Championships\Data\GroupStandings;

final class BracketPlanner
{
    /**
     * Builds the complete list of knockout slot specifications (QF, SF, 3RD, FINAL)
     * with source references and initial resolved team IDs.
     *
     * @param array<string, GroupStandings> $standingsByGroup
     * @return list<array{
     *     phase: \App\Championships\Enums\GamePhase,
     *     round_number: int,
     *     slot_key: string,
     *     bracket_position: int,
     *     home_source: string,
     *     away_source: string,
     *     home_team_id: int|null,
     *     away_team_id: int|null,
     *     is_bye: bool
     * }>
     */
    public function buildKnockoutSlots(ChampionshipRules $rules, array $standingsByGroup): array
    {
        // TODO pseudocode:
        // 1. Collect all qualified teams with their source keys:
        //    - For 2 groups (A, B) with 2 qualifiers each (4 qualifiers -> Semifinals):
        //        Standard cross-seeding:
        //        SF1: group:A:1 vs group:B:2
        //        SF2: group:B:1 vs group:A:2
        //    - For 2 groups (A, B) with 4 qualifiers each (8 qualifiers -> Quarterfinals):
        //        QF1: group:A:1 vs group:B:4
        //        QF2: group:B:2 vs group:A:3
        //        QF3: group:B:1 vs group:A:4
        //        QF4: group:A:2 vs group:B:3
        //        (ensures 1A and 1B are on opposite halves of the bracket and cannot meet before FINAL).
        //    - For 4 groups (A, B, C, D) with 2 qualifiers each (8 qualifiers -> Quarterfinals):
        //        QF1: group:A:1 vs group:B:2
        //        QF2: group:C:1 vs group:D:2
        //        QF3: group:B:1 vs group:A:2
        //        QF4: group:D:1 vs group:C:2
        //    - General case (any Q qualifiers, including extraBestQualifiers or non-power-of-two Q):
        //        * Compute bracket size B = 2 ^ ceil(log2(Q)), where 2 <= B <= 16.
        //        * Rank all Q qualifiers globally into seeds 1..Q:
        //          First by group finish tier (all 1st-place teams sorted by campaign, then all 2nd-place teams, ..., then extra qualifiers).
        //        * Number of byes = B - Q. Top (B - Q) seeds receive a first-round bye (away_source = 'bye', is_bye = true).
        //        * Pair seeds into B/2 first-round slots using standard bracket fold (Seed k vs Seed B + 1 - k),
        //          applying a single swap among equal-tier lower seeds if needed to avoid same-group clashes in the first round.
        // 2. Build subsequent rounds recursively:
        //    - Each next-round slot i takes home_source = "winner:{prevRoundSlot(2i-1)}", away_source = "winner:{prevRoundSlot(2i)}".
        // 3. When reaching Semifinals (2 slots: SF1, SF2):
        //    - If $rules->hasThirdPlaceMatch is true:
        //        Create slot '3RD' (phase = ThirdPlace, home_source = 'loser:SF1', away_source = 'loser:SF2').
        //    - Create slot 'FINAL' (phase = Final, home_source = 'winner:SF1', away_source = 'winner:SF2').
        throw new \LogicException('not implemented');
    }
}
```

---

### 3.10 Encoding Invariants: Types & DB Constraints > Runtime Checks > Comments

1. **Database Constraints (Hardest Guarantee)**:
   - `UNIQUE(championship_id, name)` on `championship_teams` — impossible to register two teams with the same name in a championship.
   - `UNIQUE(championship_team_id, jersey_number)` on `championship_players` — impossible for two players on the same team to wear the same number.
   - `UNIQUE(championship_id, slot_key)` on `championship_games` — impossible to duplicate a bracket slot (`QF1`, `SF1`, `3RD`, `FINAL`) or group fixture (`GRP-A-L1-1-2`).
   - `UNIQUE(championship_id, phase, group_code, leg_number, home_team_id, away_team_id)` on `championship_games` — impossible to generate duplicate group matches.
2. **Type System & Value Objects**:
   - Backed enums (`ChampionshipStatus`, `GamePhase`, `GameStatus`, `GameEventType`, `TeamSide`, `TieBreakCriterion`, `KnockoutTieResolution`, `PlayerPosition`) reject invalid strings at deserialization time.
   - `ChampionshipRules` readonly value object encapsulates all rule parameters so no controller or calculator reads raw associative arrays.
   - `GoalCommand`, `ShootoutAttemptCommand`, `GameScheduleCommand` prevent passing raw `Request` data into `TournamentEngine`.
3. **Boundary FormRequests (`app/Http/Requests/Championships/`)**:
   - `StoreChampionshipRequest` / `UpdateChampionshipRulesRequest`: validates `groups_count` (`1..8`), `qualifiers_per_group` (`1..8`), `max_assists_per_goal` (`0..2`), distinct `tie_break_order` items from `TieBreakCriterion`, and clock bounds (`period_minutes` `1..120`, `periods_count` `1..6`).
   - `StoreChampionshipTeamRequest`: validates `logo` with `['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'dimensions:max_width=2048,max_height=2048', 'max:4096']` (raster formats only—excludes SVG to eliminate stored XSS on uploaded external team logos; stored via `$file->store("championships/{$userId}/logos", 'public')`).
   - `RecordChampionshipGoalRequest`: validates `scorer_id`, `first_assist_id`, `second_assist_id` at the HTTP boundary and converts to `GoalCommand`.

---

### 3.11 Tenancy & Route Bindings

Every new model uses `App\Models\Concerns\BelongsToTeam`. In `routes/web.php`, explicit/implicit bindings verify parent-child hierarchy in addition to tenant scope:
- `Championship` resolves via `BelongsToTeam` global scope (`where user_id = auth()->id()`). Cross-tenant access returns `404 Not Found`.
- Child bindings (`{team}`, `{player}`, `{game}`, `{event}`) are scoped to `{championship}` using `Route::scopeBindings()` so `/campeonatos/{championshipA}/jogos/{gameFromChampionshipB}` automatically 404s even within the same tenant.

---

### 3.12 Inertia Routes (pt-BR URLs), Vue Pages & Clock Reuse

#### Routes (`routes/web.php` inside `Route::middleware(['auth'])->scopeBindings()->group(...)`)

| Verb | URI (pt-BR) | Route Name | Controller @ Action | Purpose |
|---|---|---|---|---|
| `GET` | `/campeonatos` | `championships.index` | `ChampionshipController@index` | List championships + create modal/form |
| `POST` | `/campeonatos` | `championships.store` | `ChampionshipController@store` | Create championship with rules |
| `GET` | `/campeonatos/{championship}` | `championships.show` | `ChampionshipController@show` | Hub: tabs for Equipes, Grupos & Classificação, Jogos, Mata-Mata, Resumo & Pódio, Auditoria |
| `PATCH` | `/campeonatos/{championship}/regras` | `championships.rules.update` | `ChampionshipController@updateRules` | Update rules (only while `draft`) |
| `POST` | `/campeonatos/{championship}/equipes` | `championships.teams.store` | `ChampionshipTeamController@store` | Enroll team + logo upload |
| `PATCH` | `/campeonatos/{championship}/equipes/{team}` | `championships.teams.update` | `ChampionshipTeamController@update` | Edit team / replace logo |
| `DELETE` | `/campeonatos/{championship}/equipes/{team}` | `championships.teams.destroy` | `ChampionshipTeamController@destroy` | Delete team (only while `draft`) |
| `POST` | `/campeonatos/{championship}/equipes/{team}/jogadores` | `championships.players.store` | `ChampionshipPlayerController@store` | Enroll player on team |
| `DELETE` | `/campeonatos/{championship}/equipes/{team}/jogadores/{player}` | `championships.players.destroy` | `ChampionshipPlayerController@destroy` | Remove player |
| `POST` | `/campeonatos/{championship}/sorteio-grupos` | `championships.groups.draw` | `ChampionshipStageController@drawGroups` | Perform / confirm persistent group draw & schedule |
| `POST` | `/campeonatos/{championship}/desempate-manual` | `championships.groups.resolve-tie` | `ChampionshipStageController@resolveTie` | Record audited manual tie-break resolution |
| `POST` | `/campeonatos/{championship}/gerar-mata-mata` | `championships.bracket.generate` | `ChampionshipStageController@generateBracket` | Generate knockout bracket from group standings |
| `PATCH` | `/campeonatos/{championship}/jogos/{game}/agendamento` | `championships.games.schedule` | `ChampionshipGameController@schedule` | Set venue & datetime |
| `GET` | `/campeonatos/{championship}/jogos/{game}` | `championships.games.live` | `ChampionshipGameController@showLive` | Live scoreboard + clock + goal/shootout recording |
| `POST` | `/campeonatos/{championship}/jogos/{game}/gols` | `championships.games.goals.store` | `ChampionshipGameController@recordGoal` | Record regulation/OT goal (0..2 assists) |
| `POST` | `/campeonatos/{championship}/jogos/{game}/penaltis` | `championships.games.shootouts.store` | `ChampionshipGameController@recordShootout` | Record shootout attempt |
| `DELETE` | `/campeonatos/{championship}/jogos/{game}/eventos/{event}` | `championships.games.events.destroy` | `ChampionshipGameController@removeEvent` | Undo/remove goal or shootout attempt |
| `POST` | `/campeonatos/{championship}/jogos/{game}/finalizar` | `championships.games.finish` | `ChampionshipGameController@finish` | Finish game & advance bracket |
| `POST` | `/campeonatos/{championship}/jogos/{game}/reabrir` | `championships.games.reopen` | `ChampionshipGameController@reopen` | Reopen finished game (with reason & phase-lock check) |

#### Navigation & Vue Pages (`resources/js/`)
- `resources/js/layouts/AppLayout.vue`: Add `{ title: 'Campeonatos', compact: 'Campeonato', href: '/campeonatos', icon: Trophy }` to `navigation`.
- `resources/js/types/championship.ts`: TypeScript interfaces matching all Inertia props (`ChampionshipProp`, `ChampionshipRulesProp`, `EnrolledTeamProp`, `EnrolledPlayerProp`, `StandingRowProp`, `GroupStandingsProp`, `ChampionshipGameProp`, `ChampionshipLiveGameProp`, `ChampionshipSummaryProp`).
- `resources/js/pages/championships/Index.vue`: List of championships with status badges (`Rascunho`, `Fase de grupos`, `Mata-mata`, `Encerrado`) and creation modal with editable rule defaults.
- `resources/js/pages/championships/Show.vue`: Championship management & summary hub with 5 focused sub-components in `resources/js/components/championships/`:
  - `TeamsAndRostersTab.vue` (team cards, logo upload preview, roster table with position & goalkeeper badge, rules editor before draw)
  - `GroupsAndStandingsTab.vue` (draw confirmation dialog, group tables `P J V E D GP GC SG`, unresolved tie warning banner + audited manual resolution modal, round-robin fixtures list with venue/datetime editor)
  - `KnockoutBracketTab.vue` (generate bracket confirmation dialog, visual bracket columns Oitavas → Quartas → Semifinais → 3º Lugar & Final with source labels and scores)
  - `ChampionshipSummaryTab.vue` (podium cards for 1º, 2º, 3º when determined, tournament totals, Artilharia, Assistências, and Participações em Gols `G+A`)
  - `AuditLogTab.vue` (chronological audit trail of draws, manual tie-breaks, game reopens, and rectifications)
- `resources/js/pages/championships/GameLive.vue`:
  - **How it reuses the clock without breaking `matches/Create.vue`**:
    - Imports `useMatchClock`, `useMatchAudio`, `useWakeLock`, and `formatClock` directly—**zero modifications** to `matchClockCore.ts`, `useMatchClock.ts`, `useMatchAudio.ts`, `useWakeLock.ts`, or `matches/Create.vue`.
    - Initializes `useMatchClock` with `props.game.clock_settings` (derived from `championship.rules`: `minutes: rules.periodMinutes`, `periods: rules.periodsCount`, `rest_seconds: rules.restSeconds`, `countdown_seconds: rules.countdownSeconds`).
    - Persists running timer state in `localStorage` under `campeonato-relogio:${props.game.id}` (isolated from casual `hockey-rascunho:${teamId}`), so refreshing the browser mid-period preserves millisecond-accurate clock state while `props.game.events` and scores come authoritatively from the server via Inertia props.
    - When regulation ends tied in a knockout game:
      - If `rules.knockoutTieResolution === 'overtime_then_shootout'`, offers an **"Iniciar Prorrogação"** button that reconfigures the clock (`clock.reset(); clock.configure({ minutes: rules.overtimeMinutes, periods: 1, rest_seconds: 0, countdown_seconds: rules.countdownSeconds }); clock.start()`) and sets `isOvertime = true` for goals scored in that period.
      - Offers a **"Disputa de Shootout / Pênaltis"** panel that records each attempt (`shooter_id`, `scored`, `round_number`) via `POST /campeonatos/{championship}/jogos/{game}/penaltis`, keeping shootout tallies visually and statistically separate from regulation/OT goals.

---

## 4. Tradeoffs Accepted

1. **Six new database tables instead of reusing `matches` / `goals` / `players`**:
   - *Cost*: More migration files and dedicated Eloquent models (`Championship*`).
   - *Benefit*: Complete isolation from the casual pickup flow (`StatsController`, `StoreMatchRequest`, `PlayerController`), native foreign keys to `championship_teams` and `championship_players`, native support for 0..2 assists and shootout attempts, and zero risk of championship games polluting club pickup statistics.
2. **Standings computed on read rather than persisted in a `championship_standings` table**:
   - *Cost*: Every render of `championships/Show` runs `StandingsCalculator::calculate` over the championship's finished group games.
   - *Benefit*: For realistic city championships (4–16 teams, 6–60 group games), in-memory calculation takes `< 1 ms`, cannot go out of sync when a game is reopened or a goal is removed, and makes multi-team head-to-head mini-table evaluation trivial and bug-free.
3. **Incremental server persistence on `GameLive.vue` vs single batch POST at game end**:
   - *Cost*: Recording a goal or shootout attempt makes a lightweight Inertia `POST` request during the game.
   - *Benefit*: Championship games are official tournament records; incremental persistence prevents losing an entire game's scoresheet if a referee's phone battery dies in the final minute, while `localStorage` (`campeonato-relogio:{gameId}`) still keeps the 200ms clock tick 100% client-side.

---

## 5. Alternatives Considered

### Alternative A: Reuse `players`, `matches`, and `goals` with nullable `championship_id` and JSON metadata
- **Description**: Add nullable `championship_id`, `championship_team_id` to `players`, `championship_id`, `phase`, `group_code`, `slot_key` to `matches`, and `second_assist_id`, `is_shootout` to `goals`.
- **Why rejected (judged on interface depth & invariant safety)**:
  - **Shallow, leaky interface**: Every existing query in `PlayerController`, `DrawController`, `MatchController`, and `StatsController` would require `.whereNull('championship_id')` guards; forgetting even one guard leaks external tournament players or goals into the user's pickup club roster and stats.
  - **Broken DB constraints**: `players` has `UNIQUE(user_id, jersey_number)`. In a championship with 8 external teams, multiple teams will have a `#10` player—requiring dropping or hacking the existing unique index on `players`.
  - **JSON roster mismatch**: `HockeyMatch::$roster` is a casual snapshot of `level` (1–5 stars) and `present`, whereas tournament games link relational `home_team_id` / `away_team_id` that can be `NULL` in unresolved future bracket slots.

### Alternative B: Persisted `championship_standings` table updated via Eloquent Model Observers / Events
- **Description**: Store `points, played, wins, draws, losses, goals_for, goals_against` in a `championship_standings` table and increment/decrement rows whenever a `ChampionshipGame` transitions to/from `finished`.
- **Why rejected (judged on interface depth & single source of truth)**:
  - **Dual source of truth**: Incremental "+3 points" observers cannot compute head-to-head mini-tables (`confronto direto` among $k$ tied teams) without querying all finished games anyway.
  - **Fragile reopen/rectify**: Reopening a finished game, editing a goal, or changing a shootout outcome requires inverse state mutations across multiple observer hooks. Computing standings as a pure function of `finished` games collapses the entire state synchronization problem to zero.

---

## 6. Open Questions & Risks

1. **Non-power-of-two qualifiers & Byes**:
   - *Decision*: `BracketPlanner` supports power-of-two brackets ($2, 4, 8, 16$). If `totalQualifiers()` is not a power of two (e.g., 3 groups × 2 qualifiers = 6 teams), `BracketPlanner` rounds up to the next power of two ($8$) and assigns automatic first-round `BYE` slots to the top $8 - 6 = 2$ group winners, auto-advancing them on bracket generation.
2. **Double Round-Robin ("Turno e Returno")**:
   - *Decision*: Included as a boolean toggle `doubleRoundRobin` (default `false`) in `ChampionshipRules`. When `true`, `drawGroups` generates Leg 1 and Leg 2 with swapped home/away teams and distinct `slot_key`s (`GRP-A-L1-1-2` and `GRP-A-L2-2-1`).
3. **Public Shareable Summary Link**:
   - *Decision*: Default is authenticated inside `/campeonatos/{championship}` (matching the rest of the app, which has no public pages). Because all read data is assembled by `TournamentEngine::computeSummary($championship)`, adding an opt-in signed/token read-only route later requires only a 10-line controller calling the exact same method with `withoutGlobalScope('team')`.

---

## 7. Next Implementation Step

Start immediately with **Unit 1 (Database Migrations, Backed Enums, Value Objects & Models)** and run `php artisan test --compact` to verify schema creation, unique constraints, and `BelongsToTeam` tenant isolation before writing the pure calculators in Unit 2.

---

## 8. Ordered Implementation Plan of Verifiable Units

Each unit is self-contained, keeps the test suite green, and follows a strict build order (Migrations & Models → Pure Calculators → Tournament Engine → HTTP Controllers/Requests → Vue UI).

### Unit 1: Migrations, Backed Enums, `ChampionshipRules` VO & Eloquent Models
- **Files to create**:
  - `database/migrations/2026_11_11_000001_create_championships_table.php`
  - `database/migrations/2026_11_11_000002_create_championship_teams_table.php`
  - `database/migrations/2026_11_11_000003_create_championship_players_table.php`
  - `database/migrations/2026_11_11_000004_create_championship_games_table.php`
  - `database/migrations/2026_11_11_000005_create_championship_game_events_table.php`
  - `database/migrations/2026_11_11_000006_create_championship_audit_logs_table.php`
  - `app/Championships/Enums/{ChampionshipStatus,GamePhase,GameStatus,GameEventType,TeamSide,PlayerPosition,TieBreakCriterion,KnockoutTieResolution}.php`
  - `app/Championships/Data/{ChampionshipRules,StandingRow,GroupStandings,GoalCommand,ShootoutAttemptCommand,GameScheduleCommand,PlayerLeaderRow,Podium,ChampionshipSummary}.php`
  - `app/Casts/ChampionshipRulesCast.php`
  - `app/Models/{Championship,ChampionshipTeam,ChampionshipPlayer,ChampionshipGame,ChampionshipGameEvent,ChampionshipAuditLog}.php`
  - `database/factories/{ChampionshipFactory,ChampionshipTeamFactory,ChampionshipPlayerFactory}.php`
- **Tests to write**:
  - `tests/Feature/Championships/ChampionshipSchemaAndTenancyTest.php`:
    - Verifies `BelongsToTeam` global scope isolates all 6 models across two `User` tenants.
    - Verifies DB unique constraints reject duplicate team names in a championship, duplicate jersey numbers on the same team (while allowing same jersey number on different teams!), and duplicate `slot_key`s in `championship_games`.
    - Verifies `ChampionshipRulesCast` round-trips all rule properties cleanly.
- **Done-check**: `php artisan test --compact tests/Feature/Championships/ChampionshipSchemaAndTenancyTest.php` passes.

### Unit 2: Pure Calculators (`StandingsCalculator` & `BracketPlanner`)
- **Files to create**:
  - `app/Championships/StandingsCalculator.php`
  - `app/Championships/BracketPlanner.php`
- **Tests to write**:
  - `tests/Unit/Championships/StandingsCalculatorTest.php`:
    - Computes `P, J, V, E, D, GP, GC, SG` solely from `finished` games (ignoring `scheduled` and `live` games).
    - Honors custom point rules (e.g., 3-2-1-0 with OT/shootout win/loss vs 3-1-0 with draws).
    - Resolves 2-team and 3-team ties via head-to-head mini-table (`confronto direto`), goal difference, goals for, fewest goals against, and wins in any configured order.
    - Flags `unresolvedTieGroups` and `tiedWithNext = true` when all objective criteria are exhausted, and resolves them once `manual_tie_resolutions` is provided.
    - Computes `extraBestQualifiers` across groups.
  - `tests/Unit/Championships/BracketPlannerTest.php`:
    - Generates cross-seeded slots (`1A × 2B`, `1B × 2A` for 4 qualifiers; `QF1..QF4`, `SF1..SF2`, `3RD`, `FINAL` for 8 qualifiers).
    - Handles non-power-of-two qualifiers (e.g., 6 qualifiers → 8-slot bracket with 2 top-seed `is_bye` slots).
    - Omits `3RD` slot when `hasThirdPlaceMatch === false`.
- **Done-check**: `php artisan test --compact tests/Unit/Championships/` passes.

### Unit 3: Core Domain Service (`TournamentEngine`) — Enrollment, Group Draw, Scoring, Advancement, Reopen & Summary
- **Files to create**:
  - `app/Championships/TournamentEngine.php`
- **Tests to write**:
  - `tests/Feature/Championships/TournamentEngineLifecycleTest.php`:
    - Group draw creates round-robin schedule (single and double), persists groups, and is idempotent / blocks redraw once games start.
    - `recordGoal` validates roster membership, enforces `maxAssistsPerGoal` (`0..2`), rejects self-assist or duplicate assist, and updates `home_regular_score` / `away_regular_score`.
    - `recordShootoutAttempt` tracks shootout score separately and does NOT increment regulation score or player goal stats.
    - `finishGame` is idempotent, rejects tied knockout games without a shootout winner, and advances winner to `winner:{slot}` and semifinal loser to `loser:{slot}` (`3RD`).
    - `reopenGame` enforces phase-lock guards (blocks reopening group game when knockout has started; blocks reopening SF when FINAL has started; clears downstream unstarted slot when allowed) and writes `ChampionshipAuditLog`.
    - `computeSummary` returns accurate top scorers, top assists (counting both 1st and 2nd assists), `G+A` leaders, and reveals podium positions only when `FINAL` / `3RD` are `finished`.
    - Casual `StatsController` (`/estatisticas`) remains completely unaffected by championship goals and games.
- **Done-check**: `php artisan test --compact tests/Feature/Championships/TournamentEngineLifecycleTest.php` passes.

### Unit 4: HTTP Layer — Routes, FormRequests, Controllers & Logo Upload Security
- **Files to create / update**:
  - `routes/web.php` (add `/campeonatos` route group)
  - `app/Http/Requests/Championships/{StoreChampionshipRequest,UpdateChampionshipRulesRequest,StoreChampionshipTeamRequest,StoreChampionshipPlayerRequest,DrawGroupsRequest,ResolveGroupTieRequest,RecordChampionshipGoalRequest,RecordShootoutAttemptRequest,ScheduleGameRequest,FinishChampionshipGameRequest,ReopenChampionshipGameRequest}.php`
  - `app/Http/Controllers/Championships/{ChampionshipController,ChampionshipTeamController,ChampionshipPlayerController,ChampionshipStageController,ChampionshipGameController}.php`
- **Tests to write**:
  - `tests/Feature/Championships/ChampionshipHttpTest.php`:
    - End-to-end HTTP tests for creating a championship, uploading raster team logos (`Storage::fake('public')`, rejecting SVG/non-images), enrolling players, drawing groups, resolving manual ties with audit reason, scheduling games, recording live goals/shootouts, finishing, reopening, and viewing the summary.
    - Cross-tenant and cross-championship route-binding isolation tests (`assertNotFound()`).
- **Done-check**: `php artisan test --compact tests/Feature/Championships/ChampionshipHttpTest.php` passes.

### Unit 5: Vue / Inertia Frontend — Championship Hub, Bracket, Summary & Live Game Scoreboard
- **Files to create / update**:
  - `resources/js/types/championship.ts`
  - `resources/js/layouts/AppLayout.vue` (add `/campeonatos` nav item)
  - `resources/js/pages/championships/Index.vue`
  - `resources/js/pages/championships/Show.vue`
  - `resources/js/pages/championships/GameLive.vue`
  - `resources/js/components/championships/{TeamsAndRostersTab,GroupsAndStandingsTab,KnockoutBracketTab,ChampionshipSummaryTab,AuditLogTab}.vue`
- **Tests & verification to run**:
  - `npm run types:check` (TypeScript strict check across all Vue pages and props)
  - `npm run test:clock` (verifies `matchClockCore` has zero regressions)
  - `npm run build`
  - `php artisan test --compact` (full Pest test suite including existing 34 casual tests + all new championship tests)
- **Done-check**: `npm run types:check && npm run test:clock && npm run build && php artisan test --compact` all pass with zero warnings or errors.
