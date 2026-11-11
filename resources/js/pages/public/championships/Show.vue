<script setup lang="ts">
import ChampionshipTeamBadge from '@/components/championships/ChampionshipTeamBadge.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import {
    Calendar,
    CircleDot,
    Clock3,
    Goal,
    Medal,
    Trophy,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type {
    GameStatus,
    PublicChampionshipGameProp,
    PublicChampionshipShowPageProps,
    PublicPlayerLeaderRowProp,
} from '@/types/championship';

defineOptions({ layout: PublicLayout });

const props = defineProps<PublicChampionshipShowPageProps>();
type TabKey = 'classificacao' | 'jogos' | 'mata-mata' | 'estatisticas';
const activeTab = ref<TabKey>('classificacao');
const statusLabels: Record<GameStatus, string> = {
    scheduled: 'Agendado',
    live: 'Ao vivo',
    finished: 'Encerrado',
};
const phaseOrder = [
    'group',
    'round_of_16',
    'quarter_final',
    'semi_final',
    'third_place',
    'final',
];
const standingsEntries = computed(() =>
    Object.entries(props.standings).sort(([a], [b]) => a.localeCompare(b)),
);
const finishedGames = computed(() =>
    props.games.filter((game) => game.status === 'finished'),
);
const upcomingGames = computed(() =>
    props.games.filter((game) => game.status !== 'finished'),
);
const bracketGames = computed(() =>
    props.games.filter((game) => game.phase !== 'group'),
);
const hasLiveGame = computed(() =>
    props.games.some((game) => game.status === 'live'),
);
const teamsById = computed(
    () => new Map(props.teams.map((team) => [team.id, team])),
);
const groupedFinishedGames = computed(() => groupGames(finishedGames.value));
const groupedUpcomingGames = computed(() => groupGames(upcomingGames.value));
const groupedBracketGames = computed(() => groupGames(bracketGames.value));
const tabs = computed(() => [
    ['classificacao', 'Classificação'] as [TabKey, string],
    ['jogos', 'Jogos'] as [TabKey, string],
    ...(bracketGames.value.length
        ? [['mata-mata', 'Mata-mata'] as [TabKey, string]]
        : []),
    ['estatisticas', 'Estatísticas'] as [TabKey, string],
]);
const poll = usePoll(
    30_000,
    { only: ['games', 'standings', 'stats'] },
    { autoStart: false, background: 'pause' },
);

watch(hasLiveGame, (live) => (live ? poll.start() : poll.stop()), {
    immediate: true,
});
onBeforeUnmount(poll.stop);

function groupGames(games: PublicChampionshipGameProp[]) {
    const groups = new Map<string, PublicChampionshipGameProp[]>();
    for (const game of games)
        groups.set(game.phase_label, [
            ...(groups.get(game.phase_label) ?? []),
            game,
        ]);
    return [...groups.entries()].sort(
        ([, a], [, b]) =>
            phaseOrder.indexOf(a[0]?.phase ?? 'group') -
            phaseOrder.indexOf(b[0]?.phase ?? 'group'),
    );
}
function formatDateTime(value: string | null): string {
    if (!value) return 'Data a definir';
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? value
        : date.toLocaleString('pt-BR', {
              day: '2-digit',
              month: '2-digit',
              hour: '2-digit',
              minute: '2-digit',
          });
}
function gameUrl(game: PublicChampionshipGameProp): string {
    return typeof window === 'undefined'
        ? '#'
        : `${window.location.pathname.replace(/\/$/, '')}/jogos/${game.id}`;
}
function leaderValue(
    leader: PublicPlayerLeaderRowProp,
    kind: 'goals' | 'assists' | 'points',
): number {
    return leader[kind];
}
</script>

