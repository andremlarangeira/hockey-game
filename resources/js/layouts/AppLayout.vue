<script setup lang="ts">
import TeamLogo from '@/components/hockey/TeamLogo.vue';
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    History,
    LogOut,
    Menu,
    Settings,
    Shuffle,
    Timer,
    TrendingUp,
    Trophy,
    Users,
    X,
} from 'lucide-vue-next';

const page = usePage();
const team = computed(
    () =>
        page.props.team as {
            name: string;
            logo_url: string | null;
            primary_color: string;
            secondary_color: string;
        },
);
const open = ref(false);
const navigation = [
    {
        title: 'Jogadores e presença',
        compact: 'Jogadores',
        href: '/jogadores',
        icon: Users,
    },
    {
        title: 'Sortear equipes',
        compact: 'Equipes',
        href: '/sorteio',
        icon: Shuffle,
    },
    {
        title: 'Cronômetro e placar',
        compact: 'Placar',
        href: '/partida',
        icon: Timer,
    },
    {
        title: 'Histórico de partidas',
        compact: 'Partidas',
        href: '/partidas',
        icon: History,
    },
    {
        title: 'Campeonatos',
        compact: 'Campeonato',
        href: '/campeonatos',
        icon: Trophy,
    },
    { title: 'Estatísticas', href: '/estatisticas', icon: TrendingUp },
    { title: 'Meu time', href: '/time', icon: Settings },
];
const flash = computed(
    () => page.props.flash as { success?: string } | undefined,
);
</script>

<template>
    <div
        class="min-h-svh bg-slate-100 pb-20 text-slate-950 md:pb-0 landscape-short:pb-0"
        :style="{
            '--team-primary': team.primary_color,
            '--team-secondary': team.secondary_color,
        }"
    >
        <header
            class="sticky top-0 z-40 border-b border-white/10 bg-slate-950 px-4 text-white landscape-short:hidden"
        >
            <div
                class="mx-auto flex h-16 max-w-7xl items-center justify-between"
            >
                <TeamLogo v-bind="team" />
                <div class="flex items-center gap-3">
                    <Link
                        href="/partida"
                        class="hidden min-h-10 items-center gap-2 rounded-xl bg-[var(--team-primary)] px-4 text-sm font-bold text-white sm:flex"
                        ><Timer class="size-4" />Nova partida</Link
                    >
                    <button
                        class="flex size-11 items-center justify-center rounded-xl hover:bg-white/10 md:hidden"
                        aria-label="Abrir menu"
                        @click="open = !open"
                    >
                        <X v-if="open" /><Menu v-else />
                    </button>
                </div>
            </div>
        </header>
        <aside
            v-if="open"
            class="fixed inset-x-0 top-16 bottom-0 z-50 bg-slate-950 p-4 text-white md:hidden"
        >
            <nav class="space-y-2">
                <Link
                    v-for="item in navigation"
                    :key="item.href"
                    :href="item.href"
                    class="flex min-h-14 items-center gap-3 rounded-xl px-4 font-semibold hover:bg-white/10"
                    @click="open = false"
                    ><component :is="item.icon" class="size-5" />{{
                        item.title
                    }}</Link
                >
            </nav>
            <button
                class="mt-4 flex min-h-14 w-full items-center gap-3 rounded-xl border border-white/15 px-4"
                @click="router.post('/logout')"
            >
                <LogOut class="size-5" />Sair
            </button>
        </aside>
        <div class="mx-auto flex max-w-7xl md:gap-6 md:px-6">
            <aside
                class="hidden min-h-[calc(100svh-4rem)] w-60 shrink-0 flex-col border-r border-slate-200 py-6 md:flex landscape-short:hidden"
            >
                <nav class="space-y-1">
                    <Link
                        v-for="item in navigation"
                        :key="item.href"
                        :href="item.href"
                        class="flex min-h-12 items-center gap-3 rounded-xl px-3 text-sm font-semibold hover:bg-white"
                        :class="
                            $page.url.startsWith(item.href)
                                ? 'bg-[var(--team-primary)] text-white'
                                : 'text-slate-700'
                        "
                        ><component :is="item.icon" class="size-5" />{{
                            item.title
                        }}</Link
                    >
                </nav>
                <button
                    class="mt-auto flex min-h-12 items-center gap-3 rounded-xl px-3 text-sm text-slate-600 hover:bg-white"
                    @click="router.post('/logout')"
                >
                    <LogOut class="size-5" />Sair
                </button>
            </aside>
            <main
                class="min-w-0 flex-1 px-4 py-5 md:py-8 landscape-short:max-w-none landscape-short:p-2"
            >
                <div
                    v-if="flash?.success"
                    class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-800"
                    role="status"
                >
                    {{ flash.success }}
                </div>
                <slot />
            </main>
        </div>
        <nav
            class="fixed inset-x-0 bottom-0 z-20 grid grid-cols-4 border-t border-slate-200 bg-white pb-[env(safe-area-inset-bottom)] md:hidden landscape-short:hidden"
        >
            <Link
                v-for="item in navigation.slice(0, 4)"
                :key="item.href"
                :href="item.href"
                class="flex min-h-16 flex-col items-center justify-center gap-1 px-1 text-[10px] font-bold"
                :class="
                    $page.url.startsWith(item.href)
                        ? 'text-[var(--team-primary)]'
                        : 'text-slate-500'
                "
                ><component :is="item.icon" class="size-5" />{{
                    item.compact
                }}</Link
            >
        </nav>
    </div>
</template>
