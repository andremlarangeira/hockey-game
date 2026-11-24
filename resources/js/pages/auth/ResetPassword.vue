<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{ token: string; email: string }>();
defineOptions({
    layout: {
        title: 'Defina sua nova senha',
        description: 'Escolha uma senha segura para o seu time.',
    },
});
const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});
</script>

<template>
    <Head title="Nova senha" />
    <form class="space-y-4" @submit.prevent="form.post('/reset-password')">
        <label
            class="block text-sm font-bold text-slate-800 dark:text-slate-200"
            >E-mail
            <input
                v-model="form.email"
                type="email"
                name="email"
                readonly
                class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-200 bg-slate-100 px-3.5 font-normal text-slate-600 dark:border-slate-800 dark:bg-slate-800/60 dark:text-slate-400"
            />
        </label>
        <label
            class="block text-sm font-bold text-slate-800 dark:text-slate-200"
            >Nova senha
            <input
                v-model="form.password"
                type="password"
                name="password"
                required
                class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-normal text-slate-950 transition-colors focus:border-emerald-600 focus:ring-2 focus:ring-emerald-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            />
            <span
                v-if="form.errors.password"
                class="mt-1 block text-xs font-semibold text-red-600 dark:text-red-400"
                >{{ form.errors.password }}</span
            >
        </label>
        <label
            class="block text-sm font-bold text-slate-800 dark:text-slate-200"
            >Confirme a senha
            <input
                v-model="form.password_confirmation"
                type="password"
                name="password_confirmation"
                required
                class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-normal text-slate-950 transition-colors focus:border-emerald-600 focus:ring-2 focus:ring-emerald-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            />
        </label>
        <button
            class="min-h-12 w-full rounded-xl bg-emerald-700 font-bold text-white shadow-md shadow-emerald-900/20 transition-all hover:bg-emerald-600 active:scale-[0.98] disabled:opacity-50"
            :disabled="form.processing"
        >
            Salvar nova senha
        </button>
    </form>
</template>
