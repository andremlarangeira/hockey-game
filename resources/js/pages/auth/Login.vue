<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Sparkles } from 'lucide-vue-next';

defineProps<{ canResetPassword?: boolean; status?: string }>();
defineOptions({
    layout: {
        title: 'Entrar no seu time',
        description: 'Presença, sorteio equilibrado e placar na palma da mão.',
    },
});
const form = useForm({ email: '', password: '', remember: false });

function fillDemo() {
    form.email = 'demo@naquadra.test';
    form.password = 'hockey123';
}
</script>

<template>
    <Head title="Entrar" />
    <p
        v-if="status"
        class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-3.5 py-2.5 text-sm font-medium text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/60 dark:text-emerald-200"
    >
        {{ status }}
    </p>
    <form
        class="space-y-4"
        @submit.prevent="
            form.post('/login', { onFinish: () => form.reset('password') })
        "
    >
        <label
            class="block text-sm font-bold text-slate-800 dark:text-slate-200"
            >E-mail
            <input
                id="email"
                v-model="form.email"
                type="email"
                name="email"
                required
                autofocus
                autocomplete="email"
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
                id="password"
                v-model="form.password"
                type="password"
                name="password"
                required
                autocomplete="current-password"
                class="mt-1.5 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-3.5 font-normal text-slate-950 transition-colors focus:border-emerald-600 focus:ring-2 focus:ring-emerald-500/20 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                placeholder="Sua senha"
            />
            <span
                v-if="form.errors.password"
                class="mt-1 block text-xs font-semibold text-red-600 dark:text-red-400"
                >{{ form.errors.password }}</span
            >
        </label>
        <label
            class="flex cursor-pointer items-center gap-2.5 text-sm font-medium text-slate-600 dark:text-slate-300"
        >
            <input
                v-model="form.remember"
                type="checkbox"
                class="size-4 rounded accent-emerald-700"
            />
            Lembrar de mim
        </label>
        <button
            type="submit"
            class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-emerald-700 px-4 font-bold text-white shadow-md shadow-emerald-900/20 transition-all hover:bg-emerald-600 active:scale-[0.98] disabled:opacity-50"
            :disabled="form.processing"
            data-test="entrar"
        >
            {{ form.processing ? 'Entrando…' : 'Entrar' }}
            <ArrowRight v-if="!form.processing" class="size-4" />
        </button>
    </form>
    <div class="mt-5 flex flex-wrap items-center justify-between gap-2 text-sm">
        <Link
            v-if="canResetPassword"
            href="/forgot-password"
            class="font-medium text-slate-500 transition-colors hover:text-slate-900 dark:text-slate-400 dark:hover:text-white"
            >Esqueci a senha</Link
        >
        <Link
            href="/register"
            class="font-bold text-emerald-700 transition-colors hover:text-emerald-600 dark:text-emerald-400"
            >Criar meu time</Link
        >
    </div>
    <div
        class="mt-5 rounded-2xl border border-emerald-200/80 bg-emerald-50/80 p-3.5 text-center text-xs leading-relaxed text-emerald-950 dark:border-emerald-800/60 dark:bg-emerald-950/40 dark:text-emerald-100"
    >
        <p class="font-bold">Quer conhecer o app agora?</p>
        <p class="mt-0.5 text-emerald-800 dark:text-emerald-200/90">
            <code>demo@naquadra.test</code> · senha <code>hockey123</code>
        </p>
        <button
            type="button"
            class="mt-2.5 inline-flex items-center gap-1.5 rounded-lg bg-emerald-700/10 px-3 py-1.5 font-bold text-emerald-800 transition-colors hover:bg-emerald-700/20 dark:bg-emerald-400/15 dark:text-emerald-200 dark:hover:bg-emerald-400/25"
            @click="fillDemo"
        >
            <Sparkles class="size-3.5" />
            Preencher conta demo
        </button>
    </div>
</template>
