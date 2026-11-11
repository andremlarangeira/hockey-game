<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Clock, FileText, History, User } from 'lucide-vue-next';
import type {
    ChampionshipAuditLogProp,
    ChampionshipProp,
} from '@/types/championship';

defineProps<{
    championship: ChampionshipProp;
    auditLogs: ChampionshipAuditLogProp[];
}>();

const actionLabels: Record<string, string> = {
    groups_drawn: 'Sorteio de grupos realizado',
    groups_redrawn: 'Sorteio de grupos refeito',
    groups_reset: 'Sorteio de grupos reiniciado',
    tie_break_resolved: 'Desempate manual registrado',
    bracket_generated: 'Chave do mata-mata gerada',
    bracket_reset: 'Chave do mata-mata reiniciada',
    game_finished: 'Partida encerrada',
    game_reopened: 'Partida reaberta para retificação',
    event_removed: 'Evento removido da súmula',
};

const actionClasses: Record<string, string> = {
    groups_drawn: 'bg-blue-100 text-blue-800',
    groups_redrawn: 'bg-amber-100 text-amber-800',
    groups_reset: 'bg-amber-100 text-amber-800',
    tie_break_resolved: 'bg-purple-100 text-purple-800',
    bracket_generated: 'bg-indigo-100 text-indigo-800',
    bracket_reset: 'bg-amber-100 text-amber-800',
    game_finished: 'bg-emerald-100 text-emerald-800',
    game_reopened: 'bg-red-100 text-red-800',
    event_removed: 'bg-rose-100 text-rose-800',
};

function formatDateTime(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}
</script>

<template>
    <div class="space-y-4">
        <div
            class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
        >
            <h2 class="text-lg font-black">Histórico e auditoria</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">
                Registro imutável de sorteios, desempates manuais, geração de
                chaves, encerramentos, reaberturas e retificações de súmula.
            </p>
        </div>

        <div v-if="auditLogs.length" class="space-y-3">
            <article
                v-for="log in auditLogs"
                :key="log.id"
                class="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"
                :data-audit-id="log.id"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span
                            class="rounded-full px-2.5 py-0.5 text-xs font-bold"
                            :class="
                                actionClasses[log.action] ??
                                'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-100'
                            "
                        >
                            {{ actionLabels[log.action] ?? log.action }}
                        </span>
                        <span
                            class="font-mono text-[11px] font-semibold text-slate-400"
                        >
                            ({{ log.action }})
                        </span>
                    </div>

                    <div
                        class="flex flex-wrap items-center gap-3 text-xs text-slate-500 dark:text-slate-400"
                    >
                        <span class="inline-flex items-center gap-1">
                            <User class="size-3.5" />
                            {{ log.actor_name ?? 'Organizador' }}
                        </span>
                        <span class="inline-flex items-center gap-1">
                            <Clock class="size-3.5" />
                            {{ formatDateTime(log.recorded_at) }}
                        </span>
                    </div>
                </div>

                <div
                    v-if="log.reason"
                    class="mt-3 flex items-start gap-2 rounded-xl bg-slate-50 p-3 text-xs text-slate-700 dark:bg-slate-800/50 dark:text-slate-200"
                >
                    <FileText class="mt-0.5 size-4 shrink-0 text-slate-400" />
                    <div>
                        <strong class="text-slate-900 dark:text-slate-100"
                            >Motivo:</strong
                        >
                        {{ log.reason }}
                    </div>
                </div>

                <div
                    v-if="
                        log.championship_game_id ||
                        log.before_snapshot ||
                        log.after_snapshot
                    "
                    class="mt-3 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500 dark:text-slate-400"
                >
                    <Link
                        v-if="log.championship_game_id"
                        :href="`/campeonatos/${championship.id}/jogos/${log.championship_game_id}`"
                        class="font-bold text-slate-700 underline hover:text-slate-900 dark:text-slate-200 dark:hover:text-white"
                    >
                        Ver partida #{{ log.championship_game_id }}
                    </Link>

                    <details
                        v-if="log.before_snapshot || log.after_snapshot"
                        class="w-full"
                    >
                        <summary
                            class="cursor-pointer text-[11px] font-bold text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white"
                        >
                            Detalhes técnicos do registro (snapshot)
                        </summary>
                        <div
                            class="mt-2 grid gap-2 rounded-xl bg-slate-950 p-3 font-mono text-[11px] text-slate-200 sm:grid-cols-2"
                        >
                            <div v-if="log.before_snapshot">
                                <p class="mb-1 font-bold text-amber-400">
                                    Antes:
                                </p>
                                <pre
                                    class="overflow-x-auto whitespace-pre-wrap"
                                    >{{
                                        JSON.stringify(
                                            log.before_snapshot,
                                            null,
                                            2,
                                        )
                                    }}</pre>
                            </div>
                            <div v-if="log.after_snapshot">
                                <p class="mb-1 font-bold text-emerald-400">
                                    Depois:
                                </p>
                                <pre
                                    class="overflow-x-auto whitespace-pre-wrap"
                                    >{{
                                        JSON.stringify(
                                            log.after_snapshot,
                                            null,
                                            2,
                                        )
                                    }}</pre>
                            </div>
                        </div>
                    </details>
                </div>
            </article>
        </div>

        <div
            v-else
            class="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-600 dark:bg-slate-900"
        >
            <History class="mx-auto size-10 text-slate-400" />
            <h3 class="mt-4 text-lg font-bold">
                Nenhum evento de auditoria registrado
            </h3>
            <p
                class="mx-auto mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400"
            >
                Sorteios de grupos, resoluções manuais de empate, geração de
                chaves e reaberturas de jogos serão listados aqui.
            </p>
        </div>
    </div>
</template>
