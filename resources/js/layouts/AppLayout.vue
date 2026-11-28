<script setup lang="ts">
import ConfirmDialog from '@/components/common/ConfirmDialog.vue';
import ToastContainer from '@/components/common/ToastContainer.vue';
import PendingMatchesPanel from '@/components/hockey/PendingMatchesPanel.vue';
import TeamLogo from '@/components/hockey/TeamLogo.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useToast } from '@/composables/useToast';
import { clearOfflineSnapshots } from '@/lib/offlineStorage';
import {
    offlineStatus,
    openPendingMatchesPanel,
    syncNow,
} from '@/lib/offlineSync';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    CloudOff,
    History,
    LogOut,
    Menu,
    Moon,
    RefreshCw,
    Settings,
    Shuffle,
    Sun,
    Timer,
    TrendingUp,
    Trophy,
    Users,
    X,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const page = usePage();
const { appearance, updateAppearance } = useAppearance();
const toast = useToast();

const team = computed(
    () =>
        page.props.team as {
            id: number;
            name: string;
            logo_url: string | null;
            primary_color: string;
            secondary_color: string;
            primary_text?: string;
        },
);

const open = ref(false);

const primaryNav = [
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
        title: 'Estatísticas',
        compact: 'Stats',
        href: '/estatisticas',
        icon: TrendingUp,
    },
];

const orgNav = [
    {
        title: 'Campeonatos',
        compact: 'Torneios',
        href: '/campeonatos',
        icon: Trophy,
    },
    {
        title: 'Meu time',
        compact: 'Time',
        href: '/time',
        icon: Settings,
    },
];

const allNav = [...primaryNav, ...orgNav];

const mobileBottomNav = [
    primaryNav[0]!,
    primaryNav[1]!,
    primaryNav[2]!,
    orgNav[0]!,
];

const flash = computed(
    () => page.props.flash as { success?: string } | undefined,
);

watch(
    () => flash.value?.success,
    (message) => {
        if (message) {
            toast.success(message);
        }
    },
    { immediate: true },
);

const isDark = computed(() => {
    if (appearance.value === 'dark') return true;
    if (appearance.value === 'light') return false;
    if (typeof window === 'undefined') return false;
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
});

function toggleTheme() {
    updateAppearance(isDark.value ? 'light' : 'dark');
}

const isActive = (href: string): boolean => {
    const [path] = page.url.split('?');
    return path === href || path.startsWith(`${href}/`);
};

const isMoreActive = computed(
    () =>
        isActive('/partidas') ||
        isActive('/estatisticas') ||
        isActive('/time') ||
        isActive('/settings'),
);

function signOut() {
    const accountId = team.value?.id;
    if (accountId) clearOfflineSnapshots(localStorage, accountId);
    navigator.serviceWorker?.controller?.postMessage({
        type: 'CLEAR_OFFLINE_CACHE',
    });
    router.post('/logout');
}
</script>

