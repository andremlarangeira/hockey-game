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
        <h1 class="mt-1 text-2xl font-black">Artilharia e assistências</h1>
    </div>
    <section
        class="mt-5 overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
    >
        <table class="w-full min-w-[600px] text-left text-sm">
            <thead
                class="bg-slate-50 text-xs tracking-wider text-slate-500 uppercase dark:bg-slate-800/50 dark:text-slate-400"
            >
                <tr>
                    <th class="p-4">Jogador</th>
                    <th class="p-4 text-center">Jogos</th>
                    <th class="p-4 text-center">Gols</th>
                    <th class="p-4 text-center">Assistências</th>
                    <th class="p-4 text-center">Pontos</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="player in players"
                    :key="player.id"
                    :data-player-id="player.id"
                    class="border-t border-slate-100 dark:border-slate-800"
                >
                    <td class="p-3">
                        <div class="flex items-center gap-3">
                            <PlayerPhoto :player="player" size="size-10" /><span
                                class="font-bold"
                                >{{ player.name }}</span
                            >
                        </div>
                    </td>
                    <td class="p-3 text-center text-xl font-black">
                        {{ player.games }}
                    </td>
                    <td class="p-3 text-center text-xl font-black">
                        {{ player.goals }}
                    </td>
                    <td class="p-3 text-center text-xl font-black">
                        {{ player.assists }}
                    </td>
                    <td class="p-3 text-center text-xl font-black">
                        {{ player.points }}
                    </td>
                </tr>
            </tbody>
        </table>
        <p
            v-if="!players.length"
            class="p-8 text-center text-sm text-slate-500 dark:text-slate-400"
        >
            Cadastre jogadores para acompanhar as estatísticas.
        </p>
    </section>
</template>
