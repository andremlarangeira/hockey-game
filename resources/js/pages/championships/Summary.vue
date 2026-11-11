<script setup lang="ts">
import ChampionshipTeamBadge from '@/components/championships/ChampionshipTeamBadge.vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Award,
    Calendar,
    CheckCircle2,
    GitBranch,
    Goal,
    MapPin,
    Medal,
    Printer,
    Shield,
    Sparkles,
    Target,
    Trophy,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import type {
    ChampionshipGameProp,
    ChampionshipStatus,
    ChampionshipSummaryPageProps,
    GamePhase,
    GroupStandingsProp,
    PlayerLeaderRowProp,
} from '@/types/championship';

const props = defineProps<ChampionshipSummaryPageProps>();

const statusLabels: Record<ChampionshipStatus, string> = {
    draft: 'Rascunho',
    group_stage: 'Fase de grupos',
    knockout_stage: 'Mata-mata',
    completed: 'Encerrado',
};

const statusClasses: Record<ChampionshipStatus, string> = {
    draft: 'bg-amber-100 text-amber-800 border-amber-200',
    group_stage: 'bg-blue-100 text-blue-800 border-blue-200',
    knockout_stage: 'bg-purple-100 text-purple-800 border-purple-200',
    completed: 'bg-green-100 text-green-800 border-green-200',
};

const resolvedPodium = computed(() => props.podium ?? props.summary.podium);

const totalGamesCount = computed(
    () => props.total_games ?? props.summary.total_games ?? 0,
);
const finishedGamesCount = computed(
    () => props.finished_games ?? props.summary.finished_games ?? 0,
);
const totalGoalsCount = computed(
    () => props.total_goals ?? props.summary.total_goals ?? 0,
);

const goalsPerGameAverage = computed(() => {
    if (finishedGamesCount.value <= 0) return '0,00';
    return (totalGoalsCount.value / finishedGamesCount.value)
        .toFixed(2)
        .replace('.', ',');
});

const scorersList = computed<PlayerLeaderRowProp[]>(
    () => props.top_scorers ?? props.summary.top_scorers ?? [],
);

const assistsList = computed<PlayerLeaderRowProp[]>(
    () => props.top_assists ?? props.summary.top_assists ?? [],
);

const pointLeadersList = computed<PlayerLeaderRowProp[]>(
    () => props.top_point_leaders ?? props.summary.top_point_leaders ?? [],
);

const standingsEntries = computed<[string, GroupStandingsProp][]>(() => {
    const map = props.standings ?? props.summary.standings_by_group ?? {};
    return Object.entries(map).sort(([a], [b]) => a.localeCompare(b));
});

const phaseOrder: GamePhase[] = [
    'round_of_16',
    'quarter_final',
    'semi_final',
    'third_place',
    'final',
];

const sortedBracket = computed<ChampionshipGameProp[]>(() =>
    [...(props.bracket ?? [])].sort((a, b) => {
        const diff = phaseOrder.indexOf(a.phase) - phaseOrder.indexOf(b.phase);
        if (diff !== 0) return diff;
        return (a.bracket_position ?? a.id) - (b.bracket_position ?? b.id);
    }),
);

function formatDate(dateStr: string | null): string {
    if (!dateStr) return '';
    const parts = dateStr.slice(0, 10).split('-');
    if (parts.length !== 3) return dateStr;
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
}

