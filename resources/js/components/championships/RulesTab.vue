<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Check,
    Info,
    Lock,
    RotateCcw,
} from 'lucide-vue-next';
import { computed, watch } from 'vue';
import type {
    ChampionshipProp,
    KnockoutTieResolution,
    TieBreakCriterion,
} from '@/types/championship';

const props = defineProps<{
    championship: ChampionshipProp;
    canEditRules: boolean;
}>();

const defaultTieBreakOrder: TieBreakCriterion[] = [
    'points',
    'head_to_head',
    'goal_difference',
    'goals_for',
    'wins',
    'fewest_goals_against',
    'manual_draw',
];

const criterionLabels: Record<TieBreakCriterion, string> = {
    points: 'Pontos (P)',
    head_to_head: 'Confronto direto (mini-tabela entre empatados)',
    goal_difference: 'Saldo de gols (SG)',
    goals_for: 'Gols pró (GP)',
    wins: 'Número de vitórias (V)',
    fewest_goals_against: 'Menos gols contra (GC)',
    manual_draw: 'Sorteio / definição manual auditada',
};

const form = useForm<{
    groups_count: number;
    qualifiers_per_group: number;
    extra_best_qualifiers: number;
    double_round_robin: boolean;
    points_win: number;
    points_draw: number;
    points_loss: number;
    allow_group_overtime_or_shootout: boolean;
    points_overtime_win: number;
    points_overtime_loss: number;
    tie_break_order: TieBreakCriterion[];
    period_minutes: number;
    periods_count: number;
    rest_seconds: number;
    countdown_seconds: number;
    overtime_minutes: number;
    knockout_tie_resolution: KnockoutTieResolution;
    has_third_place_match: boolean;
    max_assists_per_goal: number;
}>({
    groups_count: props.championship.rules.groups_count,
    qualifiers_per_group: props.championship.rules.qualifiers_per_group,
    extra_best_qualifiers: props.championship.rules.extra_best_qualifiers,
    double_round_robin: props.championship.rules.double_round_robin,
    points_win: props.championship.rules.points_win,
    points_draw: props.championship.rules.points_draw,
    points_loss: props.championship.rules.points_loss,
    allow_group_overtime_or_shootout:
        props.championship.rules.allow_group_overtime_or_shootout,
    points_overtime_win: props.championship.rules.points_overtime_win,
    points_overtime_loss: props.championship.rules.points_overtime_loss,
    tie_break_order: [...props.championship.rules.tie_break_order],
    period_minutes: props.championship.rules.period_minutes,
    periods_count: props.championship.rules.periods_count,
    rest_seconds: props.championship.rules.rest_seconds,
    countdown_seconds: props.championship.rules.countdown_seconds,
    overtime_minutes: props.championship.rules.overtime_minutes,
    knockout_tie_resolution: props.championship.rules.knockout_tie_resolution,
    has_third_place_match: props.championship.rules.has_third_place_match,
    max_assists_per_goal: props.championship.rules.max_assists_per_goal,
});

watch(
    () => props.championship.rules,
    (rules) => {
        form.groups_count = rules.groups_count;
        form.qualifiers_per_group = rules.qualifiers_per_group;
        form.extra_best_qualifiers = rules.extra_best_qualifiers;
        form.double_round_robin = rules.double_round_robin;
        form.points_win = rules.points_win;
        form.points_draw = rules.points_draw;
        form.points_loss = rules.points_loss;
        form.allow_group_overtime_or_shootout =
            rules.allow_group_overtime_or_shootout;
        form.points_overtime_win = rules.points_overtime_win;
        form.points_overtime_loss = rules.points_overtime_loss;
        form.tie_break_order = [...rules.tie_break_order];
        form.period_minutes = rules.period_minutes;
        form.periods_count = rules.periods_count;
        form.rest_seconds = rules.rest_seconds;
        form.countdown_seconds = rules.countdown_seconds;
        form.overtime_minutes = rules.overtime_minutes;
        form.knockout_tie_resolution = rules.knockout_tie_resolution;
        form.has_third_place_match = rules.has_third_place_match;
        form.max_assists_per_goal = rules.max_assists_per_goal;
    },
);

const totalQualifiers = computed(
    () =>
        Number(form.groups_count) * Number(form.qualifiers_per_group) +
        Number(form.extra_best_qualifiers),
);

function moveCriterion(index: number, direction: -1 | 1) {
    if (!props.canEditRules) return;
    const target = index + direction;
    if (target < 0 || target >= form.tie_break_order.length) return;
    const copy = [...form.tie_break_order];
    const [item] = copy.splice(index, 1);
    copy.splice(target, 0, item);
    form.tie_break_order = copy;
}

