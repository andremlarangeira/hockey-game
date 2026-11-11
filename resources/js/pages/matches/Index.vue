<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, History } from 'lucide-vue-next';
interface Summary {
    id: number;
    title: string;
    home_name: string;
    away_name: string;
    home_color: string;
    away_color: string;
    home_score: number;
    away_score: number;
    goals_count: number;
    finished_at: string | null;
}
defineProps<{ matches: Summary[] }>();
</script>
<template>
    <Head title="Histórico de partidas" />
    <div>
        <p
            class="text-xs font-bold tracking-widest text-slate-500 uppercase dark:text-slate-400"
        >
            Jogos concluídos
        </p>
        <h1 class="mt-1 text-2xl font-black">Histórico de partidas</h1>
    </div>
    <div v-if="matches.length" class="mt-5 space-y-3">
        <Link
            v-for="match in matches"
            :key="match.id"
            :href="`/partidas/${match.id}`"
            class="block rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
            ><div class="flex items-center gap-4">
                <div class="min-w-0 flex-1">
                    <p class="truncate font-bold">{{ match.home_name }}</p>
                    <p class="mt-2 truncate font-bold">{{ match.away_name }}</p>
                </div>
                <div class="text-center text-2xl font-black">
                    <p>{{ match.home_score }}</p>
                    <p class="mt-2">{{ match.away_score }}</p>
                </div>
                <ArrowRight class="size-5 text-slate-400" />
            </div>
            <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                {{ match.finished_at }} · {{ match.goals_count }} gols
            </p></Link
        >
    </div>
    <div
        v-else
        class="mt-5 rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-600 dark:bg-slate-900"
    >
        <History class="mx-auto size-10 text-slate-400" />
        <h2 class="mt-4 text-lg font-bold">Nenhuma partida salva</h2>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            O placar, os gols e as assistências aparecem aqui.
        </p>
        <Link
            href="/partida"
            class="mt-5 inline-block rounded-xl bg-slate-900 px-4 py-3 font-bold text-white dark:bg-slate-700"
            >Abrir placar</Link
        >
    </div>
</template>
