<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        name: string;
        shortName?: string | null;
        logoUrl?: string | null;
        primaryColor?: string | null;
        secondaryColor?: string | null;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    {
        shortName: null,
        logoUrl: null,
        primaryColor: '#2563eb',
        secondaryColor: '#ffffff',
        size: 'md',
    },
);

const initials = computed(() => {
    if (props.shortName && props.shortName.trim().length > 0) {
        return props.shortName.trim().slice(0, 3).toUpperCase();
    }
    const words = props.name.trim().split(/\s+/);
    if (words.length >= 2) {
        return `${words[0][0] ?? ''}${words[1][0] ?? ''}`.toUpperCase();
    }
    return props.name.trim().slice(0, 3).toUpperCase() || 'EQ';
});

const boxClasses = computed(() => {
    if (props.size === 'sm') return 'size-8 rounded-lg text-[10px]';
    if (props.size === 'lg') return 'size-14 rounded-2xl text-base';
    return 'size-10 rounded-xl text-xs';
});
</script>

<template>
    <img
        v-if="logoUrl"
        :src="logoUrl"
        :alt="`Escudo ${name}`"
        class="shrink-0 border border-slate-200 bg-white object-cover dark:border-slate-700"
        :class="boxClasses"
    />
    <span
        v-else
        class="inline-flex shrink-0 items-center justify-center border font-black tracking-tight select-none"
        :class="boxClasses"
        :style="{
            backgroundColor: primaryColor || '#2563eb',
            color: secondaryColor || '#ffffff',
            borderColor: 'rgba(15, 23, 42, 0.12)',
        }"
        :aria-label="`Escudo ${name}`"
    >
        {{ initials }}
    </span>
</template>
