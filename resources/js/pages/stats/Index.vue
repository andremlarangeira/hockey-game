<script setup lang="ts">
import PlayerPhoto from '@/components/hockey/PlayerPhoto.vue';
import type { Player } from '@/types/hockey';
import { Head } from '@inertiajs/vue3';
import {
    ArrowUpDown,
    Award,
    Search,
    Sparkles,
    Target,
    Trophy,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

type StatsPlayer = Player & {
    goals: number;
    assists: number;
    points: number;
    games: number;
};

type SortKey = 'points' | 'goals' | 'assists' | 'games' | 'ppg';

const props = defineProps<{
    players: StatsPlayer[];
}>();

const search = ref('');
const sortKey = ref<SortKey>('points');
const sortDesc = ref(true);

function pointsPerGame(player: StatsPlayer): number {
    return player.games > 0 ? player.points / player.games : 0;
}

function formatPpg(player: StatsPlayer): string {
    if (player.games <= 0) return '—';
    return pointsPerGame(player).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function setSort(key: SortKey) {
    if (sortKey.value === key) {
        sortDesc.value = !sortDesc.value;
    } else {
        sortKey.value = key;
        sortDesc.value = true;
    }
}

const totalGoals = computed(() =>
    props.players.reduce((sum, player) => sum + player.goals, 0),
);
const totalAssists = computed(() =>
    props.players.reduce((sum, player) => sum + player.assists, 0),
);

const topPoints = computed(
    () =>
        [...props.players]
            .filter((player) => player.points > 0)
            .sort((a, b) => b.points - a.points || b.goals - a.goals)[0] ??
        null,
);

const topScorer = computed(
    () =>
        [...props.players]
            .filter((player) => player.goals > 0)
            .sort((a, b) => b.goals - a.goals || b.points - a.points)[0] ??
        null,
);

const topAssister = computed(
    () =>
        [...props.players]
            .filter((player) => player.assists > 0)
            .sort((a, b) => b.assists - a.assists || b.points - a.points)[0] ??
        null,
);

const displayedPlayers = computed(() => {
    const query = search.value.trim().toLowerCase();
    const list = query
        ? props.players.filter((player) =>
              `${player.name} ${player.jersey_number}`
                  .toLowerCase()
                  .includes(query),
          )
        : props.players;

    const dir = sortDesc.value ? -1 : 1;
    return [...list].sort((a, b) => {
        const valA =
            sortKey.value === 'ppg' ? pointsPerGame(a) : a[sortKey.value];
        const valB =
            sortKey.value === 'ppg' ? pointsPerGame(b) : b[sortKey.value];
        if (valA !== valB) return (valA - valB) * dir;
        if (b.points !== a.points) return b.points - a.points;
        if (b.goals !== a.goals) return b.goals - a.goals;
        return a.name.localeCompare(b.name, 'pt-BR');
    });
});
</script>

<template>
    <Head title="Estatísticas dos jogadores" />

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p
                class="text-[11px] font-extrabold tracking-widest text-[var(--team-primary)] uppercase dark:text-emerald-400"
            >
                Desempenho no time
            </p>
            <h1
                class="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-950 sm:text-3xl dark:text-white"
            >
                Pontuação dos jogadores
            </h1>
        </div>

        <div
            v-if="players.length"
            class="flex flex-wrap items-center gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"
        >
            <span class="surface-card rounded-xl px-3 py-1.5">
                {{ players.length }} jogadores
            </span>
            <span class="surface-card rounded-xl px-3 py-1.5">
                {{ totalGoals }} gols
            </span>
            <span class="surface-card rounded-xl px-3 py-1.5">
                {{ totalAssists }} assistências
            </span>
        </div>
    </div>

    <div
        v-if="topPoints || topScorer || topAssister"
        class="mb-5 grid gap-3 sm:grid-cols-3"
    >
        <article
            v-if="topPoints"
            class="relative overflow-hidden rounded-2xl border border-amber-300/60 bg-gradient-to-br from-amber-50 to-white p-4 shadow-xs dark:border-amber-700/50 dark:from-amber-950/40 dark:to-slate-900"
        >
            <div class="flex items-center justify-between">
                <span
                    class="inline-flex items-center gap-1.5 text-[11px] font-extrabold tracking-wider text-amber-800 uppercase dark:text-amber-300"
                >
                    <Trophy class="size-3.5" />
                    Líder de pontos
                </span>
                <strong
                    class="font-display text-3xl leading-none font-extrabold text-amber-700 tabular-nums dark:text-amber-300"
                >
                    {{ topPoints.points }}
                </strong>
            </div>
            <div class="mt-3 flex items-center gap-3">
                <PlayerPhoto :player="topPoints" size="size-11" />
                <div class="min-w-0">
                    <p
                        class="truncate text-sm font-extrabold text-slate-950 dark:text-white"
                    >
                        {{ topPoints.name }}
                    </p>
                    <p
                        class="text-xs font-semibold text-slate-500 dark:text-slate-400"
                    >
                        {{ topPoints.goals }}G · {{ topPoints.assists }}A ·
                        {{ topPoints.games }}J
                    </p>
                </div>
            </div>
        </article>

        <article v-if="topScorer" class="surface-card rounded-2xl p-4">
            <div class="flex items-center justify-between">
                <span
                    class="inline-flex items-center gap-1.5 text-[11px] font-extrabold tracking-wider text-emerald-700 uppercase dark:text-emerald-400"
                >
                    <Target class="size-3.5" />
                    Artilheiro
                </span>
                <strong
                    class="font-display text-3xl leading-none font-extrabold text-slate-950 tabular-nums dark:text-white"
                >
                    {{ topScorer.goals }}
                </strong>
            </div>
            <div class="mt-3 flex items-center gap-3">
                <PlayerPhoto :player="topScorer" size="size-11" />
                <div class="min-w-0">
                    <p
                        class="truncate text-sm font-extrabold text-slate-950 dark:text-white"
                    >
                        {{ topScorer.name }}
                    </p>
                    <p
                        class="text-xs font-semibold text-slate-500 dark:text-slate-400"
                    >
                        #{{ topScorer.jersey_number }} · {{ topScorer.games }}
                        jogo(s)
                    </p>
                </div>
            </div>
        </article>

        <article v-if="topAssister" class="surface-card rounded-2xl p-4">
            <div class="flex items-center justify-between">
                <span
                    class="inline-flex items-center gap-1.5 text-[11px] font-extrabold tracking-wider text-sky-700 uppercase dark:text-sky-400"
                >
                    <Sparkles class="size-3.5" />
                    Líder de assistências
                </span>
                <strong
                    class="font-display text-3xl leading-none font-extrabold text-slate-950 tabular-nums dark:text-white"
                >
                    {{ topAssister.assists }}
                </strong>
            </div>
            <div class="mt-3 flex items-center gap-3">
                <PlayerPhoto :player="topAssister" size="size-11" />
                <div class="min-w-0">
                    <p
                        class="truncate text-sm font-extrabold text-slate-950 dark:text-white"
                    >
                        {{ topAssister.name }}
                    </p>
                    <p
                        class="text-xs font-semibold text-slate-500 dark:text-slate-400"
                    >
                        #{{ topAssister.jersey_number }} ·
                        {{ topAssister.points }} pts
                    </p>
                </div>
            </div>
        </article>
    </div>

    <div v-if="players.length" class="mb-3">
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3.5 size-4.5 -translate-y-1/2 text-slate-400"
            />
            <input
                v-model="search"
                type="search"
                class="surface-card min-h-11 w-full rounded-2xl pr-4 pl-10 text-sm font-medium text-slate-950 focus:border-[var(--team-primary)] focus:outline-none dark:text-slate-100"
                placeholder="Buscar jogador por nome ou número"
                aria-label="Buscar jogador nas estatísticas"
            />
        </div>
    </div>

    <section class="surface-card overflow-hidden rounded-2xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead
                    class="bg-slate-50/90 text-xs tracking-wider text-slate-500 uppercase dark:bg-slate-800/60 dark:text-slate-400"
                >
                    <tr>
                        <th class="px-3 py-3 sm:p-4">Jogador</th>
                        <th
                            class="w-11 px-1.5 py-3 text-center sm:w-20 sm:p-4"
                            title="Jogos"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-0.5 font-extrabold hover:text-slate-900 dark:hover:text-white"
                                @click="setSort('games')"
                            >
                                <abbr title="Jogos" class="no-underline"
                                    >J</abbr
                                >
                                <ArrowUpDown class="hidden size-3 sm:inline" />
                            </button>
                        </th>
                        <th
                            class="w-11 px-1.5 py-3 text-center sm:w-20 sm:p-4"
                            title="Gols"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-0.5 font-extrabold hover:text-slate-900 dark:hover:text-white"
                                @click="setSort('goals')"
                            >
                                <abbr title="Gols" class="no-underline">G</abbr>
                                <ArrowUpDown class="hidden size-3 sm:inline" />
                            </button>
                        </th>
                        <th
                            class="w-11 px-1.5 py-3 text-center sm:w-20 sm:p-4"
                            title="Assistências"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-0.5 font-extrabold hover:text-slate-900 dark:hover:text-white"
                                @click="setSort('assists')"
                            >
                                <abbr title="Assistências" class="no-underline"
                                    >A</abbr
                                >
                                <ArrowUpDown class="hidden size-3 sm:inline" />
                            </button>
                        </th>
                        <th
                            class="w-12 bg-amber-50/80 px-1.5 py-3 text-center font-black text-amber-950 sm:w-24 sm:p-4 dark:bg-amber-950/35 dark:text-amber-300"
                            title="Pontos"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-0.5 font-black"
                                @click="setSort('points')"
                            >
                                <abbr title="Pontos" class="no-underline"
                                    >Pts</abbr
                                >
                                <ArrowUpDown class="hidden size-3 sm:inline" />
                            </button>
                        </th>
                        <th
                            class="hidden w-20 px-2 py-3 text-center sm:table-cell sm:p-4"
                            title="Média de pontos por jogo"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-0.5 font-extrabold hover:text-slate-900 dark:hover:text-white"
                                @click="setSort('ppg')"
                            >
                                <abbr
                                    title="Pontos por jogo"
                                    class="no-underline"
                                    >Pts/J</abbr
                                >
                                <ArrowUpDown class="size-3" />
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(player, index) in displayedPlayers"
                        :key="player.id"
                        :data-player-id="player.id"
                        class="border-t border-slate-100 transition-colors hover:bg-slate-50/80 dark:border-slate-800 dark:hover:bg-slate-800/40"
                    >
                        <td class="max-w-0 px-3 py-2.5 sm:p-3.5">
                            <div
                                class="flex min-w-0 items-center gap-2 sm:gap-3"
                            >
                                <span
                                    class="flex size-6 shrink-0 items-center justify-center rounded-lg text-xs font-extrabold tabular-nums"
                                    :class="
                                        index === 0
                                            ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/70 dark:text-amber-300'
                                            : index === 1
                                              ? 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200'
                                              : index === 2
                                                ? 'bg-orange-100 text-orange-800 dark:bg-orange-950/60 dark:text-orange-300'
                                                : 'text-slate-400 dark:text-slate-500'
                                    "
                                >
                                    {{ index + 1 }}
                                </span>
                                <PlayerPhoto
                                    :player="player"
                                    size="size-8 text-xs sm:size-10 sm:text-sm"
                                />
                                <div class="min-w-0 flex-1">
                                    <span
                                        class="line-clamp-1 font-extrabold text-slate-950 dark:text-white"
                                        :title="player.name"
                                        >{{ player.name }}</span
                                    >
                                    <span
                                        class="block text-[11px] font-semibold text-slate-400"
                                        >#{{ player.jersey_number }}</span
                                    >
                                </div>
                            </div>
                        </td>
                        <td
                            class="px-1.5 py-2.5 text-center font-display text-lg font-extrabold text-slate-700 tabular-nums sm:p-3 sm:text-2xl dark:text-slate-200"
                        >
                            {{ player.games }}
                        </td>
                        <td
                            class="px-1.5 py-2.5 text-center font-display text-lg font-extrabold text-slate-900 tabular-nums sm:p-3 sm:text-2xl dark:text-white"
                        >
                            {{ player.goals }}
                        </td>
                        <td
                            class="px-1.5 py-2.5 text-center font-display text-lg font-extrabold text-slate-700 tabular-nums sm:p-3 sm:text-2xl dark:text-slate-200"
                        >
                            {{ player.assists }}
                        </td>
                        <td
                            class="bg-amber-50/70 px-1.5 py-2.5 text-center font-display text-xl font-extrabold text-amber-950 tabular-nums sm:p-3 sm:text-2xl dark:bg-amber-950/30 dark:text-amber-300"
                        >
                            {{ player.points }}
                        </td>
                        <td
                            class="hidden px-2 py-2.5 text-center text-xs font-bold text-slate-500 tabular-nums sm:table-cell sm:p-3 dark:text-slate-400"
                        >
                            {{ formatPpg(player) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p
            v-if="players.length"
            class="border-t border-slate-100 px-4 py-2.5 text-center text-xs text-slate-500 sm:text-left dark:border-slate-800 dark:text-slate-400"
        >
            J = jogos · G = gols · A = assistências · Pts = pontos · Pts/J =
            média por jogo · Clique nas colunas para ordenar
        </p>

        <div
            v-if="!players.length"
            class="p-10 text-center text-sm text-slate-500 dark:text-slate-400"
        >
            <Award class="mx-auto size-10 text-slate-300 dark:text-slate-600" />
            <p class="mt-3 font-bold text-slate-700 dark:text-slate-200">
                Cadastre jogadores para acompanhar as estatísticas.
            </p>
        </div>
    </section>
</template>
