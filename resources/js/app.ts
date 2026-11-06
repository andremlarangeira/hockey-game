import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';

const appName = 'Na Quadra';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        if (name.startsWith('auth/')) return AuthLayout;
        if (name === 'settings/Team') return AppLayout;
        if (name.startsWith('settings/')) return [AppLayout, SettingsLayout];
        return AppLayout;
    },
    progress: { color: '#166534' },
});
