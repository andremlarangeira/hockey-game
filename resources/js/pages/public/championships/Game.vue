<script setup lang="ts">
import ChampionshipTeamBadge from '@/components/championships/ChampionshipTeamBadge.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { Head, Link, usePoll } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CircleDot,
    Clock3,
    Goal,
    Timer,
    Trophy,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type {
    PublicChampionshipEventProp,
    PublicChampionshipGamePageProps,
} from '@/types/championship';

defineOptions({ layout: PublicLayout });

const props = defineProps<PublicChampionshipGamePageProps>();
const updatedAt = ref(new Date());
const goalEvents = computed(() =>
    props.events.filter((event) => event.type === 'goal'),
);
const lastEvent = computed(() => props.events.at(-1) ?? null);
const gameIsLive = computed(() => props.game.status === 'live');
const poll = usePoll(
    5000,
    {
        only: ['game', 'events'],
        onSuccess: () => {
            updatedAt.value = new Date();
        },
    },
    { autoStart: false, background: 'pause' },
);

watch(
    () => props.poll_interval_ms,
    (interval) =>
        interval !== null && gameIsLive.value ? poll.start() : poll.stop(),
    { immediate: true },
);
watch(gameIsLive, (live) => {
    if (!live) poll.stop();
});
onBeforeUnmount(poll.stop);

const championshipUrl = computed(() => {
    if (typeof window === 'undefined') return '#';
    return window.location.pathname.replace(/\/jogos\/\d+$/, '');
});

function formatElapsed(seconds: number): string {
    const minutes = Math.floor(seconds / 60);
    return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
}
function eventPeriod(event: PublicChampionshipEventProp): string {
    if (event.is_overtime) return 'Prorrogação';
    return event.period ? `${event.period}º tempo` : 'Partida';
}
function eventDescription(event: PublicChampionshipEventProp): string {
    const assists = [
        event.first_assist_player_name,
        event.second_assist_player_name,
    ].filter(Boolean);
    return assists.length
        ? `Assistências: ${assists.join(' e ')}`
        : 'Sem assistência registrada';
}
</script>

<template>
    <Head :title="`${championship.name} · Jogo`" />
    <Link
        :href="championshipUrl"
        class="inline-flex min-h-10 items-center gap-1.5 text-sm font-bold text-slate-600 hover:text-slate-950 dark:text-slate-300 dark:hover:text-white"
        ><ArrowLeft class="size-4" /> Voltar ao campeonato</Link
    >

    <section
        class="mt-3 overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3 text-xs font-bold dark:border-slate-700"
        >
            <span class="text-slate-500 dark:text-slate-400"
                >{{ game.phase_label
                }}<template v-if="game.group_code">
                    · Grupo {{ game.group_code }}</template
                ></span
            ><span
                v-if="gameIsLive"
                class="inline-flex items-center gap-1 rounded-full bg-red-600 px-2.5 py-1 text-white"
                ><CircleDot class="size-3 animate-pulse" /> AO VIVO</span
            ><span
                v-else
                class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                >{{
                    game.status === 'finished' ? 'Encerrado' : 'Agendado'
                }}</span
            >
        </div>
        <div
            class="grid grid-cols-[1fr_auto_1fr] items-center gap-3 p-4 sm:p-7"
        >
            <div class="flex min-w-0 flex-col items-center gap-2 text-center">
                <ChampionshipTeamBadge
                    v-if="game.home_team"
                    :name="game.home_team.name"
                    :short-name="game.home_team.short_name"
                    :logo-url="game.home_team.logo_url"
                    :primary-color="game.home_team.primary_color"
                    :secondary-color="game.home_team.secondary_color"
                    size="lg"
                /><span class="line-clamp-2 text-sm font-black sm:text-base">{{
                    game.home_team?.name ?? game.home_source ?? 'A definir'
                }}</span>
            </div>
            <div class="text-center">
                <div class="text-3xl font-black tabular-nums sm:text-5xl">
                    {{
                        game.is_bye
                            ? 'BYE'
                            : `${game.home_regular_score} × ${game.away_regular_score}`
                    }}
                </div>
                <p
                    v-if="game.went_to_overtime"
                    class="mt-1 text-xs font-bold text-amber-700 dark:text-amber-400"
                >
                    Prorrogação
                </p>
                <p
                    v-if="game.went_to_shootout"
                    class="mt-1 text-xs font-bold text-amber-700 dark:text-amber-400"
                >
                    Pênaltis {{ game.home_shootout_score }}–{{
                        game.away_shootout_score
                    }}
                </p>
            </div>
            <div class="flex min-w-0 flex-col items-center gap-2 text-center">
                <ChampionshipTeamBadge
                    v-if="game.away_team"
                    :name="game.away_team.name"
                    :short-name="game.away_team.short_name"
                    :logo-url="game.away_team.logo_url"
                    :primary-color="game.away_team.primary_color"
                    :secondary-color="game.away_team.secondary_color"
                    size="lg"
                /><span class="line-clamp-2 text-sm font-black sm:text-base">{{
                    game.away_team?.name ?? game.away_source ?? 'A definir'
                }}</span>
            </div>
        </div>
        <div
            class="border-t border-slate-200 px-4 py-3 text-center text-xs font-medium text-slate-500 dark:border-slate-700 dark:text-slate-400"
        >
            <span v-if="lastEvent"
                >Último evento: {{ eventPeriod(lastEvent) }} ·
                {{ formatElapsed(lastEvent.elapsed_seconds) }}</span
            ><span v-else-if="game.scheduled_at"
                >Jogo agendado para
                {{ new Date(game.scheduled_at).toLocaleString('pt-BR') }}</span
            ><span v-else>Horário a definir</span>
        </div>
    </section>

    <section
        class="mt-5 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
    >
        <h1 class="flex items-center gap-2 text-lg font-black">
            <Goal class="size-5 text-emerald-600" /> Linha do tempo de gols
        </h1>
        <p
            v-if="!goalEvents.length"
            class="mt-4 rounded-xl bg-slate-100 p-4 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-400"
        >
            Ainda não há gols registrados nesta partida.
        </p>
        <ol v-else class="mt-4 space-y-3">
            <li
                v-for="event in goalEvents"
                :key="event.id"
                class="flex gap-3 border-l-2 border-emerald-500 pl-4"
            >
                <span
                    class="min-w-12 text-sm font-black text-emerald-700 dark:text-emerald-400"
                    >{{ formatElapsed(event.elapsed_seconds) }}</span
                >
                <div>
                    <p class="font-bold">
                        Gol de
                        {{
                            event.primary_player_name ?? 'Jogador não informado'
                        }}
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ eventPeriod(event) }} · {{ eventDescription(event) }}
                    </p>
                </div>
            </li>
        </ol>
    </section>

    <footer
        class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-slate-200 px-4 py-3 text-xs text-slate-600 dark:bg-slate-900 dark:text-slate-400"
    >
        <span class="inline-flex items-center gap-1"
            ><Clock3 class="size-3.5" /> Atualizado às
            {{ updatedAt.toLocaleTimeString('pt-BR') }}</span
        ><span class="inline-flex items-center gap-1"
            ><Timer class="size-3.5" /> O cronômetro oficial fica no dispositivo
            da mesa.</span
        ><span class="inline-flex items-center gap-1"
            ><Trophy class="size-3.5" /> {{ championship.name }}</span
        >
    </footer>
</template>
