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
        <label class="block text-sm font-bold"
            >E-mail<input
                v-model="form.email"
                type="email"
                name="email"
                readonly
                class="mt-1 min-h-12 w-full rounded-xl border bg-slate-50 px-3" /></label
        ><label class="block text-sm font-bold"
            >Nova senha<input
                v-model="form.password"
                type="password"
                name="password"
                required
                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3"
            /><span v-if="form.errors.password" class="text-sm text-red-600">{{
                form.errors.password
            }}</span></label
        ><label class="block text-sm font-bold"
            >Confirme a senha<input
                v-model="form.password_confirmation"
                type="password"
                name="password_confirmation"
                required
                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3" /></label
        ><button
            class="min-h-12 w-full rounded-xl bg-green-700 font-bold text-white"
            :disabled="form.processing"
        >
            Salvar nova senha
        </button>
    </form>
</template>
