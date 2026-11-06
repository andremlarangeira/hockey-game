<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check } from 'lucide-vue-next';
const props = defineProps<{
    team: {
        name: string;
        email: string;
        logo_url: string | null;
        primary_color: string;
        secondary_color: string;
    };
}>();
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
        <p class="text-xs font-bold tracking-widest text-slate-500 uppercase">
            Identidade do elenco
        </p>
        <h1 class="mt-1 text-2xl font-black">Meu time</h1>
        <p class="mt-1 text-sm text-slate-500">
            Cada conta é um time separado, com seus próprios jogadores e
            partidas.
        </p>
    </div>
    <form
        class="mt-5 max-w-2xl space-y-4 rounded-2xl border border-slate-200 bg-white p-5"
        @submit.prevent="submit"
    >
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="text-sm font-bold"
                >Nome do time<input
                    v-model="form.name"
                    required
                    class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3"
                /><span v-if="form.errors.name" class="text-xs text-red-600">{{
                    form.errors.name
                }}</span></label
            ><label class="text-sm font-bold"
                >E-mail de acesso<input
                    v-model="form.email"
                    type="email"
                    required
                    class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3"
            /></label>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <label class="text-sm font-bold"
                >Cor principal
                <div
                    class="mt-1 flex items-center gap-2 rounded-xl border border-slate-300 p-2"
                >
                    <input
                        v-model="form.primary_color"
                        type="color"
                        class="size-10"
                    /><span class="text-xs">{{ form.primary_color }}</span>
                </div></label
            ><label class="text-sm font-bold"
                >Cor secundária
                <div
                    class="mt-1 flex items-center gap-2 rounded-xl border border-slate-300 p-2"
                >
                    <input
                        v-model="form.secondary_color"
                        type="color"
                        class="size-10"
                    /><span class="text-xs">{{ form.secondary_color }}</span>
                </div></label
            >
        </div>
        <label class="block text-sm font-bold"
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
                    class="w-full text-xs"
                    @input="
                        form.logo =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
                />
            </div>
            <span v-if="form.errors.logo" class="text-xs text-red-600">{{
                form.errors.logo
            }}</span></label
        ><button
            class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[var(--team-primary)] font-bold text-[var(--team-primary-text)] disabled:opacity-50"
            :disabled="form.processing"
        >
            <Check class="size-5" />Salvar identidade
        </button>
    </form>
</template>
