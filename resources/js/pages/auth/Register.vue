<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
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
        <label class="block text-sm font-bold"
            >Nome do time<input
                v-model="form.name"
                name="name"
                required
                autofocus
                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
                placeholder="Ex.: Hockey da Vila"
            /><span v-if="form.errors.name" class="text-sm text-red-600">{{
                form.errors.name
            }}</span></label
        ><label class="block text-sm font-bold"
            >E-mail de acesso<input
                v-model="form.email"
                name="email"
                type="email"
                required
                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
                placeholder="voce@seutime.com.br"
            /><span v-if="form.errors.email" class="text-sm text-red-600">{{
                form.errors.email
            }}</span></label
        ><label class="block text-sm font-bold"
            >Senha<input
                v-model="form.password"
                name="password"
                type="password"
                required
                autocomplete="new-password"
                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
            /><span v-if="form.errors.password" class="text-sm text-red-600">{{
                form.errors.password
            }}</span></label
        ><label class="block text-sm font-bold"
            >Confirme a senha<input
                v-model="form.password_confirmation"
                name="password_confirmation"
                type="password"
                required
                autocomplete="new-password"
                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600" /></label
        ><button
            type="submit"
            class="min-h-12 w-full rounded-xl bg-green-700 font-bold text-white disabled:opacity-50"
            :disabled="form.processing"
        >
            {{ form.processing ? 'Criando…' : 'Criar meu time' }}
        </button>
    </form>
    <p class="mt-5 text-center text-sm text-slate-500 dark:text-slate-400">
        Já tem um time?
        <Link href="/login" class="font-bold text-green-800">Entrar</Link>
    </p>
</template>
