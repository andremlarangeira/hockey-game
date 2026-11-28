<script setup lang="ts">
import MatchPlayerStatsCard from '@/components/hockey/MatchPlayerStatsCard.vue';
import MatchTimelineCard from '@/components/hockey/MatchTimelineCard.vue';
import ScoreShareButton from '@/components/hockey/ScoreShareButton.vue';
import { useConfirm } from '@/composables/useConfirm';
import { useToast } from '@/composables/useToast';
import { readableText } from '@/lib/balancedDraw';
import { formatClock } from '@/lib/matchClock';
import type {
    MatchGoalProp,
    MatchPlayerStatsProp,
    MatchPublicLinkProp,
    SavedMatchProp,
} from '@/types/hockey';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Calendar,
    Check,
    Copy,
    ExternalLink,
    Globe2,
    Link2,
    RefreshCw,
    Trash2,
} from 'lucide-vue-next';

const props = defineProps<{
    match: SavedMatchProp;
    goals: MatchGoalProp[];
    player_stats: MatchPlayerStatsProp;
    public_link: MatchPublicLinkProp;
}>();

const { confirm } = useConfirm();
const toast = useToast();

async function destroyMatch() {
    const accepted = await confirm({
        title: 'Excluir partida salva?',
        message:
            'Os gols e assistências desta partida também serão removidos das estatísticas do elenco.',
        confirmLabel: 'Excluir partida',
        variant: 'danger',
    });
    if (!accepted) return;

    router.delete(`/partidas/${props.match.id}`);
}

function activatePublicLink() {
    router.post(
        `/partidas/${props.match.id}/link-publico`,
        {},
        {
            preserveScroll: true,
        },
    );
}

async function regeneratePublicLink() {
    const accepted = await confirm({
        title: 'Gerar um novo link público?',
        message:
            'O endereço atual vai parar de funcionar imediatamente para quem já o salvou.',
        confirmLabel: 'Gerar novo link',
        variant: 'primary',
    });
    if (!accepted) {
        return;
    }

    router.post(
        `/partidas/${props.match.id}/link-publico/regenerar`,
        {},
        { preserveScroll: true },
    );
}

async function disablePublicLink() {
    const accepted = await confirm({
        title: 'Desativar link público?',
        message:
            'Quem tiver o endereço não poderá mais visualizar a partida até você reativar.',
        confirmLabel: 'Desativar link',
        variant: 'danger',
    });
    if (!accepted) {
        return;
    }

    router.delete(`/partidas/${props.match.id}/link-publico`, {
        preserveScroll: true,
    });
}

async function copyPublicLink() {
    const url = props.public_link.url;
    if (!url) return;

    try {
        await navigator.clipboard.writeText(url);
        toast.success('Link público copiado!');
        return;
    } catch {
        const input = document.createElement('textarea');
        input.value = url;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        input.remove();
        toast.success('Link público copiado!');
    }
}
</script>

