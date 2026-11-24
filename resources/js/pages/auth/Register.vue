<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';

defineProps<{ passwordRules?: string }>();
defineOptions({
    layout: {
        title: 'Crie o seu time',
        description: 'Uma conta para cada equipe. Seus dados ficam separados.',
    },
});
const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});
</script>

<template>
    <Head title="Criar time" />
    <form
        class="space-y-4"
        @submit.prevent="
            form.post('/register', {
                onFinish: () => form.reset('password', 'password_confirmation'),
            })
        "
    >
        <label
            class="block text-sm font-bold text-slate-800 dark:text-slate-200"
            >Nome do time
            <input
                v-model="form.name"
                name="name"
                required
                autofocus
                class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-normal text-slate-950 transition-colors focus:border-emerald-600 focus:ring-2 focus:ring-emerald-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                placeholder="Ex.: Hockey da Vila"
            />
            <span
                v-if="form.errors.name"
                class="mt-1 block text-xs font-semibold text-red-600 dark:text-red-400"
                >{{ form.errors.name }}</span
            >
        </label>
        <label
            class="block text-sm font-bold text-slate-800 dark:text-slate-200"
            >E-mail de acesso
            <input
                v-model="form.email"
                name="email"
                type="email"
                required
                class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-normal text-slate-950 transition-colors focus:border-emerald-600 focus:ring-2 focus:ring-emerald-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                placeholder="voce@seutime.com.br"
            />
            <span
                v-if="form.errors.email"
                class="mt-1 block text-xs font-semibold text-red-600 dark:text-red-400"
                >{{ form.errors.email }}</span
            >
        </label>
        <label
            class="block text-sm font-bold text-slate-800 dark:text-slate-200"
            >Senha
            <input
                v-model="form.password"
                name="password"
                type="password"
                required
                autocomplete="new-password"
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
                name="password_confirmation"
                type="password"
                required
                autocomplete="new-password"
                class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-normal text-slate-950 transition-colors focus:border-emerald-600 focus:ring-2 focus:ring-emerald-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            />
        </label>
        <button
            type="submit"
            class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-700 px-4 font-bold text-white shadow-md shadow-emerald-900/20 transition-all hover:bg-emerald-600 active:scale-[0.98] disabled:opacity-50"
            :disabled="form.processing"
        >
            {{ form.processing ? 'Criando…' : 'Criar meu time' }}
            <ArrowRight v-if="!form.processing" class="size-4" />
        </button>
    </form>
    <p class="mt-5 text-center text-sm text-slate-500 dark:text-slate-400">
        Já tem um time?
        <Link
            href="/login"
            class="font-bold text-emerald-700 transition-colors hover:text-emerald-600 dark:text-emerald-400"
            >Entrar</Link
        >
    </p>
</template>
