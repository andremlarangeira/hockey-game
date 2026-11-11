<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
defineProps<{ canResetPassword?: boolean; status?: string }>();
defineOptions({
    layout: {
        title: 'Entrar no seu time',
        description: 'Presença, sorteio e placar na palma da mão.',
    },
});
const form = useForm({ email: '', password: '', remember: false });
</script>
<template>
    <Head title="Entrar" />
    <p v-if="status" class="mb-4 text-sm text-green-700">{{ status }}</p>
    <form
        class="space-y-4"
        @submit.prevent="
            form.post('/login', { onFinish: () => form.reset('password') })
        "
    >
        <label class="block text-sm font-bold"
            >E-mail<input
                id="email"
                v-model="form.email"
                type="email"
                name="email"
                required
                autofocus
                autocomplete="email"
                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
                placeholder="voce@seutime.com.br"
            /><span
                v-if="form.errors.email"
                class="mt-1 block text-sm text-red-600"
                >{{ form.errors.email }}</span
            ></label
        ><label class="block text-sm font-bold"
            >Senha<input
                id="password"
                v-model="form.password"
                type="password"
                name="password"
                required
                autocomplete="current-password"
                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
                placeholder="Sua senha"
            /><span
                v-if="form.errors.password"
                class="mt-1 block text-sm text-red-600"
                >{{ form.errors.password }}</span
            ></label
        ><label class="flex items-center gap-2 text-sm"
            ><input
                v-model="form.remember"
                type="checkbox"
                class="size-4"
            />Lembrar de mim</label
        ><button
            type="submit"
            class="min-h-12 w-full rounded-xl bg-green-700 font-bold text-white disabled:opacity-50"
            :disabled="form.processing"
            data-test="entrar"
        >
            {{ form.processing ? 'Entrando…' : 'Entrar' }}
        </button>
    </form>
    <div class="mt-5 flex flex-wrap justify-between gap-2 text-sm">
        <Link
            v-if="canResetPassword"
            href="/forgot-password"
            class="text-slate-500 dark:text-slate-400"
            >Esqueci a senha</Link
        ><Link href="/register" class="font-bold text-green-800"
            >Criar meu time</Link
        >
    </div>
    <div
        class="mt-5 rounded-xl bg-green-50 p-3 text-center text-xs leading-relaxed text-green-900"
    >
        <strong>Quer conhecer?</strong><br />Use
        <code>demo@naquadra.test</code> e senha <code>hockey123</code>.
    </div>
</template>
