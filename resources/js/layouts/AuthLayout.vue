<script setup lang="ts">
defineProps<{ title?: string; description?: string }>();
</script>

<template>
    <div
        class="flex min-h-svh flex-col justify-center bg-slate-950 p-5 text-slate-950 sm:p-8"
    >
        <div class="mx-auto w-full max-w-md">
            <div class="mb-7 flex items-center gap-3 text-white">
                <img src="/logo.png" alt="" class="size-14 shrink-0" />
                <div>
                    <p class="text-2xl font-black">Na Quadra</p>
                    <p class="text-xs text-slate-400">
                        Seu hockey. Suas equipes.
                    </p>
                </div>
            </div>
            <div
                class="rounded-3xl bg-white p-6 shadow-2xl sm:p-8 dark:bg-slate-900 dark:text-slate-100 dark:ring-1 dark:ring-slate-800"
            >
                <h1 class="text-2xl font-black">{{ title }}</h1>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    {{ description }}
                </p>
                <div class="mt-6"><slot /></div>
            </div>
            <p class="mt-6 text-center text-xs text-slate-500">
                Da presença ao apito final.
            </p>
        </div>
    </div>
</template>
