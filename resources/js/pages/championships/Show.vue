<script setup lang="ts">
import AuditLogTab from '@/components/championships/AuditLogTab.vue';
import GroupsAndStandingsTab from '@/components/championships/GroupsAndStandingsTab.vue';
import KnockoutBracketTab from '@/components/championships/KnockoutBracketTab.vue';
import RulesTab from '@/components/championships/RulesTab.vue';
import TeamsAndRostersTab from '@/components/championships/TeamsAndRostersTab.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Award,
    Calendar,
    GitBranch,
    History,
    MapPin,
    Pencil,
    Scale,
    Shield,
    Shuffle,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type {
    ChampionshipAuditLogProp,
    ChampionshipGameProp,
    ChampionshipProp,
    ChampionshipStatus,
    ChampionshipStatusFlagsProp,
    ChampionshipSummaryProp,
    EnrolledTeamProp,
    GamePhase,
    GroupStandingsProp,
} from '@/types/championship';

const props = defineProps<{
    championship: ChampionshipProp;
    teams: EnrolledTeamProp[];
    standings: Record<string, GroupStandingsProp>;
    games: Partial<Record<GamePhase, ChampionshipGameProp[]>>;
    all_games: ChampionshipGameProp[];
    bracket: ChampionshipGameProp[];
    audit_logs: ChampionshipAuditLogProp[];
    audit_log?: ChampionshipAuditLogProp[];
    status_flags: ChampionshipStatusFlagsProp;
    can_edit_rules: boolean;
    can_draw: boolean;
    can_generate_bracket: boolean;
    summary: ChampionshipSummaryProp;
}>();

type TabKey = 'times' | 'regras' | 'grupos' | 'mata-mata' | 'historico';

const activeTab = ref<TabKey>('times');

const page = usePage();
const pageErrors = computed(
    () => (page.props.errors ?? {}) as Record<string, string | undefined>,
);

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

const groupGames = computed<ChampionshipGameProp[]>(
    () =>
        props.games.group ?? props.all_games.filter((g) => g.phase === 'group'),
);

