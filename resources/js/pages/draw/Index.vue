<script setup lang="ts">
import PlayerPhoto from '@/components/hockey/PlayerPhoto.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowRight, GripVertical, Shuffle } from 'lucide-vue-next';
import draggable from 'vuedraggable';
import { computed, onMounted, ref } from 'vue';
import { load, reconcile, save as saveDraw } from '@/lib/drawStorage';
import type { DrawResult, DrawTeam, Player } from '@/types/hockey';

const props = defineProps<{
    players: Player[];
    mode: 'players' | 'teams';
    size: number;
    error?: string;
}>();
const page = usePage();
const accountId = page.props.team.id;
const teams = ref<DrawTeam[]>([]);
const reserves = ref<Player[]>([]);
const mode = ref<'players' | 'teams'>(props.mode);
const size = ref(props.size);
const loading = ref(false);
const error = ref(props.error || '');
const reconciliationNotice = ref('');
const options = computed(() =>
    mode.value === 'players' ? [2, 3, 4, 5, 6, 7, 8] : [2, 3, 4, 5, 6, 7, 8],
);
const total = (players: Player[]) =>
    players.reduce((sum, player) => sum + Number(player.level), 0);
const average = (players: Player[]) =>
    players.length
        ? (total(players) / players.length).toLocaleString('pt-BR', {
              maximumFractionDigits: 2,
          })
        : '0';
function persistDraw() {
    saveDraw(localStorage, accountId, {
        teams: teams.value,
        reserves: reserves.value,
        mode: mode.value,
        size: size.value,
    });
}
function reconciliationMessage(removedCount: number, addedCount: number) {
    const messages: string[] = [];
    if (removedCount)
        messages.push(
            `${removedCount} jogador${removedCount === 1 ? '' : 'es'} ${
                removedCount === 1 ? 'saiu' : 'saíram'
            } da lista de presentes e ${removedCount === 1 ? 'foi' : 'foram'} removido${
                removedCount === 1 ? '' : 's'
            }.`,
        );
    if (addedCount)
        messages.push(
            `${addedCount} novo${addedCount === 1 ? '' : 's'} jogador${
                addedCount === 1 ? '' : 'es'
            } ${addedCount === 1 ? 'foi colocado' : 'foram colocados'} nos reservas.`,
        );
    return messages.join(' ');
}
onMounted(() => {
    const saved = load(localStorage, accountId);
    if (!saved) return;

    const reconciled = reconcile(saved, props.players);
    teams.value = reconciled.draw.teams;
    reserves.value = reconciled.draw.reserves;
    mode.value = reconciled.draw.mode;
    size.value = reconciled.draw.size;
    reconciliationNotice.value = reconciliationMessage(
        reconciled.removedCount,
        reconciled.addedCount,
    );
    persistDraw();
});
async function draw() {
    loading.value = true;
    error.value = '';
    try {
        const csrf =
            document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.content ?? '';
        const response = await fetch('/sorteio', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
            },
            body: JSON.stringify({ mode: mode.value, size: size.value }),
        });
        const data = await response.json();
        if (!response.ok) {
            error.value =
                data.errors?.size?.[0] ||
                data.message ||
                'Não foi possível sortear.';
            return;
        }
        const result = data as DrawResult;
        teams.value = result.teams;
        reserves.value = result.reserves;
        persistDraw();
        sessionStorage.removeItem('hockey-match');
    } finally {
        loading.value = false;
    }
}
function totals(team: DrawTeam) {
    team.total = total(team.players);
    team.average = Number(average(team.players).replace(',', '.'));
}
function update() {
    teams.value.forEach(totals);
    persistDraw();
}
function goToMatch() {
    persistDraw();
    sessionStorage.setItem(
        'hockey-teams',
        JSON.stringify({ teams: teams.value, reserves: reserves.value }),
    );
    router.visit('/partida');
}
</script>

