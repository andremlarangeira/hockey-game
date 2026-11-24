<script setup lang="ts">
import { computed } from 'vue';
import type { ChampionshipStatus, GameStatus } from '@/types/championship';

const props = defineProps<{
    status: ChampionshipStatus | GameStatus;
}>();

const labels: Record<ChampionshipStatus | GameStatus, string> = {
    draft: 'Rascunho',
    group_stage: 'Fase de grupos',
    knockout_stage: 'Mata-mata',
    completed: 'Encerrado',
    scheduled: 'Agendado',
    live: 'Ao vivo',
    finished: 'Encerrado',
};

const classes: Record<ChampionshipStatus | GameStatus, string> = {
    draft: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800/70 dark:bg-amber-950/60 dark:text-amber-200',
    group_stage:
        'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800/70 dark:bg-sky-950/60 dark:text-sky-200',
    knockout_stage:
        'border-violet-200 bg-violet-50 text-violet-800 dark:border-violet-800/70 dark:bg-violet-950/60 dark:text-violet-200',
    completed:
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/70 dark:bg-emerald-950/60 dark:text-emerald-200',
    scheduled:
        'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200',
    live: 'border-red-500/40 bg-red-600 text-white shadow-xs shadow-red-600/30',
    finished:
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/70 dark:bg-emerald-950/60 dark:text-emerald-200',
};

const isLive = computed(() => props.status === 'live');
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-bold tracking-tight"
        :class="classes[status]"
    >
        <span
            v-if="isLive"
            class="size-1.5 animate-pulse rounded-full bg-white"
        />
        {{ labels[status] }}
    </span>
</template>
