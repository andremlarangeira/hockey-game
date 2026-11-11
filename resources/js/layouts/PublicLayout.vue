<script setup lang="ts">
import { useAppearance, type Appearance } from '@/composables/useAppearance';
import { usePage } from '@inertiajs/vue3';
import { Monitor, Moon, Trophy, Sun } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const { appearance, updateAppearance } = useAppearance();
const championship = computed(
    () => page.props.championship as { name: string; city: string },
);
const appearanceOptions: {
    value: Appearance;
    label: string;
    icon: typeof Sun;
}[] = [
    { value: 'light', label: 'Claro', icon: Sun },
    { value: 'dark', label: 'Escuro', icon: Moon },
    { value: 'system', label: 'Sistema', icon: Monitor },
];
</script>

<template>
    <div
        class="min-h-svh bg-slate-100 text-slate-950 dark:bg-slate-950 dark:text-slate-100"
    >
        <header class="border-b border-slate-800 bg-slate-950 px-4 text-white">
            <div
                class="mx-auto flex min-h-16 max-w-6xl flex-wrap items-center justify-between gap-3 py-3"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500 text-white"
                        ><Trophy class="size-5"
                    /></span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-black tracking-tight">
                            {{ championship.name }}
                        </p>
                        <p
                            class="truncate text-xs font-semibold text-slate-300"
                        >
                            {{ championship.city }}
                        </p>
                    </div>
                </div>
                <fieldset
                    class="flex rounded-xl bg-white/10 p-1"
                    aria-label="Aparência"
                >
                    <legend class="sr-only">Aparência</legend>
                    <label
                        v-for="option in appearanceOptions"
                        :key="option.value"
                        class="flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg px-2 text-[11px] font-bold transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-white"
                        :class="
                            appearance === option.value
                                ? 'bg-white text-slate-950'
                                : 'text-slate-300 hover:text-white'
                        "
                    >
                        <input
                            type="radio"
                            name="public-appearance"
                            :value="option.value"
                            :checked="appearance === option.value"
                            class="sr-only"
                            @change="updateAppearance(option.value)"
                        />
                        <component :is="option.icon" class="size-3.5" />
                        <span class="hidden sm:inline">{{ option.label }}</span>
                    </label>
                </fieldset>
            </div>
        </header>
        <main class="mx-auto max-w-6xl px-4 py-5 sm:py-8"><slot /></main>
    </div>
</template>
