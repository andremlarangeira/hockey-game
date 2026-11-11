<script setup lang="ts">
import { useAppearance, type Appearance } from '@/composables/useAppearance';
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Monitor, Moon, Sun } from 'lucide-vue-next';
const props = defineProps<{
    team: {
        name: string;
        email: string;
        logo_url: string | null;
        primary_color: string;
        secondary_color: string;
    };
}>();
const { appearance, updateAppearance } = useAppearance();
const appearanceOptions: {
    value: Appearance;
    label: string;
    icon: typeof Sun;
}[] = [
    { value: 'light', label: 'Claro', icon: Sun },
    { value: 'dark', label: 'Escuro', icon: Moon },
    { value: 'system', label: 'Sistema', icon: Monitor },
];
const form = useForm<{
    name: string;
    email: string;
    primary_color: string;
    secondary_color: string;
    logo: File | null;
}>({
    name: props.team.name,
    email: props.team.email,
    primary_color: props.team.primary_color,
    secondary_color: props.team.secondary_color,
    logo: null,
});
const submit = () =>
    form
        .transform((data) => ({ ...data, _method: 'patch' }))
        .post('/time', { forceFormData: true });
</script>
<template>
    <Head title="Meu time" />
    <div>
        <p
            class="text-xs font-bold tracking-widest text-slate-500 uppercase dark:text-slate-400"
        >
            Identidade do elenco
        </p>
        <h1 class="mt-1 text-2xl font-black text-slate-950 dark:text-white">
            Meu time
        </h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Cada conta é um time separado, com seus próprios jogadores e
            partidas.
        </p>
    </div>
    <form
        class="mt-5 max-w-2xl space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
        @submit.prevent="submit"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm font-bold text-slate-900 dark:text-slate-100"
                >Nome do time<input
                    v-model="form.name"
                    required
                    class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-slate-950 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                /><span
                    v-if="form.errors.name"
                    class="text-xs text-red-600 dark:text-red-400"
                    >{{ form.errors.name }}</span
                ></label
            ><label class="text-sm font-bold text-slate-900 dark:text-slate-100"
                >E-mail de acesso<input
                    v-model="form.email"
                    type="email"
                    required
                    class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3 text-slate-950 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            /></label>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <label class="text-sm font-bold text-slate-900 dark:text-slate-100"
                >Cor principal
                <div
                    class="mt-1 flex items-center gap-2 rounded-xl border border-slate-300 bg-white p-2 dark:border-slate-700 dark:bg-slate-950"
                >
                    <input
                        v-model="form.primary_color"
                        type="color"
                        class="size-10"
                    /><span class="text-xs">{{ form.primary_color }}</span>
                </div></label
            ><label class="text-sm font-bold text-slate-900 dark:text-slate-100"
                >Cor secundária
                <div
                    class="mt-1 flex items-center gap-2 rounded-xl border border-slate-300 bg-white p-2 dark:border-slate-700 dark:bg-slate-950"
                >
                    <input
                        v-model="form.secondary_color"
                        type="color"
                        class="size-10"
                    /><span class="text-xs">{{ form.secondary_color }}</span>
                </div></label
            >
        </div>
        <label
            class="block text-sm font-bold text-slate-900 dark:text-slate-100"
            >Escudo
            <div class="mt-2 flex items-center gap-3">
                <img
                    v-if="team.logo_url"
                    :src="team.logo_url"
                    alt="Escudo atual"
                    class="size-16 rounded-xl object-cover"
                /><input
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    class="w-full text-xs text-slate-700 dark:text-slate-300"
                    @input="
                        form.logo =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
                />
            </div>
            <span
                v-if="form.errors.logo"
                class="text-xs text-red-600 dark:text-red-400"
                >{{ form.errors.logo }}</span
            ></label
        ><button
            class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[var(--team-primary)] font-bold text-[var(--team-primary-text)] transition-colors hover:brightness-95 focus-visible:ring-2 focus-visible:ring-[var(--team-primary)] focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-50 dark:focus-visible:ring-offset-slate-900"
            :disabled="form.processing"
        >
            <Check class="size-5" />Salvar identidade
        </button>
    </form>
    <section
        class="mt-5 max-w-2xl rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
        aria-labelledby="appearance-legend"
    >
        <fieldset>
            <legend
                id="appearance-legend"
                class="text-sm font-bold text-slate-900 dark:text-slate-100"
            >
                Aparência
            </legend>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Escolha como o Na Quadra é exibido neste dispositivo.
            </p>
            <div
                role="radiogroup"
                aria-label="Aparência"
                class="mt-3 grid grid-cols-3 gap-2 rounded-xl bg-slate-100 p-1 dark:bg-slate-950"
            >
                <label
                    v-for="option in appearanceOptions"
                    :key="option.value"
                    :data-test="`appearance-${option.value}`"
                    class="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg px-3 text-sm font-bold transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[var(--team-primary)] has-[:focus-visible]:ring-offset-2 dark:has-[:focus-visible]:ring-offset-slate-950"
                    :class="
                        appearance === option.value
                            ? 'bg-white text-slate-950 shadow-xs dark:bg-slate-800 dark:text-white'
                            : 'text-slate-600 hover:text-slate-950 dark:text-slate-400 dark:hover:text-white'
                    "
                >
                    <input
                        type="radio"
                        name="appearance"
                        :value="option.value"
                        :checked="appearance === option.value"
                        class="sr-only"
                        @change="updateAppearance(option.value)"
                    />
                    <component :is="option.icon" class="size-4 shrink-0" />
                    <span>{{ option.label }}</span>
                </label>
            </div>
        </fieldset>
    </section>
</template>
