<script setup lang="ts">
import type { TimelineEvent } from '@/types/hockey';
import { ChevronDown, ChevronUp, Clock3 } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    timeline: TimelineEvent[];
}>();

const isOpen = ref(false);

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
    <section
        class="surface-card rounded-2xl p-4 sm:p-5"
        data-test="card-cronologia"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <Clock3 class="size-4.5 text-slate-400" />
                <h2
                    class="text-base font-extrabold text-slate-950 dark:text-white"
                >
                    Cronologia
                </h2>
                <span
                    class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                >
                    {{ timeline?.length ?? 0 }}
                </span>
            </div>

            <button
                type="button"
                :aria-expanded="isOpen"
                data-test="toggle-cronologia"
                class="inline-flex min-h-9 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                @click="isOpen = !isOpen"
            >
                <span>{{
                    isOpen ? 'Ocultar cronologia' : 'Ver cronologia'
                }}</span>
                <ChevronUp v-if="isOpen" class="size-3.5" />
                <ChevronDown v-else class="size-3.5" />
            </button>
        </div>

        <div v-if="isOpen" data-test="conteudo-cronologia">
            <ol
                v-if="timeline?.length"
                class="mt-4 space-y-2.5 border-l-2 border-slate-200 pl-4 dark:border-slate-800"
            >
                <li
                    v-for="(event, index) in timeline"
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
                        {{ event.at }}
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
        </div>
    </section>
</template>