<template>
    <Head :title="championship.name" />
    <section
        class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900"
    >
        <p
            class="text-xs font-black tracking-widest text-emerald-700 uppercase dark:text-emerald-400"
        >
            Campeonato
        </p>
        <div class="mt-1 flex flex-wrap items-center gap-3">
            <h1 class="text-3xl font-black tracking-tight">
                {{ championship.name }}
            </h1>
            <span
                class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                >{{ championship.status_label }}</span
            >
        </div>
        <div
            class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-sm font-medium text-slate-600 dark:text-slate-300"
        >
            <span
                >{{ championship.city
                }}<template v-if="championship.venue">
                    · {{ championship.venue }}</template
                ></span
            ><span
                v-if="championship.starts_on"
                class="inline-flex items-center gap-1"
                ><Calendar class="size-4" /> Início
                {{
                    new Date(championship.starts_on).toLocaleDateString('pt-BR')
                }}</span
            >
        </div>
    </section>
    <nav
        class="mt-5 flex gap-1 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-1.5 dark:border-slate-700 dark:bg-slate-900"
        aria-label="Seções públicas do campeonato"
    >
        <button
            v-for="tab in tabs"
            :key="tab[0]"
            type="button"
            class="min-h-10 shrink-0 rounded-xl px-3.5 text-sm font-bold"
            :class="
                activeTab === tab[0]
                    ? 'bg-emerald-600 text-white'
                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
            "
            @click="activeTab = tab[0]"
        >
            {{ tab[1] }}
        </button>
    </nav>

    <section v-if="activeTab === 'classificacao'" class="mt-5 space-y-5">
        <div
            v-if="!standingsEntries.length"
            class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm font-medium text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400"
        >
            Os grupos ainda não foram sorteados.
        </div>
        <article
            v-for="[code, group] in standingsEntries"
            :key="code"
            class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
        >
            <div
                class="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-slate-700"
            >
                <h2 class="font-black">Grupo {{ group.group_code }}</h2>
                <span
                    v-if="group.all_games_finished"
                    class="text-xs font-bold text-emerald-700 dark:text-emerald-400"
                    >Encerrado</span
                >
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-xs">
                    <thead
                        class="bg-slate-50 text-slate-500 dark:bg-slate-800/60 dark:text-slate-400"
                    >
                        <tr>
                            <th class="px-3 py-2">#</th>
                            <th class="px-3 py-2">Time</th>
                            <th class="px-2 py-2 text-center">P</th>
                            <th class="px-2 py-2 text-center">J</th>
                            <th class="px-2 py-2 text-center">V</th>
                            <th class="px-2 py-2 text-center">E</th>
                            <th class="px-2 py-2 text-center">D</th>
                            <th class="px-3 py-2 text-center">SG</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in group.rows"
                            :key="row.team_id"
                            :class="
                                row.qualifies
                                    ? 'bg-emerald-50/80 dark:bg-emerald-950/25'
                                    : ''
                            "
                        >
                            <td class="px-3 py-2.5 font-black">
                                {{ row.position }}
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="flex min-w-36 items-center gap-2"
                                    ><ChampionshipTeamBadge
                                        :name="row.team_name"
                                        :logo-url="row.team_logo_url"
                                        :primary-color="row.primary_color"
                                        size="sm"
                                    /><span class="font-bold">{{
                                        row.team_name
                                    }}</span
                                    ><span
                                        v-if="row.qualifies"
                                        class="text-[10px] font-black text-emerald-700 dark:text-emerald-400"
                                        >CLASS.</span
                                    ></span
                                >
                            </td>
                            <td class="px-2 py-2.5 text-center font-black">
                                {{ row.points }}
                            </td>
                            <td class="px-2 py-2.5 text-center">
                                {{ row.played }}
                            </td>
                            <td class="px-2 py-2.5 text-center">
                                {{ row.wins }}
                            </td>
                            <td class="px-2 py-2.5 text-center">
                                {{ row.draws }}
                            </td>
                            <td class="px-2 py-2.5 text-center">
                                {{ row.losses }}
                            </td>
                            <td class="px-3 py-2.5 text-center">
                                {{ row.goal_difference }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section v-else-if="activeTab === 'jogos'" class="mt-5 space-y-6">
        <div
            v-if="!games.length"
            class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm font-medium text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400"
        >
            A tabela de jogos ainda não foi gerada.
        </div>
        <template v-else
            ><div>
                <h2 class="mb-3 text-lg font-black">Agenda e ao vivo</h2>
                <p
                    v-if="!groupedUpcomingGames.length"
                    class="rounded-xl bg-slate-100 p-4 text-sm text-slate-600 dark:bg-slate-900 dark:text-slate-400"
                >
                    Não há próximos jogos.
                </p>
                <div
                    v-for="[phase, phaseGames] in groupedUpcomingGames"
                    :key="phase"
                    class="mb-5"
                >
                    <h3
                        class="mb-2 text-sm font-black text-slate-600 dark:text-slate-300"
                    >
                        {{ phase }}
                    </h3>
                    <div class="grid gap-3 md:grid-cols-2">
                        <article
                            v-for="game in phaseGames"
                            :key="game.id"
                            class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
                        >
                            <div
                                class="flex items-center justify-between gap-2 text-xs font-bold text-slate-500 dark:text-slate-400"
                            >
                                <span>{{
                                    game.group_code
                                        ? `Grupo ${game.group_code} · Rodada ${game.round_number}`
                                        : game.venue || 'Partida'
                                }}</span
                                ><Link
                                    v-if="game.status === 'live'"
                                    :href="gameUrl(game)"
                                    class="inline-flex items-center gap-1 rounded-full bg-red-600 px-2 py-1 font-black text-white"
                                    ><CircleDot class="size-3 animate-pulse" />
                                    AO VIVO</Link
                                ><span v-else>{{
                                    statusLabels[game.status]
                                }}</span>
                            </div>
                            <Link :href="gameUrl(game)" class="mt-3 block"
                                ><div
                                    class="grid grid-cols-[1fr_auto_1fr] items-center gap-2"
                                >
                                    <div
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <ChampionshipTeamBadge
                                            v-if="game.home_team"
                                            :name="game.home_team.name"
                                            :short-name="
                                                game.home_team.short_name
                                            "
                                            :logo-url="game.home_team.logo_url"
                                            :primary-color="
                                                game.home_team.primary_color
                                            "
                                            :secondary-color="
                                                game.home_team.secondary_color
                                            "
                                            size="sm"
                                        /><span
                                            class="truncate text-sm font-bold"
                                            >{{
                                                game.home_team?.name ??
                                                game.home_source ??
                                                'A definir'
                                            }}</span
                                        >
                                    </div>
                                    <strong class="text-xl font-black">{{
                                        game.is_bye
                                            ? 'BYE'
                                            : `${game.home_regular_score} × ${game.away_regular_score}`
                                    }}</strong>
                                    <div
                                        class="flex min-w-0 items-center justify-end gap-2"
                                    >
                                        <span
                                            class="truncate text-right text-sm font-bold"
                                            >{{
                                                game.away_team?.name ??
                                                game.away_source ??
                                                'A definir'
                                            }}</span
                                        ><ChampionshipTeamBadge
                                            v-if="game.away_team"
                                            :name="game.away_team.name"
                                            :short-name="
                                                game.away_team.short_name
                                            "
                                            :logo-url="game.away_team.logo_url"
                                            :primary-color="
                                                game.away_team.primary_color
                                            "
                                            :secondary-color="
                                                game.away_team.secondary_color
                                            "
                                            size="sm"
                                        />
                                    </div>
                                </div>
                                <p
                                    class="mt-3 flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400"
                                >
                                    <Clock3 class="size-3.5" />
                                    {{ formatDateTime(game.scheduled_at) }}
                                </p></Link
                            >
                        </article>
                    </div>
                </div>
            </div>
            <div>
                <h2 class="mb-3 text-lg font-black">Resultados</h2>
                <p
                    v-if="!groupedFinishedGames.length"
                    class="rounded-xl bg-slate-100 p-4 text-sm text-slate-600 dark:bg-slate-900 dark:text-slate-400"
                >
                    Os resultados aparecerão aqui quando as partidas terminarem.
                </p>
                <div
                    v-for="[phase, phaseGames] in groupedFinishedGames"
                    :key="phase"
                    class="mb-5"
                >
                    <h3
                        class="mb-2 text-sm font-black text-slate-600 dark:text-slate-300"
                    >
                        {{ phase }}
                    </h3>
                    <div class="grid gap-3 md:grid-cols-2">
                        <article
                            v-for="game in phaseGames"
                            :key="game.id"
                            class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
                        >
                            <Link :href="gameUrl(game)" class="block"
                                ><div
                                    class="flex items-center justify-between text-xs font-bold text-slate-500 dark:text-slate-400"
                                >
                                    <span>{{
                                        game.group_code
                                            ? `Grupo ${game.group_code}`
                                            : game.phase_label
                                    }}</span
                                    ><span>Encerrado</span>
                                </div>
                                <div
                                    class="mt-3 grid grid-cols-[1fr_auto_1fr] items-center gap-2"
                                >
                                    <div
                                        class="flex min-w-0 items-center gap-2"
                                    >
                                        <ChampionshipTeamBadge
                                            v-if="game.home_team"
                                            :name="game.home_team.name"
                                            :short-name="
                                                game.home_team.short_name
                                            "
                                            :logo-url="game.home_team.logo_url"
                                            :primary-color="
                                                game.home_team.primary_color
                                            "
                                            :secondary-color="
                                                game.home_team.secondary_color
                                            "
                                            size="sm"
                                        /><span
                                            class="truncate text-sm font-bold"
                                            >{{
                                                game.home_team?.name ??
                                                game.home_source ??
                                                'A definir'
                                            }}</span
                                        >
                                    </div>
                                    <span class="text-center"
                                        ><strong class="text-xl font-black">{{
                                            game.is_bye
                                                ? 'BYE'
                                                : `${game.home_regular_score} × ${game.away_regular_score}`
                                        }}</strong
                                        ><small
                                            v-if="game.went_to_shootout"
                                            class="block text-[10px] text-slate-500"
                                            >Pênaltis
                                            {{ game.home_shootout_score }}–{{
                                                game.away_shootout_score
                                            }}</small
                                        ></span
                                    >
                                    <div
                                        class="flex min-w-0 items-center justify-end gap-2"
                                    >
                                        <span
                                            class="truncate text-right text-sm font-bold"
                                            >{{
                                                game.away_team?.name ??
                                                game.away_source ??
                                                'A definir'
                                            }}</span
                                        ><ChampionshipTeamBadge
                                            v-if="game.away_team"
                                            :name="game.away_team.name"
                                            :short-name="
                                                game.away_team.short_name
                                            "
                                            :logo-url="game.away_team.logo_url"
                                            :primary-color="
                                                game.away_team.primary_color
                                            "
                                            :secondary-color="
                                                game.away_team.secondary_color
                                            "
                                            size="sm"
                                        />
                                    </div></div
                            ></Link>
                        </article>
                    </div>
                </div></div
        ></template>
    </section>

    <section v-else-if="activeTab === 'mata-mata'" class="mt-5 space-y-5">
        <div v-for="[phase, phaseGames] in groupedBracketGames" :key="phase">
            <h2 class="mb-3 text-lg font-black">{{ phase }}</h2>
            <div class="grid gap-3 md:grid-cols-2">
                <article
                    v-for="game in phaseGames"
                    :key="game.id"
                    class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
                >
                    <Link :href="gameUrl(game)" class="block"
                        ><p
                            class="text-xs font-bold text-slate-500 dark:text-slate-400"
                        >
                            {{
                                game.status === 'scheduled'
                                    ? formatDateTime(game.scheduled_at)
                                    : statusLabels[game.status]
                            }}
                        </p>
                        <div class="mt-3 space-y-2">
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <span>{{
                                    game.home_team?.name ??
                                    game.home_source ??
                                    'A definir'
                                }}</span
                                ><strong>{{ game.home_regular_score }}</strong>
                            </div>
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <span>{{
                                    game.away_team?.name ??
                                    game.away_source ??
                                    'A definir'
                                }}</span
                                ><strong>{{ game.away_regular_score }}</strong>
                            </div>
                        </div></Link
                    >
                </article>
            </div>
        </div>
    </section>

    <section v-else class="mt-5 space-y-5">
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl bg-emerald-600 p-4 text-white">
                <p class="text-xs font-bold text-emerald-100">
                    Jogos encerrados
                </p>
                <p class="mt-1 text-2xl font-black">
                    {{ stats.finished_games }}/{{ stats.total_games }}
                </p>
            </div>
            <div class="rounded-2xl bg-slate-900 p-4 text-white">
                <p class="text-xs font-bold text-slate-300">Gols</p>
                <p class="mt-1 text-2xl font-black">{{ stats.total_goals }}</p>
            </div>
            <div class="rounded-2xl bg-amber-500 p-4 text-slate-950">
                <p class="text-xs font-bold">Classificação final</p>
                <p class="mt-1 text-2xl font-black">
                    {{ stats.podium.champion_team_name ?? 'Em disputa' }}
                </p>
            </div>
        </div>
        <article
            class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
        >
            <h2 class="flex items-center gap-2 font-black">
                <Trophy class="size-5 text-amber-500" /> Pódio
            </h2>
            <ol class="mt-3 grid gap-2 sm:grid-cols-3">
                <li
                    v-for="[place, id, name] in [
                        [
                            '1º',
                            stats.podium.champion_team_id,
                            stats.podium.champion_team_name,
                        ],
                        [
                            '2º',
                            stats.podium.runner_up_team_id,
                            stats.podium.runner_up_team_name,
                        ],
                        [
                            '3º',
                            stats.podium.third_place_team_id,
                            stats.podium.third_place_team_name,
                        ],
                    ]"
                    :key="place"
                    class="flex items-center gap-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60"
                >
                    <Medal class="size-5 text-amber-500" /><span
                        class="font-black"
                        >{{ place }}</span
                    ><ChampionshipTeamBadge
                        v-if="id && teamsById.get(id)"
                        :name="teamsById.get(id)!.name"
                        :short-name="teamsById.get(id)!.short_name"
                        :logo-url="teamsById.get(id)!.logo_url"
                        :primary-color="teamsById.get(id)!.primary_color"
                        :secondary-color="teamsById.get(id)!.secondary_color"
                        size="sm"
                    /><span class="text-sm font-bold">{{
                        name ?? 'A definir'
                    }}</span>
                </li>
            </ol>
        </article>
        <div class="grid gap-5 lg:grid-cols-3">
            <article
                v-for="[title, leaders, kind] in [
                    ['Artilheiros', stats.top_scorers, 'goals'],
                    ['Assistências', stats.top_assists, 'assists'],
                    ['Pontos', stats.top_point_leaders, 'points'],
                ]"
                :key="title"
                class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
            >
                <h2 class="flex items-center gap-2 font-black">
                    <Goal class="size-5 text-emerald-600" /> {{ title }}
                </h2>
                <ol v-if="leaders.length" class="mt-3 space-y-2">
                    <li
                        v-for="(leader, index) in leaders"
                        :key="leader.player_id"
                        class="flex items-center gap-3"
                    >
                        <span class="w-5 text-sm font-black text-slate-400">{{
                            index + 1
                        }}</span
                        ><span class="min-w-0 flex-1"
                            ><span class="block truncate text-sm font-bold"
                                >{{ leader.player_name }}
                                <small
                                    v-if="leader.jersey_number"
                                    class="text-slate-500"
                                    >#{{ leader.jersey_number }}</small
                                ></span
                            ><span
                                class="block truncate text-xs text-slate-500"
                                >{{ leader.team_name }}</span
                            ></span
                        ><strong>{{ leaderValue(leader, kind) }}</strong>
                    </li>
                </ol>
                <p
                    v-else
                    class="mt-3 text-sm text-slate-500 dark:text-slate-400"
                >
                    Ainda não há dados.
                </p>
            </article>
        </div>
    </section>
</template>