<template>
    <Head :title="`${match.roster.home.name} × ${match.roster.away.name}`" />

    <div class="mb-3 flex items-center justify-between gap-2">
        <Link
            href="/partidas"
            class="inline-flex min-h-10 items-center gap-1.5 rounded-xl px-2 text-sm font-bold text-slate-600 transition-colors hover:bg-white hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
        >
            <ArrowLeft class="size-4" />
            Histórico
        </Link>
        <button
            type="button"
            class="inline-flex min-h-9 items-center gap-1.5 rounded-xl border border-red-200 bg-white px-3 text-xs font-bold text-red-600 transition-colors hover:bg-red-50 dark:border-red-900/60 dark:bg-slate-900 dark:text-red-400 dark:hover:bg-red-950/50"
            @click="destroyMatch"
        >
            <Trash2 class="size-3.5" />
            Excluir partida
        </button>
    </div>

    <section
        class="relative overflow-hidden rounded-3xl border border-white/10 bg-slate-950 p-6 text-center text-white shadow-2xl"
    >
        <div
            class="pointer-events-none absolute inset-y-0 left-0 w-1/3 opacity-25"
            :style="{
                background: `radial-gradient(circle at 0% 50%, ${match.roster.home.color}, transparent 75%)`,
            }"
        />
        <div
            class="pointer-events-none absolute inset-y-0 right-0 w-1/3 opacity-25"
            :style="{
                background: `radial-gradient(circle at 100% 50%, ${match.roster.away.color}, transparent 75%)`,
            }"
        />

        <div class="relative">
            <div class="flex flex-wrap items-center justify-center gap-2">
                <span
                    v-if="match.finished_at"
                    class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-slate-300"
                >
                    <Calendar class="size-3.5" />
                    {{ match.finished_at }}
                </span>
                <span
                    class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-bold text-emerald-300"
                >
                    <Check class="size-3.5" />
                    Partida salva
                </span>
            </div>

            <div
                class="mt-6 grid grid-cols-[1fr_auto_1fr] items-center gap-3 sm:gap-6"
            >
                <div class="min-w-0">
                    <span
                        class="mx-auto mb-2 block h-1.5 w-14 rounded-full"
                        :style="{ background: match.roster.home.color }"
                    />
                    <h1 class="truncate text-base font-extrabold sm:text-xl">
                        {{ match.roster.home.name }}
                    </h1>
                </div>

                <p
                    class="font-display text-6xl leading-none font-extrabold tabular-nums sm:text-7xl"
                >
                    {{ match.home_score }}
                    <span class="mx-1 text-slate-500">×</span>
                    {{ match.away_score }}
                </p>

                <div class="min-w-0">
                    <span
                        class="mx-auto mb-2 block h-1.5 w-14 rounded-full"
                        :style="{ background: match.roster.away.color }"
                    />
                    <h1 class="truncate text-base font-extrabold sm:text-xl">
                        {{ match.roster.away.name }}
                    </h1>
                </div>
            </div>
        </div>
    </section>

    <div class="mt-3 flex justify-center">
        <ScoreShareButton :match="match" :goals="goals" />
    </div>

    <section
        class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
        aria-labelledby="link-publico-heading"
        data-test="card-link-publico"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex items-center gap-2">
                    <Globe2 class="size-4 text-[var(--team-primary)]" />
                    <h2
                        id="link-publico-heading"
                        class="text-sm font-black text-slate-950 dark:text-white"
                    >
                        Link público
                    </h2>
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Compartilhe o placar, os gols e as estatísticas desta
                    partida sem dar acesso à gestão.
                </p>
            </div>

            <button
                v-if="!public_link.enabled"
                type="button"
                class="inline-flex min-h-10 items-center gap-1.5 rounded-xl bg-[var(--team-primary)] px-3.5 py-2 text-xs font-bold text-white hover:brightness-95"
                @click="activatePublicLink"
            >
                <Link2 class="size-4" />
                Ativar link público
            </button>
        </div>

        <div
            v-if="public_link.enabled && public_link.url"
            class="mt-4 flex flex-col gap-2 sm:flex-row"
        >
            <input
                :value="public_link.url"
                readonly
                aria-label="URL do link público"
                class="min-h-10 min-w-0 flex-1 rounded-xl border border-slate-300 bg-slate-50 px-3 text-xs text-slate-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200"
            />
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    @click="copyPublicLink"
                >
                    <Copy class="size-3.5" /> Copiar
                </button>
                <a
                    :href="public_link.url"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-slate-200 px-3 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    <ExternalLink class="size-3.5" /> Abrir
                </a>
                <button
                    type="button"
                    class="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-amber-300 px-3 text-xs font-bold text-amber-800 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-950/30"
                    @click="regeneratePublicLink"
                >
                    <RefreshCw class="size-3.5" /> Gerar novo link
                </button>
                <button
                    type="button"
                    class="min-h-10 rounded-xl border border-red-200 px-3 text-xs font-bold text-red-700 hover:bg-red-50 dark:border-red-900 dark:text-red-300 dark:hover:bg-red-950/30"
                    @click="disablePublicLink"
                >
                    Desativar
                </button>
            </div>
        </div>
    </section>

    <MatchPlayerStatsCard
        :roster="match.roster"
        :player-stats="player_stats"
    />

    <section class="surface-card mt-4 rounded-2xl p-4 sm:p-5">
        <div class="flex items-center justify-between">
            <h2 class="text-base font-extrabold text-slate-950 dark:text-white">
                Gols e assistências
            </h2>
            <span
                class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300"
            >
                {{ goals.length }}
            </span>
        </div>

        <p
            v-if="!goals.length"
            class="mt-4 text-sm text-slate-500 dark:text-slate-400"
        >
            Nenhum gol marcado.
        </p>

        <div class="mt-3 space-y-2.5">
            <div
                v-for="goal in goals"
                :key="goal.id"
                class="flex items-center gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"
            >
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl font-display text-base font-extrabold shadow-xs"
                    :style="{
                        background: match.roster[goal.team].color,
                        color: readableText(match.roster[goal.team].color),
                    }"
                >
                    {{ goal.period }}º
                </span>
                <div class="min-w-0 flex-1">
                    <p
                        class="truncate font-extrabold text-slate-950 dark:text-white"
                    >
                        {{ goal.scorer_name }}
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{
                            goal.assist_name
                                ? `Assistência: ${goal.assist_name}`
                                : 'Sem assistência'
                        }}
                    </p>
                </div>
                <div class="shrink-0 text-right">
                    <span
                        class="block font-display text-lg leading-none font-extrabold text-slate-800 tabular-nums dark:text-slate-100"
                    >
                        {{ formatClock(goal.remaining_seconds) }}
                    </span>
                    <span
                        v-if="goal.recorded_at"
                        class="mt-0.5 block text-[11px] text-slate-400 tabular-nums"
                    >
                        {{ goal.recorded_at }}
                    </span>
                </div>
            </div>
        </div>
    </section>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <MatchTimelineCard :timeline="match.timeline" />

        <section class="surface-card rounded-2xl p-4 sm:p-5">
            <h2 class="text-base font-extrabold text-slate-950 dark:text-white">
                Elencos salvos
            </h2>
            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                <div
                    v-for="side in ['home', 'away'] as const"
                    :key="side"
                    class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/50"
                >
                    <div class="flex items-center gap-2">
                        <span
                            class="size-3 shrink-0 rounded-full"
                            :style="{ background: match.roster[side].color }"
                        />
                        <h3
                            class="truncate text-sm font-extrabold text-slate-950 dark:text-white"
                        >
                            {{ match.roster[side].name }}
                        </h3>
                    </div>
                    <ul class="mt-2.5 space-y-1.5">
                        <li
                            v-for="(player, index) in match.roster[side].players"
                            :key="`${side}-${index}-${player.name}`"
                            class="flex items-center justify-between text-xs font-medium text-slate-700 dark:text-slate-300"
                        >
                            <span class="truncate">{{ player.name }}</span>
                            <strong
                                v-if="player.jersey_number !== null"
                                class="ml-2 shrink-0 font-display text-base font-extrabold text-slate-900 tabular-nums dark:text-white"
                            >
                                #{{ player.jersey_number }}
                            </strong>
                        </li>
                    </ul>
                </div>
            </div>
        </section>
    </div>
</template>
