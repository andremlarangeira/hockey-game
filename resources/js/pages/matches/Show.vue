<script setup lang="ts">
import { useConfirm } from '@/composables/useConfirm';
import { readableText } from '@/lib/balancedDraw';
import { formatClock } from '@/lib/matchClock';
import type {
    DrawTeam,
    GoalEvent,
    MatchSettings,
    TimelineEvent,
} from '@/types/hockey';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Calendar, Check, Clock3, Trash2 } from 'lucide-vue-next';

interface SavedMatch {
    id: number;
    home_score: number;
    away_score: number;
    settings: MatchSettings;
    roster: { home: DrawTeam; away: DrawTeam };
    timeline: TimelineEvent[];
    started_at: string;
    finished_at: string;
}

const props = defineProps<{
    match: SavedMatch;
    goals: (Omit<GoalEvent, 'recorded_at'> & {
        recorded_at: string;
        created_at: string;
    })[];
}>();

const { confirm } = useConfirm();

const eventNames: Record<string, string> = {
    pre_start: 'Contagem para começar',
    start: 'Início',
    pause: 'Pausa',
    resume: 'Retomada',
    period_start: 'Início do tempo',
    period_end: 'Fim do tempo',
    rest_start: 'Início do descanso',
    rest_end: 'Fim do descanso',
    finish: 'Fim da partida',
    goal_added: 'Gol marcado',
    goal_removed: 'Gol desfeito',
};

async function destroyMatch() {
    const accepted = await confirm({
        title: 'Excluir partida salva?',
        message:
            'Os gols e assistências desta partida também serão removidos das estatísticas do elenco.',
        confirmLabel: 'Excluir partida',
        variant: 'danger',
    });
    if (!accepted) return;

    router.delete(`/partidas/${props.match.id}`);
}
</script>

