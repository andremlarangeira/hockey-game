# Grounding: Na Quadra (hockey-times) — current architecture (Phase A)

Stack: Laravel 13, PHP 8.5, Inertia v3 + Vue 3 + TypeScript, Fortify auth, Pest tests (sqlite :memory: via phpunit.xml), vite-plus (`npm run build`, `npm run types:check`, `npm run test:clock`). pt-BR UI. Pint, PHPStan config present.

## Tenancy
- Each `User` account IS a team/organization ("cada conta é um time"). There is no separate workspace model.
- `App\Models\Concerns\BelongsToTeam` trait: on creating sets `user_id = auth()->id()`, and adds a global scope `where user_id = auth()->id()`. Player, HockeyMatch, Goal use it. Route model binding (`Route::bind('match', ...)` and implicit bindings) therefore 404s cross-tenant records. Tests in tests/Feature/TenantIsolationTest.php assert isolation.
- User has logo_path (public disk, `logos/{user_id}`), primary/secondary colors. Logo upload in TeamController validates `image|mimes:jpg,jpeg,png,webp,svg|max:4096` and stores with `store()` (random name) on `public` disk.

## Casual (pickup) flow — MUST NOT regress
- players table: user_id, name, jersey_number (unique per user), photo_path, level (1–5 half stars), present.
- /sorteio (DrawController + Services/BalancedDraw): balanced random split of present players into ad-hoc colored teams (DrawTeam), client-side.
- /partida (resources/js/pages/matches/Create.vue, ~990 lines): fully client-side live match: picks two DrawTeams, runs clock via `useMatchClock` (composable) over `lib/matchClockCore.ts` (pure timestamp-based clock with periods, rest, countdown, pause/resume, goal pause), `useMatchAudio` (whistle/buzzer), `useWakeLock`, fullscreen scoreboard. Goals: big buttons → scorer → assist ("Sem assistência"), undo last goal. Draft persisted in localStorage `hockey-rascunho:{teamId}`. On finish POSTs whole match once to POST /partidas.
- StoreMatchRequest validates: settings (minutes 1–120, periods 1–6, rest_seconds, countdown_seconds, sounds), roster {home,away}{name,color,players[{id,name,jersey_number,level}]}, timeline events (pre_start,start,pause,resume,period_start,period_end,rest_start,rest_end,finish,goal_added,goal_removed), goals[{team,scorer_id,assist_id?,period,elapsed_seconds,remaining_seconds,recorded_at}]; after(): every roster player id must be one of the account's `players`, no player on both sides, scorer/assist on that side, assist != scorer, score == goal count.
- matches table (model HockeyMatch, table 'matches'): user_id, home_score, away_score, settings (json, MatchSettingsCast→App\Data\MatchSettings), roster (json snapshot, MatchRosterCast→MatchRoster/RosterTeam), timeline (json), started_at, finished_at. goals table: user_id, match_id, team('home'|'away'), scorer_id/assist_id (FK players), scorer_name/assist_name snapshots, period, elapsed/remaining seconds, recorded_at. Only ONE assist per goal today.
- /partidas index & show; /estatisticas (StatsController) aggregates ALL goals rows of the user by scorer_id/assist_id and counts games from matches.roster. => If tournament goals were written into `goals`/`matches` they would pollute casual stats unless filtered.
- Layout nav (resources/js/layouts/AppLayout.vue): Jogadores, Sorteio, Partida, Partidas, Estatísticas, Meu time. Components hockey/TeamLogo.vue, PlayerPhoto.vue. Types in resources/js/types/hockey.ts. Shared Inertia props in HandleInertiaRequests (auth.user, team{id,name,logo_url,colors}, flash.success).
- No queues, no scheduler; synchronous app. No public pages besides auth.

## Baseline (worktree /home/andre/Documents/laravel/hockey-times-campeonato, branch feat/campeonato from origin/main 7cc3f69)
- `php artisan test --compact`: 34 passed (209 assertions) after `npm run build`.
- `npm run types:check` clean; `npm run test:clock` passes.
