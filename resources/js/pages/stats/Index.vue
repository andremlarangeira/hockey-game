<script setup lang="ts">
import PlayerPhoto from '@/components/hockey/PlayerPhoto.vue';
import { Head } from '@inertiajs/vue3';
import type { Player } from '@/types/hockey';
defineProps<{
    players: (Player & {
        goals: number;
        assists: number;
        points: number;
        games: number;
    })[];
}>();
</script>
<template>
    <Head title="Estatísticas dos jogadores" />
    <div>
        <p
            class="text-xs font-bold tracking-widest text-slate-500 uppercase dark:text-slate-400"
        >
            Desempenho no time
        </p>
        <h1 class="mt-1 text-2xl font-black">Pontuação dos jogadores</h1>
    </div>
    <section
        class="mt-5 overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
    >
        <table class="w-full text-left text-sm">
            <thead
                class="bg-slate-50 text-xs tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400"
            >
                <tr>
                    <th class="px-2.5 py-2.5 sm:p-4">Jogador</th>
                    <th
                        class="w-10 px-1.5 py-2.5 text-center sm:w-20 sm:p-4"
                        title="Jogos"
                    >
                        <abbr title="Jogos" class="no-underline">J</abbr>
                    </th>
                    <th
                        class="w-10 px-1.5 py-2.5 text-center sm:w-20 sm:p-4"
                        title="Gols"
                    >
                        <abbr title="Gols" class="no-underline">G</abbr>
                    </th>
                    <th
                        class="w-10 px-1.5 py-2.5 text-center sm:w-20 sm:p-4"
                        title="Assistências"
                    >
                        <abbr title="Assistências" class="no-underline">A</abbr>
                    </th>
                    <th
                        class="w-11 bg-amber-50/70 px-1.5 py-2.5 text-center font-black text-amber-950 sm:w-24 sm:p-4 dark:bg-amber-950/30 dark:text-amber-300"
                        title="Pontos"
                    >
                        <abbr title="Pontos" class="no-underline">Pts</abbr>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(player, index) in players"
                    :key="player.id"
                    :data-player-id="player.id"
                    class="border-t border-slate-100 dark:border-slate-800"
                >
                    <td class="max-w-0 px-2.5 py-2 sm:p-3">
                        <div class="flex min-w-0 items-center gap-2 sm:gap-3">
                            <span
                                class="w-4 shrink-0 text-center text-xs font-bold text-slate-400 tabular-nums dark:text-slate-500"
                                >{{ index + 1 }}</span
                            >
                            <PlayerPhoto
                                :player="player"
                                size="size-8 text-xs sm:size-10 sm:text-sm"
                            />
                            <span
                                class="line-clamp-2 min-w-0 flex-1 leading-snug font-bold break-words"
                                :title="player.name"
                                >{{ player.name }}</span
                            >
                        </div>
                    </td>
                    <td
                        class="px-1.5 py-2 text-center text-base font-black tabular-nums sm:p-3 sm:text-xl"
                    >
                        {{ player.games }}
                    </td>
                    <td
                        class="px-1.5 py-2 text-center text-base font-black tabular-nums sm:p-3 sm:text-xl"
                    >
                        {{ player.goals }}
                    </td>
                    <td
                        class="px-1.5 py-2 text-center text-base font-black tabular-nums sm:p-3 sm:text-xl"
                    >
                        {{ player.assists }}
                    </td>
                    <td
                        class="bg-amber-50/70 px-1.5 py-2 text-center text-base font-black text-amber-950 tabular-nums sm:p-3 sm:text-xl dark:bg-amber-950/30 dark:text-amber-300"
                    >
                        {{ player.points }}
                    </td>
                </tr>
            </tbody>
        </table>
        <p
            v-if="players.length"
            class="border-t border-slate-100 px-3 py-2 text-center text-xs text-slate-500 sm:px-4 sm:text-left dark:border-slate-800 dark:text-slate-400"
        >
            J = jogos · G = gols · A = assistências · Pts = pontos
        </p>
        <p
            v-if="!players.length"
            class="p-8 text-center text-sm text-slate-500 dark:text-slate-400"
        >
            Cadastre jogadores para acompanhar as estatísticas.
        </p>
    </section>
</template>
