<script setup lang="ts">
import ChampionshipTeamBadge from '@/components/championships/ChampionshipTeamBadge.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Calendar,
    GitBranch,
    MapPin,
    Timer,
    Trophy,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type {
    ChampionshipGameProp,
    ChampionshipProp,
    GamePhase,
    GameStatus,
} from '@/types/championship';

const props = defineProps<{
    championship: ChampionshipProp;
    bracket: ChampionshipGameProp[];
    canGenerateBracket: boolean;
}>();

const showConfirmGenerate = ref(false);
const generating = ref(false);

const phaseOrder: GamePhase[] = [
    'round_of_16',
    'quarter_final',
    'semi_final',
    'final',
    'third_place',
];

const phaseColumnTitles: Record<string, string> = {
    round_of_16: 'Oitavas de final',
    quarter_final: 'Quartas de final',
    semi_final: 'Semifinais',
    final: 'Finais',
};

interface BracketColumn {
    key: string;
    title: string;
    games: ChampionshipGameProp[];
}

const columns = computed<BracketColumn[]>(() => {
    if (!props.bracket.length) return [];

    const byPhase: Partial<Record<GamePhase, ChampionshipGameProp[]>> = {};
    for (const game of props.bracket) {
        if (!byPhase[game.phase]) byPhase[game.phase] = [];
        byPhase[game.phase]!.push(game);
    }

    const result: BracketColumn[] = [];

    for (const phase of [
        'round_of_16',
        'quarter_final',
        'semi_final',
    ] as const) {
        const list = byPhase[phase];
        if (list && list.length > 0) {
            result.push({
                key: phase,
                title: phaseColumnTitles[phase],
                games: [...list].sort(
                    (a, b) =>
                        (a.bracket_position ?? a.id) -
                        (b.bracket_position ?? b.id),
                ),
            });
        }
    }

    const finalsList: ChampionshipGameProp[] = [
        ...(byPhase.final ?? []),
        ...(byPhase.third_place ?? []),
    ];
    if (finalsList.length > 0) {
        result.push({
            key: 'finals',
            title:
                byPhase.third_place && byPhase.third_place.length > 0
                    ? 'Final e 3º lugar'
                    : 'Grande Final',
            games: finalsList,
        });
    }

    // Fallback in case any custom phase exists
    if (!result.length && props.bracket.length) {
        result.push({
            key: 'all',
            title: 'Chaveamento',
            games: [...props.bracket].sort(
                (a, b) =>
                    phaseOrder.indexOf(a.phase) - phaseOrder.indexOf(b.phase),
            ),
        });
    }

    return result;
});

const gameStatusLabels: Record<GameStatus, string> = {
    scheduled: 'Agendado',
    live: 'Ao vivo',
    finished: 'Encerrado',
};

const gameStatusClasses: Record<GameStatus, string> = {
    scheduled:
        'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    live: 'bg-red-100 text-red-800 animate-pulse',
    finished: 'bg-emerald-100 text-emerald-800',
};

function formatSourceLabel(source: string): string {
    if (!source) return 'A definir';
    if (source === 'bye') return 'BYE (avanço automático)';
    if (source.startsWith('group:')) {
        const [, groupCode, pos] = source.split(':');
        return `${pos}º do Grupo ${groupCode}`;
    }
    if (source.startsWith('best:') || source.startsWith('best_extra:')) {
        const [, rank] = source.split(':');
        return `${rank}º Melhor classificado extra`;
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

function confirmGenerateBracket() {
    generating.value = true;
    router.post(
        `/campeonatos/${props.championship.id}/gerar-mata-mata`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                generating.value = false;
                showConfirmGenerate.value = false;
            },
        },
    );
}

// Schedule modal for knockout games
const editingScheduleGame = ref<ChampionshipGameProp | null>(null);
const scheduleForm = useForm<{
    venue: string;
    scheduled_at: string;
}>({
    venue: '',
    scheduled_at: '',
});

