<script setup lang="ts">
import PlayerPhoto from '@/components/hockey/PlayerPhoto.vue';
import { useToast } from '@/composables/useToast';
import {
    applyTeamColorsToTeams,
    balancedDraw,
    readableText,
} from '@/lib/balancedDraw';
import { load, reconcile, save as saveDraw } from '@/lib/drawStorage';
import { loadPlayers } from '@/lib/offlineStorage';
import type { DrawResult, DrawTeam, Player } from '@/types/hockey';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    ArrowRight,
    GripVertical,
    Pencil,
    Scale,
    Shuffle,
    Users,
} from 'lucide-vue-next';
import { computed, defineAsyncComponent, onMounted, ref, watch } from 'vue';

const draggable = defineAsyncComponent(() => import('vuedraggable'));

const props = defineProps<{
    players: Player[];
    mode: 'players' | 'teams';
    size: number;
    error?: string;
}>();

const page = usePage();
const toast = useToast();
const accountId = page.props.team.id;
const players = ref<Player[]>(
    loadPlayers(localStorage, accountId, props.players).filter(
        (player) => player.present,
    ),
);
const teams = ref<DrawTeam[]>([]);
const reserves = ref<Player[]>([]);
const mode = ref<'players' | 'teams'>(props.mode);
const size = ref(props.size);
const loading = ref(false);
const error = ref(props.error || '');
const reconciliationNotice = ref('');
const movingPlayerId = ref<number | null>(null);

const options = computed(() => [2, 3, 4, 5, 6, 7, 8]);

const total = (list: Player[]) =>
    list.reduce((sum, player) => sum + Number(player.level), 0);

const average = (list: Player[]) =>
    list.length
        ? (total(list) / list.length).toLocaleString('pt-BR', {
              maximumFractionDigits: 2,
          })
        : '0';

const maxTeamTotal = computed(() => {
    if (!teams.value.length) return 1;
    return Math.max(1, ...teams.value.map((team) => total(team.players)));
});

const balanceMetrics = computed(() => {
    const active = teams.value.filter((team) => team.players.length > 0);
    if (active.length < 2) return null;
    const totalsList = active.map((team) => total(team.players));
    const spread = Math.max(...totalsList) - Math.min(...totalsList);
    const label =
        spread <= 0.5
            ? 'Equilíbrio excelente'
            : spread <= 1.5
              ? 'Bom equilíbrio'
              : 'Diferença moderada';
    const tone =
        spread <= 0.5
            ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/50 dark:text-emerald-200'
            : spread <= 1.5
              ? 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800/60 dark:bg-sky-950/50 dark:text-sky-200'
              : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800/60 dark:bg-amber-950/50 dark:text-amber-200';
    return {
        spread: spread.toLocaleString('pt-BR', { maximumFractionDigits: 1 }),
        label,
        tone,
    };
});

function formatLevel(level: number | string): string {
    return Number(level).toLocaleString('pt-BR', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    });
}

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

    players.value = loadPlayers(localStorage, accountId, props.players).filter(
        (player) => player.present,
    );
    const reconciled = reconcile(
        saved,
        players.value,
        page.props.team?.primary_color,
        page.props.team?.secondary_color,
    );
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

watch(
    () => [page.props.team?.primary_color, page.props.team?.secondary_color],
    ([primaryColor, secondaryColor]) => {
        if (!teams.value.length) return;
        teams.value = applyTeamColorsToTeams(
            teams.value,
            primaryColor,
            secondaryColor,
        );
        persistDraw();
    },
);