function resetDefaultCriteria() {
    if (!props.canEditRules) return;
    form.tie_break_order = [...defaultTieBreakOrder];
}

function submitRules() {
    if (!props.canEditRules) return;
    form.patch(`/campeonatos/${props.championship.id}/regras`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <div class="space-y-4">
        <div
            class="flex items-start gap-3 rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900"
        >
            <Info class="mt-0.5 size-5 shrink-0 text-blue-700" />
            <div>
                <p class="font-bold">
                    Padrão sugerido — ajuste ao regulamento local
                </p>
                <p class="mt-0.5 text-xs text-blue-800">
                    Os valores pré-preenchidos são apenas sugestões iniciais
                    (ex.: 3-1-0 pontos, até 2 assistências por gol, 2 períodos
                    de 10 minutos). Ajuste livremente cada parâmetro conforme o
                    regulamento oficial do seu campeonato antes do sorteio dos
                    grupos.
                </p>
            </div>
        </div>

        <div
            v-if="!canEditRules"
            class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"
            role="status"
        >
            <Lock class="mt-0.5 size-5 shrink-0 text-amber-700" />
            <div>
                <p class="font-bold">Regras travadas após o sorteio</p>
                <p class="mt-0.5 text-xs text-amber-800">
                    Como o sorteio dos grupos já foi realizado, as regras estão
                    bloqueadas para edição a fim de garantir a integridade da
                    classificação e do chaveamento.
                </p>
            </div>
        </div>

        <form
            class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5"
            @submit.prevent="submitRules"
        >
            <fieldset :disabled="!canEditRules" class="space-y-6">
                <!-- Formato da Fase de Grupos e Classificação -->
                <div>
                    <h3 class="text-base font-black text-slate-900">
                        1. Fase de grupos e vagas no mata-mata
                    </h3>
                    <p class="text-xs text-slate-500">
                        Total de classificados calculado:
                        <strong
                            :class="
                                totalQualifiers >= 2 && totalQualifiers <= 16
                                    ? 'text-emerald-700'
                                    : 'text-red-600'
                            "
                        >
                            {{ totalQualifiers }} equipe(s)
                        </strong>
                        (deve ficar entre 2 e 16).
                    </p>

                    <div class="mt-3 grid gap-4 sm:grid-cols-3">
                        <label class="block text-sm font-bold">
                            Quantidade de grupos
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão sugerido: 2 (1 a 8)</span
                            >
                            <input
                                v-model.number="form.groups_count"
                                name="groups_count"
                                type="number"
                                min="1"
                                max="8"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                            <span
                                v-if="form.errors.groups_count"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ form.errors.groups_count }}
                            </span>
                        </label>

                        <label class="block text-sm font-bold">
                            Classificados por grupo
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão sugerido: 2 (1 a 8)</span
                            >
                            <input
                                v-model.number="form.qualifiers_per_group"
                                name="qualifiers_per_group"
                                type="number"
                                min="1"
                                max="8"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                            <span
                                v-if="form.errors.qualifiers_per_group"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ form.errors.qualifiers_per_group }}
                            </span>
                        </label>

                        <label class="block text-sm font-bold">
                            Melhores classificados extras
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão sugerido: 0 (melhores 3ºs, etc.)</span
                            >
                            <input
                                v-model.number="form.extra_best_qualifiers"
                                name="extra_best_qualifiers"
                                type="number"
                                min="0"
                                max="8"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                            <span
                                v-if="form.errors.extra_best_qualifiers"
                                class="mt-1 block text-xs text-red-600"
                            >
                                {{ form.errors.extra_best_qualifiers }}
                            </span>
                        </label>
                    </div>

                    <label
                        class="mt-3 flex cursor-pointer items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm font-bold"
                    >
                        <input
                            v-model="form.double_round_robin"
                            name="double_round_robin"
                            type="checkbox"
                            class="size-5 accent-emerald-700"
                        />
                        <div>
                            <span>Turno e returno na fase de grupos</span>
                            <span
                                class="block text-xs font-normal text-slate-500"
                            >
                                Padrão sugerido: desmarcado (apenas turno único
                                dentro do grupo).
                            </span>
                        </div>
                    </label>
                </div>

                <!-- Sistema de Pontuação -->
                <div class="border-t border-slate-100 pt-5">
                    <h3 class="text-base font-black text-slate-900">
                        2. Sistema de pontuação
                    </h3>
                    <div class="mt-3 grid gap-4 sm:grid-cols-3">
                        <label class="block text-sm font-bold">
                            Pontos por vitória (V)
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão sugerido: 3</span
                            >
                            <input
                                v-model.number="form.points_win"
                                name="points_win"
                                type="number"
                                min="0"
                                max="10"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                        </label>

                        <label class="block text-sm font-bold">
                            Pontos por empate (E)
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão sugerido: 1</span
                            >
                            <input
                                v-model.number="form.points_draw"
                                name="points_draw"
                                type="number"
                                min="0"
                                max="10"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                        </label>

                        <label class="block text-sm font-bold">
                            Pontos por derrota (D)
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão sugerido: 0</span
                            >
                            <input
                                v-model.number="form.points_loss"
                                name="points_loss"
                                type="number"
                                min="0"
                                max="10"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                        </label>
                    </div>

                    <label
                        class="mt-3 flex cursor-pointer items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm font-bold"
                    >
                        <input
                            v-model="form.allow_group_overtime_or_shootout"
                            name="allow_group_overtime_or_shootout"
                            type="checkbox"
                            class="size-5 accent-emerald-700"
                        />
                        <div>
                            <span
                                >Exigir desempate por prorrogação/shootout na
                                fase de grupos</span
                            >
                            <span
                                class="block text-xs font-normal text-slate-500"
                            >
                                Padrão sugerido: desmarcado (permite empate no
                                tempo normal).
                            </span>
                        </div>
                    </label>

                    <div
                        v-if="form.allow_group_overtime_or_shootout"
                        class="mt-3 grid gap-4 sm:grid-cols-2"
                    >
                        <label class="block text-sm font-bold">
                            Pontos por vitória na prorrogação/shootout
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão sugerido: 2</span
                            >
                            <input
                                v-model.number="form.points_overtime_win"
                                name="points_overtime_win"
                                type="number"
                                min="0"
                                max="10"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                        </label>

                        <label class="block text-sm font-bold">
                            Pontos por derrota na prorrogação/shootout
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão sugerido: 1</span
                            >
                            <input
                                v-model.number="form.points_overtime_loss"
                                name="points_overtime_loss"
                                type="number"
                                min="0"
                                max="10"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                        </label>
                    </div>
                </div>

                <!-- Ordem de Desempate -->
                <div class="border-t border-slate-100 pt-5">
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <div>
                            <h3 class="text-base font-black text-slate-900">
                                3. Ordem dos critérios de desempate
                            </h3>
                            <p class="text-xs text-slate-500">
                                Ordem padrão sugerida: Pontos → Confronto direto
                                → Saldo de gols → Gols pró → Vitórias → Menos
                                gols contra → Sorteio manual.
                            </p>
                        </div>
                        <button
                            v-if="canEditRules"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-200"
                            @click="resetDefaultCriteria"
                        >
                            <RotateCcw class="size-3.5" />Restaurar padrão
                        </button>
                    </div>

                    <ol class="mt-3 space-y-2">
                        <li
                            v-for="(criterion, index) in form.tie_break_order"
                            :key="criterion"
                            class="flex items-center justify-between gap-2 rounded-xl border border-slate-200 bg-slate-50/70 px-3 py-2 text-xs"
                        >
                            <div class="flex items-center gap-2.5">
                                <span
                                    class="inline-flex size-6 items-center justify-center rounded-lg bg-slate-900 text-[11px] font-black text-white"
                                >
                                    {{ index + 1 }}º
                                </span>
                                <span class="font-bold text-slate-800">
                                    {{
                                        criterionLabels[criterion] ?? criterion
                                    }}
                                </span>
                            </div>

                            <div
                                v-if="canEditRules"
                                class="flex items-center gap-1"
                            >
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 bg-white p-1 text-slate-600 hover:bg-slate-100 disabled:opacity-40"
                                    :disabled="index === 0"
                                    :aria-label="`Subir ${criterionLabels[criterion]}`"
                                    @click="moveCriterion(index, -1)"
                                >
                                    <ArrowUp class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg border border-slate-200 bg-white p-1 text-slate-600 hover:bg-slate-100 disabled:opacity-40"
                                    :disabled="
                                        index ===
                                        form.tie_break_order.length - 1
                                    "
                                    :aria-label="`Descer ${criterionLabels[criterion]}`"
                                    @click="moveCriterion(index, 1)"
                                >
                                    <ArrowDown class="size-3.5" />
                                </button>
                            </div>
                        </li>
                    </ol>
                    <span
                        v-if="form.errors.tie_break_order"
                        class="mt-1 block text-xs text-red-600"
                    >
                        {{ form.errors.tie_break_order }}
                    </span>
                </div>

                <!-- Tempo de Jogo e Cronômetro -->
                <div class="border-t border-slate-100 pt-5">
                    <h3 class="text-base font-black text-slate-900">
                        4. Tempo de jogo e cronômetro
                    </h3>
                    <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                        <label class="block text-sm font-bold">
                            Minutos / período
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão: 10 min</span
                            >
                            <input
                                v-model.number="form.period_minutes"
                                name="period_minutes"
                                type="number"
                                min="1"
                                max="120"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                        </label>

                        <label class="block text-sm font-bold">
                            Nº de períodos
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão: 2</span
                            >
                            <input
                                v-model.number="form.periods_count"
                                name="periods_count"
                                type="number"
                                min="1"
                                max="6"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                        </label>

                        <label class="block text-sm font-bold">
                            Intervalo (seg)
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão: 120 s</span
                            >
                            <input
                                v-model.number="form.rest_seconds"
                                name="rest_seconds"
                                type="number"
                                min="0"
                                max="3600"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                        </label>

                        <label class="block text-sm font-bold">
                            Contagem (seg)
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão: 5 s</span
                            >
                            <input
                                v-model.number="form.countdown_seconds"
                                name="countdown_seconds"
                                type="number"
                                min="0"
                                max="120"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                        </label>

                        <label class="block text-sm font-bold">
                            Prorrogação (min)
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão: 5 min</span
                            >
                            <input
                                v-model.number="form.overtime_minutes"
                                name="overtime_minutes"
                                type="number"
                                min="1"
                                max="60"
                                required
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal disabled:bg-slate-100"
                            />
                        </label>
                    </div>
                </div>

                <!-- Mata-mata e Súmula -->
                <div class="border-t border-slate-100 pt-5">
                    <h3 class="text-base font-black text-slate-900">
                        5. Mata-mata e súmula
                    </h3>

                    <div class="mt-3 grid gap-4 sm:grid-cols-2">
                        <label class="block text-sm font-bold">
                            Resolução de empate no mata-mata
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão sugerido: Prorrogação + shootout</span
                            >
                            <select
                                v-model="form.knockout_tie_resolution"
                                name="knockout_tie_resolution"
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 font-normal disabled:bg-slate-100"
                            >
                                <option value="overtime_then_shootout">
                                    Prorrogação seguida de shootout / pênaltis
                                </option>
                                <option value="shootout_only">
                                    Direto para shootout / pênaltis
                                </option>
                            </select>
                        </label>

                        <label class="block text-sm font-bold">
                            Máximo de assistências por gol
                            <span
                                class="block text-xs font-normal text-slate-500"
                                >Padrão sugerido: até 2 assistências (0 a
                                2)</span
                            >
                            <select
                                v-model.number="form.max_assists_per_goal"
                                name="max_assists_per_goal"
                                class="mt-1 min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 font-normal disabled:bg-slate-100"
                            >
                                <option :value="0">
                                    0 (sem registro de assistências)
                                </option>
                                <option :value="1">
                                    Até 1 assistência por gol
                                </option>
                                <option :value="2">
                                    Até 2 assistências por gol
                                </option>
                            </select>
                        </label>
                    </div>

                    <label
                        class="mt-3 flex cursor-pointer items-center gap-3 rounded-xl bg-slate-50 p-3 text-sm font-bold"
                    >
                        <input
                            v-model="form.has_third_place_match"
                            name="has_third_place_match"
                            type="checkbox"
                            class="size-5 accent-emerald-700"
                        />
                        <div>
                            <span>Incluir disputa de 3º lugar</span>
                            <span
                                class="block text-xs font-normal text-slate-500"
                            >
                                Padrão sugerido: marcado (perdedores das
                                semifinais disputam o 3º lugar).
                            </span>
                        </div>
                    </label>
                </div>
            </fieldset>

            <div v-if="canEditRules" class="flex justify-end pt-2">
                <button
                    type="submit"
                    class="flex min-h-12 items-center gap-2 rounded-xl bg-[var(--team-primary)] px-6 font-bold text-white disabled:opacity-50"
                    data-test="salvar-regras"
                    :disabled="form.processing"
                >
                    <Check class="size-5" />
                    {{ form.processing ? 'Salvando…' : 'Salvar regras' }}
                </button>
            </div>
        </form>
    </div>
</template>