function openScheduleModal(game: ChampionshipGameProp) {
    editingScheduleGame.value = game;
    scheduleForm.clearErrors();
    scheduleForm.venue = game.venue ?? props.championship.venue ?? '';
    scheduleForm.scheduled_at = game.scheduled_at
        ? game.scheduled_at.slice(0, 16)
        : '';
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
</script>

<template>
    <div class="space-y-5">
        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
        >
            <div>
                <h2 class="text-lg font-black">Chaveamento do mata-mata</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    <template v-if="bracket.length">
                        {{ bracket.length }} confronto(s) na chave eliminatória.
                        Os vencedores avançam automaticamente ao encerrar cada
                        partida.
                    </template>
                    <template v-else>
                        Ao encerrar todos os jogos da fase de grupos (e resolver
                        eventuais empates técnicos), gere a chave do mata-mata.
                    </template>
                </p>
            </div>

            <button
                type="button"
                class="flex min-h-11 items-center gap-2 rounded-xl bg-[var(--team-primary)] px-4 text-sm font-bold text-white disabled:opacity-50"
                :disabled="!canGenerateBracket"
                data-test="abrir-gerar-mata-mata"
                @click="showConfirmGenerate = true"
            >
                <GitBranch class="size-4" />
                {{
                    bracket.length
                        ? 'Atualizar chave do mata-mata'
                        : 'Gerar chave do mata-mata'
                }}
            </button>
        </div>

        <div
            v-if="columns.length"
            class="grid gap-4"
            :class="{
                'lg:grid-cols-1': columns.length === 1,
                'lg:grid-cols-2': columns.length === 2,
                'lg:grid-cols-3': columns.length === 3,
                'lg:grid-cols-4': columns.length >= 4,
            }"
        >
            <section
                v-for="col in columns"
                :key="col.key"
                class="space-y-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-3.5 dark:border-slate-700 dark:bg-slate-800/50"
                :data-bracket-column="col.key"
            >
                <div
                    class="flex items-center justify-between border-b border-slate-200 pb-2.5 dark:border-slate-700"
                >
                    <h3
                        class="text-sm font-black tracking-tight text-slate-900 uppercase dark:text-slate-100"
                    >
                        {{ col.title }}
                    </h3>
                    <span
                        class="rounded-full bg-white px-2 py-0.5 text-[11px] font-bold text-slate-600 dark:bg-slate-900 dark:text-slate-300"
                    >
                        {{ col.games.length }}
                    </span>
                </div>

                <div class="space-y-3">
                    <article
                        v-for="game in col.games"
                        :key="game.id"
                        class="overflow-hidden rounded-xl border bg-white shadow-xs dark:bg-slate-900"
                        :class="
                            game.slot_key === 'FINAL'
                                ? 'border-amber-300 ring-1 ring-amber-200'
                                : 'border-slate-200 dark:border-slate-700'
                        "
                        :data-slot-key="game.slot_key"
                        :data-game-id="game.id"
                    >
                        <div
                            class="flex items-center justify-between gap-2 border-b border-slate-100 px-3 py-2 text-[11px] dark:border-slate-800"
                            :class="
                                game.slot_key === 'FINAL'
                                    ? 'bg-amber-50/80'
                                    : 'bg-slate-50/80 dark:bg-slate-800/60'
                            "
                        >
                            <div class="flex items-center gap-1.5">
                                <span
                                    class="rounded bg-slate-900 px-1.5 py-0.5 font-mono text-[10px] font-black text-white dark:bg-slate-700"
                                >
                                    {{ game.slot_key }}
                                </span>
                                <span
                                    class="font-bold text-slate-700 dark:text-slate-200"
                                >
                                    {{ game.phase_label }}
                                </span>
                            </div>

                            <span
                                v-if="game.is_bye"
                                class="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-200"
                            >
                                Avanço BYE
                            </span>
                            <span
                                v-else
                                class="rounded-full px-2 py-0.5 text-[10px] font-bold"
                                :class="gameStatusClasses[game.status]"
                            >
                                {{ gameStatusLabels[game.status] }}
                            </span>
                        </div>

                        <div
                            class="divide-y divide-slate-100 p-3 dark:divide-slate-800"
                        >
                            <!-- Home side -->
                            <div
                                class="flex items-center justify-between gap-2 pb-2"
                                :class="
                                    game.winner_team_id &&
                                    game.winner_team_id === game.home_team_id
                                        ? 'font-black text-emerald-900'
                                        : 'text-slate-800 dark:text-slate-100'
                                "
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
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
                                        <div class="min-w-0 flex-1">
                                            <p
                                                class="truncate text-sm font-bold"
                                            >
                                                {{
                                                    game.home_team_name ??
                                                    formatSourceLabel(
                                                        game.home_source,
                                                    )
                                                }}
                                            </p>
                                            <p
                                                class="truncate text-[10px] font-semibold text-slate-400"
                                            >
                                                {{
                                                    formatSourceLabel(
                                                        game.home_source,
                                                    )
                                                }}
                                                <span class="font-mono"
                                                    >({{
                                                        game.home_source
                                                    }})</span
                                                >
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    v-if="!game.is_bye"
                                    class="flex shrink-0 items-center gap-1.5"
                                >
                                    <Trophy
                                        v-if="
                                            game.winner_team_id &&
                                            game.winner_team_id ===
                                                game.home_team_id
                                        "
                                        class="size-3.5 text-amber-500"
                                    />
                                    <span
                                        v-if="game.status !== 'scheduled'"
                                        class="rounded-md bg-slate-100 px-2 py-0.5 text-sm font-black dark:bg-slate-800"
                                    >
                                        {{ game.home_regular_score }}
                                        <span
                                            v-if="game.went_to_shootout"
                                            class="text-[10px] font-bold text-slate-500 dark:text-slate-400"
                                        >
                                            ({{ game.home_shootout_score }})
                                        </span>
                                    </span>
                                </div>
                            </div>

                            <!-- Away side -->
                            <div
                                class="flex items-center justify-between gap-2 pt-2"
                                :class="
                                    game.winner_team_id &&
                                    game.winner_team_id === game.away_team_id
                                        ? 'font-black text-emerald-900'
                                        : 'text-slate-800 dark:text-slate-100'
                                "
                            >
                                <div class="min-w-0 flex-1">
                                    <div
                                        v-if="game.is_bye"
                                        class="text-xs text-slate-400 italic"
                                    >
                                        BYE — Sem adversário (avança direto)
                                    </div>
                                    <div v-else class="flex items-center gap-2">
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
                                        <div class="min-w-0 flex-1">
                                            <p
                                                class="truncate text-sm font-bold"
                                            >
                                                {{
                                                    game.away_team_name ??
                                                    formatSourceLabel(
                                                        game.away_source,
                                                    )
                                                }}
                                            </p>
                                            <p
                                                class="truncate text-[10px] font-semibold text-slate-400"
                                            >
                                                {{
                                                    formatSourceLabel(
                                                        game.away_source,
                                                    )
                                                }}
                                                <span class="font-mono"
                                                    >({{
                                                        game.away_source
                                                    }})</span
                                                >
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    v-if="!game.is_bye"
                                    class="flex shrink-0 items-center gap-1.5"
                                >
                                    <Trophy
                                        v-if="
                                            game.winner_team_id &&
                                            game.winner_team_id ===
                                                game.away_team_id
                                        "
                                        class="size-3.5 text-amber-500"
                                    />
                                    <span
                                        v-if="game.status !== 'scheduled'"
                                        class="rounded-md bg-slate-100 px-2 py-0.5 text-sm font-black dark:bg-slate-800"
                                    >
                                        {{ game.away_regular_score }}
                                        <span
                                            v-if="game.went_to_shootout"
                                            class="text-[10px] font-bold text-slate-500 dark:text-slate-400"
                                        >
                                            ({{ game.away_shootout_score }})
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Winner Banner + Footer Actions -->
                        <div
                            v-if="!game.is_bye"
                            class="border-t border-slate-100 bg-slate-50/50 px-3 py-2 text-[11px] dark:border-slate-800 dark:bg-slate-800/40"
                        >
                            <div
                                v-if="game.winner_team_name"
                                class="mb-1.5 flex items-center gap-1 font-bold text-emerald-800"
                            >
                                <Trophy class="size-3.5 text-amber-500" />
                                Vencedor: {{ game.winner_team_name }}
                                <span
                                    v-if="game.went_to_shootout"
                                    class="text-[10px] font-semibold text-slate-500 dark:text-slate-400"
                                >
                                    (nos pênaltis/shootout)
                                </span>
                                <span
                                    v-else-if="game.went_to_overtime"
                                    class="text-[10px] font-semibold text-slate-500 dark:text-slate-400"
                                >
                                    (na prorrogação)
                                </span>
                            </div>

                            <div
                                class="flex flex-wrap items-center justify-between gap-2 text-slate-500 dark:text-slate-400"
                            >
                                <div class="flex flex-wrap items-center gap-2">
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
                                        class="rounded-lg border border-slate-200 bg-white px-2 py-1 font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800/60"
                                        @click="openScheduleModal(game)"
                                    >
                                        Agendar
                                    </button>
                                    <Link
                                        :href="`/campeonatos/${championship.id}/jogos/${game.id}`"
                                        class="inline-flex items-center gap-1 rounded-lg bg-slate-900 px-2.5 py-1 font-bold text-white hover:bg-slate-800 dark:bg-slate-700 dark:hover:bg-slate-600"
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
                        </div>
                    </article>
                </div>
            </section>
        </div>

        <div
            v-else
            class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-600 dark:bg-slate-900"
        >
            <GitBranch class="mx-auto size-10 text-slate-400" />
            <h3 class="mt-4 text-lg font-bold">
                Chave do mata-mata ainda não gerada
            </h3>
            <p
                class="mx-auto mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400"
            >
                Conclua todas as partidas da fase de grupos e resolva eventuais
                empates técnicos na linha de classificação para liberar a
                geração automática da chave.
            </p>
            <button
                v-if="canGenerateBracket"
                type="button"
                class="mt-5 inline-flex items-center gap-2 rounded-xl bg-slate-900 px-5 py-3 font-bold text-white dark:bg-slate-700"
                @click="showConfirmGenerate = true"
            >
                <GitBranch class="size-4" />
                Gerar mata-mata agora
            </button>
        </div>

        <!-- Generate Bracket Confirmation Modal -->
        <Teleport to="body">
            <div
                v-if="showConfirmGenerate"
                class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/70 p-4"
                @click.self="showConfirmGenerate = false"
            >
                <div
                    class="mx-auto my-6 max-w-md rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900"
                >
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-black">
                            Gerar chave do mata-mata
                        </h2>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                            aria-label="Fechar"
                            @click="showConfirmGenerate = false"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                        Os classificados da fase de grupos serão distribuídos
                        nos confrontos eliminatórios conforme as regras do
                        campeonato (incluindo avanços automáticos de BYE quando
                        aplicável).
                    </p>

                    <div class="mt-6 flex gap-3">
                        <button
                            type="button"
                            class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700 dark:border-slate-600 dark:text-slate-200"
                            @click="showConfirmGenerate = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            class="min-h-12 flex-1 rounded-xl bg-[var(--team-primary)] font-bold text-white disabled:opacity-50"
                            data-test="confirmar-gerar-mata-mata"
                            :disabled="generating"
                            @click="confirmGenerateBracket"
                        >
                            {{
                                generating ? 'Gerando…' : 'Confirmar mata-mata'
                            }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Schedule Knockout Game Modal -->
        <Teleport to="body">
            <div
                v-if="editingScheduleGame"
                class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/70 p-4"
                @click.self="closeScheduleModal"
            >
                <form
                    class="mx-auto my-6 max-w-md rounded-2xl bg-white p-5 shadow-2xl dark:bg-slate-900"
                    @submit.prevent="submitSchedule"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <p
                                class="text-xs font-bold tracking-wider text-slate-500 uppercase dark:text-slate-400"
                            >
                                {{ editingScheduleGame.slot_key }} ·
                                {{ editingScheduleGame.phase_label }}
                            </p>
                            <h2 class="text-xl font-black">
                                Agendar confronto
                            </h2>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                            aria-label="Fechar"
                            @click="closeScheduleModal"
                        >
                            <X class="size-5" />
                        </button>
                    </div>

                    <div class="mt-4 space-y-4">
                        <label class="block text-sm font-bold">
                            Local / Quadra
                            <input
                                v-model="scheduleForm.venue"
                                name="venue"
                                type="text"
                                maxlength="140"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal dark:border-slate-600"
                                placeholder="Ex.: Ginásio Principal"
                            />
                        </label>

                        <label class="block text-sm font-bold">
                            Data e horário
                            <input
                                v-model="scheduleForm.scheduled_at"
                                name="scheduled_at"
                                type="datetime-local"
                                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 font-normal dark:border-slate-600"
                            />
                        </label>
                    </div>

                    <div class="mt-6 flex gap-3">
                        <button
                            type="button"
                            class="min-h-12 flex-1 rounded-xl border border-slate-300 font-bold text-slate-700 dark:border-slate-600 dark:text-slate-200"
                            @click="closeScheduleModal"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            class="min-h-12 flex-1 rounded-xl bg-[var(--team-primary)] font-bold text-white disabled:opacity-50"
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
