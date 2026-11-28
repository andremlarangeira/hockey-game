<script setup lang="ts">
import PlayerPhoto from '@/components/hockey/PlayerPhoto.vue';
import StarRating from '@/components/hockey/StarRating.vue';
import type { Player } from '@/types/hockey';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Archive, CalendarDays, TrendingUp } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type PeriodKey = 'all' | 'year' | 'month' | 'custom';
type MatchRow = {
    id: number;
    date: string | null;
    home_name: string;
    away_name: string;
    home_score: number;
    away_score: number;
    side: 'home' | 'away';
    goals: number;
    assists: number;
    url: string;
};

const props = defineProps<{
    player: Player & {
        archived: boolean;
        goals: number;
        assists: number;
        points: number;
        games: number;
        points_per_game: number | null;
        frequency: number;
        last_match_at: string | null;
    };
    matches: { data: MatchRow[]; links: { url: string | null; label: string; active: boolean }[] };
    trend: { month: string; label: string; points: number }[];
    period: { key: PeriodKey; year: number | null; month: string | null; from: string | null; to: string | null; label: string };
    availableYears: number[];
}>();

const periodKey = ref<PeriodKey>(props.period.key);
const year = ref(props.period.year ? String(props.period.year) : '');
const month = ref(props.period.month ?? '');
const from = ref(props.period.from ?? '');
const to = ref(props.period.to ?? '');
const maxTrend = computed(() => Math.max(1, ...props.trend.map((item) => item.points)));

function params() {
    return { period: periodKey.value, ...(year.value ? { year: year.value } : {}), ...(month.value ? { month: month.value } : {}), ...(from.value ? { from: from.value } : {}), ...(to.value ? { to: to.value } : {}) };
}

function applyPeriod() {
    router.get(`/estatisticas/jogadores/${props.player.id}`, params(), { preserveScroll: true });
}

function backUrl(): string {
    return `/estatisticas?${new URLSearchParams(
        params() as Record<string, string>,
    ).toString()}`;
}

function paginationLabel(label: string, index: number): string {
    if (index === 0) return 'Anterior';
    if (index === props.matches.links.length - 1) return 'Próxima';
    return label.replace(/&laquo;|&raquo;/g, '').trim();
}
</script>

