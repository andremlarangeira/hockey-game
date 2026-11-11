import { onMounted, ref } from 'vue';

export type Appearance = 'light' | 'dark' | 'system';

const isAppearance = (value: unknown): value is Appearance =>
    value === 'light' || value === 'dark' || value === 'system';

const getCookie = (name: string): string | null => {
    if (typeof document === 'undefined') {
        return null;
    }

    const prefix = `${name}=`;
    const match = document.cookie
        .split(';')
        .map((part) => part.trim())
        .find((part) => part.startsWith(prefix));

    return match ? decodeURIComponent(match.slice(prefix.length)) : null;
};

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const getStoredAppearance = (): Appearance => {
    if (typeof window === 'undefined') {
        return 'system';
    }

    const fromCookie = getCookie('appearance');
    if (isAppearance(fromCookie)) {
        return fromCookie;
    }

    const fromStorage = localStorage.getItem('appearance');
    if (isAppearance(fromStorage)) {
        return fromStorage;
    }

    return 'system';
};

const mediaQuery = (): MediaQueryList | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

export function updateTheme(value: Appearance): void {
    if (typeof window === 'undefined') {
        return;
    }

    const isDark =
        value === 'system'
            ? (mediaQuery()?.matches ?? false)
            : value === 'dark';

    document.documentElement.classList.toggle('dark', isDark);
}

const appearance = ref<Appearance>(getStoredAppearance());
let listenerAttached = false;

const handleSystemThemeChange = (): void => {
    const current = getStoredAppearance();
    appearance.value = current;
    updateTheme(current);
};

const ensureSystemListener = (): void => {
    const mq = mediaQuery();
    if (!mq || listenerAttached) {
        return;
    }

    mq.addEventListener('change', handleSystemThemeChange);
    listenerAttached = true;
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const saved = getStoredAppearance();
    appearance.value = saved;
    updateTheme(saved);
    ensureSystemListener();
}

export function useAppearance() {
    onMounted(() => {
        initializeTheme();
    });

    function updateAppearance(value: Appearance): void {
        appearance.value = value;

        if (typeof window !== 'undefined') {
            localStorage.setItem('appearance', value);
        }

        setCookie('appearance', value);
        updateTheme(value);
        ensureSystemListener();
    }

    return {
        appearance,
        updateAppearance,
    };
}
