<script setup lang="ts">
import { useConfirm } from '@/composables/useConfirm';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    Calendar,
    History,
    Search,
    Timer,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

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

const props = defineProps<{ matches: Summary[] }>();
const { confirm } = useConfirm();
const search = ref('');

const totalGoals = computed(() =>
    props.matches.reduce((sum, match) => sum + match.goals_count, 0),
);

const filteredMatches = computed(() => {
    const query = search.value.trim().toLowerCase();
    if (!query) return props.matches;
    return props.matches.filter((match) =>
        `${match.home_name} ${match.away_name} ${match.finished_at ?? ''}`
            .toLowerCase()
            .includes(query),
    );
});

async function destroyMatch(match: Summary) {
    const accepted = await confirm({
        title: 'Excluir partida salva?',
        message: `${match.home_name} ${match.home_score} × ${match.away_score} ${match.away_name} será removida e os gols dela sairão das estatísticas.`,
        confirmLabel: 'Excluir partida',
        variant: 'danger',
    });
    if (!accepted) return;

    router.delete(`/partidas/${match.id}`);
}
</script>

<template>
    <Head title="Histórico de partidas" />

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p
                class="text-[11px] font-extrabold tracking-widest text-[var(--team-primary)] uppercase dark:text-emerald-400"
            >
                Jogos concluídos
            </p>
            <h1
                class="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-950 sm:text-3xl dark:text-white"
            >
                Histórico de partidas
            </h1>
        </div>
        <div
            v-if="matches.length"
            class="flex items-center gap-2 text-xs font-bold text-slate-600 dark:text-slate-300"
        >
            <span class="surface-card rounded-xl px-3 py-1.5"
                >{{ matches.length }} partida(s)</span
            >
            <span class="surface-card rounded-xl px-3 py-1.5"
                >{{ totalGoals }} gol(s)</span
            >
        </div>
    </div>

    <div v-if="matches.length" class="mb-4">
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3.5 size-4.5 -translate-y-1/2 text-slate-400"
            />
            <input
                v-model="search"
                type="search"
                class="surface-card min-h-11 w-full rounded-2xl pr-4 pl-10 text-sm font-medium text-slate-950 focus:border-[var(--team-primary)] focus:outline-none dark:text-slate-100"
                placeholder="Filtrar por nome da equipe ou data"
                aria-label="Filtrar partidas"
            />
        </div>
    </div>

    <div v-if="filteredMatches.length" class="space-y-3">
        <article
            v-for="match in filteredMatches"
            :key="match.id"
            class="surface-card group flex items-stretch overflow-hidden rounded-2xl transition-all hover:shadow-md"
        >
            <div class="flex w-1.5 shrink-0 flex-col">
                <span
                    class="flex-1"
                    :style="{ background: match.home_color }"
                />
                <span
                    class="flex-1"
                    :style="{ background: match.away_color }"
                />
            </div>

            <Link
                :href="`/partidas/${match.id}`"
                class="flex min-w-0 flex-1 items-center gap-4 p-4 transition-colors hover:bg-slate-50/70 dark:hover:bg-slate-800/40"
            >
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="flex items-center gap-2.5">
                        <span
                            class="size-2.5 shrink-0 rounded-full"
                            :style="{ background: match.home_color }"
                        />
                        <p
                            class="truncate text-sm font-extrabold sm:text-base"
                            :class="
                                match.home_score >= match.away_score
                                    ? 'text-slate-950 dark:text-white'
                                    : 'text-slate-500 dark:text-slate-400'
                            "
                        >
                            {{ match.home_name }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2.5">
                        <span
                            class="size-2.5 shrink-0 rounded-full"
                            :style="{ background: match.away_color }"
                        />
                        <p
                            class="truncate text-sm font-extrabold sm:text-base"
                            :class="
                                match.away_score >= match.home_score
                                    ? 'text-slate-950 dark:text-white'
                                    : 'text-slate-500 dark:text-slate-400'
                            "
                        >
                            {{ match.away_name }}
                        </p>
                    </div>
                    <p
                        class="flex flex-wrap items-center gap-2 pt-0.5 text-xs font-medium text-slate-500 dark:text-slate-400"
                    >
                        <span class="inline-flex items-center gap-1">
                            <Calendar class="size-3.5" />
                            {{ match.finished_at }}
                        </span>
                        <span>·</span>
                        <span>{{ match.goals_count }} gol(s)</span>
                        <span
                            v-if="match.home_score === match.away_score"
                            class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[10px] font-extrabold text-slate-600 uppercase dark:bg-slate-800 dark:text-slate-300"
                        >
                            Empate
                        </span>
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <div
                        class="space-y-0.5 text-right font-display text-3xl leading-none font-extrabold tabular-nums"
                    >
                        <p
                            :class="
                                match.home_score >= match.away_score
                                    ? 'text-slate-950 dark:text-white'
                                    : 'text-slate-400 dark:text-slate-500'
                            "
                        >
                            {{ match.home_score }}
                        </p>
                        <p
                            :class="
                                match.away_score >= match.home_score
                                    ? 'text-slate-950 dark:text-white'
                                    : 'text-slate-400 dark:text-slate-500'
                            "
                        >
                            {{ match.away_score }}
                        </p>
                    </div>
                    <ArrowRight
                        class="size-4.5 shrink-0 text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-slate-600 dark:text-slate-600 dark:group-hover:text-slate-300"
                    />
                </div>
            </Link>

            <div
                class="flex items-center border-l border-slate-100 px-2.5 dark:border-slate-800"
            >
                <button
                    type="button"
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl text-slate-400 transition-colors hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/60 dark:hover:text-red-400"
                    aria-label="Excluir partida"
                    @click="destroyMatch(match)"
                >
                    <Trash2 class="size-4.5" />
                </button>
            </div>
        </article>
    </div>

    <div
        v-else-if="!matches.length"
        class="surface-card mt-5 rounded-3xl border-dashed p-10 text-center"
    >
        <span
            class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-[var(--team-primary-soft)] text-[var(--team-primary)]"
        >
            <History class="size-7" />
        </span>
        <h2 class="mt-4 text-xl font-extrabold text-slate-950 dark:text-white">
            Nenhuma partida salva
        </h2>
        <p class="mt-1.5 text-sm text-slate-500 dark:text-slate-400">
            O placar, os gols e as assistências de cada jogo aparecem aqui.
        </p>
        <Link
            href="/partida"
            class="mt-5 inline-flex min-h-12 items-center gap-2 rounded-xl bg-[var(--team-primary)] px-5 font-bold text-[var(--team-primary-text)] shadow-sm transition-all hover:brightness-110"
        >
            <Timer class="size-4.5" />
            Abrir placar
        </Link>
    </div>

    <div
        v-else
        class="surface-card rounded-2xl p-8 text-center text-sm text-slate-500 dark:text-slate-400"
    >
        Nenhuma partida encontrada para “{{ search }}”.
    </div>
</template>
