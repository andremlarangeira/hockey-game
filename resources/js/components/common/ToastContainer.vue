<script setup lang="ts">
import { useToast } from '@/composables/useToast';
import { AlertCircle, CheckCircle2, Info, X } from 'lucide-vue-next';

const { toasts, dismiss } = useToast();
</script>

<template>
    <Teleport to="body">
        <div
            v-if="toasts.length"
            class="pointer-events-none fixed right-4 bottom-24 left-4 z-[70] mx-auto flex max-w-md flex-col gap-2 sm:bottom-6 sm:left-auto sm:mx-0 sm:w-96"
            aria-live="polite"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                role="status"
                class="animate-toast-in pointer-events-auto flex items-start gap-2.5 rounded-2xl border px-4 py-3 text-sm font-semibold shadow-xl backdrop-blur-md"
                :class="{
                    'border-emerald-200/80 bg-white/95 text-slate-900 dark:border-emerald-800/70 dark:bg-slate-900/95 dark:text-slate-100':
                        toast.variant === 'success',
                    'border-red-200/80 bg-red-50/95 text-red-900 dark:border-red-800/70 dark:bg-red-950/95 dark:text-red-100':
                        toast.variant === 'error',
                    'border-sky-200/80 bg-white/95 text-slate-900 dark:border-sky-800/70 dark:bg-slate-900/95 dark:text-slate-100':
                        toast.variant === 'info',
                }"
            >
                <CheckCircle2
                    v-if="toast.variant === 'success'"
                    class="mt-0.5 size-4.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                />
                <AlertCircle
                    v-else-if="toast.variant === 'error'"
                    class="mt-0.5 size-4.5 shrink-0 text-red-600 dark:text-red-400"
                />
                <Info
                    v-else
                    class="mt-0.5 size-4.5 shrink-0 text-sky-600 dark:text-sky-400"
                />
                <span class="min-w-0 flex-1 leading-snug">{{
                    toast.message
                }}</span>
                <button
                    type="button"
                    class="-mr-1 rounded-lg p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                    aria-label="Fechar aviso"
                    @click="dismiss(toast.id)"
                >
                    <X class="size-4" />
                </button>
            </div>
        </div>
    </Teleport>
</template>
