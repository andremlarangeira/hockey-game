<script setup lang="ts">
import ChampionshipTeamBadge from '@/components/championships/ChampionshipTeamBadge.vue';
import { Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDown,
    ArrowRight,
    ArrowUp,
    Calendar,
    CheckCircle2,
    MapPin,
    RotateCcw,
    Scale,
    Shuffle,
    Timer,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type {
    ChampionshipGameProp,
    ChampionshipProp,
    EnrolledTeamProp,
    GameStatus,
    GroupStandingsProp,
} from '@/types/championship';

const props = defineProps<{
    championship: ChampionshipProp;
    teams: EnrolledTeamProp[];
    standings: Record<string, GroupStandingsProp>;
    groupGames: ChampionshipGameProp[];
    canDraw: boolean;
}>();

const isDraft = computed(() => props.championship.status === 'draft');

const minRequiredTeams = computed(() =>
    Math.max(
        props.championship.rules.groups_count * 2,
        props.championship.rules.groups_count *
            props.championship.rules.qualifiers_per_group +
            props.championship.rules.extra_best_qualifiers,
    ),
);

const groupEntries = computed(() =>
    Object.entries(props.standings).sort(([a], [b]) => a.localeCompare(b)),
);

const gamesByGroup = computed(() => {
    const map: Record<string, ChampionshipGameProp[]> = {};
    for (const game of props.groupGames) {
        const code = game.group_code ?? 'A';
        if (!map[code]) map[code] = [];
        map[code].push(game);
    }
    return map;
});

const teamsById = computed(() => {
    const map = new Map<number, EnrolledTeamProp>();
    for (const t of props.teams) {
        map.set(t.id, t);
    }
    return map;
});

const gameStatusLabels: Record<GameStatus, string> = {
    scheduled: 'Agendado',
    live: 'Ao vivo',
    finished: 'Encerrado',
};

const gameStatusClasses: Record<GameStatus, string> = {
    scheduled: 'bg-slate-100 text-slate-700',
    live: 'bg-red-100 text-red-800 animate-pulse',
    finished: 'bg-emerald-100 text-emerald-800',
};

const criterionShortLabels: Record<string, string> = {
    points: 'Pontos',
    head_to_head: 'Confronto direto',
    goal_difference: 'Saldo de gols',
    goals_for: 'Gols pró',
    wins: 'Vitórias',
    fewest_goals_against: 'Menos GC',
    manual_draw: 'Sorteio manual',
};

// Draw / Redraw confirmation modal
const showDrawModal = ref(false);
const isRedrawMode = ref(false);

const drawForm = useForm<{
    refazer: boolean;
    motivo: string;
}>({
    refazer: false,
    motivo: '',
});

function openDrawConfirm(redraw: boolean) {
    isRedrawMode.value = redraw;
    drawForm.clearErrors();
    drawForm.refazer = redraw;
    drawForm.motivo = '';
    showDrawModal.value = true;
}

function closeDrawModal() {
    showDrawModal.value = false;
    drawForm.clearErrors();
}

function submitDraw() {
    drawForm.post(`/campeonatos/${props.championship.id}/sorteio`, {
        preserveScroll: true,
        onSuccess: () => closeDrawModal(),
    });
}

// Manual Tie Resolution modal
const showTieModal = ref(false);
const tieForm = useForm<{
    group_code: string;
    ordered_team_ids: number[];
    motivo: string;
}>({
    group_code: '',
    ordered_team_ids: [],
    motivo: '',
});

function openTieModal(groupCode: string, tiedTeamIds: number[]) {
    tieForm.clearErrors();
    tieForm.group_code = groupCode;
    tieForm.ordered_team_ids = [...tiedTeamIds];
    tieForm.motivo = '';
    showTieModal.value = true;
}

function closeTieModal() {
    showTieModal.value = false;
    tieForm.clearErrors();
}

function moveTiedTeam(index: number, direction: -1 | 1) {
    const target = index + direction;
    if (target < 0 || target >= tieForm.ordered_team_ids.length) return;
    const copy = [...tieForm.ordered_team_ids];
    const [id] = copy.splice(index, 1);
    copy.splice(target, 0, id);
    tieForm.ordered_team_ids = copy;
}

function submitTieResolution() {
    tieForm.post(`/campeonatos/${props.championship.id}/desempate`, {
        preserveScroll: true,
        onSuccess: () => closeTieModal(),
    });
}

function teamNameById(teamId: number): string {
    return teamsById.value.get(teamId)?.name ?? `Equipe #${teamId}`;
}

// Schedule Game modal
const editingScheduleGame = ref<ChampionshipGameProp | null>(null);
const scheduleForm = useForm<{
    venue: string;
    scheduled_at: string;
}>({
    venue: '',
    scheduled_at: '',
});

function toDatetimeLocalValue(iso: string | null): string {
    if (!iso) return '';
    return iso.slice(0, 16);
}

function openScheduleModal(game: ChampionshipGameProp) {
    editingScheduleGame.value = game;
    scheduleForm.clearErrors();
    scheduleForm.venue = game.venue ?? props.championship.venue ?? '';
    scheduleForm.scheduled_at = toDatetimeLocalValue(game.scheduled_at);
}

function closeScheduleModal() {
    editingScheduleGame.value = null;
    scheduleForm.clearErrors();
}

function submitSchedule() {
    const game = editingScheduleGame.value;
    if (!game) return;
    scheduleForm.patch(
        `/campeonatos/${props.championship.id}/jogos/${game.id}/agendamento`,
        {
            preserveScroll: true,
            onSuccess: () => closeScheduleModal(),
        },
    );
}

function formatDateTime(iso: string | null): string {
    if (!iso) return 'Horário a definir';
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
</script>

<template>
    <div class="space-y-5">
        <!-- Top action bar for Draw / Redraw -->
        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4"
        >
            <div>
                <h2 class="text-lg font-black">
                    Sorteio de grupos, classificação e jogos
                </h2>
                <p v-if="isDraft" class="text-xs text-slate-500">
                    {{ teams.length }} de {{ minRequiredTeams }} equipes mínimas
                    inscritas para sortear
                    {{ championship.rules.groups_count }} grupo(s).
                </p>
                <p v-else class="text-xs text-slate-500">
                    Classificação calculada automaticamente das partidas
                    encerradas ({{ groupGames.length }} jogos na fase de
                    grupos).
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-if="isDraft"
                    type="button"
                    class="flex min-h-11 items-center gap-2 rounded-xl bg-[var(--team-primary)] px-4 text-sm font-bold text-white disabled:opacity-50"
                    :disabled="!canDraw"
                    data-test="abrir-sorteio-grupos"
                    @click="openDrawConfirm(false)"
                >
                    <Shuffle class="size-4" />
                    Sortear grupos e gerar jogos
                </button>

                <button
                    v-else-if="canDraw"
                    type="button"
                    class="flex min-h-11 items-center gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 text-sm font-bold text-amber-900 hover:bg-amber-100"
                    data-test="abrir-refazer-sorteio"
                    @click="openDrawConfirm(true)"
                >
                    <RotateCcw class="size-4" />
                    Refazer sorteio
                </button>
            </div>
        </div>

        <!-- Empty state when groups not drawn yet -->
        <div
            v-if="!groupEntries.length"
            class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center"
        >
            <Shuffle class="mx-auto size-10 text-slate-400" />
            <h3 class="mt-4 text-lg font-bold">
                Grupos ainda não foram sorteados
            </h3>
            <p class="mx-auto mt-1 max-w-md text-sm text-slate-500">
                Inscreva pelo menos {{ minRequiredTeams }} equipes na aba "Times
                e elencos", confira as regras e clique em "Sortear grupos e
                gerar jogos".
            </p>
            <button
                v-if="canDraw"
                type="button"
                class="mt-5 inline-flex items-center gap-2 rounded-xl bg-slate-900 px-5 py-3 font-bold text-white"
                @click="openDrawConfirm(false)"
            >
                <Shuffle class="size-4" />
                Realizar sorteio agora
            </button>
        </div>

        <!-- Groups Standings + Fixtures -->
        <div v-else class="space-y-6">
            <section
                v-for="[groupCode, groupStandings] in groupEntries"
                :key="groupCode"
                class="overflow-hidden rounded-2xl border border-slate-200 bg-white"
                :data-group-code="groupCode"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-4 py-3"
                >
                    <div class="flex items-center gap-2.5">
                        <span
                            class="inline-flex size-8 items-center justify-center rounded-xl bg-slate-900 text-sm font-black text-white"
                        >
                            {{ groupCode }}
                        </span>
                        <div>
                            <h3 class="text-base font-black text-slate-900">
                                Grupo {{ groupCode }}
                            </h3>
                            <p class="text-[11px] text-slate-500">
                                Classificam-se os
                                {{ championship.rules.qualifiers_per_group }}
                                primeiros colocados
                            </p>
                        </div>
                    </div>

                    <span
                        v-if="groupStandings.all_games_finished"
                        class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800"
                    >
                        <CheckCircle2 class="size-3.5" />
                        Jogos do grupo concluídos
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-1 text-xs font-bold text-blue-800"
                    >
                        Em andamento
                    </span>
                </div>

                <!-- Unresolved Tie Banner -->
                <div
                    v-if="groupStandings.unresolved_tie_groups.length"
                    class="border-b border-amber-200 bg-amber-50 p-4"
                    role="alert"
                >
                    <div
                        v-for="(
                            tiedBucket, bucketIdx
                        ) in groupStandings.unresolved_tie_groups"
                        :key="bucketIdx"
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <div class="flex items-start gap-2.5">
                            <AlertTriangle
                                class="mt-0.5 size-5 shrink-0 text-amber-700"
                            />
                            <div>
                                <p class="text-sm font-bold text-amber-950">
                                    Empate técnico no Grupo {{ groupCode }}
                                </p>
                                <p class="text-xs text-amber-800">
                                    As equipes
                                    <strong>{{
                                        tiedBucket
                                            .map((id) => teamNameById(id))
                                            .join(', ')
                                    }}</strong>
                                    permanecem empatadas após todos os critérios
                                    automáticos. Realize o sorteio/desempate
                                    manual auditado para definir a ordem.
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center gap-1.5 rounded-xl bg-amber-900 px-3.5 py-2 text-xs font-bold text-white hover:bg-amber-800"
                            :data-test="`resolver-empate-${groupCode}`"
                            @click="openTieModal(groupCode, tiedBucket)"
                        >
                            <Scale class="size-4" />
                            Resolver empate manual
                        </button>
                    </div>
                </div>

                <!-- Standings Table P J V E D GP GC SG -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs sm:text-sm">
                        <thead
                            class="border-b border-slate-200 bg-slate-50/60 text-[11px] font-bold tracking-wider text-slate-500 uppercase"
                        >
                            <tr>
                                <th class="w-10 px-3 py-2.5 text-center">#</th>
                                <th class="px-3 py-2.5">Equipe</th>
                                <th
                                    class="px-2.5 py-2.5 text-center font-black text-slate-900"
                                    title="Pontos"
                                >
                                    P
                                </th>
                                <th
                                    class="px-2.5 py-2.5 text-center"
                                    title="Jogos"
                                >
                                    J
                                </th>
                                <th
                                    class="px-2.5 py-2.5 text-center"
                                    title="Vitórias"
                                >
                                    V
                                </th>
                                <th
                                    class="px-2.5 py-2.5 text-center"
                                    title="Empates"
                                >
                                    E
                                </th>
                                <th
                                    class="px-2.5 py-2.5 text-center"
                                    title="Derrotas"
                                >
                                    D
                                </th>
                                <th
                                    class="px-2.5 py-2.5 text-center"
                                    title="Gols Pró"
                                >
                                    GP
                                </th>
                                <th
                                    class="px-2.5 py-2.5 text-center"
                                    title="Gols Contra"
                                >
                                    GC
                                </th>
                                <th
                                    class="px-3 py-2.5 text-center"
                                    title="Saldo de Gols"
                                >
                                    SG
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr
                                v-for="row in groupStandings.rows"
                                :key="row.team_id"
                                :class="
                                    row.qualifies
                                        ? 'bg-emerald-50/50 hover:bg-emerald-50'
                                        : 'hover:bg-slate-50'
                                "
                                :data-standing-team-id="row.team_id"
                            >
                                <td class="px-3 py-2.5 text-center font-black">
                                    <span
                                        class="inline-flex size-6 items-center justify-center rounded-full text-xs"
                                        :class="
                                            row.qualifies
                                                ? 'bg-emerald-600 text-white'
                                                : 'bg-slate-100 text-slate-700'
                                        "
                                    >
                                        {{ row.position }}
                                    </span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <ChampionshipTeamBadge
                                            :name="row.team_name"
                                            :logo-url="row.team_logo_url"
                                            :primary-color="row.primary_color"
                                            size="sm"
                                        />
                                        <span
                                            class="font-bold text-slate-900"
                                            >{{ row.team_name }}</span
                                        >
                                        <span
                                            v-if="row.qualifies"
                                            class="rounded-md bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold text-emerald-800"
                                        >
                                            Classificado
                                        </span>
                                        <span
                                            v-if="row.tied_with_next"
                                            class="rounded-md bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800"
                                        >
                                            Empatado
                                        </span>
                                        <span
                                            v-else-if="
                                                row.resolved_by_criterion
                                            "
                                            class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-600"
                                        >
                                            {{
                                                criterionShortLabels[
                                                    row.resolved_by_criterion
                                                ] ?? row.resolved_by_criterion
                                            }}
                                        </span>
                                    </div>
                                </td>
                                <td
                                    class="px-2.5 py-2.5 text-center text-sm font-black text-slate-950"
                                >
                                    {{ row.points }}
                                </td>
                                <td
                                    class="px-2.5 py-2.5 text-center text-slate-700"
                                >
                                    {{ row.played }}
                                </td>
                                <td
                                    class="px-2.5 py-2.5 text-center text-slate-700"
                                >
                                    {{ row.wins }}
                                </td>
                                <td
                                    class="px-2.5 py-2.5 text-center text-slate-700"
                                >
                                    {{ row.draws }}
                                </td>
                                <td
                                    class="px-2.5 py-2.5 text-center text-slate-700"
                                >
                                    {{ row.losses }}
                                </td>
                                <td
                                    class="px-2.5 py-2.5 text-center text-slate-700"
                                >
                                    {{ row.goals_for }}
                                </td>
                                <td
                                    class="px-2.5 py-2.5 text-center text-slate-700"
                                >
                                    {{ row.goals_against }}
                                </td>
                                <td
                                    class="px-3 py-2.5 text-center font-bold"
                                    :class="
                                        row.goal_difference > 0
                                            ? 'text-emerald-700'
                                            : row.goal_difference < 0
                                              ? 'text-red-600'
                                              : 'text-slate-600'
                                    "
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

                <!-- Group Fixtures List -->
                <div class="border-t border-slate-200 bg-slate-50/40 p-4">
                    <h4
                        class="mb-3 text-xs font-bold tracking-wider text-slate-500 uppercase"
                    >
                        Jogos do Grupo {{ groupCode }}
                    </h4>

                    <div
                        v-if="(gamesByGroup[groupCode] ?? []).length"
                        class="grid gap-3 md:grid-cols-2"
                    >
                        <article
                            v-for="game in gamesByGroup[groupCode]"
                            :key="game.id"
                            class="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-3.5"
                            :data-game-id="game.id"
                        >
                            <div>
                                <div
                                    class="flex items-center justify-between gap-2 text-[11px]"
                                >
                                    <span class="font-bold text-slate-500">
                                        Rodada {{ game.round_number }}
                                        <template v-if="game.leg_number === 2">
                                            · Returno
                                        </template>
                                    </span>
                                    <span
                                        class="rounded-full px-2 py-0.5 font-bold"
                                        :class="gameStatusClasses[game.status]"
                                    >
                                        {{ gameStatusLabels[game.status] }}
                                    </span>
                                </div>

                                <div
                                    class="mt-2.5 flex items-center justify-between gap-2"
                                >
                                    <div
                                        class="flex min-w-0 flex-1 items-center gap-2"
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
                                        />
                                        <span
                                            class="truncate text-sm font-bold text-slate-900"
                                        >
                                            {{
                                                game.home_team_name ??
                                                game.home_source
                                            }}
                                        </span>
                                    </div>

                                    <div
                                        class="shrink-0 rounded-lg bg-slate-100 px-2.5 py-1 text-center text-sm font-black text-slate-900"
                                    >
                                        <template
                                            v-if="game.status !== 'scheduled'"
                                        >
                                            {{ game.home_regular_score }} ×
                                            {{ game.away_regular_score }}
                                            <span
                                                v-if="game.went_to_shootout"
                                                class="block text-[10px] font-bold text-slate-600"
                                            >
                                                SO:
                                                {{
                                                    game.home_shootout_score
                                                }}×{{
                                                    game.away_shootout_score
                                                }}
                                            </span>
                                            <span
                                                v-else-if="
                                                    game.went_to_overtime
                                                "
                                                class="block text-[10px] font-bold text-slate-600"
                                            >
                                                Prorrogação
                                            </span>
                                        </template>
                                        <template v-else>vs</template>
                                    </div>

                                    <div
                                        class="flex min-w-0 flex-1 items-center justify-end gap-2 text-right"
                                    >
                                        <span
                                            class="truncate text-sm font-bold text-slate-900"
                                        >
                                            {{
                                                game.away_team_name ??
                                                game.away_source
                                            }}
                                        </span>
                                        <ChampionshipTeamBadge
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
                            </div>

                            <div
                                class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2.5 text-xs text-slate-500"
                            >
                                <div
                                    class="flex flex-wrap items-center gap-2 text-[11px]"
                                >
                                    <span
                                        v-if="game.venue"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <MapPin class="size-3" />
                                        {{ game.venue }}
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1"
                                    >
                                        <Calendar class="size-3" />
                                        {{ formatDateTime(game.scheduled_at) }}
                                    </span>
                                </div>

                                <div class="flex items-center gap-1.5">
                                    <button
                                        v-if="game.status !== 'finished'"
                                        type="button"
                                        class="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-700 hover:bg-slate-50"
                                        @click="openScheduleModal(game)"
                                    >
                                        Agendar
                                    </button>
                                    <Link
                                        :href="`/campeonatos/${championship.id}/jogos/${game.id}`"
                                        class="inline-flex items-center gap-1 rounded-lg bg-slate-900 px-2.5 py-1 text-[11px] font-bold text-white hover:bg-slate-800"
                                        :data-test="`abrir-jogo-${game.id}`"
                                    >
                                        <Timer class="size-3" />
                                        {{
                                            game.status === 'finished'
                                                ? 'Súmula'
                                                : 'Placar ao vivo'
                                        }}
                                        <ArrowRight class="size-3" />
                                    </Link>
                                </div>
                            </div>
                        </article>
                    </div>
                </div>
            </section>
        </div>

        <!-- Draw / Redraw Confirmation Modal -->
        <Teleport to="body">
            <div
                v-if="showDrawModal"
                class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/70 p-4"
                @click.self="closeDrawModal"
            >
                <form
                    class="mx-auto my-6 max-w-md rounded-2xl bg-white p-5 shadow-2xl"
                    @submit.prevent="submitDraw"
                >
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-black">
                            {{
                                isRedrawMode
                                    ? 'Refazer sorteio dos grupos'
                                    : 'Confirmar sorteio dos grupos'
                            }}
                        </h2>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                            aria-label="Fechar"
                            @click="closeDrawModal"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <p v-if="!isRedrawMode" class="mt-2 text-sm text-slate-600">
                        As {{ teams.length }} equipes inscritas serão
                        distribuídas nos
                        {{ championship.rules.groups_count }} grupo(s)
                        (respeitando os potes configurados) e a tabela de jogos
                        será gerada automaticamente.
                        <strong class="mt-2 block text-slate-900">
                            Atenção: após o sorteio, as regras do campeonato e a
                            inscrição de novas equipes ficarão travadas.
                        </strong>
                    </p>

                    <div v-else class="mt-2 space-y-3">
                        <p class="text-sm text-slate-600">
                            O sorteio atual e os jogos da fase de grupos ainda
                            não iniciados serão substituídos por um novo
                            sorteio. Esta ação ficará registrada no histórico de
                            auditoria.
                        </p>

                        <label class="block text-sm font-bold">
                            Motivo para refazer o sorteio *
                            <textarea
                                v-model="drawForm.motivo"
                                name="motivo"
                                required
                                rows="3"
                                maxlength="500"
                                class="mt-1 w-full rounded-xl border border-slate-300 p-3 font-normal"
                                placeholder="Ex.: Solicitação unânime dos representantes na reunião técnica"
                            />
                            <span
                                v-if="drawForm.errors.motivo"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ drawForm.errors.motivo }}
                            </span>
                        </label>
                    </div>

                    <div class="mt-6 flex gap-3">
                        <button
                            type="button"
                            class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700"
                            @click="closeDrawModal"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="min-h-12 flex-1 rounded-xl bg-[var(--team-primary)] font-bold text-white disabled:opacity-50"
                            data-test="confirmar-sorteio-grupos"
                            :disabled="drawForm.processing"
                        >
                            {{
                                drawForm.processing
                                    ? 'Sorteando…'
                                    : isRedrawMode
                                      ? 'Confirmar novo sorteio'
                                      : 'Confirmar sorteio'
                            }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>

        <!-- Manual Tie Resolution Modal -->
        <Teleport to="body">
            <div
                v-if="showTieModal"
                class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/70 p-4"
                @click.self="closeTieModal"
            >
                <form
                    class="mx-auto my-6 max-w-md rounded-2xl bg-white p-5 shadow-2xl"
                    @submit.prevent="submitTieResolution"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-xs font-bold tracking-wider text-slate-500 uppercase"
                            >
                                Grupo {{ tieForm.group_code }}
                            </p>
                            <h2 class="text-xl font-black">
                                Desempate manual auditado
                            </h2>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                            aria-label="Fechar"
                            @click="closeTieModal"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <p class="mt-2 text-xs text-slate-600">
                        Defina a ordem final entre as equipes empatadas (1º para
                        o último dentro do empate) e registre o motivo oficial
                        para o histórico de auditoria.
                    </p>

                    <ol class="mt-4 space-y-2">
                        <li
                            v-for="(teamId, index) in tieForm.ordered_team_ids"
                            :key="teamId"
                            class="flex items-center justify-between gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm"
                        >
                            <div class="flex items-center gap-2.5">
                                <span
                                    class="inline-flex size-6 items-center justify-center rounded-lg bg-slate-900 text-xs font-black text-white"
                                >
                                    {{ index + 1 }}º
                                </span>
                                <span class="font-bold text-slate-900">
                                    {{ teamNameById(teamId) }}
                                </span>
                            </div>

                            <div class="flex items-center gap-1">
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 bg-white p-1.5 text-slate-600 hover:bg-slate-100 disabled:opacity-40"
                                    :disabled="index === 0"
                                    :aria-label="`Subir ${teamNameById(teamId)}`"
                                    @click="moveTiedTeam(index, -1)"
                                >
                                    <ArrowUp class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 bg-white p-1.5 text-slate-600 hover:bg-slate-100 disabled:opacity-40"
                                    :disabled="
                                        index ===
                                        tieForm.ordered_team_ids.length - 1
                                    "
                                    :aria-label="`Descer ${teamNameById(teamId)}`"
                                    @click="moveTiedTeam(index, 1)"
                                >
                                    <ArrowDown class="size-4" />
                                </button>
                            </div>
                        </li>
                    </ol>

                    <label class="mt-4 block text-sm font-bold">
                        Motivo / ata do desempate *
                        <textarea
                            v-model="tieForm.motivo"
                            name="motivo"
                            required
                            rows="3"
                            maxlength="500"
                            class="mt-1 w-full rounded-xl border border-slate-300 p-3 font-normal"
                            placeholder="Ex.: Sorteio em envelope realizado na mesa diretora com os capitães"
                        />
                        <span
                            v-if="tieForm.errors.motivo"
                            class="mt-1 block text-xs text-red-600"
                        >
                            {{ tieForm.errors.motivo }}
                        </span>
                    </label>

                    <div class="mt-6 flex gap-3">
                        <button
                            type="button"
                            class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700"
                            @click="closeTieModal"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="min-h-12 flex-1 rounded-xl bg-[var(--team-primary)] font-bold text-white disabled:opacity-50"
                            data-test="confirmar-desempate-manual"
                            :disabled="tieForm.processing"
                        >
                            {{
                                tieForm.processing
                                    ? 'Salvando…'
                                    : 'Salvar desempate'
                            }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>

        <!-- Schedule Game Modal -->
        <Teleport to="body">
            <div
                v-if="editingScheduleGame"
                class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/70 p-4"
                @click.self="closeScheduleModal"
            >
                <form
                    class="mx-auto my-6 max-w-md rounded-2xl bg-white p-5 shadow-2xl"
                    @submit.prevent="submitSchedule"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-xs font-bold tracking-wider text-slate-500 uppercase"
                            >
                                Grupo {{ editingScheduleGame.group_code }} ·
                                Rodada {{ editingScheduleGame.round_number }}
                            </p>
                            <h2 class="text-xl font-black">Agendar partida</h2>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
                            aria-label="Fechar"
                            @click="closeScheduleModal"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <p class="mt-1 text-xs font-semibold text-slate-600">
                        {{ editingScheduleGame.home_team_name }} ×
                        {{ editingScheduleGame.away_team_name }}
                    </p>

                    <div class="mt-4 space-y-4">
                        <label class="block text-sm font-bold">
                            Local / Quadra
                            <input
                                v-model="scheduleForm.venue"
                                name="venue"
                                type="text"
                                maxlength="140"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"
                                placeholder="Ex.: Quadra Principal"
                            />
                            <span
                                v-if="scheduleForm.errors.venue"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ scheduleForm.errors.venue }}
                            </span>
                        </label>

                        <label class="block text-sm font-bold">
                            Data e horário
                            <input
                                v-model="scheduleForm.scheduled_at"
                                name="scheduled_at"
                                type="datetime-local"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal"
                            />
                            <span
                                v-if="scheduleForm.errors.scheduled_at"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ scheduleForm.errors.scheduled_at }}
                            </span>
                        </label>
                    </div>

                    <div class="mt-6 flex gap-3">
                        <button
                            type="button"
                            class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700"
                            @click="closeScheduleModal"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="min-h-12 flex-1 rounded-xl bg-[var(--team-primary)] font-bold text-white disabled:opacity-50"
                            data-test="salvar-agendamento-jogo"
                            :disabled="scheduleForm.processing"
                        >
                            {{
                                scheduleForm.processing
                                    ? 'Salvando…'
                                    : 'Salvar agendamento'
                            }}
                        </button>
                    </div>
                </form>
            </div>
        </Teleport>
    </div>
</template>