function formatDateRange(
    startsOn: string | null,
    endsOn: string | null,
): string {
    if (startsOn && endsOn) {
        return `${formatDate(startsOn)} a ${formatDate(endsOn)}`;
    }
    if (startsOn) {
        return `Início em ${formatDate(startsOn)}`;
    }
    if (endsOn) {
        return `Até ${formatDate(endsOn)}`;
    }
    return 'Datas a definir';
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatSourceLabel(source: string): string {
    if (!source) return 'A definir';
    if (source === 'bye') return 'BYE';
    if (source.startsWith('group:')) {
        const [, groupCode, pos] = source.split(':');
        return `${pos}º Grupo ${groupCode}`;
    }
    if (source.startsWith('best:') || source.startsWith('best_extra:')) {
        const [, rank] = source.split(':');
        return `${rank}º Melhor extra`;
    }
    if (source.startsWith('winner:')) {
        const [, slot] = source.split(':');
        return `Vencedor ${slot}`;
    }
    if (source.startsWith('loser:')) {
        const [, slot] = source.split(':');
        return `Perdedor ${slot}`;
    }
    return source;
}

function printPage() {
    window.print();
}
</script>

<template>
    <Head :title="`Resumo · ${championship.name}`" />

    <div class="space-y-6 print:space-y-4">
        <!-- Header Card -->
        <header
            class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 print:border-slate-300 print:p-3 print:shadow-none"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <div
                        class="flex flex-wrap items-center gap-3 text-xs font-bold print:hidden"
                    >
                        <Link
                            :href="`/campeonatos/${championship.id}`"
                            class="inline-flex items-center gap-1 text-slate-600 hover:text-slate-950"
                            data-test="voltar-gerenciar-campeonato"
                        >
                            <ArrowLeft class="size-3.5" />
                            Voltar ao gerenciamento
                        </Link>
                        <span class="text-slate-300">·</span>
                        <Link
                            href="/campeonatos"
                            class="text-slate-500 hover:text-slate-900"
                        >
                            Todos os campeonatos
                        </Link>
                    </div>

                    <div class="mt-1.5 flex flex-wrap items-center gap-2.5">
                        <h1 class="text-2xl font-black text-slate-950">
                            {{ championship.name }}
                        </h1>
                        <span
                            class="rounded-full border px-2.5 py-0.5 text-xs font-bold"
                            :class="statusClasses[championship.status]"
                        >
                            {{ statusLabels[championship.status] }}
                        </span>
                    </div>

                    <div
                        class="mt-2 flex flex-wrap items-center gap-3 text-xs font-semibold text-slate-600"
                    >
                        <span class="inline-flex items-center gap-1">
                            <MapPin class="size-3.5 text-slate-400" />
                            {{ championship.city }}
                            <template v-if="championship.venue">
                                · {{ championship.venue }}
                            </template>
                        </span>
                        <span class="inline-flex items-center gap-1">
                            <Calendar class="size-3.5 text-slate-400" />
                            {{
                                formatDateRange(
                                    championship.starts_on,
                                    championship.ends_on,
                                )
                            }}
                        </span>
                    </div>
                </div>

                <div class="flex items-center gap-2 print:hidden">
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
                        data-test="imprimir-resumo"
                        @click="printPage"
                    >
                        <Printer class="size-4" />
                        Imprimir / PDF
                    </button>
                </div>
            </div>
        </header>

        <!-- 1. Podium (1º / 2º / 3º only when defined, otherwise 'A definir') -->
        <section
            class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 print:break-inside-avoid print:border-slate-300"
            data-test="secao-podio"
        >
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Trophy class="size-5 text-amber-500" />
                    <h2 class="text-lg font-black text-slate-900">
                        Pódio oficial
                    </h2>
                </div>
                <span class="text-xs text-slate-500">
                    Definido somente após o encerramento da Final e disputa de
                    3º lugar
                </span>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <!-- 1º Lugar -->
                <article
                    class="flex flex-col justify-between rounded-2xl border-2 border-amber-300 bg-amber-50/70 p-4"
                    data-test="podio-1"
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="inline-flex items-center gap-1 rounded-full bg-amber-400 px-2.5 py-0.5 text-xs font-black text-amber-950"
                        >
                            <Trophy class="size-3.5" />
                            1º Lugar · Campeão
                        </span>
                    </div>
                    <div class="mt-3">
                        <p
                            v-if="
                                resolvedPodium.champion_team_id &&
                                resolvedPodium.champion_team_name
                            "
                            class="text-xl font-black text-slate-950"
                        >
                            {{ resolvedPodium.champion_team_name }}
                        </p>
                        <p
                            v-else
                            class="text-base font-bold text-slate-400 italic"
                        >
                            A definir
                        </p>
                    </div>
                </article>

                <!-- 2º Lugar -->
                <article
                    class="flex flex-col justify-between rounded-2xl border border-slate-300 bg-slate-50/80 p-4"
                    data-test="podio-2"
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="inline-flex items-center gap-1 rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-black text-slate-800"
                        >
                            <Medal class="size-3.5" />
                            2º Lugar · Vice-campeão
                        </span>
                    </div>
                    <div class="mt-3">
                        <p
                            v-if="
                                resolvedPodium.runner_up_team_id &&
                                resolvedPodium.runner_up_team_name
                            "
                            class="text-xl font-black text-slate-900"
                        >
                            {{ resolvedPodium.runner_up_team_name }}
                        </p>
                        <p
                            v-else
                            class="text-base font-bold text-slate-400 italic"
                        >
                            A definir
                        </p>
                    </div>
                </article>

                <!-- 3º Lugar -->
                <article
                    class="flex flex-col justify-between rounded-2xl border border-orange-200 bg-orange-50/60 p-4"
                    data-test="podio-3"
                >
                    <div class="flex items-center justify-between">
                        <span
                            class="inline-flex items-center gap-1 rounded-full bg-orange-200 px-2.5 py-0.5 text-xs font-black text-orange-950"
                        >
                            <Award class="size-3.5" />
                            3º Lugar
                        </span>
                    </div>
                    <div class="mt-3">
                        <p
                            v-if="
                                resolvedPodium.third_place_team_id &&
                                resolvedPodium.third_place_team_name
                            "
                            class="text-xl font-black text-slate-900"
                        >
                            {{ resolvedPodium.third_place_team_name }}
                        </p>
                        <p
                            v-else
                            class="text-base font-bold text-slate-400 italic"
                        >
                            A definir
                        </p>
                    </div>
                </article>
            </div>
        </section>

        <!-- 2. Tournament Totals (jogos finalizados, gols — shootout excluído) -->
        <section
            class="grid gap-4 sm:grid-cols-3 print:grid-cols-3"
            data-test="secao-totais"
        >
            <div
                class="rounded-2xl border border-slate-200 bg-white p-4 print:border-slate-300"
            >
                <p
                    class="text-xs font-bold tracking-wider text-slate-500 uppercase"
                >
                    Jogos finalizados
                </p>
                <p
                    class="mt-1 text-3xl font-black text-slate-950 tabular-nums"
                    data-test="total-jogos-finalizados"
                >
                    {{ finishedGamesCount }}
                    <span class="text-base font-bold text-slate-400">
                        / {{ totalGamesCount }}
                    </span>
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    Partidas oficiais concluídas no campeonato
                </p>
            </div>

            <div
                class="rounded-2xl border border-slate-200 bg-white p-4 print:border-slate-300"
            >
                <p
                    class="text-xs font-bold tracking-wider text-slate-500 uppercase"
                >
                    Total de gols marcados
                </p>
                <p
                    class="mt-1 text-3xl font-black text-slate-950 tabular-nums"
                    data-test="total-gols"
                >
                    {{ totalGoalsCount }}
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    Tempo normal e prorrogação (cobranças de shootout excluídas)
                </p>
            </div>

            <div
                class="rounded-2xl border border-slate-200 bg-white p-4 print:border-slate-300"
            >
                <p
                    class="text-xs font-bold tracking-wider text-slate-500 uppercase"
                >
                    Média de gols por jogo
                </p>
                <p class="mt-1 text-3xl font-black text-slate-950 tabular-nums">
                    {{ goalsPerGameAverage }}
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    Calculada sobre os {{ finishedGamesCount }} jogos encerrados
                </p>
            </div>
        </section>

        <!-- 3. Player Leaderboards: Artilharia, Assistências, Pontos (G + A) -->
        <div class="grid gap-5 lg:grid-cols-3">
            <!-- Artilharia -->
            <section
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white print:break-inside-avoid print:border-slate-300"
                data-test="tabela-artilharia"
            >
                <div
                    class="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-4 py-3"
                >
                    <Goal class="size-4 text-emerald-600" />
                    <div>
                        <h2 class="text-sm font-black text-slate-900">
                            Artilharia (Gols)
                        </h2>
                        <p class="text-[11px] text-slate-500">
                            Apenas jogos finalizados · Shootout não conta
                        </p>
                    </div>
                </div>

                <div v-if="scorersList.length" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead
                            class="border-b border-slate-100 text-[11px] font-bold text-slate-500 uppercase"
                        >
                            <tr>
                                <th class="w-8 px-3 py-2 text-center">#</th>
                                <th class="px-3 py-2">Atleta</th>
                                <th class="px-2 py-2 text-center" title="Jogos">
                                    J
                                </th>
                                <th
                                    class="px-3 py-2 text-right font-black text-slate-900"
                                    title="Gols"
                                >
                                    G
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="(row, idx) in scorersList"
                                :key="row.player_id"
                                class="hover:bg-slate-50"
                                :data-scorer-row="row.player_id"
                            >
                                <td
                                    class="px-3 py-2.5 text-center font-black text-slate-500"
                                >
                                    {{ idx + 1 }}º
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="font-bold text-slate-900">
                                        #{{ row.jersey_number }}
                                        {{ row.player_name }}
                                    </div>
                                    <div class="text-[11px] text-slate-500">
                                        {{ row.team_name }}
                                    </div>
                                </td>
                                <td class="px-2 py-2.5 text-center">
                                    {{ row.games_played }}
                                </td>
                                <td
                                    class="px-3 py-2.5 text-right text-sm font-black text-emerald-700 tabular-nums"
                                >
                                    {{ row.goals }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="p-6 text-center text-xs text-slate-500">
                    Nenhum gol computado em partidas encerradas até o momento.
                </div>
            </section>

            <!-- Assistências -->
            <section
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white print:break-inside-avoid print:border-slate-300"
                data-test="tabela-assistencias"
            >
                <div
                    class="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-4 py-3"
                >
                    <Users class="size-4 text-blue-600" />
                    <div>
                        <h2 class="text-sm font-black text-slate-900">
                            Líderes em Assistências
                        </h2>
                        <p class="text-[11px] text-slate-500">
                            Soma de 1ª e 2ª assistências confirmadas
                        </p>
                    </div>
                </div>

                <div v-if="assistsList.length" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead
                            class="border-b border-slate-100 text-[11px] font-bold text-slate-500 uppercase"
                        >
                            <tr>
                                <th class="w-8 px-3 py-2 text-center">#</th>
                                <th class="px-3 py-2">Atleta</th>
                                <th class="px-2 py-2 text-center" title="Jogos">
                                    J
                                </th>
                                <th
                                    class="px-3 py-2 text-right font-black text-slate-900"
                                    title="Assistências"
                                >
                                    A
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="(row, idx) in assistsList"
                                :key="row.player_id"
                                class="hover:bg-slate-50"
                                :data-assist-row="row.player_id"
                            >
                                <td
                                    class="px-3 py-2.5 text-center font-black text-slate-500"
                                >
                                    {{ idx + 1 }}º
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="font-bold text-slate-900">
                                        #{{ row.jersey_number }}
                                        {{ row.player_name }}
                                    </div>
                                    <div class="text-[11px] text-slate-500">
                                        {{ row.team_name }}
                                    </div>
                                </td>
                                <td class="px-2 py-2.5 text-center">
                                    {{ row.games_played }}
                                </td>
                                <td
                                    class="px-3 py-2.5 text-right text-sm font-black text-blue-700 tabular-nums"
                                >
                                    {{ row.assists }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="p-6 text-center text-xs text-slate-500">
                    Nenhuma assistência computada em partidas encerradas até o
                    momento.
                </div>
            </section>

            <!-- Pontos (G + A) -->
            <section
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white print:break-inside-avoid print:border-slate-300"
                data-test="tabela-pontos"
            >
                <div
                    class="flex items-center gap-2 border-b border-slate-100 bg-slate-50 px-4 py-3"
                >
                    <Sparkles class="size-4 text-purple-600" />
                    <div>
                        <h2 class="text-sm font-black text-slate-900">
                            Pontos / Participações (G + A)
                        </h2>
                        <p class="text-[11px] text-slate-500">
                            Gols somados às assistências no torneio
                        </p>
                    </div>
                </div>

                <div v-if="pointLeadersList.length" class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead
                            class="border-b border-slate-100 text-[11px] font-bold text-slate-500 uppercase"
                        >
                            <tr>
                                <th class="w-8 px-3 py-2 text-center">#</th>
                                <th class="px-3 py-2">Atleta</th>
                                <th class="px-2 py-2 text-center" title="Gols">
                                    G
                                </th>
                                <th
                                    class="px-2 py-2 text-center"
                                    title="Assistências"
                                >
                                    A
                                </th>
                                <th
                                    class="px-3 py-2 text-right font-black text-slate-900"
                                    title="Pontos (G + A)"
                                >
                                    PTS
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="(row, idx) in pointLeadersList"
                                :key="row.player_id"
                                class="hover:bg-slate-50"
                                :data-points-row="row.player_id"
                            >
                                <td
                                    class="px-3 py-2.5 text-center font-black text-slate-500"
                                >
                                    {{ idx + 1 }}º
                                </td>
                                <td class="px-3 py-2.5">
                                    <div class="font-bold text-slate-900">
                                        #{{ row.jersey_number }}
                                        {{ row.player_name }}
                                    </div>
                                    <div class="text-[11px] text-slate-500">
                                        {{ row.team_name }}
                                    </div>
                                </td>
                                <td class="px-2 py-2.5 text-center">
                                    {{ row.goals }}
                                </td>
                                <td class="px-2 py-2.5 text-center">
                                    {{ row.assists }}
                                </td>
                                <td
                                    class="px-3 py-2.5 text-right text-sm font-black text-purple-700 tabular-nums"
                                >
                                    {{ row.points }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="p-6 text-center text-xs text-slate-500">
                    Nenhuma participação em gol computada até o momento.
                </div>
            </section>
        </div>

        <!-- 4. Group Standings Tables -->
        <section
            class="space-y-4 print:break-inside-avoid"
            data-test="secao-classificacao-grupos"
        >
            <div class="flex items-center gap-2">
                <Shield class="size-5 text-slate-700" />
                <h2 class="text-lg font-black text-slate-900">
                    Classificação da fase de grupos
                </h2>
            </div>

            <div
                v-if="standingsEntries.length"
                class="grid gap-4 lg:grid-cols-2"
            >
                <article
                    v-for="[groupCode, groupStandings] in standingsEntries"
                    :key="groupCode"
                    class="overflow-hidden rounded-2xl border border-slate-200 bg-white print:border-slate-300"
                >
                    <div
                        class="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-4 py-3"
                    >
                        <h3 class="text-sm font-black text-slate-900">
                            Grupo {{ groupCode }}
                        </h3>
                        <span
                            class="rounded-full px-2 py-0.5 text-[11px] font-bold"
                            :class="
                                groupStandings.all_games_finished
                                    ? 'bg-emerald-100 text-emerald-800'
                                    : 'bg-blue-100 text-blue-800'
                            "
                        >
                            {{
                                groupStandings.all_games_finished
                                    ? 'Concluído'
                                    : 'Em andamento'
                            }}
                        </span>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead
                                class="border-b border-slate-100 bg-slate-50/50 text-[11px] font-bold text-slate-500 uppercase"
                            >
                                <tr>
                                    <th class="w-8 px-2.5 py-2 text-center">
                                        #
                                    </th>
                                    <th class="px-2.5 py-2">Equipe</th>
                                    <th
                                        class="px-2 py-2 text-center font-black text-slate-900"
                                    >
                                        P
                                    </th>
                                    <th class="px-2 py-2 text-center">J</th>
                                    <th class="px-2 py-2 text-center">V</th>
                                    <th class="px-2 py-2 text-center">E</th>
                                    <th class="px-2 py-2 text-center">D</th>
                                    <th class="px-2 py-2 text-center">GP</th>
                                    <th class="px-2 py-2 text-center">GC</th>
                                    <th class="px-2.5 py-2 text-center">SG</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr
                                    v-for="row in groupStandings.rows"
                                    :key="row.team_id"
                                    :class="
                                        row.qualifies ? 'bg-emerald-50/40' : ''
                                    "
                                >
                                    <td
                                        class="px-2.5 py-2 text-center font-black"
                                    >
                                        {{ row.position }}
                                    </td>
                                    <td class="px-2.5 py-2">
                                        <div class="flex items-center gap-2">
                                            <ChampionshipTeamBadge
                                                :name="row.team_name"
                                                :logo-url="row.team_logo_url"
                                                :primary-color="
                                                    row.primary_color
                                                "
                                                size="sm"
                                            />
                                            <span
                                                class="font-bold text-slate-900"
                                            >
                                                {{ row.team_name }}
                                            </span>
                                            <span
                                                v-if="row.qualifies"
                                                class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold text-emerald-800"
                                            >
                                                Classificado
                                            </span>
                                        </div>
                                    </td>
                                    <td
                                        class="px-2 py-2 text-center font-black text-slate-950"
                                    >
                                        {{ row.points }}
                                    </td>
                                    <td class="px-2 py-2 text-center">
                                        {{ row.played }}
                                    </td>
                                    <td class="px-2 py-2 text-center">
                                        {{ row.wins }}
                                    </td>
                                    <td class="px-2 py-2 text-center">
                                        {{ row.draws }}
                                    </td>
                                    <td class="px-2 py-2 text-center">
                                        {{ row.losses }}
                                    </td>
                                    <td class="px-2 py-2 text-center">
                                        {{ row.goals_for }}
                                    </td>
                                    <td class="px-2 py-2 text-center">
                                        {{ row.goals_against }}
                                    </td>
                                    <td
                                        class="px-2.5 py-2 text-center font-bold"
                                    >
                                        {{
                                            row.goal_difference > 0
                                                ? `+${row.goal_difference}`
                                                : row.goal_difference
                                        }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>

            <div
                v-else
                class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500"
            >
                Os grupos ainda não foram sorteados neste campeonato.
            </div>
        </section>

        <!-- 5. Knockout Bracket Summary -->
        <section
            class="space-y-4 print:break-inside-avoid"
            data-test="secao-mata-mata-resumo"
        >
            <div class="flex items-center gap-2">
                <GitBranch class="size-5 text-slate-700" />
                <h2 class="text-lg font-black text-slate-900">
                    Chaveamento do mata-mata
                </h2>
            </div>

            <div
                v-if="sortedBracket.length"
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4"
            >
                <article
                    v-for="game in sortedBracket"
                    :key="game.id"
                    class="flex flex-col justify-between rounded-2xl border border-slate-200 bg-white p-3.5 print:border-slate-300"
                    :data-bracket-slot="game.slot_key"
                >
                    <div>
                        <div
                            class="flex items-center justify-between gap-2 text-[11px]"
                        >
                            <span
                                class="rounded bg-slate-900 px-1.5 py-0.5 font-mono font-black text-white"
                            >
                                {{ game.slot_key }}
                            </span>
                            <span class="font-bold text-slate-500">
                                {{ game.phase_label }}
                            </span>
                        </div>

                        <div class="mt-3 space-y-2 text-xs">
                            <div
                                class="flex items-center justify-between gap-2"
                                :class="
                                    game.winner_team_id &&
                                    game.winner_team_id === game.home_team_id
                                        ? 'font-black text-emerald-900'
                                        : 'text-slate-700'
                                "
                            >
                                <span class="truncate">
                                    {{
                                        game.home_team_name ??
                                        formatSourceLabel(game.home_source)
                                    }}
                                </span>
                                <span
                                    v-if="
                                        !game.is_bye &&
                                        game.status !== 'scheduled'
                                    "
                                    class="font-black tabular-nums"
                                >
                                    {{ game.home_regular_score }}
                                    <template v-if="game.went_to_shootout">
                                        ({{ game.home_shootout_score }})
                                    </template>
                                </span>
                            </div>

                            <div
                                class="flex items-center justify-between gap-2"
                                :class="
                                    game.winner_team_id &&
                                    game.winner_team_id === game.away_team_id
                                        ? 'font-black text-emerald-900'
                                        : 'text-slate-700'
                                "
                            >
                                <span class="truncate">
                                    {{
                                        game.is_bye
                                            ? 'BYE (avanço automático)'
                                            : (game.away_team_name ??
                                              formatSourceLabel(
                                                  game.away_source,
                                              ))
                                    }}
                                </span>
                                <span
                                    v-if="
                                        !game.is_bye &&
                                        game.status !== 'scheduled'
                                    "
                                    class="font-black tabular-nums"
                                >
                                    {{ game.away_regular_score }}
                                    <template v-if="game.went_to_shootout">
                                        ({{ game.away_shootout_score }})
                                    </template>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div
                        class="mt-3 flex items-center justify-between border-t border-slate-100 pt-2 text-[11px] text-slate-500"
                    >
                        <span v-if="game.winner_team_name" class="font-bold">
                            Vencedor: {{ game.winner_team_name }}
                        </span>
                        <span v-else>A definir</span>

                        <Link
                            v-if="!game.is_bye"
                            :href="`/campeonatos/${championship.id}/jogos/${game.id}`"
                            class="font-bold text-slate-900 underline print:hidden"
                        >
                            Súmula
                        </Link>
                    </div>
                </article>
            </div>

            <div
                v-else
                class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500"
            >
                A chave do mata-mata ainda não foi gerada.
            </div>
        </section>

        <!-- 6. Results List (Partidas Finalizadas) -->
        <section
            class="space-y-4 print:break-inside-avoid"
            data-test="secao-resultados"
        >
            <div class="flex items-center gap-2">
                <CheckCircle2 class="size-5 text-emerald-600" />
                <h2 class="text-lg font-black text-slate-900">
                    Resultados das partidas finalizadas ({{ results.length }})
                </h2>
            </div>

            <div v-if="results.length" class="grid gap-3 md:grid-cols-2">
                <article
                    v-for="game in results"
                    :key="game.id"
                    class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-3.5 print:border-slate-300"
                    :data-result-game-id="game.id"
                >
                    <div class="min-w-0 flex-1">
                        <div
                            class="flex flex-wrap items-center gap-2 text-[11px] text-slate-500"
                        >
                            <span class="font-bold text-slate-700">
                                {{ game.phase_label }}
                                <template v-if="game.group_code">
                                    · Grupo {{ game.group_code }}
                                </template>
                                <template v-else>
                                    · {{ game.slot_key }}
                                </template>
                            </span>
                            <span>· Rodada {{ game.round_number }}</span>
                            <span v-if="game.finished_at">
                                · {{ formatDateTime(game.finished_at) }}
                            </span>
                        </div>

                        <div class="mt-1.5 flex items-center gap-2 text-sm">
                            <span
                                class="truncate font-bold"
                                :class="
                                    game.winner_team_id === game.home_team_id
                                        ? 'font-black text-emerald-900'
                                        : 'text-slate-800'
                                "
                            >
                                {{ game.home_team_name ?? game.home_source }}
                            </span>
                            <span
                                class="rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-black text-slate-950 tabular-nums"
                            >
                                {{ game.home_regular_score }} ×
                                {{ game.away_regular_score }}
                            </span>
                            <span
                                class="truncate font-bold"
                                :class="
                                    game.winner_team_id === game.away_team_id
                                        ? 'font-black text-emerald-900'
                                        : 'text-slate-800'
                                "
                            >
                                {{ game.away_team_name ?? game.away_source }}
                            </span>
                        </div>

                        <div
                            v-if="
                                game.went_to_shootout || game.went_to_overtime
                            "
                            class="mt-1 flex items-center gap-2 text-[11px] font-semibold text-purple-700"
                        >
                            <Target class="size-3" />
                            <span v-if="game.went_to_shootout">
                                Shootout: {{ game.home_shootout_score }} ×
                                {{ game.away_shootout_score }}
                            </span>
                            <span v-else-if="game.went_to_overtime">
                                Decidido na prorrogação
                            </span>
                        </div>
                    </div>

                    <Link
                        :href="`/campeonatos/${championship.id}/jogos/${game.id}`"
                        class="shrink-0 rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 print:hidden"
                    >
                        Ver súmula
                    </Link>
                </article>
            </div>

            <div
                v-else
                class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500"
            >
                Nenhuma partida foi finalizada neste campeonato até o momento.
            </div>
        </section>
    </div>
</template>
