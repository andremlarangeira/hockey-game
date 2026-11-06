<script setup lang="ts">
import type { Player } from '@/types/hockey';
withDefaults(
    defineProps<{
        player: Pick<Player, 'name' | 'jersey_number' | 'photo_url'>;
        size?: string;
    }>(),
    { size: 'size-12' },
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
        >{{ player.jersey_number }}</span
    >
</template>
