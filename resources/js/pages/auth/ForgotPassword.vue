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
        <label class="block text-sm font-bold"
            >E-mail<input
                v-model="form.email"
                name="email"
                type="email"
                required
                autofocus
                class="mt-1 min-h-12 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600"
            /><span v-if="form.errors.email" class="text-sm text-red-600">{{
                form.errors.email
            }}</span></label
        ><button
            class="min-h-12 w-full rounded-xl bg-green-700 font-bold text-white"
            :disabled="form.processing"
        >
            Enviar link de recuperação
        </button>
    </form>
    <Link
        href="/login"
        class="mt-5 block text-center text-sm font-bold text-green-800"
        >Voltar ao login</Link
    >
</template>
