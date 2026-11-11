<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { KeyRound } from 'lucide-vue-next';
const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});
</script>
<template>
    <Head title="Senha e segurança" />
    <div>
        <p
            class="text-xs font-bold tracking-widest text-slate-500 uppercase dark:text-slate-400"
        >
            Acesso do time
        </p>
        <h1 class="mt-1 text-2xl font-black">Alterar senha</h1>
    </div>
    <form
        class="mt-5 max-w-lg space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900"
        @submit.prevent="
            form.put('/settings/password', { onSuccess: () => form.reset() })
        "
    >
        <label class="block text-sm font-bold"
            >Senha atual<input
                v-model="form.current_password"
                type="password"
                name="current_password"
                required
                autocomplete="current-password"
                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
            /><span
                v-if="form.errors.current_password"
                class="text-xs text-red-600"
                >{{ form.errors.current_password }}</span
            ></label
        ><label class="block text-sm font-bold"
            >Nova senha<input
                v-model="form.password"
                type="password"
                name="password"
                required
                autocomplete="new-password"
                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
            /><span v-if="form.errors.password" class="text-xs text-red-600">{{
                form.errors.password
            }}</span></label
        ><label class="block text-sm font-bold"
            >Confirme a nova senha<input
                v-model="form.password_confirmation"
                type="password"
                name="password_confirmation"
                required
                autocomplete="new-password"
                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600" /></label
        ><button
            class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-[var(--team-primary)] font-bold text-[var(--team-primary-text)]"
            :disabled="form.processing"
        >
            <KeyRound class="size-5" />Salvar nova senha
        </button>
    </form>
</template>
