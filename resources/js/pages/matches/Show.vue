<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Calendar, Check } from 'lucide-vue-next';
import { formatClock } from '@/lib/matchClock';
import type {
    GoalEvent,
    MatchSettings,
    DrawTeam,
    TimelineEvent,
} from '@/types/hockey';
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
</script>
<template>
    <Head
        :title="`${match.roster.home.name} × ${match.roster.away.name}`"
    /><Link
        href="/partidas"
        class="inline-flex min-h-10 items-center gap-2 text-sm font-bold"
        ><ArrowLeft class="size-4" />Histórico</Link
    >
    <section class="mt-3 rounded-2xl bg-slate-950 p-6 text-center text-white">
        <p class="text-xs text-slate-400">
            <Calendar class="mr-1 inline size-4" />{{
                new Date(match.finished_at).toLocaleString('pt-BR')
            }}
        </p>
        <div class="mt-5 grid grid-cols-[1fr_auto_1fr] items-center gap-3">
            <h1 class="font-black">{{ match.roster.home.name }}</h1>
            <p class="text-5xl font-black">
                {{ match.home_score }}
                <span class="text-slate-500 dark:text-slate-400">×</span>
                {{ match.away_score }}
            </p>
            <h1 class="font-black">{{ match.roster.away.name }}</h1>
        </div>
        <span
            class="mt-5 inline-flex items-center gap-2 rounded-full bg-green-900 px-3 py-1 text-xs text-green-100"
            ><Check class="size-4" />Partida salva</span
        >
    </section>
    <section
        class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
    >
        <h2 class="text-lg font-black">Gols e assistências</h2>
        <p
            v-if="!goals.length"
            class="mt-4 text-sm text-slate-500 dark:text-slate-400"
        >
            Nenhum gol marcado.
        </p>
        <div
            v-for="goal in goals"
            :key="goal.id"
            class="mt-3 flex gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"
        >
            <span
                class="flex size-11 shrink-0 items-center justify-center rounded-xl font-black text-white"
                :style="{ background: match.roster[goal.team].color }"
                >{{ goal.period }}º</span
            >
            <div class="min-w-0 flex-1">
                <p class="font-bold">{{ goal.scorer_name }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    {{
                        goal.assist_name
                            ? `Assistência: ${goal.assist_name}`
                            : 'Sem assistência'
                    }}
                </p>
                <p class="mt-1 text-[11px] text-slate-400">
                    {{ formatClock(goal.remaining_seconds) }} restantes ·
                    {{ new Date(goal.recorded_at).toLocaleString('pt-BR') }}
                </p>
            </div>
        </div>
    </section>
    <section
        class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
    >
        <h2 class="text-lg font-black">Cronologia</h2>
        <div class="mt-3 space-y-2">
            <p
                v-for="(event, index) in match.timeline"
                :key="index"
                class="text-xs text-slate-600 dark:text-slate-300"
            >
                <span class="font-bold">{{
                    new Date(event.at).toLocaleTimeString('pt-BR')
                }}</span>
                · {{ eventNames[event.type] ?? event.type
                }}{{ event.period ? ` · ${event.period}º tempo` : '' }}
            </p>
        </div>
        <p
            v-if="!match.timeline?.length"
            class="text-sm text-slate-500 dark:text-slate-400"
        >
            Sem eventos de relógio registrados.
        </p>
    </section>
    <details
        class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
    >
        <summary class="cursor-pointer font-bold">Elencos salvos</summary>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div v-for="side in ['home', 'away'] as const" :key="side">
                <h3 class="font-black">{{ match.roster[side].name }}</h3>
                <p
                    v-for="player in match.roster[side].players"
                    :key="player.id"
                    class="mt-1 text-sm"
                >
                    #{{ player.jersey_number }} {{ player.name }}
                </p>
            </div>
        </div>
    </details>
</template>
