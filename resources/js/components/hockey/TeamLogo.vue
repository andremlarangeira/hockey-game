<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

withDefaults(
    defineProps<{
        name: string;
        logo?: string | null;
        href?: string | null;
        compact?: boolean;
    }>(),
    { logo: null, href: '/jogadores', compact: false },
);
</script>

<template>
    <Link
        :href="href ?? '#'"
        class="flex min-w-0 items-center gap-2.5"
        :aria-label="`Início — ${name}`"
    >
        <img
            v-if="logo"
            :src="logo"
            class="size-10 shrink-0 rounded-xl border border-white/15 object-cover"
            :alt="`Escudo ${name}`"
        />
        <span
            v-else
            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white p-1"
            ><img src="/logo.png" alt="" class="size-full object-contain"
        /></span>
        <span
            v-if="!compact"
            class="truncate text-base font-black tracking-tight text-white"
            >{{ name }}</span
        >
    </Link>
</template>
