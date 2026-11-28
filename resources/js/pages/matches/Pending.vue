<script setup lang="ts">
import PendingMatchesPanel from '@/components/hockey/PendingMatchesPanel.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
</script>

<template>
    <Head title="Partidas pendentes" />

    <div class="mb-3">
        <Link
            href="/partidas"
            class="inline-flex min-h-10 items-center gap-1.5 rounded-xl px-2 text-sm font-bold text-slate-600 transition-colors hover:bg-white hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
        >
            <ArrowLeft class="size-4" />
            Histórico de partidas
        </Link>
    </div>

    <PendingMatchesPanel :as-modal="false" />
</template>
