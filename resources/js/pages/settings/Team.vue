<script setup lang="ts">
import { useAppearance, type Appearance } from '@/composables/useAppearance';
import { readableText } from '@/lib/balancedDraw';
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Monitor, Moon, Palette, Sun } from 'lucide-vue-next';
import { onBeforeUnmount, ref } from 'vue';

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

const colorPresets = [
    { name: 'Floresta & Ouro', primary: '#166534', secondary: '#f59e0b' },
    { name: 'Gelo & Royal', primary: '#1d4ed8', secondary: '#38bdf8' },
    { name: 'Rubro-Negro', primary: '#b91c1c', secondary: '#facc15' },
    { name: 'Oceano', primary: '#0f766e', secondary: '#f97316' },
    { name: 'Arena Roxo', primary: '#6d28d9', secondary: '#eab308' },
    { name: 'Aço & Fogo', primary: '#334155', secondary: '#ef4444' },
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

const logoPreview = ref<string | null>(props.team.logo_url);
let objectUrl: string | null = null;

function revokePreview() {
    if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = null;
    }
}

function onLogoSelect(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    revokePreview();
    form.logo = file;
    if (file) {
        objectUrl = URL.createObjectURL(file);
        logoPreview.value = objectUrl;
    } else {
        logoPreview.value = props.team.logo_url;
    }
}

function applyPreset(primary: string, secondary: string) {
    form.primary_color = primary;
    form.secondary_color = secondary;
}

onBeforeUnmount(() => revokePreview());

const submit = () =>
    form
        .transform((data) => ({ ...data, _method: 'patch' }))
        .post('/time', { forceFormData: true });
</script>

