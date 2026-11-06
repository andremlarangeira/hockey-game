<script setup lang="ts">
import { computed } from 'vue';
import type { Player } from '@/types/hockey';
const props = withDefaults(
    defineProps<{
        player: Pick<Player, 'name' | 'photo_url'>;
        size?: string;
    }>(),
    { size: 'size-12' },
);
const initials = computed(() =>
    props.player.name
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .map((part) => part[0]?.toLocaleUpperCase('pt-BR') ?? '')
        .slice(0, 2)
        .join(''),
);
</script>

<template>
    <img
        v-if="player.photo_url"
        :src="player.photo_url"
        :alt="`Foto de ${player.name}`"
        class="shrink-0 rounded-xl border border-slate-200 object-cover"
        :class="size"
    />
    <span
        v-else
        class="flex shrink-0 items-center justify-center rounded-xl bg-slate-800 font-black text-white"
        :class="size"
        role="img"
        :aria-label="player.name"
        >{{ initials }}</span
    >
</template>
