<script setup lang="ts">
import { useAppearance, type Appearance } from '@/composables/useAppearance';
import { usePage } from '@inertiajs/vue3';
import { Monitor, Moon, Sun, Trophy } from 'lucide-vue-next';
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
    <div class="arena-bg min-h-svh text-slate-950 dark:text-slate-100">
        <div
            class="h-1 w-full bg-gradient-to-r from-emerald-500 via-teal-400 to-amber-400"
        />
        <header
            class="sticky top-0 z-30 border-b border-white/10 bg-slate-950/92 px-4 text-white backdrop-blur-md"
        >
            <div
                class="mx-auto flex min-h-16 max-w-6xl flex-wrap items-center justify-between gap-3 py-2.5"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-emerald-500 to-teal-700 text-white shadow-sm"
                    >
                        <Trophy class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <p
                            class="truncate text-sm font-extrabold tracking-tight"
                        >
                            {{ championship.name }}
                        </p>
                        <p
                            class="truncate text-xs font-semibold text-slate-400"
                        >
                            {{ championship.city }} · Portal ao vivo
                        </p>
                    </div>
                </div>
                <fieldset
                    class="flex rounded-xl border border-white/10 bg-white/5 p-1"
                    aria-label="Aparência"
                >
                    <legend class="sr-only">Aparência</legend>
                    <label
                        v-for="option in appearanceOptions"
                        :key="option.value"
                        class="flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg px-2.5 text-[11px] font-bold transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-white"
                        :class="
                            appearance === option.value
                                ? 'bg-white text-slate-950 shadow-xs'
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
        <main class="mx-auto max-w-6xl px-4 py-5 sm:py-8">
            <slot />
        </main>
    </div>
</template>