<template>
    <div
        class="arena-bg min-h-svh pb-20 text-slate-950 md:pb-0 dark:text-slate-100 landscape-short:pb-0"
        :style="{
            '--team-primary': team.primary_color,
            '--team-primary-text': team.primary_text ?? '#ffffff',
            '--team-secondary': team.secondary_color,
        }"
    >
        <div
            class="h-1 w-full landscape-short:hidden"
            :style="{
                background: `linear-gradient(90deg, ${team.primary_color}, ${team.secondary_color})`,
            }"
        />
        <header
            class="sticky top-0 z-40 border-b border-white/10 bg-slate-950/92 px-4 text-white shadow-sm backdrop-blur-md landscape-short:hidden"
        >
            <div
                class="mx-auto flex h-16 max-w-7xl items-center justify-between gap-3"
            >
                <TeamLogo
                    :name="team.name"
                    :logo-url="team.logo_url"
                    :logo="team.logo_url"
                />
                <div class="flex items-center gap-2">
                    <button
                        v-if="offlineStatus.pendingMatches"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px] font-bold transition-colors"
                        :class="
                            offlineStatus.failedMatches > 0
                                ? 'border-red-400/40 bg-red-500/20 text-red-200 hover:bg-red-500/30'
                                : 'border-amber-400/30 bg-amber-400/15 text-amber-200 hover:bg-amber-400/25'
                        "
                        data-test="header-contador-pendentes"
                        @click="openPendingMatchesPanel"
                    >
                        <span>{{ offlineStatus.pendingMatches }}</span>
                        <span class="hidden sm:inline">pendente(s)</span>
                    </button>
                    <span
                        v-if="offlineStatus.offline"
                        class="inline-flex items-center gap-1.5 rounded-full border border-amber-400/30 bg-amber-400/15 px-2.5 py-1 text-[11px] font-bold text-amber-200"
                    >
                        <CloudOff class="size-3.5" />
                        <span class="hidden sm:inline">Offline</span>
                    </span>
                    <button
                        type="button"
                        class="flex size-10 items-center justify-center rounded-xl border border-white/10 bg-white/5 text-slate-200 transition-colors hover:bg-white/15 hover:text-white focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                        :aria-label="
                            isDark
                                ? 'Alternar para tema claro'
                                : 'Alternar para tema escuro'
                        "
                        :title="isDark ? 'Tema claro' : 'Tema escuro'"
                        @click="toggleTheme"
                    >
                        <Sun v-if="isDark" class="size-4.5 text-amber-300" />
                        <Moon v-else class="size-4.5 text-sky-300" />
                    </button>
                    <Link
                        href="/partida"
                        class="hidden min-h-10 items-center gap-2 rounded-xl bg-[var(--team-primary)] px-4 text-sm font-bold text-[var(--team-primary-text)] shadow-sm transition-all hover:brightness-110 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950 focus-visible:outline-none active:scale-[0.98] sm:flex"
                    >
                        <Timer class="size-4" />
                        Nova partida
                    </Link>
                    <button
                        type="button"
                        class="flex size-10 items-center justify-center rounded-xl border border-white/10 bg-white/5 transition-colors hover:bg-white/15 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none md:hidden"
                        aria-label="Abrir menu"
                        @click="open = !open"
                    >
                        <X v-if="open" class="size-5" />
                        <Menu v-else class="size-5" />
                    </button>
                </div>
            </div>
        </header>

        <aside
            v-if="open"
            class="fixed inset-x-0 top-[68px] bottom-0 z-50 overflow-y-auto bg-slate-950/98 p-4 text-white backdrop-blur-lg md:hidden"
        >
            <nav class="space-y-1.5">
                <Link
                    v-for="item in allNav"
                    :key="item.href"
                    :href="item.href"
                    :aria-current="isActive(item.href) ? 'page' : undefined"
                    class="flex min-h-13 items-center gap-3 rounded-2xl px-4 font-bold transition-all focus-visible:ring-2 focus-visible:ring-[var(--team-primary)] focus-visible:outline-none"
                    :class="
                        isActive(item.href)
                            ? 'bg-[var(--team-primary)] text-[var(--team-primary-text)] shadow-md'
                            : 'text-slate-200 hover:bg-white/10 hover:text-white'
                    "
                    @click="open = false"
                >
                    <component :is="item.icon" class="size-5 shrink-0" />
                    <span>{{ item.title }}</span>
                </Link>
            </nav>
            <button
                type="button"
                class="mt-5 flex min-h-13 w-full items-center gap-3 rounded-2xl border border-white/15 px-4 font-bold text-slate-300 transition-colors hover:bg-white/10 hover:text-white focus-visible:ring-2 focus-visible:ring-[var(--team-primary)] focus-visible:outline-none"
                @click="signOut"
            >
                <LogOut class="size-5" />
                Sair da conta
            </button>
        </aside>

        <div class="mx-auto flex max-w-7xl md:gap-6 md:px-6">
            <aside
                class="hidden min-h-[calc(100svh-4.25rem)] w-64 shrink-0 flex-col py-6 md:flex landscape-short:hidden"
            >
                <div class="sticky top-22 flex flex-1 flex-col">
                    <p
                        class="px-3 text-[11px] font-extrabold tracking-widest text-slate-400 uppercase dark:text-slate-500"
                    >
                        Noite de jogo
                    </p>
                    <nav class="mt-2 space-y-1">
                        <Link
                            v-for="item in primaryNav"
                            :key="item.href"
                            :href="item.href"
                            :aria-current="
                                isActive(item.href) ? 'page' : undefined
                            "
                            class="group flex min-h-11 items-center gap-3 rounded-xl px-3.5 text-sm font-bold transition-all focus-visible:ring-2 focus-visible:ring-[var(--team-primary)] focus-visible:outline-none"
                            :class="
                                isActive(item.href)
                                    ? 'bg-[var(--team-primary)] text-[var(--team-primary-text)] shadow-sm'
                                    : 'text-slate-700 hover:bg-white hover:text-slate-950 hover:shadow-xs dark:text-slate-300 dark:hover:bg-slate-800/80 dark:hover:text-white'
                            "
                        >
                            <component
                                :is="item.icon"
                                class="size-4.5 shrink-0 transition-transform duration-150 group-hover:scale-105"
                            />
                            <span class="truncate">{{ item.title }}</span>
                        </Link>
                    </nav>

                    <p
                        class="mt-6 px-3 text-[11px] font-extrabold tracking-widest text-slate-400 uppercase dark:text-slate-500"
                    >
                        Organização
                    </p>
                    <nav class="mt-2 space-y-1">
                        <Link
                            v-for="item in orgNav"
                            :key="item.href"
                            :href="item.href"
                            :aria-current="
                                isActive(item.href) ? 'page' : undefined
                            "
                            class="group flex min-h-11 items-center gap-3 rounded-xl px-3.5 text-sm font-bold transition-all focus-visible:ring-2 focus-visible:ring-[var(--team-primary)] focus-visible:outline-none"
                            :class="
                                isActive(item.href)
                                    ? 'bg-[var(--team-primary)] text-[var(--team-primary-text)] shadow-sm'
                                    : 'text-slate-700 hover:bg-white hover:text-slate-950 hover:shadow-xs dark:text-slate-300 dark:hover:bg-slate-800/80 dark:hover:text-white'
                            "
                        >
                            <component
                                :is="item.icon"
                                class="size-4.5 shrink-0 transition-transform duration-150 group-hover:scale-105"
                            />
                            <span class="truncate">{{ item.title }}</span>
                        </Link>
                    </nav>

                    <button
                        type="button"
                        class="mt-auto flex min-h-11 items-center gap-3 rounded-xl px-3.5 text-sm font-semibold text-slate-500 transition-colors hover:bg-white hover:text-red-600 dark:text-slate-400 dark:hover:bg-slate-800/80 dark:hover:text-red-400"
                        @click="signOut"
                    >
                        <LogOut class="size-4.5" />
                        Sair
                    </button>
                </div>
            </aside>

            <main
                class="min-w-0 flex-1 px-4 py-5 md:py-7 landscape-short:max-w-none landscape-short:p-2"
            >
                <div
                    v-if="
                        offlineStatus.offline ||
                        offlineStatus.syncing ||
                        offlineStatus.pendingMatches ||
                        offlineStatus.relogin ||
                        offlineStatus.error
                    "
                    class="surface-card mb-4 flex flex-wrap items-center justify-between gap-2 rounded-2xl px-4 py-2.5 text-xs font-semibold text-slate-700 dark:text-slate-200"
                    role="status"
                >
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span
                            v-if="offlineStatus.relogin"
                            class="flex items-center gap-2"
                        >
                            Entre novamente para enviar as partidas.
                            <a
                                href="/login"
                                class="font-bold text-[var(--team-primary)] underline"
                                >Entrar</a
                            >
                        </span>
                        <span
                            v-else-if="offlineStatus.syncing"
                            class="inline-flex items-center gap-2"
                        >
                            <RefreshCw class="size-3.5 animate-spin" />
                            Sincronizando…
                        </span>
                        <span
                            v-else-if="offlineStatus.offline"
                            class="inline-flex items-center gap-2 text-amber-700 dark:text-amber-300"
                        >
                            <CloudOff class="size-3.5" />
                            Sem conexão — modo offline ativo
                        </span>

                        <button
                            v-if="offlineStatus.pendingMatches"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg px-2 py-0.5 text-left font-bold underline decoration-dotted underline-offset-4 transition-colors hover:bg-slate-100 dark:hover:bg-slate-800"
                            :class="
                                offlineStatus.failedMatches > 0
                                    ? 'text-red-700 dark:text-red-300'
                                    : 'text-slate-800 dark:text-slate-100'
                            "
                            data-test="contador-pendentes"
                            @click="openPendingMatchesPanel"
                        >
                            <span>
                                {{ offlineStatus.pendingMatches }} partida(s)
                                aguardando envio
                            </span>
                            <span
                                v-if="offlineStatus.failedMatches > 0"
                                class="rounded-md bg-red-100 px-1.5 py-0.5 text-[10px] font-extrabold text-red-700 no-underline dark:bg-red-950/70 dark:text-red-300"
                            >
                                {{ offlineStatus.failedMatches }} com falha
                            </span>
                        </button>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            v-if="offlineStatus.pendingMatches"
                            type="button"
                            class="rounded-lg border border-slate-300 bg-white px-2.5 py-1 font-bold text-slate-700 transition-colors hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                            data-test="abrir-partidas-pendentes"
                            @click="openPendingMatchesPanel"
                        >
                            Ver fila
                        </button>
                        <button
                            v-if="
                                !offlineStatus.offline &&
                                !offlineStatus.relogin &&
                                offlineStatus.retryableMatches > 0
                            "
                            type="button"
                            class="rounded-lg bg-[var(--team-primary-soft)] px-2.5 py-1 font-bold text-[var(--team-primary)] transition-colors hover:brightness-95"
                            @click="syncNow()"
                        >
                            Enviar agora
                        </button>
                    </div>
                    <span
                        v-if="offlineStatus.error"
                        class="w-full text-red-700 dark:text-red-300"
                    >
                        {{ offlineStatus.error }}
                    </span>
                </div>
                <slot />
            </main>
        </div>

        <nav
            class="fixed inset-x-0 bottom-0 z-20 grid grid-cols-5 border-t border-slate-200/80 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md md:hidden dark:border-slate-800 dark:bg-slate-900/95 landscape-short:hidden"
            aria-label="Navegação principal"
        >
            <Link
                v-for="item in mobileBottomNav"
                :key="item.href"
                :href="item.href"
                :aria-current="isActive(item.href) ? 'page' : undefined"
                class="relative flex min-h-16 flex-col items-center justify-center gap-1 px-1 text-[10px] font-bold transition-colors focus-visible:ring-2 focus-visible:ring-[var(--team-primary)] focus-visible:outline-none focus-visible:ring-inset"
                :class="
                    isActive(item.href)
                        ? 'text-[var(--team-primary)] dark:text-white'
                        : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200'
                "
            >
                <span
                    v-if="isActive(item.href)"
                    class="absolute top-0 h-0.5 w-8 rounded-b-full bg-[var(--team-primary)]"
                />
                <span
                    class="flex size-8 items-center justify-center rounded-xl transition-colors"
                    :class="
                        isActive(item.href)
                            ? 'bg-[var(--team-primary-soft)]'
                            : ''
                    "
                >
                    <component :is="item.icon" class="size-4.5" />
                </span>
                <span class="truncate">{{ item.compact }}</span>
            </Link>
            <button
                type="button"
                class="relative flex min-h-16 flex-col items-center justify-center gap-1 px-1 text-[10px] font-bold transition-colors focus-visible:ring-2 focus-visible:ring-[var(--team-primary)] focus-visible:outline-none focus-visible:ring-inset"
                :class="
                    open || isMoreActive
                        ? 'text-[var(--team-primary)] dark:text-white'
                        : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-200'
                "
                aria-label="Mais opções"
                @click="open = !open"
            >
                <span
                    v-if="open || isMoreActive"
                    class="absolute top-0 h-0.5 w-8 rounded-b-full bg-[var(--team-primary)]"
                />
                <span
                    class="flex size-8 items-center justify-center rounded-xl transition-colors"
                    :class="
                        open || isMoreActive
                            ? 'bg-[var(--team-primary-soft)]'
                            : ''
                    "
                >
                    <X v-if="open" class="size-4.5" />
                    <Menu v-else class="size-4.5" />
                </span>
                <span>Mais</span>
            </button>
        </nav>

        <PendingMatchesPanel />
        <ToastContainer />
        <ConfirmDialog />
    </div>
</template>
