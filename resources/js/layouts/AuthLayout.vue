<script setup lang="ts">
import { Trophy, Timer, Users } from 'lucide-vue-next';

defineProps<{ title?: string; description?: string }>();
</script>

<template>
    <div
        class="arena-hero relative flex min-h-svh flex-col justify-center overflow-hidden p-5 text-slate-950 sm:p-8"
    >
        <div
            class="pointer-events-none absolute inset-0 opacity-[0.07]"
            style="
                background-image:
                    linear-gradient(to right, #ffffff 1px, transparent 1px),
                    linear-gradient(to bottom, #ffffff 1px, transparent 1px);
                background-size: 40px 40px;
            "
        />

        <div class="relative mx-auto w-full max-w-md">
            <div class="mb-7 flex items-center gap-3.5 text-white">
                <div
                    class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-white/95 p-1.5 shadow-lg ring-1 shadow-black/30 ring-white/25"
                >
                    <img
                        src="/logo.png"
                        alt=""
                        class="size-full object-contain"
                    />
                </div>
                <div>
                    <p
                        class="font-display text-3xl leading-none font-extrabold tracking-wide uppercase"
                    >
                        Hockey Game
                    </p>
                    <p class="mt-1 text-xs font-medium text-slate-300">
                        Seu hockey. Suas equipes. Da presença ao apito final.
                    </p>
                </div>
            </div>

            <div
                class="rounded-3xl border border-white/10 bg-white/95 p-6 shadow-2xl shadow-black/40 backdrop-blur-md sm:p-8 dark:bg-slate-900/95 dark:text-slate-100"
            >
                <h1
                    class="text-2xl font-extrabold tracking-tight text-slate-950 dark:text-white"
                >
                    {{ title }}
                </h1>
                <p class="mt-1.5 text-sm text-slate-500 dark:text-slate-400">
                    {{ description }}
                </p>
                <div class="mt-6">
                    <slot />
                </div>
            </div>

            <div
                class="mt-6 grid grid-cols-3 gap-2 text-center text-[11px] font-semibold text-slate-400"
            >
                <div
                    class="flex flex-col items-center gap-1 rounded-2xl border border-white/10 bg-white/5 px-2 py-2.5"
                >
                    <Users class="size-4 text-emerald-400" />
                    <span>Sorteio justo</span>
                </div>
                <div
                    class="flex flex-col items-center gap-1 rounded-2xl border border-white/10 bg-white/5 px-2 py-2.5"
                >
                    <Timer class="size-4 text-amber-400" />
                    <span>Placar offline</span>
                </div>
                <div
                    class="flex flex-col items-center gap-1 rounded-2xl border border-white/10 bg-white/5 px-2 py-2.5"
                >
                    <Trophy class="size-4 text-sky-400" />
                    <span>Campeonatos</span>
                </div>
            </div>
        </div>
    </div>
</template>