async function draw() {
    loading.value = true;
    error.value = '';
    movingPlayerId.value = null;
    try {
        if (!navigator.onLine) {
            const result = balancedDraw(
                players.value,
                mode.value,
                size.value,
                undefined,
                page.props.team?.primary_color,
                page.props.team?.secondary_color,
            );
            teams.value = result.teams;
            reserves.value = result.reserves;
            persistDraw();
            sessionStorage.removeItem('hockey-match');
            toast.success('Equipes sorteadas!');
            return;
        }
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
        toast.success('Equipes equilibradas geradas!');
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

function movePlayer(
    player: Player,
    fromTeamId: string | 'reserves',
    targetId: string | 'reserves',
) {
    if (fromTeamId === targetId) {
        movingPlayerId.value = null;
        return;
    }
    if (fromTeamId === 'reserves') {
        reserves.value = reserves.value.filter((p) => p.id !== player.id);
    } else {
        const source = teams.value.find((t) => t.id === fromTeamId);
        if (source) {
            source.players = source.players.filter((p) => p.id !== player.id);
        }
    }
    if (targetId === 'reserves') {
        reserves.value.push(player);
    } else {
        const destination = teams.value.find((t) => t.id === targetId);
        if (destination) {
            destination.players.push(player);
        }
    }
    movingPlayerId.value = null;
    update();
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

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
            <p
                class="text-[11px] font-extrabold tracking-widest text-[var(--team-primary)] uppercase dark:text-emerald-400"
            >
                Presença confirmada
            </p>
            <h1
                class="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-950 sm:text-3xl dark:text-white"
            >
                Sorteio equilibrado
            </h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                <strong class="font-bold text-slate-800 dark:text-slate-200">{{
                    players.length
                }}</strong>
                jogadores presentes. Arraste ou toque para ajustar as equipes.
            </p>
        </div>

        <div
            v-if="balanceMetrics"
            class="inline-flex items-center gap-2 rounded-2xl border px-3.5 py-2 text-xs font-extrabold"
            :class="balanceMetrics.tone"
        >
            <Scale class="size-4 shrink-0" />
            <span>{{ balanceMetrics.label }}</span>
            <span class="opacity-75">· Δ {{ balanceMetrics.spread }} ★</span>
        </div>
    </div>

    <section class="surface-card rounded-2xl p-4 sm:p-5">
        <div
            class="grid grid-cols-2 gap-1.5 rounded-xl bg-slate-100 p-1 dark:bg-slate-800"
        >
            <button
                type="button"
                class="min-h-11 rounded-lg text-sm font-bold transition-all"
                :class="
                    mode === 'players'
                        ? 'bg-white text-slate-950 shadow-xs dark:bg-slate-900 dark:text-white'
                        : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
                "
                :aria-pressed="mode === 'players'"
                @click="mode = 'players'"
            >
                Jogadores por equipe
            </button>
            <button
                type="button"
                class="min-h-11 rounded-lg text-sm font-bold transition-all"
                :class="
                    mode === 'teams'
                        ? 'bg-white text-slate-950 shadow-xs dark:bg-slate-900 dark:text-white'
                        : 'text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-slate-200'
                "
                :aria-pressed="mode === 'teams'"
                @click="mode = 'teams'"
            >
                Número de equipes
            </button>
        </div>

        <div class="mt-4 flex flex-wrap items-end gap-3">
            <label
                class="min-w-44 flex-1 text-sm font-bold text-slate-800 dark:text-slate-200"
            >
                {{
                    mode === 'players'
                        ? 'Jogadores em cada equipe'
                        : 'Quantidade de equipes'
                }}
                <select
                    v-model.number="size"
                    class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-semibold text-slate-950 focus:border-[var(--team-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                >
                    <option
                        v-for="option in options"
                        :key="option"
                        :value="option"
                    >
                        {{ option }}
                    </option>
                </select>
            </label>
            <button
                type="button"
                class="flex min-h-12 min-w-44 flex-1 items-center justify-center gap-2 rounded-xl bg-[var(--team-primary)] px-4 font-bold text-[var(--team-primary-text)] shadow-sm transition-all hover:brightness-110 active:scale-[0.98] disabled:opacity-50"
                :disabled="loading"
                data-test="sortear"
                @click="draw"
            >
                <Shuffle class="size-5" :class="{ 'animate-spin': loading }" />
                {{
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
            class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-700 dark:border-red-800/60 dark:bg-red-950/50 dark:text-red-200"
            role="alert"
        >
            {{ error }}
        </p>
        <p
            v-if="reconciliationNotice"
            class="mt-3 rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm font-medium text-sky-900 dark:border-sky-800/60 dark:bg-sky-950/40 dark:text-sky-200"
        >
            {{ reconciliationNotice }}
        </p>
        <p
            class="mt-3 text-xs leading-relaxed text-slate-500 dark:text-slate-400"
        >
            O sorteio é aleatório e prioriza somas de estrelas próximas. No modo
            por quantidade de equipes, todos entram com pelo menos 2 jogadores
            por equipe. No modo jogadores por equipe, as sobras ficam nos
            reservas.
        </p>
    </section>

    <div v-if="teams.length" class="mt-5 grid gap-4 lg:grid-cols-2">
        <section
            v-for="(team, index) in teams"
            :key="team.id"
            class="surface-card overflow-hidden rounded-2xl"
        >
            <div class="h-2" :style="{ background: team.color }" />
            <div class="p-4 pb-3">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex min-w-0 flex-1 items-center gap-2">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-xl text-xs font-black shadow-xs"
                            :style="{
                                background: team.color,
                                color: readableText(team.color),
                            }"
                        >
                            {{ index + 1 }}
                        </span>
                        <div class="relative min-w-0 flex-1">
                            <input
                                v-model="team.name"
                                maxlength="50"
                                class="w-full rounded-lg bg-transparent py-1 pr-6 text-lg font-extrabold tracking-tight text-slate-950 transition-colors hover:bg-slate-100/70 focus:bg-slate-100 focus:px-2 focus:outline-none dark:text-white dark:hover:bg-slate-800/70 dark:focus:bg-slate-800"
                                :aria-label="`Nome da equipe ${index + 1}`"
                                @input="persistDraw"
                                @change="persistDraw"
                            />
                            <Pencil
                                class="pointer-events-none absolute top-1/2 right-1.5 size-3.5 -translate-y-1/2 text-slate-400"
                            />
                        </div>
                    </div>
                    <div class="shrink-0 text-right">
                        <span
                            class="font-display text-xl leading-none font-extrabold text-slate-950 tabular-nums dark:text-white"
                        >
                            {{ total(team.players).toLocaleString('pt-BR') }} ★
                        </span>
                        <p
                            class="mt-0.5 text-[11px] font-bold text-slate-500 dark:text-slate-400"
                        >
                            {{ team.players.length }} jog. · Média
                            {{ average(team.players) }}
                        </p>
                    </div>
                </div>

                <div
                    class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800"
                >
                    <div
                        class="h-full rounded-full transition-all duration-300"
                        :style="{
                            width: `${Math.round((total(team.players) / maxTeamTotal) * 100)}%`,
                            background: team.color,
                        }"
                    />
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
            >
                <template #item="{ element: player }">
                    <div
                        :data-player-id="player.id"
                        class="rounded-xl border border-slate-200/80 bg-slate-50/70 p-2 transition-colors hover:border-slate-300 dark:border-slate-700/80 dark:bg-slate-800/50 dark:hover:border-slate-600"
                    >
                        <div
                            class="flex min-h-11 cursor-grab touch-pan-y items-center gap-2 active:cursor-grabbing"
                        >
                            <GripVertical
                                class="size-4.5 shrink-0 text-slate-300 dark:text-slate-600"
                            />
                            <PlayerPhoto :player="player" size="size-10" />
                            <div class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-bold text-slate-900 dark:text-slate-100"
                                >
                                    {{ player.name }}
                                </span>
                                <span
                                    class="text-[11px] font-bold text-amber-700 tabular-nums dark:text-amber-300"
                                >
                                    {{ formatLevel(player.level) }} ★
                                </span>
                            </div>
                            <strong
                                class="shrink-0 font-display text-2xl leading-none font-extrabold text-slate-800 tabular-nums dark:text-slate-100"
                            >
                                #{{ player.jersey_number }}
                            </strong>
                            <button
                                type="button"
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-white hover:text-slate-800 dark:hover:bg-slate-700 dark:hover:text-white"
                                :aria-label="`Mover ${player.name}`"
                                :title="`Mover ${player.name}`"
                                @click.stop="
                                    movingPlayerId =
                                        movingPlayerId === player.id
                                            ? null
                                            : player.id
                                "
                            >
                                <ArrowLeftRight class="size-3.5" />
                            </button>
                        </div>

                        <div
                            v-if="movingPlayerId === player.id"
                            class="mt-2 flex flex-wrap gap-1.5 border-t border-slate-200/80 pt-2 dark:border-slate-700"
                        >
                            <span
                                class="self-center text-[11px] font-bold text-slate-500 dark:text-slate-400"
                                >Mover para:</span
                            >
                            <button
                                v-for="dest in teams.filter(
                                    (candidate) => candidate.id !== team.id,
                                )"
                                :key="dest.id"
                                type="button"
                                class="rounded-lg px-2.5 py-1 text-xs font-bold transition-transform active:scale-95"
                                :style="{
                                    background: dest.color,
                                    color: readableText(dest.color),
                                }"
                                @click="movePlayer(player, team.id, dest.id)"
                            >
                                {{ dest.name }}
                            </button>
                            <button
                                type="button"
                                class="rounded-lg bg-slate-200 px-2.5 py-1 text-xs font-bold text-slate-800 dark:bg-slate-700 dark:text-slate-100"
                                @click="movePlayer(player, team.id, 'reserves')"
                            >
                                Reservas
                            </button>
                        </div>
                    </div>
                </template>
            </draggable>
        </section>

        <section
            class="rounded-2xl border-2 border-dashed border-slate-300/80 bg-white/60 p-4 dark:border-slate-700 dark:bg-slate-900/40"
            data-test="reservas"
        >
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <Users class="size-4.5 text-slate-400" />
                    <h2
                        class="text-base font-extrabold text-slate-900 dark:text-white"
                    >
                        Reservas
                    </h2>
                </div>
                <span
                    class="rounded-full bg-slate-200/80 px-2.5 py-0.5 text-xs font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                >
                    {{ reserves.length }} jogadores
                </span>
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
            >
                <template #item="{ element: player }">
                    <div
                        :data-player-id="player.id"
                        class="rounded-xl border border-slate-200 bg-white p-2 shadow-2xs dark:border-slate-700 dark:bg-slate-900"
                    >
                        <div
                            class="flex min-h-11 cursor-grab touch-pan-y items-center gap-2 active:cursor-grabbing"
                        >
                            <GripVertical
                                class="size-4.5 text-slate-300 dark:text-slate-600"
                            />
                            <PlayerPhoto :player="player" size="size-10" />
                            <div class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-bold text-slate-900 dark:text-slate-100"
                                >
                                    {{ player.name }}
                                </span>
                                <span
                                    class="text-[11px] font-bold text-amber-700 tabular-nums dark:text-amber-300"
                                >
                                    {{ formatLevel(player.level) }} ★
                                </span>
                            </div>
                            <strong
                                class="shrink-0 font-display text-2xl leading-none font-extrabold text-slate-800 tabular-nums dark:text-slate-100"
                            >
                                #{{ player.jersey_number }}
                            </strong>
                            <button
                                type="button"
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-white"
                                :aria-label="`Mover ${player.name}`"
                                @click.stop="
                                    movingPlayerId =
                                        movingPlayerId === player.id
                                            ? null
                                            : player.id
                                "
                            >
                                <ArrowLeftRight class="size-3.5" />
                            </button>
                        </div>

                        <div
                            v-if="movingPlayerId === player.id"
                            class="mt-2 flex flex-wrap gap-1.5 border-t border-slate-200/80 pt-2 dark:border-slate-700"
                        >
                            <span
                                class="self-center text-[11px] font-bold text-slate-500 dark:text-slate-400"
                                >Colocar em:</span
                            >
                            <button
                                v-for="dest in teams"
                                :key="dest.id"
                                type="button"
                                class="rounded-lg px-2.5 py-1 text-xs font-bold transition-transform active:scale-95"
                                :style="{
                                    background: dest.color,
                                    color: readableText(dest.color),
                                }"
                                @click="movePlayer(player, 'reserves', dest.id)"
                            >
                                {{ dest.name }}
                            </button>
                        </div>
                    </div>
                </template>
            </draggable>
        </section>
    </div>

    <div
        v-if="teams.length >= 2"
        class="sticky bottom-20 z-10 mt-5 rounded-2xl border border-white/10 bg-slate-950/95 p-3 shadow-2xl backdrop-blur-md md:bottom-4"
    >
        <button
            type="button"
            class="flex min-h-14 w-full items-center justify-center gap-2 rounded-xl bg-[var(--team-primary)] text-lg font-extrabold text-[var(--team-primary-text)] shadow-md transition-all hover:brightness-110 active:scale-[0.99]"
            data-test="iniciar-partida"
            @click="goToMatch"
        >
            Preparar partida
            <ArrowRight class="size-5" />
        </button>
    </div>

    <div
        v-else-if="!players.length"
        class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-6 text-center dark:border-amber-800/60 dark:bg-amber-950/40"
    >
        <p class="font-extrabold text-amber-900 dark:text-amber-100">
            Nenhum jogador presente
        </p>
        <p class="mt-1 text-xs text-amber-800/80 dark:text-amber-200/80">
            Marque quem chegou na quadra antes de gerar as equipes.
        </p>
        <Link
            href="/jogadores"
            class="mt-3 inline-flex items-center gap-2 rounded-xl bg-amber-900 px-4 py-2.5 text-sm font-bold text-white transition-colors hover:bg-amber-800 dark:bg-amber-500 dark:text-slate-950"
        >
            Marcar presença
            <ArrowRight class="size-4" />
        </Link>
    </div>

    <div
        v-else
        class="surface-card mt-5 rounded-2xl border-dashed p-8 text-center text-sm font-medium text-slate-500 dark:text-slate-400"
    >
        Pressione “Sortear equipes” para montar as equipes equilibradas.
    </div>
</template>
