<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        name: string;
        logo?: string | null;
        logo_url?: string | null;
        href?: string | null;
        compact?: boolean;
    }>(),
    { logo: null, logo_url: null, href: '/jogadores', compact: false },
);

const resolvedLogo = computed(() => props.logo ?? props.logo_url ?? null);
</script>

<template>
    <Link
        :href="href ?? '#'"
        class="group flex min-w-0 items-center gap-2.5 rounded-xl focus-visible:ring-2 focus-visible:ring-white/80 focus-visible:outline-none"
        :aria-label="`Início — ${name}`"
    >
        <img
            v-if="resolvedLogo"
            :src="resolvedLogo"
            class="size-10 shrink-0 rounded-xl border border-white/15 object-cover shadow-sm transition-transform duration-200 group-hover:scale-105"
            :alt="`Escudo ${name}`"
            decoding="async"
        />
        <span
            v-else
            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white/95 p-1 shadow-sm ring-1 ring-white/20 transition-transform duration-200 group-hover:scale-105"
        >
            <img src="/logo.png" alt="" class="size-full object-contain" />
        </span>
        <div v-if="!compact" class="min-w-0">
            <span
                class="block truncate text-base leading-tight font-extrabold tracking-tight text-white"
            >
                {{ name }}
            </span>
            <span
                class="hidden text-[10px] font-bold tracking-widest text-slate-400 uppercase sm:block"
            >
                Hockey Game
            </span>
        </div>
    </Link>
</template>
