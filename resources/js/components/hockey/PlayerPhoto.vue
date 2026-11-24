<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { Player } from '@/types/hockey';

const props = withDefaults(
    defineProps<{
        player: Pick<Player, 'name' | 'photo_url'>;
        size?: string;
    }>(),
    { size: 'size-12' },
);

const gradients = [
    'from-emerald-600 to-teal-800',
    'from-sky-600 to-indigo-800',
    'from-violet-600 to-purple-900',
    'from-amber-500 to-orange-700',
    'from-rose-600 to-red-800',
    'from-cyan-600 to-blue-800',
    'from-slate-700 to-slate-900',
];

const initials = computed(() =>
    props.player.name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .map((part) => part[0]?.toLocaleUpperCase('pt-BR') ?? '')
        .slice(0, 2)
        .join(''),
);

const avatarGradient = computed(() => {
    let hash = 0;
    for (let i = 0; i < props.player.name.length; i++) {
        hash = (hash * 31 + props.player.name.charCodeAt(i)) >>> 0;
    }
    return gradients[hash % gradients.length];
});

const photoAvailable = ref(true);

watch(
    () => props.player.photo_url,
    () => {
        photoAvailable.value = true;
    },
);
</script>

<template>
    <img
        v-if="player.photo_url && photoAvailable"
        :src="player.photo_url"
        :alt="`Foto de ${player.name}`"
        loading="lazy"
        decoding="async"
        class="shrink-0 rounded-xl border border-slate-200/80 object-cover shadow-xs dark:border-slate-700"
        :class="size"
        @error="photoAvailable = false"
    />
    <span
        v-else
        class="flex shrink-0 items-center justify-center rounded-xl bg-gradient-to-br font-extrabold tracking-tight text-white shadow-xs ring-1 ring-black/10 ring-inset dark:ring-white/10"
        :class="[size, avatarGradient]"
        role="img"
        :aria-label="player.name"
    >
        {{ initials }}
    </span>
</template>
