<script setup lang="ts">
import { useConfirm } from '@/composables/useConfirm';
import { AlertTriangle, X } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted } from 'vue';

const { activeConfirm, accept, cancel } = useConfirm();

function onKeydown(event: KeyboardEvent) {
    if (!activeConfirm.value) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        cancel();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="activeConfirm"
            class="fixed inset-0 z-[80] flex items-end justify-center bg-slate-950/75 p-4 backdrop-blur-xs sm:items-center"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="'confirm-dialog-title'"
            @click.self="cancel"
        >
            <div
                class="animate-toast-in surface-card w-full max-w-md rounded-3xl p-5 sm:p-6"
            >
                <div class="flex items-start gap-3.5">
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-2xl"
                        :class="
                            activeConfirm.variant === 'danger'
                                ? 'bg-red-100 text-red-600 dark:bg-red-950/70 dark:text-red-400'
                                : 'bg-[var(--team-primary-soft)] text-[var(--team-primary)]'
                        "
                    >
                        <AlertTriangle class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2
                            id="confirm-dialog-title"
                            class="text-lg font-extrabold text-slate-950 dark:text-white"
                        >
                            {{ activeConfirm.title }}
                        </h2>
                        <p
                            class="mt-1.5 text-sm leading-relaxed text-slate-600 dark:text-slate-300"
                        >
                            {{ activeConfirm.message }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="-mt-1 -mr-1 rounded-xl p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                        aria-label="Fechar"
                        @click="cancel"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-2.5 sm:flex-row">
                    <button
                        type="button"
                        class="min-h-11 flex-1 rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                        data-test="confirm-cancel"
                        @click="cancel"
                    >
                        {{ activeConfirm.cancelLabel }}
                    </button>
                    <button
                        type="button"
                        class="min-h-11 flex-1 rounded-xl px-4 text-sm font-bold transition-all active:scale-[0.98]"
                        :class="
                            activeConfirm.variant === 'danger'
                                ? 'bg-red-600 text-white hover:bg-red-700'
                                : 'bg-[var(--team-primary)] text-[var(--team-primary-text)] hover:brightness-95'
                        "
                        data-test="confirm-accept"
                        @click="accept"
                    >
                        {{ activeConfirm.confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
