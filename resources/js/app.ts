import { createInertiaApp, router } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import {
    showOfflineNavigationMessage,
    startOfflineSync,
    warmOfflinePages,
} from '@/lib/offlineSync';

const appName = 'Hockey Game';

initializeTheme();

router.on('networkError', (event) => {
    if (!navigator.onLine) {
        event.preventDefault();
        showOfflineNavigationMessage();
    }
});

router.on('httpException', (event) => {
    if (!navigator.onLine) {
        event.preventDefault();
        showOfflineNavigationMessage();
    }
});

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        if (name.startsWith('auth/')) return AuthLayout;
        if (name === 'settings/Team') return AppLayout;
        if (name.startsWith('settings/')) return [AppLayout, SettingsLayout];
        return AppLayout;
    },
    progress: { color: '#166534' },
}).then(() => {
    const raw =
        document.querySelector<HTMLElement>('[data-page]')?.dataset.page;
    const team = raw
        ? ((
              JSON.parse(raw) as {
                  props?: { team?: Parameters<typeof startOfflineSync>[0] };
              }
          ).props?.team ?? null)
        : null;
    const usesServiceWorker =
        import.meta.env.PROD && 'serviceWorker' in navigator;
    startOfflineSync(team, !usesServiceWorker);
    if (usesServiceWorker)
        void navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .then((registration) => {
                registration.addEventListener('updatefound', () => {
                    const worker = registration.installing;
                    worker?.addEventListener('statechange', () => {
                        if (
                            worker.state === 'installed' &&
                            navigator.serviceWorker.controller &&
                            location.pathname !== '/partida' &&
                            window.confirm(
                                'Há uma nova versão do Hockey Game. Atualizar agora?',
                            )
                        )
                            worker.postMessage({ type: 'SKIP_WAITING' });
                    });
                });
                navigator.serviceWorker.addEventListener(
                    'controllerchange',
                    () => {
                        if (team) void warmOfflinePages(team, true);
                    },
                );
                void navigator.serviceWorker.ready.then((ready) => {
                    ready.active?.postMessage({ type: 'PRECACHE_ASSETS' });
                    if (team) void warmOfflinePages(team);
                });
            });
});
