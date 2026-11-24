<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

defineOptions({
    layout: {
        title: 'Recuperar acesso',
        description: 'Enviaremos um link para o e-mail do seu time.',
    },
});
const form = useForm({ email: '' });
</script>

<template>
    <Head title="Recuperar acesso" />
    <form class="space-y-4" @submit.prevent="form.post('/forgot-password')">
        <label
            class="block text-sm font-bold text-slate-800 dark:text-slate-200"
            >E-mail
            <input
                v-model="form.email"
                name="email"
                type="email"
                required
                autofocus
                class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-normal text-slate-950 transition-colors focus:border-emerald-600 focus:ring-2 focus:ring-emerald-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            />
            <span
                v-if="form.errors.email"
                class="mt-1 block text-xs font-semibold text-red-600 dark:text-red-400"
                >{{ form.errors.email }}</span
            >
        </label>
        <button
            class="min-h-12 w-full rounded-xl bg-emerald-700 font-bold text-white shadow-md shadow-emerald-900/20 transition-all hover:bg-emerald-600 active:scale-[0.98] disabled:opacity-50"
            :disabled="form.processing"
        >
            Enviar link de recuperação
        </button>
    </form>
    <Link
        href="/login"
        class="mt-5 block text-center text-sm font-bold text-emerald-700 transition-colors hover:text-emerald-600 dark:text-emerald-400"
        >Voltar ao login</Link
    >
</template>
