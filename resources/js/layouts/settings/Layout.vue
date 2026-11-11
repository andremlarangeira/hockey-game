<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>
<template>
    <section>
        <nav class="mb-4 flex flex-wrap gap-2" aria-label="Configurações">
            <Link
                href="/time"
                class="min-h-10 rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white dark:bg-slate-700"
                >Meu time</Link
            ><Link
                href="/settings/security"
                class="min-h-10 rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-bold dark:border-slate-600 dark:bg-slate-900"
                >Senha e segurança</Link
            >
        </nav>
        <slot />
    </section>
</template>