<template>
    <Head title="Meu time" />

    <div>
        <p
            class="text-[11px] font-extrabold tracking-widest text-[var(--team-primary)] uppercase dark:text-emerald-400"
        >
            Identidade do elenco
        </p>
        <h1
            class="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-950 sm:text-3xl dark:text-white"
        >
            Meu time
        </h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Personalize o escudo e as cores que dão vida ao app, ao sorteio e ao
            placar.
        </p>
    </div>

    <div class="mt-5 grid max-w-4xl gap-5 lg:grid-cols-[1fr_280px]">
        <form
            class="surface-card space-y-5 rounded-2xl p-5"
            @submit.prevent="submit"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <label
                    class="text-sm font-bold text-slate-900 dark:text-slate-100"
                    >Nome do time
                    <input
                        v-model="form.name"
                        required
                        class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-normal text-slate-950 focus:border-[var(--team-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    />
                    <span
                        v-if="form.errors.name"
                        class="mt-1 block text-xs text-red-600 dark:text-red-400"
                        >{{ form.errors.name }}</span
                    >
                </label>
                <label
                    class="text-sm font-bold text-slate-900 dark:text-slate-100"
                    >E-mail de acesso
                    <input
                        v-model="form.email"
                        type="email"
                        required
                        class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-normal text-slate-950 focus:border-[var(--team-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    />
                </label>
            </div>

            <div>
                <p
                    class="mb-2 flex items-center gap-1.5 text-xs font-extrabold tracking-wider text-slate-500 uppercase dark:text-slate-400"
                >
                    <Palette class="size-3.5" />
                    Paletas esportivas rápidas
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="preset in colorPresets"
                        :key="preset.name"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-bold text-slate-700 transition-colors hover:border-slate-300 hover:bg-white dark:border-slate-700 dark:bg-slate-800/70 dark:text-slate-200 dark:hover:bg-slate-800"
                        @click="applyPreset(preset.primary, preset.secondary)"
                    >
                        <span class="flex -space-x-1">
                            <span
                                class="size-3.5 rounded-full ring-1 ring-white dark:ring-slate-900"
                                :style="{ background: preset.primary }"
                            />
                            <span
                                class="size-3.5 rounded-full ring-1 ring-white dark:ring-slate-900"
                                :style="{ background: preset.secondary }"
                            />
                        </span>
                        {{ preset.name }}
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <label
                    class="text-sm font-bold text-slate-900 dark:text-slate-100"
                    >Cor principal
                    <div
                        class="mt-1.5 flex items-center gap-2.5 rounded-xl border border-slate-300 bg-white p-2 dark:border-slate-700 dark:bg-slate-950"
                    >
                        <input
                            v-model="form.primary_color"
                            type="color"
                            class="size-10 cursor-pointer rounded-lg border-0 bg-transparent"
                        />
                        <span class="font-mono text-xs font-semibold uppercase">
                            {{ form.primary_color }}
                        </span>
                    </div>
                </label>
                <label
                    class="text-sm font-bold text-slate-900 dark:text-slate-100"
                    >Cor secundária
                    <div
                        class="mt-1.5 flex items-center gap-2.5 rounded-xl border border-slate-300 bg-white p-2 dark:border-slate-700 dark:bg-slate-950"
                    >
                        <input
                            v-model="form.secondary_color"
                            type="color"
                            class="size-10 cursor-pointer rounded-lg border-0 bg-transparent"
                        />
                        <span class="font-mono text-xs font-semibold uppercase">
                            {{ form.secondary_color }}
                        </span>
                    </div>
                </label>
            </div>

            <label
                class="block text-sm font-bold text-slate-900 dark:text-slate-100"
                >Escudo
                <div class="mt-2 flex items-center gap-3">
                    <img
                        v-if="logoPreview"
                        :src="logoPreview"
                        alt="Escudo atual"
                        class="size-16 rounded-2xl border border-slate-200 object-cover shadow-xs dark:border-slate-700"
                    />
                    <span
                        v-else
                        class="flex size-16 items-center justify-center rounded-2xl bg-slate-100 p-2 dark:bg-slate-800"
                    >
                        <img
                            src="/logo.png"
                            alt=""
                            class="size-full object-contain"
                        />
                    </span>
                    <input
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        class="w-full text-xs text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-xs file:font-bold file:text-slate-800 hover:file:bg-slate-200 dark:text-slate-300 dark:file:bg-slate-800 dark:file:text-slate-100"
                        @input="onLogoSelect"
                    />
                </div>
                <span
                    v-if="form.errors.logo"
                    class="mt-1 block text-xs text-red-600 dark:text-red-400"
                    >{{ form.errors.logo }}</span
                >
            </label>

            <button
                type="submit"
                class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[var(--team-primary)] font-bold text-[var(--team-primary-text)] shadow-sm transition-all hover:brightness-110 focus-visible:ring-2 focus-visible:ring-[var(--team-primary)] focus-visible:ring-offset-2 focus-visible:outline-none active:scale-[0.99] disabled:opacity-50 dark:focus-visible:ring-offset-slate-900"
                :disabled="form.processing"
            >
                <Check class="size-5" />
                Salvar identidade
            </button>
        </form>

        <div class="space-y-5">
            <section
                class="overflow-hidden rounded-2xl border border-white/10 bg-slate-950 text-white shadow-xl"
            >
                <div
                    class="h-1.5"
                    :style="{
                        background: `linear-gradient(90deg, ${form.primary_color}, ${form.secondary_color})`,
                    }"
                />
                <div class="p-4">
                    <p
                        class="text-[10px] font-extrabold tracking-widest text-slate-400 uppercase"
                    >
                        Prévia ao vivo
                    </p>
                    <div class="mt-3 flex items-center gap-3">
                        <img
                            v-if="logoPreview"
                            :src="logoPreview"
                            alt=""
                            class="size-11 rounded-xl border border-white/15 object-cover"
                        />
                        <span
                            v-else
                            class="flex size-11 items-center justify-center rounded-xl bg-white p-1.5"
                        >
                            <img
                                src="/logo.png"
                                alt=""
                                class="size-full object-contain"
                            />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate font-extrabold">
                                {{ form.name || 'Seu Time' }}
                            </p>
                            <p class="text-[11px] text-slate-400">
                                Cabeçalho & placar
                            </p>
                        </div>
                    </div>
                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <span
                            class="rounded-xl px-3 py-2 text-center text-xs font-extrabold"
                            :style="{
                                background: form.primary_color,
                                color: readableText(form.primary_color),
                            }"
                        >
                            Equipe 1
                        </span>
                        <span
                            class="rounded-xl px-3 py-2 text-center text-xs font-extrabold"
                            :style="{
                                background: form.secondary_color,
                                color: readableText(form.secondary_color),
                            }"
                        >
                            Equipe 2
                        </span>
                    </div>
                </div>
            </section>

            <section
                class="surface-card rounded-2xl p-5"
                aria-labelledby="appearance-legend"
            >
                <fieldset>
                    <legend
                        id="appearance-legend"
                        class="text-sm font-extrabold text-slate-900 dark:text-slate-100"
                    >
                        Aparência
                    </legend>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        Escolha como o Hockey Game é exibido neste dispositivo.
                    </p>
                    <div
                        role="radiogroup"
                        aria-label="Aparência"
                        class="mt-3 grid grid-cols-3 gap-1.5 rounded-xl bg-slate-100 p-1 dark:bg-slate-950"
                    >
                        <label
                            v-for="option in appearanceOptions"
                            :key="option.value"
                            :data-test="`appearance-${option.value}`"
                            class="flex min-h-11 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg px-2 py-1.5 text-xs font-bold transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-[var(--team-primary)] has-[:focus-visible]:ring-offset-2 dark:has-[:focus-visible]:ring-offset-slate-950"
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
                            <component
                                :is="option.icon"
                                class="size-4 shrink-0"
                            />
                            <span>{{ option.label }}</span>
                        </label>
                    </div>
                </fieldset>
            </section>
        </div>
    </div>
</template>