const hasUnresolvedGroupTie = computed(() =>
    Object.values(props.standings).some(
        (gs) => gs.unresolved_tie_groups.length > 0,
    ),
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

// Edit Championship Details Modal
const showEditDetails = ref(false);
const detailsForm = useForm<{
    name: string;
    city: string;
    venue: string;
    starts_on: string;
    ends_on: string;
}>({
    name: props.championship.name,
    city: props.championship.city,
    venue: props.championship.venue ?? '',
    starts_on: props.championship.starts_on ?? '',
    ends_on: props.championship.ends_on ?? '',
});

function openEditDetails() {
    detailsForm.clearErrors();
    detailsForm.name = props.championship.name;
    detailsForm.city = props.championship.city;
    detailsForm.venue = props.championship.venue ?? '';
    detailsForm.starts_on = props.championship.starts_on ?? '';
    detailsForm.ends_on = props.championship.ends_on ?? '';
    showEditDetails.value = true;
}

function closeEditDetails() {
    showEditDetails.value = false;
    detailsForm.clearErrors();
}

function submitEditDetails() {
    detailsForm.patch(`/campeonatos/${props.championship.id}`, {
        preserveScroll: true,
        onSuccess: () => closeEditDetails(),
    });
}
</script>

<template>
    <Head :title="championship.name" />

    <!-- Top Header -->
    <div
        class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 dark:border-slate-700 dark:bg-slate-900"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <Link
                    href="/campeonatos"
                    class="inline-flex items-center gap-1 text-xs font-bold text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
                >
                    <ArrowLeft class="size-3.5" />
                    Todos os campeonatos
                </Link>

                <div class="mt-1.5 flex flex-wrap items-center gap-2.5">
                    <h1
                        class="text-2xl font-black text-slate-950 dark:text-slate-100"
                    >
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
                    class="mt-2 flex flex-wrap items-center gap-3 text-xs font-semibold text-slate-600 dark:text-slate-300"
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

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800/60"
                    data-test="editar-campeonato"
                    @click="openEditDetails"
                >
                    <Pencil class="size-3.5" />
                    Editar dados
                </button>

                <Link
                    :href="`/campeonatos/${championship.id}/resumo`"
                    class="inline-flex min-h-10 items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-2 text-xs font-bold text-white hover:bg-slate-800 dark:bg-slate-700 dark:hover:bg-slate-600"
                    data-test="link-resumo-campeonato"
                >
                    <Award class="size-4 text-amber-400" />
                    Resumo e pódio
                </Link>
            </div>
        </div>
    </div>

    <!-- Domain / Validation Error Banner -->
    <div
        v-if="pageErrors.championship"
        class="mb-4 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800"
        role="alert"
    >
        <AlertTriangle class="mt-0.5 size-5 shrink-0 text-red-600" />
        <span>{{ pageErrors.championship }}</span>
    </div>

    <!-- Navigation Tabs -->
    <div
        class="mb-5 flex gap-1.5 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-1.5 dark:border-slate-700 dark:bg-slate-900"
        role="tablist"
        aria-label="Seções do campeonato"
    >
        <button
            type="button"
            role="tab"
            :aria-selected="activeTab === 'times'"
            class="flex min-h-11 shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-bold transition sm:text-sm"
            :class="
                activeTab === 'times'
                    ? 'bg-[var(--team-primary)] text-white shadow-xs'
                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
            "
            data-test="tab-times"
            @click="activeTab = 'times'"
        >
            <Shield class="size-4" />
            <span>Times e elencos</span>
            <span
                class="rounded-full px-1.5 py-0.5 text-[10px] font-black"
                :class="
                    activeTab === 'times'
                        ? 'bg-white/20 text-white'
                        : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'
                "
            >
                {{ teams.length }}
            </span>
        </button>

        <button
            type="button"
            role="tab"
            :aria-selected="activeTab === 'regras'"
            class="flex min-h-11 shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-bold transition sm:text-sm"
            :class="
                activeTab === 'regras'
                    ? 'bg-[var(--team-primary)] text-white shadow-xs'
                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
            "
            data-test="tab-regras"
            @click="activeTab = 'regras'"
        >
            <Scale class="size-4" />
            <span>Regras</span>
        </button>

        <button
            type="button"
            role="tab"
            :aria-selected="activeTab === 'grupos'"
            class="flex min-h-11 shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-bold transition sm:text-sm"
            :class="
                activeTab === 'grupos'
                    ? 'bg-[var(--team-primary)] text-white shadow-xs'
                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
            "
            data-test="tab-grupos"
            @click="activeTab = 'grupos'"
        >
            <Shuffle class="size-4" />
            <span>Grupos e jogos</span>
            <span
                class="rounded-full px-1.5 py-0.5 text-[10px] font-black"
                :class="
                    activeTab === 'grupos'
                        ? 'bg-white/20 text-white'
                        : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'
                "
            >
                {{ groupGames.length }}
            </span>
            <span
                v-if="hasUnresolvedGroupTie"
                class="size-2 rounded-full bg-amber-500"
                title="Empate técnico pendente"
            />
        </button>

        <button
            type="button"
            role="tab"
            :aria-selected="activeTab === 'mata-mata'"
            class="flex min-h-11 shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-bold transition sm:text-sm"
            :class="
                activeTab === 'mata-mata'
                    ? 'bg-[var(--team-primary)] text-white shadow-xs'
                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
            "
            data-test="tab-mata-mata"
            @click="activeTab = 'mata-mata'"
        >
            <GitBranch class="size-4" />
            <span>Mata-mata</span>
            <span
                class="rounded-full px-1.5 py-0.5 text-[10px] font-black"
                :class="
                    activeTab === 'mata-mata'
                        ? 'bg-white/20 text-white'
                        : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'
                "
            >
                {{ bracket.length }}
            </span>
        </button>

        <button
            type="button"
            role="tab"
            :aria-selected="activeTab === 'historico'"
            class="flex min-h-11 shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-xs font-bold transition sm:text-sm"
            :class="
                activeTab === 'historico'
                    ? 'bg-[var(--team-primary)] text-white shadow-xs'
                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'
            "
            data-test="tab-historico"
            @click="activeTab = 'historico'"
        >
            <History class="size-4" />
            <span>Histórico</span>
            <span
                class="rounded-full px-1.5 py-0.5 text-[10px] font-black"
                :class="
                    activeTab === 'historico'
                        ? 'bg-white/20 text-white'
                        : 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'
                "
            >
                {{ audit_logs.length }}
            </span>
        </button>
    </div>

    <!-- Active Tab Content -->
    <TeamsAndRostersTab
        v-if="activeTab === 'times'"
        :championship="championship"
        :teams="teams"
    />

    <RulesTab
        v-else-if="activeTab === 'regras'"
        :championship="championship"
        :can-edit-rules="can_edit_rules"
    />

    <GroupsAndStandingsTab
        v-else-if="activeTab === 'grupos'"
        :championship="championship"
        :teams="teams"
        :standings="standings"
        :group-games="groupGames"
        :can-draw="can_draw"
    />

    <KnockoutBracketTab
        v-else-if="activeTab === 'mata-mata'"
        :championship="championship"
        :bracket="bracket"
        :can-generate-bracket="can_generate_bracket"
    />

    <AuditLogTab
        v-else-if="activeTab === 'historico'"
        :championship="championship"
        :audit-logs="audit_logs"
    />

    <!-- Edit Championship Details Modal -->
    <Teleport to="body">
        <div
            v-if="showEditDetails"
            class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/70 p-4"
            @click.self="closeEditDetails"
        >
            <form
                class="mx-auto my-6 max-w-lg rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900"
                @submit.prevent="submitEditDetails"
            >
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-black">
                        Editar dados do campeonato
                    </h2>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                        aria-label="Fechar"
                        @click="closeEditDetails"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <div class="mt-4 space-y-4">
                    <label class="block text-sm font-bold">
                        Nome do campeonato *
                        <input
                            v-model="detailsForm.name"
                            name="name"
                            type="text"
                            required
                            maxlength="120"
                            class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal dark:border-slate-600"
                        />
                        <span
                            v-if="detailsForm.errors.name"
                            class="mt-1 block text-xs text-red-600"
                        >
                            {{ detailsForm.errors.name }}
                        </span>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm font-bold">
                            Cidade *
                            <input
                                v-model="detailsForm.city"
                                name="city"
                                type="text"
                                required
                                maxlength="100"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal dark:border-slate-600"
                            />
                            <span
                                v-if="detailsForm.errors.city"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ detailsForm.errors.city }}
                            </span>
                        </label>

                        <label class="block text-sm font-bold">
                            Local / Ginásio
                            <input
                                v-model="detailsForm.venue"
                                name="venue"
                                type="text"
                                maxlength="140"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal dark:border-slate-600"
                            />
                            <span
                                v-if="detailsForm.errors.venue"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ detailsForm.errors.venue }}
                            </span>
                        </label>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm font-bold">
                            Data de início
                            <input
                                v-model="detailsForm.starts_on"
                                name="starts_on"
                                type="date"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal dark:border-slate-600"
                            />
                            <span
                                v-if="detailsForm.errors.starts_on"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ detailsForm.errors.starts_on }}
                            </span>
                        </label>

                        <label class="block text-sm font-bold">
                            Data de término
                            <input
                                v-model="detailsForm.ends_on"
                                name="ends_on"
                                type="date"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal dark:border-slate-600"
                            />
                            <span
                                v-if="detailsForm.errors.ends_on"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ detailsForm.errors.ends_on }}
                            </span>
                        </label>
                    </div>
                </div>

                <div class="mt-6 flex gap-3">
                    <button
                        type="button"
                        class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700 dark:border-slate-600 dark:text-slate-200"
                        @click="closeEditDetails"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        class="min-h-12 flex-1 rounded-xl bg-[var(--team-primary)] font-bold text-white disabled:opacity-50"
                        :disabled="detailsForm.processing"
                    >
                        {{
                            detailsForm.processing
                                ? 'Salvando…'
                                : 'Salvar alterações'
                        }}
                    </button>
                </div>
            </form>
        </div>
    </Teleport>
</template>
