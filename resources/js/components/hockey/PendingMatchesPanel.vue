<script setup lang="ts">
import { useConfirm } from '@/composables/useConfirm';
import { useToast } from '@/composables/useToast';
import { isPermanentFailure, type QueuedMatch } from '@/lib/matchQueue';
import {
    closePendingMatchesPanel,
    discardQueuedMatch,
    offlineStatus,
    retryMatchNow,
    syncNow,
} from '@/lib/offlineSync';
import {
    AlertTriangle,
    Calendar,
    Clock3,
    Download,
    RefreshCw,
    Trash2,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted } from 'vue';

const props = withDefaults(
    defineProps<{
        asModal?: boolean;
    }>(),
    {
        asModal: true,
    },
);

const { confirm } = useConfirm();
const toast = useToast();

interface ParsedQueuedMatch {
    raw: QueuedMatch;
    clientUuid: string;
    homeName: string;
    awayName: string;
    homeColor: string;
    awayColor: string;
    homeScore: number;
    awayScore: number;
    formattedDate: string;
    formattedLastAttempt: string | null;
    status: 'sending' | 'failed' | 'pending';
    statusLabel: 'Enviando' | 'Falhou' | 'Pendente';
    attempts: number;
    httpStatus: number | null;
    error: string | null;
}

