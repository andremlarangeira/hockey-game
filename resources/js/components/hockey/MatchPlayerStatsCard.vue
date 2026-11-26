<script setup lang="ts">
import type {
    MatchPlayerStatsProp,
    MatchRosterTeamProp,
} from '@/types/hockey';
import { Award } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    roster: {
        home: MatchRosterTeamProp;
        away: MatchRosterTeamProp;
    };
    playerStats: MatchPlayerStatsProp;
}>();

const sides = ['home', 'away'] as const;

const totalParticipants = computed(
    () => props.playerStats.home.length + props.playerStats.away.length,
);
</script>

<template>
    <section
        class="surface-card mt-4 rounded-2xl p-4 sm:p-5"
        data-test="card-resumo-jogadores"
    >
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <Award class="size-4.5 text-amber-500" />
                <h2
                    class="text-base font-extrabold text-slate-950 dark:text-white"
                >
                    Resumo de gols e assistências por jogador
                </h2>
            </div>
            <span
                class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300"
            >
                {{ totalParticipants }}
            </span>
        </div>

        <p
            v-if="totalParticipants === 0"
            class="mt-4 text-sm text-slate-500 dark:text-slate-400"
            data-test="resumo-jogadores-vazio"
        >
            Nenhum gol ou assistência registrado.
        </p>

        <div v-else class="mt-3 grid gap-4 sm:grid-cols-2">
            <div
                v-for="side in sides"
                :key="side"
                class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"
                :data-test="`resumo-time-${side}`"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="size-3 shrink-0 rounded-full"
                        :style="{ background: roster[side].color }"
                    />
                    <h3
                        class="truncate text-sm font-extrabold text-slate-950 dark:text-white"
                    >
                        {{ roster[side].name }}
                    </h3>
                </div>

                <p
                    v-if="!playerStats[side].length"
                    class="mt-3 text-xs text-slate-500 dark:text-slate-400"
                >
                    Nenhum gol ou assistência registrado para esta equipe.
                </p>

                <div v-else class="mt-2.5 overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead>
                            <tr
                                class="border-b border-slate-200/80 text-[11px] font-bold text-slate-500 dark:border-slate-700/80 dark:text-slate-400"
                            >
                                <th class="pb-1.5 pr-2">Jogador</th>
                                <th
                                    class="px-2 pb-1.5 text-center"
                                    title="Gols"
                                >
                                    Gols
                                </th>
                                <th
                                    class="px-2 pb-1.5 text-center"
                                    title="Assistências"
                                >
                                    Assist.
                                </th>
                                <th
                                    class="pb-1.5 pl-2 text-right"
                                    title="Total de participações"
                                >
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody
                            class="divide-y divide-slate-200/60 dark:divide-slate-700/60"
                        >
                            <tr
                                v-for="(row, index) in playerStats[side]"
                                :key="`${side}-${index}-${row.name}`"
                                class="text-slate-700 dark:text-slate-200"
                            >
                                <td class="py-2 pr-2 font-bold">
                                    <div class="flex items-center gap-1.5">
                                        <span
                                            class="truncate text-slate-950 dark:text-white"
                                        >
                                            {{ row.name }}
                                        </span>
                                        <span
                                            v-if="row.jersey_number !== null"
                                            class="shrink-0 font-display text-xs font-extrabold text-slate-500 tabular-nums dark:text-slate-400"
                                        >
                                            #{{ row.jersey_number }}
                                        </span>
                                    </div>
                                </td>
                                <td
                                    class="px-2 py-2 text-center font-semibold tabular-nums"
                                >
                                    {{ row.goals }}
                                </td>
                                <td
                                    class="px-2 py-2 text-center font-semibold tabular-nums"
                                >
                                    {{ row.assists }}
                                </td>
                                <td
                                    class="py-2 pl-2 text-right font-display text-sm font-extrabold text-slate-950 tabular-nums dark:text-white"
                                >
                                    {{ row.points }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</template>