<template>
    <Head :title="`${match.roster.home.name} × ${match.roster.away.name}`" />

    <div class="mb-3 flex items-center justify-between gap-2">
        <Link
            href="/partidas"
            class="inline-flex min-h-10 items-center gap-1.5 rounded-xl px-2 text-sm font-bold text-slate-600 transition-colors hover:bg-white hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
        >
            <ArrowLeft class="size-4" />
            Histórico
        </Link>
        <button
            type="button"
            class="inline-flex min-h-9 items-center gap-1.5 rounded-xl border border-red-200 bg-white px-3 text-xs font-bold text-red-600 transition-colors hover:bg-red-50 dark:border-red-900/60 dark:bg-slate-900 dark:text-red-400 dark:hover:bg-red-950/50"
            @click="destroyMatch"
        >
            <Trash2 class="size-3.5" />
            Excluir partida
        </button>
    </div>

    <section
        class="relative overflow-hidden rounded-3xl border border-white/10 bg-slate-950 p-6 text-center text-white shadow-2xl"
    >
        <div
            class="pointer-events-none absolute inset-y-0 left-0 w-1/3 opacity-25"
            :style="{
                background: `radial-gradient(circle at 0% 50%, ${match.roster.home.color}, transparent 75%)`,
            }"
        />
        <div
            class="pointer-events-none absolute inset-y-0 right-0 w-1/3 opacity-25"
            :style="{
                background: `radial-gradient(circle at 100% 50%, ${match.roster.away.color}, transparent 75%)`,
            }"
        />

        <div class="relative">
            <div class="flex flex-wrap items-center justify-center gap-2">
                <span
                    class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-slate-300"
                >
                    <Calendar class="size-3.5" />
                    {{ new Date(match.finished_at).toLocaleString('pt-BR') }}
                </span>
                <span
                    class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-bold text-emerald-300"
                >
                    <Check class="size-3.5" />
                    Partida salva
                </span>
            </div>

            <div
                class="mt-6 grid grid-cols-[1fr_auto_1fr] items-center gap-3 sm:gap-6"
            >
                <div class="min-w-0">
                    <span
                        class="mx-auto mb-2 block h-1.5 w-14 rounded-full"
                        :style="{ background: match.roster.home.color }"
                    />
                    <h1 class="truncate text-base font-extrabold sm:text-xl">
                        {{ match.roster.home.name }}
                    </h1>
                </div>

                <p
                    class="font-display text-6xl leading-none font-extrabold tabular-nums sm:text-7xl"
                >
                    {{ match.home_score }}
                    <span class="mx-1 text-slate-500">×</span>
                    {{ match.away_score }}
                </p>

                <div class="min-w-0">
                    <span
                        class="mx-auto mb-2 block h-1.5 w-14 rounded-full"
                        :style="{ background: match.roster.away.color }"
                    />
                    <h1 class="truncate text-base font-extrabold sm:text-xl">
                        {{ match.roster.away.name }}
                    </h1>
                </div>
            </div>
        </div>
    </section>

    <section class="surface-card mt-4 rounded-2xl p-4 sm:p-5">
        <div class="flex items-center justify-between">
            <h2 class="text-base font-extrabold text-slate-950 dark:text-white">
                Gols e assistências
            </h2>
            <span
                class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300"
            >
                {{ goals.length }}
            </span>
        </div>

        <p
            v-if="!goals.length"
            class="mt-4 text-sm text-slate-500 dark:text-slate-400"
        >
            Nenhum gol marcado.
        </p>

        <div class="mt-3 space-y-2.5">
            <div
                v-for="goal in goals"
                :key="goal.id"
                class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"
            >
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl font-display text-base font-extrabold shadow-xs"
                    :style="{
                        background: match.roster[goal.team].color,
                        color: readableText(match.roster[goal.team].color),
                    }"
                >
                    {{ goal.period }}º
                </span>
                <div class="min-w-0 flex-1">
                    <p
                        class="truncate font-extrabold text-slate-950 dark:text-white"
                    >
                        {{ goal.scorer_name }}
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{
                            goal.assist_name
                                ? `Assistência: ${goal.assist_name}`
                                : 'Sem assistência'
                        }}
                    </p>
                </div>
                <div class="shrink-0 text-right">
                    <span
                        class="block font-display text-lg leading-none font-extrabold text-slate-800 tabular-nums dark:text-slate-100"
                    >
                        {{ formatClock(goal.remaining_seconds) }}
                    </span>
                    <span
                        class="mt-0.5 block text-[11px] text-slate-400 tabular-nums"
                    >
                        {{
                            new Date(goal.recorded_at).toLocaleTimeString(
                                'pt-BR',
                                { hour: '2-digit', minute: '2-digit' },
                            )
                        }}
                    </span>
                </div>
            </div>
        </div>
    </section>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <section class="surface-card rounded-2xl p-4 sm:p-5">
            <div class="flex items-center gap-2">
                <Clock3 class="size-4.5 text-slate-400" />
                <h2
                    class="text-base font-extrabold text-slate-950 dark:text-white"
                >
                    Cronologia
                </h2>
            </div>

            <ol
                v-if="match.timeline?.length"
                class="mt-4 space-y-2.5 border-l-2 border-slate-200 pl-4 dark:border-slate-800"
            >
                <li
                    v-for="(event, index) in match.timeline"
                    :key="index"
                    class="relative text-xs text-slate-600 dark:text-slate-300"
                >
                    <span
                        class="absolute top-1 -left-[21px] size-2.5 rounded-full ring-2 ring-white dark:ring-slate-900"
                        :class="
                            event.type === 'goal_added'
                                ? 'bg-emerald-500'
                                : event.type === 'goal_removed'
                                  ? 'bg-red-500'
                                  : event.type === 'finish'
                                    ? 'bg-amber-500'
                                    : 'bg-slate-400 dark:bg-slate-600'
                        "
                    />
                    <span
                        class="font-bold text-slate-900 tabular-nums dark:text-slate-100"
                    >
                        {{ new Date(event.at).toLocaleTimeString('pt-BR') }}
                    </span>
                    · {{ eventNames[event.type] ?? event.type }}
                    <span v-if="event.period" class="text-slate-400">
                        · {{ event.period }}º tempo
                    </span>
                </li>
            </ol>
            <p v-else class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                Sem eventos de relógio registrados.
            </p>
        </section>

        <section class="surface-card rounded-2xl p-4 sm:p-5">
            <h2 class="text-base font-extrabold text-slate-950 dark:text-white">
                Elencos salvos
            </h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <div
                    v-for="side in ['home', 'away'] as const"
                    :key="side"
                    class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"
                >
                    <div class="flex items-center gap-2">
                        <span
                            class="size-3 shrink-0 rounded-full"
                            :style="{ background: match.roster[side].color }"
                        />
                        <h3
                            class="truncate text-sm font-extrabold text-slate-950 dark:text-white"
                        >
                            {{ match.roster[side].name }}
                        </h3>
                    </div>
                    <ul class="mt-2.5 space-y-1.5">
                        <li
                            v-for="player in match.roster[side].players"
                            :key="player.id"
                            class="flex items-center justify-between text-xs font-medium text-slate-700 dark:text-slate-300"
                        >
                            <span class="truncate">{{ player.name }}</span>
                            <strong
                                class="ml-2 shrink-0 font-display text-base font-extrabold text-slate-900 tabular-nums dark:text-white"
                            >
                                #{{ player.jersey_number }}
                            </strong>
                        </li>
                    </ul>
                </div>
            </div>
        </section>
    </div>
</template>