function formatLocalDateTime(iso: unknown): string {
    if (typeof iso !== 'string' || !iso.trim()) return 'Data indisponível';
    const parsed = new Date(iso);
    if (Number.isNaN(parsed.getTime())) return iso;
    return parsed.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const items = computed<ParsedQueuedMatch[]>(() => {
    const sendingId = offlineStatus.value.sendingMatchId;
    return offlineStatus.value.queue.map((item) => {
        const payload = (item.payload ?? {}) as {
            home_score?: unknown;
            away_score?: unknown;
            started_at?: unknown;
            finished_at?: unknown;
            roster?: {
                home?: { name?: unknown; color?: unknown };
                away?: { name?: unknown; color?: unknown };
            };
        };
        const homeName =
            typeof payload.roster?.home?.name === 'string' &&
            payload.roster.home.name.trim() !== ''
                ? payload.roster.home.name
                : 'Equipe Casa';
        const awayName =
            typeof payload.roster?.away?.name === 'string' &&
            payload.roster.away.name.trim() !== ''
                ? payload.roster.away.name
                : 'Equipe Visitante';
        const homeColor =
            typeof payload.roster?.home?.color === 'string' &&
            /^#[0-9A-Fa-f]{6}$/.test(payload.roster.home.color)
                ? payload.roster.home.color
                : '#166534';
        const awayColor =
            typeof payload.roster?.away?.color === 'string' &&
            /^#[0-9A-Fa-f]{6}$/.test(payload.roster.away.color)
                ? payload.roster.away.color
                : '#2563eb';
        const homeScore =
            typeof payload.home_score === 'number'
                ? payload.home_score
                : Number(payload.home_score ?? 0) || 0;
        const awayScore =
            typeof payload.away_score === 'number'
                ? payload.away_score
                : Number(payload.away_score ?? 0) || 0;

        const isSending = sendingId === item.client_uuid;
        const failed = !isSending && isPermanentFailure(item);
        const status: 'sending' | 'failed' | 'pending' = isSending
            ? 'sending'
            : failed
              ? 'failed'
              : 'pending';
        const statusLabel =
            status === 'sending'
                ? 'Enviando'
                : status === 'failed'
                  ? 'Falhou'
                  : 'Pendente';

        return {
            raw: item,
            clientUuid: item.client_uuid,
            homeName,
            awayName,
            homeColor,
            awayColor,
            homeScore,
            awayScore,
            formattedDate: formatLocalDateTime(
                payload.finished_at ?? payload.started_at ?? item.queued_at,
            ),
            formattedLastAttempt: item.last_attempt_at
                ? formatLocalDateTime(item.last_attempt_at)
                : null,
            status,
            statusLabel,
            attempts:
                typeof item.attempts === 'number' && item.attempts >= 0
                    ? item.attempts
                    : 0,
            httpStatus:
                typeof item.http_status === 'number' && item.http_status > 0
                    ? item.http_status
                    : null,
            error:
                typeof item.error === 'string' && item.error.trim() !== ''
                    ? item.error.trim()
                    : null,
        };
    });
});

function downloadJsonFile(filename: string, data: unknown): void {
    const json = JSON.stringify(data, null, 2);
    const blob = new Blob([json], { type: 'application/json;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 500);
}

function exportItemJson(item: ParsedQueuedMatch): void {
    const shortId = item.clientUuid.slice(0, 8);
    downloadJsonFile(`partida-pendente-${shortId}.json`, {
        client_uuid: item.raw.client_uuid,
        queued_at: item.raw.queued_at,
        status: item.statusLabel,
        attempts: item.attempts,
        http_status: item.httpStatus,
        error: item.error,
        last_attempt_at: item.raw.last_attempt_at ?? null,
        ...item.raw.payload,
    });
    toast.success('JSON da partida exportado.');
}

function exportAllJson(): void {
    if (!items.value.length) return;
    const exported = items.value.map((item) => ({
        client_uuid: item.raw.client_uuid,
        queued_at: item.raw.queued_at,
        status: item.statusLabel,
        attempts: item.attempts,
        http_status: item.httpStatus,
        error: item.error,
        last_attempt_at: item.raw.last_attempt_at ?? null,
        payload: item.raw.payload,
    }));
    const stamp = new Date().toISOString().slice(0, 10);
    downloadJsonFile(`partidas-pendentes-${stamp}.json`, exported);
    toast.success('JSON de todas as partidas pendentes exportado.');
}

async function handleRetry(item: ParsedQueuedMatch): Promise<void> {
    if (offlineStatus.value.offline) {
        await retryMatchNow(item.clientUuid);
        toast.info(
            'Partida marcada como pendente. Será enviada assim que houver conexão.',
        );
        return;
    }
    const uploaded = await retryMatchNow(item.clientUuid);
    if (uploaded.some((u) => u.client_uuid === item.clientUuid)) {
        toast.success('Partida enviada e salva com sucesso!');
    }
}

async function handleDiscard(item: ParsedQueuedMatch): Promise<void> {
    const accepted = await confirm({
        title: 'Descartar partida pendente?',
        message: `Esta ação é irreversível. A partida ${item.homeName} ${item.homeScore} × ${item.awayScore} ${item.awayName} será removida definitivamente da fila local deste aparelho e não poderá ser recuperada.`,
        confirmLabel: 'Descartar definitivamente',
        cancelLabel: 'Manter na fila',
        variant: 'danger',
    });
    if (!accepted) return;

    discardQueuedMatch(item.clientUuid);
    toast.info('Partida descartada da fila local.');
}

function onKeydown(event: KeyboardEvent): void {
    if (!props.asModal || !offlineStatus.value.panelOpen) return;
    if (event.key === 'Escape') {
        closePendingMatchesPanel();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport :to="asModal ? 'body' : undefined" :disabled="!asModal">
        <div
            v-if="!asModal || offlineStatus.panelOpen"
            :class="
                asModal
                    ? 'fixed inset-0 z-[60] flex items-end justify-center bg-slate-950/75 p-0 backdrop-blur-xs sm:items-center sm:p-4'
                    : ''
            "
            :role="asModal ? 'dialog' : undefined"
            :aria-modal="asModal ? 'true' : undefined"
            aria-labelledby="pending-matches-title"
            data-test="painel-partidas-pendentes"
            @click.self="asModal ? closePendingMatchesPanel() : undefined"
        >
            <div
                :class="
                    asModal
                        ? 'animate-toast-in surface-card flex max-h-[90svh] w-full max-w-2xl flex-col overflow-hidden rounded-t-3xl sm:rounded-3xl'
                        : 'space-y-4'
                "
            >
                <div
                    :class="
                        asModal
                            ? 'flex flex-wrap items-start justify-between gap-3 border-b border-slate-200/80 p-4 sm:p-6 dark:border-slate-800'
                            : 'mb-4 flex flex-wrap items-end justify-between gap-3'
                    "
                >
                    <div>
                        <p
                            class="text-[11px] font-extrabold tracking-widest text-[var(--team-primary)] uppercase dark:text-emerald-400"
                        >
                            Fila local do aparelho
                        </p>
                        <h2
                            id="pending-matches-title"
                            class="mt-0.5 text-xl font-extrabold tracking-tight text-slate-950 sm:text-2xl dark:text-white"
                        >
                            Partidas pendentes ({{ items.length }})
                        </h2>
                        <p
                            class="mt-1 text-xs text-slate-500 dark:text-slate-400"
                        >
                            Guardadas neste navegador para envio ao servidor.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-if="items.length"
                            type="button"
                            class="inline-flex min-h-10 items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                            data-test="exportar-todas-json"
                            @click="exportAllJson"
                        >
                            <Download class="size-3.5" />
                            Exportar todas
                        </button>
                        <button
                            v-if="
                                items.length &&
                                !offlineStatus.offline &&
                                !offlineStatus.relogin &&
                                offlineStatus.retryableMatches > 0
                            "
                            type="button"
                            class="inline-flex min-h-10 items-center gap-1.5 rounded-xl bg-[var(--team-primary)] px-3.5 text-xs font-bold text-[var(--team-primary-text)] shadow-xs transition-all hover:brightness-110 disabled:opacity-50"
                            :disabled="offlineStatus.syncing"
                            @click="syncNow()"
                        >
                            <RefreshCw
                                class="size-3.5"
                                :class="
                                    offlineStatus.syncing ? 'animate-spin' : ''
                                "
                            />
                            Enviar pendentes
                        </button>
                        <button
                            v-if="asModal"
                            type="button"
                            class="flex size-10 items-center justify-center rounded-xl text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                            aria-label="Fechar painel de partidas pendentes"
                            data-test="fechar-partidas-pendentes"
                            @click="closePendingMatchesPanel"
                        >
                            <X class="size-5" />
                        </button>
                    </div>
                </div>

                <div
                    :class="
                        asModal
                            ? 'flex-1 space-y-3 overflow-y-auto p-4 sm:p-6'
                            : 'space-y-3'
                    "
                >
                    <div
                        v-if="!items.length"
                        class="rounded-2xl border border-dashed border-slate-300 p-8 text-center dark:border-slate-700"
                    >
                        <Clock3
                            class="mx-auto size-10 text-slate-300 dark:text-slate-600"
                        />
                        <p
                            class="mt-3 text-base font-extrabold text-slate-900 dark:text-white"
                        >
                            Nenhuma partida pendente na fila
                        </p>
                        <p
                            class="mt-1 text-xs text-slate-500 dark:text-slate-400"
                        >
                            Todas as partidas deste aparelho já foram
                            sincronizadas ou descartadas.
                        </p>
                    </div>

                    <article
                        v-for="item in items"
                        :key="item.clientUuid"
                        class="rounded-2xl border border-slate-200/90 bg-white p-4 shadow-2xs dark:border-slate-800 dark:bg-slate-900/90"
                        :data-queued-uuid="item.clientUuid"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <span
                                class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400"
                            >
                                <Calendar class="size-3.5" />
                                {{ item.formattedDate }}
                            </span>

                            <div class="flex flex-wrap items-center gap-1.5">
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[11px] font-extrabold"
                                    :class="
                                        item.status === 'sending'
                                            ? 'bg-sky-100 text-sky-800 dark:bg-sky-950/70 dark:text-sky-300'
                                            : item.status === 'failed'
                                              ? 'bg-red-100 text-red-700 dark:bg-red-950/70 dark:text-red-300'
                                              : 'bg-amber-100 text-amber-800 dark:bg-amber-950/70 dark:text-amber-300'
                                    "
                                    data-test="status-partida-fila"
                                >
                                    <RefreshCw
                                        v-if="item.status === 'sending'"
                                        class="size-3 animate-spin"
                                    />
                                    <AlertTriangle
                                        v-else-if="item.status === 'failed'"
                                        class="size-3"
                                    />
                                    <Clock3 v-else class="size-3" />
                                    {{ item.statusLabel }}
                                    <template v-if="item.httpStatus">
                                        · HTTP {{ item.httpStatus }}
                                    </template>
                                </span>

                                <span
                                    class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300"
                                    data-test="tentativas-partida-fila"
                                >
                                    {{ item.attempts }}
                                    {{
                                        item.attempts === 1
                                            ? 'tentativa'
                                            : 'tentativas'
                                    }}
                                </span>
                            </div>
                        </div>

                        <div
                            class="mt-3 flex items-center justify-between gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60"
                        >
                            <div class="min-w-0 flex-1 space-y-1.5">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="size-2.5 shrink-0 rounded-full"
                                        :style="{ background: item.homeColor }"
                                    />
                                    <span
                                        class="truncate text-sm font-extrabold text-slate-950 dark:text-white"
                                    >
                                        {{ item.homeName }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span
                                        class="size-2.5 shrink-0 rounded-full"
                                        :style="{ background: item.awayColor }"
                                    />
                                    <span
                                        class="truncate text-sm font-extrabold text-slate-950 dark:text-white"
                                    >
                                        {{ item.awayName }}
                                    </span>
                                </div>
                            </div>

                            <div
                                class="text-right font-display text-2xl leading-tight font-extrabold text-slate-950 tabular-nums dark:text-white"
                            >
                                <p>{{ item.homeScore }}</p>
                                <p>{{ item.awayScore }}</p>
                            </div>
                        </div>

                        <div
                            v-if="item.error"
                            class="mt-3 rounded-xl border px-3 py-2.5 text-xs font-medium"
                            :class="
                                item.status === 'failed'
                                    ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/60 dark:bg-red-950/50 dark:text-red-200'
                                    : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200'
                            "
                            data-test="erro-partida-fila"
                        >
                            <p class="font-bold">{{ item.error }}</p>
                            <p
                                v-if="item.formattedLastAttempt"
                                class="mt-0.5 text-[11px] opacity-80"
                            >
                                Última tentativa:
                                {{ item.formattedLastAttempt }}
                            </p>
                        </div>

                        <div
                            class="mt-3 flex flex-wrap items-center gap-2 pt-1"
                        >
                            <button
                                type="button"
                                class="inline-flex min-h-10 flex-1 items-center justify-center gap-1.5 rounded-xl bg-[var(--team-primary)] px-3 text-xs font-bold text-[var(--team-primary-text)] shadow-2xs transition-all hover:brightness-110 active:scale-[0.98] disabled:opacity-50 sm:flex-none"
                                :disabled="item.status === 'sending'"
                                data-test="tentar-de-novo-partida"
                                @click="handleRetry(item)"
                            >
                                <RefreshCw
                                    class="size-3.5"
                                    :class="
                                        item.status === 'sending'
                                            ? 'animate-spin'
                                            : ''
                                    "
                                />
                                Tentar de novo
                            </button>

                            <button
                                type="button"
                                class="inline-flex min-h-10 flex-1 items-center justify-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 text-xs font-bold text-slate-700 transition-colors hover:bg-slate-50 active:scale-[0.98] sm:flex-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                                data-test="exportar-json-partida"
                                @click="exportItemJson(item)"
                            >
                                <Download class="size-3.5" />
                                Exportar JSON
                            </button>

                            <button
                                type="button"
                                class="inline-flex min-h-10 flex-1 items-center justify-center gap-1.5 rounded-xl border border-red-200 bg-white px-3 text-xs font-bold text-red-600 transition-colors hover:bg-red-50 active:scale-[0.98] disabled:opacity-50 sm:ml-auto sm:flex-none dark:border-red-900/60 dark:bg-slate-900 dark:text-red-400 dark:hover:bg-red-950/40"
                                :disabled="item.status === 'sending'"
                                data-test="descartar-partida"
                                @click="handleDiscard(item)"
                            >
                                <Trash2 class="size-3.5" />
                                Descartar
                            </button>
                        </div>
                    </article>
                </div>
            </div>
        </div>
    </Teleport>
</template>