<template>
    <Head :title="`${player.name} — Estatísticas`" />
    <Link :href="backUrl()" class="mb-4 inline-flex min-h-10 items-center gap-1.5 rounded-xl px-2 text-sm font-bold text-slate-600 hover:bg-white dark:text-slate-300 dark:hover:bg-slate-800"><ArrowLeft class="size-4" /> Estatísticas</Link>

    <section class="surface-card rounded-3xl p-5 sm:p-6">
        <div class="flex flex-wrap items-center gap-4">
            <PlayerPhoto :player="player" size="size-20 text-xl" />
            <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h1 class="truncate text-2xl font-extrabold text-slate-950 dark:text-white">{{ player.name }}</h1><span v-if="player.archived" class="inline-flex items-center gap-1 rounded-full bg-slate-200 px-2 py-1 text-[11px] font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-200"><Archive class="size-3" /> Arquivado</span></div><p class="mt-1 text-sm text-slate-500">#{{ player.jersey_number }}</p><div class="mt-2"><StarRating :model-value="Number(player.level)" readonly compact /></div></div>
            <div class="text-right text-xs text-slate-500 dark:text-slate-400"><p>Frequência no período</p><strong class="font-display text-3xl text-slate-950 dark:text-white">{{ player.frequency.toLocaleString('pt-BR') }}%</strong><p class="mt-1">{{ player.last_match_at ? `Última: ${player.last_match_at}` : 'Sem partidas no período' }}</p></div>
        </div>
    </section>

    <form class="surface-card mt-4 grid gap-3 rounded-2xl p-3 sm:grid-cols-[auto_1fr_auto] sm:items-end" @submit.prevent="applyPeriod">
        <label class="grid gap-1 text-xs font-bold text-slate-600 dark:text-slate-300">Período<select v-model="periodKey" class="min-h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"><option value="all">Tudo</option><option value="year">Ano</option><option value="month">Mês</option><option value="custom">Intervalo</option></select></label>
        <div class="grid gap-2 sm:grid-cols-2"><label v-if="periodKey === 'year'" class="grid gap-1 text-xs font-bold text-slate-600 dark:text-slate-300">Temporada<select v-model="year" required class="min-h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900"><option value="" disabled>Selecione</option><option v-for="availableYear in availableYears" :key="availableYear" :value="availableYear">{{ availableYear }}</option></select></label><label v-if="periodKey === 'month'" class="grid gap-1 text-xs font-bold text-slate-600 dark:text-slate-300">Mês<input v-model="month" required type="month" class="min-h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" /></label><template v-if="periodKey === 'custom'"><label class="grid gap-1 text-xs font-bold text-slate-600 dark:text-slate-300">De<input v-model="from" required type="date" class="min-h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" /></label><label class="grid gap-1 text-xs font-bold text-slate-600 dark:text-slate-300">Até<input v-model="to" required type="date" :min="from" class="min-h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-900" /></label></template></div>
        <button type="submit" class="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-xl bg-[var(--team-primary)] px-4 text-xs font-bold text-white"><CalendarDays class="size-4" /> Filtrar</button>
    </form>

    <section class="mt-4 grid gap-3 sm:grid-cols-5"><article v-for="stat in [{ label: 'Jogos', value: player.games }, { label: 'Gols', value: player.goals }, { label: 'Assistências', value: player.assists }, { label: 'Pontos', value: player.points }, { label: 'Pts/J', value: player.points_per_game === null ? '—' : player.points_per_game.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }]" :key="stat.label" class="surface-card rounded-2xl p-4"><p class="text-xs font-bold text-slate-500">{{ stat.label }}</p><strong class="font-display mt-1 block text-3xl text-slate-950 dark:text-white">{{ stat.value }}</strong></article></section>

    <section class="surface-card mt-4 rounded-2xl p-4 sm:p-5"><div class="flex items-center justify-between"><h2 class="flex items-center gap-2 font-extrabold text-slate-950 dark:text-white"><TrendingUp class="size-4 text-[var(--team-primary)]" /> Evolução mensal</h2><span class="text-xs text-slate-500">{{ period.label }}</span></div><p v-if="!trend.length" class="mt-4 text-sm text-slate-500">Sem pontos no período.</p><div v-else class="mt-5 flex h-40 items-end gap-3"><div v-for="item in trend" :key="item.month" class="flex min-w-10 flex-1 flex-col items-center gap-1"><span class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ item.points }}</span><div class="w-full max-w-16 rounded-t-md bg-[var(--team-primary)]" :style="{ height: `${Math.max(10, (item.points / maxTrend) * 110)}px` }"></div><span class="text-[11px] text-slate-500">{{ item.label }}</span></div></div></section>

    <section class="surface-card mt-4 overflow-hidden rounded-2xl"><div class="flex items-center justify-between border-b border-slate-100 p-4 dark:border-slate-800"><h2 class="font-extrabold text-slate-950 dark:text-white">Histórico de partidas</h2><span class="text-xs text-slate-500">{{ matches.data.length }} nesta página</span></div><p v-if="!matches.data.length" class="p-6 text-center text-sm text-slate-500">Nenhuma partida no período.</p><div v-else class="divide-y divide-slate-100 dark:divide-slate-800"><Link v-for="match in matches.data" :key="match.id" :href="match.url" class="block p-4 hover:bg-slate-50 dark:hover:bg-slate-800/40"><div class="flex flex-wrap justify-between gap-2"><p class="font-bold text-slate-950 dark:text-white">{{ match.home_name }} <span class="font-display">{{ match.home_score }} × {{ match.away_score }}</span> {{ match.away_name }}</p><span class="text-xs text-slate-500">{{ match.date }}</span></div><p class="mt-1 text-xs text-slate-500">Lado: {{ match.side === 'home' ? 'casa' : 'visitante' }} · {{ match.goals }}G · {{ match.assists }}A</p></Link></div><nav v-if="matches.links.length > 3" class="flex flex-wrap justify-center gap-1 border-t border-slate-100 p-3 dark:border-slate-800" aria-label="Paginação do histórico"><template v-for="(link, index) in matches.links" :key="`${index}-${link.label}`"><Link v-if="link.url" :href="link.url" class="inline-flex min-h-10 items-center justify-center rounded-xl px-3 text-sm font-bold transition" :class="link.active ? 'bg-[var(--team-primary)] text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800'">{{ paginationLabel(link.label, index) }}</Link><span v-else class="inline-flex min-h-10 items-center justify-center rounded-xl px-3 text-sm font-bold text-slate-300 dark:text-slate-600">{{ paginationLabel(link.label, index) }}</span></template></nav></section>
</template>