<template>
    <Head title="Sorteio das equipes" />
    <div class="mb-5">
        <p
            class="text-xs font-bold tracking-widest text-slate-500 uppercase dark:text-slate-400"
        >
            Presença confirmada
        </p>
        <h1 class="mt-1 text-2xl font-black">Sorteio equilibrado</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ players.length }} jogadores presentes. Arraste para ajustar as
            equipes.
        </p>
    </div>
    <section
        class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
    >
        <div
            class="grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-1 dark:bg-slate-800"
        >
            <button
                class="min-h-11 rounded-lg text-sm font-bold"
                :class="
                    mode === 'players'
                        ? 'bg-white shadow-sm dark:bg-slate-900'
                        : 'text-slate-500 dark:text-slate-400'
                "
                :aria-pressed="mode === 'players'"
                @click="mode = 'players'"
            >
                Jogadores por equipe</button
            ><button
                class="min-h-11 rounded-lg text-sm font-bold"
                :class="
                    mode === 'teams'
                        ? 'bg-white shadow-sm dark:bg-slate-900'
                        : 'text-slate-500 dark:text-slate-400'
                "
                :aria-pressed="mode === 'teams'"
                @click="mode = 'teams'"
            >
                Número de equipes
            </button>
        </div>
        <div class="mt-4 flex flex-wrap items-end gap-3">
            <label class="flex-1 text-sm font-bold"
                >{{
                    mode === 'players'
                        ? 'Jogadores em cada equipe'
                        : 'Quantidade de equipes'
                }}<select
                    v-model.number="size"
                    class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 dark:border-slate-600 dark:bg-slate-900"
                >
                    <option
                        v-for="option in options"
                        :key="option"
                        :value="option"
                    >
                        {{ option }}
                    </option>
                </select></label
            ><button
                class="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-xl bg-[var(--team-primary)] font-bold text-[var(--team-primary-text)] disabled:opacity-50"
                :disabled="loading"
                data-test="sortear"
                @click="draw"
            >
                <Shuffle class="size-5" />{{
                    loading
                        ? 'Sorteando…'
                        : teams.length
                          ? 'Sortear novamente'
                          : 'Sortear equipes'
                }}
            </button>
        </div>
        <p
            v-if="error"
            class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-700"
            role="alert"
        >
            {{ error }}
        </p>
        <p
            v-if="reconciliationNotice"
            class="mt-3 rounded-lg bg-blue-50 p-3 text-sm text-blue-800 dark:bg-blue-950/40 dark:text-blue-200"
        >
            {{ reconciliationNotice }}
        </p>
        <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
            O sorteio é aleatório e prioriza somas de estrelas próximas. No modo
            por quantidade de equipas, todos entram e cada equipe precisa ter
            pelo menos 2 jogadores. No modo jogadores por equipe, as sobras
            ficam nos reservas. Arraste para ajustar.
        </p>
    </section>
    <div v-if="teams.length" class="mt-5 grid gap-4 lg:grid-cols-2">
        <section
            v-for="(team, index) in teams"
            :key="team.id"
            class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
        >
            <div class="h-1.5" :style="{ background: team.color }" />
            <div class="flex flex-wrap items-center justify-between gap-2 p-4">
                <input
                    v-model="team.name"
                    maxlength="50"
                    class="min-w-0 flex-1 bg-transparent text-lg font-black"
                    :aria-label="`Nome da equipe ${index + 1}`"
                    @input="persistDraw"
                    @change="persistDraw"
                />
                <div
                    class="text-right text-xs font-bold text-slate-500 dark:text-slate-400"
                >
                    <span class="text-base text-slate-900 dark:text-slate-100"
                        >{{
                            total(team.players).toLocaleString('pt-BR')
                        }}
                        ★</span
                    >
                    <p>Média {{ average(team.players) }}</p>
                </div>
            </div>
            <draggable
                v-model="team.players"
                :group="{ name: 'jogadores', pull: true, put: true }"
                item-key="id"
                class="min-h-28 space-y-2 px-3 pb-3"
                :delay="100"
                :delay-on-touch-only="true"
                :touch-start-threshold="4"
                :animation="150"
                ghost-class="opacity-30"
                @change="update"
                ><template #item="{ element: player }"
                    ><div
                        :data-player-id="player.id"
                        class="flex min-h-14 cursor-grab touch-pan-y items-center gap-2 rounded-xl border border-slate-200 p-2 active:cursor-grabbing dark:border-slate-700"
                    >
                        <GripVertical
                            class="size-5 shrink-0 text-slate-300"
                        /><PlayerPhoto :player="player" size="size-10" /><span
                            class="min-w-0 flex-1 truncate text-sm font-bold"
                            >{{ player.name }}</span
                        ><strong class="shrink-0 text-lg font-black"
                            >#{{ player.jersey_number }}</strong
                        >
                    </div></template
                ></draggable
            >
        </section>
        <section
            class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 dark:border-slate-600 dark:bg-slate-800/50"
            data-test="reservas"
        >
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-black">Reservas</h2>
                <span class="text-xs text-slate-500 dark:text-slate-400"
                    >{{ reserves.length }} jogadores</span
                >
            </div>
            <draggable
                v-model="reserves"
                group="jogadores"
                item-key="id"
                class="mt-3 min-h-20 space-y-2"
                :delay="100"
                :delay-on-touch-only="true"
                :animation="150"
                @change="update"
                ><template #item="{ element: player }"
                    ><div
                        :data-player-id="player.id"
                        class="flex min-h-14 cursor-grab touch-pan-y items-center gap-2 rounded-xl border border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-900"
                    >
                        <GripVertical
                            class="size-5 text-slate-300"
                        /><PlayerPhoto :player="player" size="size-10" /><span
                            class="min-w-0 flex-1 truncate text-sm font-bold"
                            >{{ player.name }}</span
                        ><strong class="shrink-0 text-lg font-black"
                            >#{{ player.jersey_number }}</strong
                        >
                    </div></template
                ></draggable
            >
        </section>
    </div>
    <div
        v-if="teams.length >= 2"
        class="sticky bottom-20 z-10 mt-5 rounded-2xl bg-slate-950 p-3 shadow-xl md:bottom-4"
    >
        <button
            class="flex min-h-14 w-full items-center justify-center gap-2 rounded-xl bg-[var(--team-primary)] text-lg font-black text-[var(--team-primary-text)]"
            data-test="iniciar-partida"
            @click="goToMatch"
        >
            Preparar partida<ArrowRight />
        </button>
    </div>
    <div
        v-else-if="!players.length"
        class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-center"
    >
        <p class="font-bold text-amber-900">Nenhum jogador presente</p>
        <Link
            href="/jogadores"
            class="mt-2 inline-flex items-center gap-2 text-sm font-bold text-amber-900 underline"
            >Marcar presença<ArrowRight class="size-4"
        /></Link>
    </div>
    <div
        v-else
        class="mt-5 rounded-2xl border border-dashed border-slate-300 bg-white p-5 text-center text-sm text-slate-600 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-300"
    >
        Pressione “Sortear equipes” para montar as equipes.
    </div>
</template>
