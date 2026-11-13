import { computed, ref } from 'vue';
import {
    acknowledgePresence,
    pendingPresence,
    saveIdentity,
    savePlayers,
} from '@/lib/offlineStorage';
import { flushQueue, loadQueue } from '@/lib/matchQueue';
import type { Player } from '@/types/hockey';
import { initialInertiaPage, type InertiaPage } from '@/lib/inertiaPage';

interface Identity {
    id: number;
    name: string;
    logo_url: string | null;
    primary_color: string;
    secondary_color: string;
    primary_text: string;
}

const account = ref<Identity | null>(null);
const syncing = ref(false);
const relogin = ref(false);
const lastError = ref('');
const revision = ref(0);
const online = ref(navigator.onLine);
let syncRequested = false;
let warmed = false;
let warmedAccountId: number | null = null;
let listenersStarted = false;
let currentInertiaVersion: string | null = null;
export const offlineStatus = computed(() => ({
    revision: revision.value,
    offline: !online.value,
    syncing: syncing.value,
    relogin: relogin.value,
    pendingMatches: account.value
        ? loadQueue(localStorage, account.value.id).length
        : 0,
    error: lastError.value,
}));
export const refreshOfflineStatus = (): void => {
    revision.value++;
};

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function replaceCsrf(token: string): void {
    const element = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    );
    if (element) element.content = token;
}

async function session(identity: Identity): Promise<boolean> {
    const response = await fetch('/sessao/estado', {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });
    if (
        response.status === 401 ||
        response.status === 419 ||
        response.redirected
    ) {
        relogin.value = true;
        return false;
    }
    if (!response.ok) {
        lastError.value = `Não foi possível verificar a sessão (HTTP ${response.status}). Tente novamente.`;
        return false;
    }
    const state = (await response.json()) as {
        authenticated: boolean;
        account_id: number;
        csrf_token: string;
    };
    if (!state.authenticated || state.account_id !== identity.id) {
        relogin.value = true;
        return false;
    }
    replaceCsrf(state.csrf_token);
    return true;
}

export function showOfflineNavigationMessage(): void {
    lastError.value =
        'Sem conexão. Offline funcionam Jogadores, Sorteio e Partida.';
}

export async function syncNow(): Promise<
    Array<{ client_uuid: string; url?: string }>
> {
    const identity = account.value;
    if (!identity) {
        lastError.value =
            'Não foi possível sincronizar: a conta não está carregada.';
        refreshOfflineStatus();
        return [];
    }
    if (!navigator.onLine) return [];
    if (syncing.value) {
        syncRequested = true;
        return [];
    }
    syncing.value = true;
    lastError.value = '';
    const uploaded: Array<{ client_uuid: string; url?: string }> = [];
    try {
        do {
            syncRequested = false;
            if (!(await session(identity))) break;
            if (account.value?.id !== identity.id) break;
            const changes = pendingPresence(localStorage, identity.id);
            if (changes.length) {
                const response = await fetch(
                    '/jogadores/presenca/sincronizar',
                    {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': csrfToken(),
                        },
                        body: JSON.stringify({ changes }),
                    },
                );
                if (response.status === 401 || response.status === 419) {
                    relogin.value = true;
                    break;
                }
                if (!response.ok) {
                    lastError.value =
                        'Não foi possível sincronizar a presença.';
                    break;
                }
                const data = (await response.json()) as { processed: number[] };
                acknowledgePresence(
                    localStorage,
                    identity.id,
                    changes.filter((change) =>
                        data.processed.includes(change.player_id),
                    ),
                );
            }
            const queue = loadQueue(localStorage, identity.id);
            const result = await flushQueue(
                localStorage,
                identity.id,
                async (item) => {
                    try {
                        const response = await fetch('/partidas', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-TOKEN': csrfToken(),
                            },
                            body: JSON.stringify({
                                ...item.payload,
                                client_uuid: item.client_uuid,
                            }),
                        });
                        const data = (await response
                            .clone()
                            .json()
                            .catch(() => null)) as {
                            message?: string;
                            errors?: Record<string, string[]>;
                            url?: string;
                        } | null;
                        return {
                            status: response.status,
                            ok: response.status >= 200 && response.status < 300,
                            url: data?.url,
                            error: data?.errors
                                ? Object.values(data.errors)[0]?.[0]
                                : data?.message,
                        };
                    } catch {
                        return {
                            status: 0,
                            ok: false,
                            error: 'Sem conexão para enviar a partida.',
                        };
                    }
                },
            );
            uploaded.push(...result.uploaded);
            const initialIds = new Set(queue.map((item) => item.client_uuid));
            if (
                loadQueue(localStorage, identity.id).some(
                    (item) => !initialIds.has(item.client_uuid),
                )
            )
                syncRequested = true;
            relogin.value = result.relogin;
            lastError.value = result.error ?? '';
        } while (syncRequested && navigator.onLine && !relogin.value);
        refreshOfflineStatus();
    } catch {
        lastError.value =
            'A sincronização será tentada novamente quando houver conexão.';
    } finally {
        syncing.value = false;
    }
    return uploaded;
}

export function updateInertiaPage(page: InertiaPage | null): void {
    currentInertiaVersion =
        typeof page?.version === 'string' ? page.version : '';
}

function inertiaVersion(): string {
    if (currentInertiaVersion !== null) return currentInertiaVersion;
    updateInertiaPage(initialInertiaPage());
    return currentInertiaVersion ?? '';
}

async function warm(url: string, inertia = false): Promise<Response | null> {
    try {
        const response = await fetch(url, {
            headers: inertia
                ? {
                      'X-Inertia': 'true',
                      'X-Requested-With': 'XMLHttpRequest',
                      'X-Inertia-Version': inertiaVersion(),
                  }
                : {},
            credentials: 'same-origin',
            cache: 'no-store',
        });
        return response.ok ? response : null;
    } catch {
        return null;
    }
}

export async function warmOfflinePages(
    identity: Identity,
    force = false,
): Promise<void> {
    if (!navigator.onLine || (warmed && !force)) return;
    warmed = true;
    warmedAccountId = identity.id;
    const pages = ['/jogadores', '/sorteio', '/partida'];
    // O HTML só pode ser guardado pelo próprio service worker; sem controller
    // (primeira visita ou dev) o aquecimento roda de novo no controllerchange.
    navigator.serviceWorker?.controller?.postMessage({
        type: 'WARM_PAGES',
        urls: pages,
    });
    for (const url of pages) {
        const inertia = await warm(url, true);
        if (url === '/jogadores' && inertia) {
            const data = (await inertia
                .clone()
                .json()
                .catch(() => null)) as {
                props?: { players?: Player[] };
            } | null;
            if (data?.props?.players) {
                savePlayers(localStorage, identity.id, data.props.players);
            }
        }
    }
}

export function startOfflineSync(
    identity: Identity | null,
    warmPages = true,
): void {
    if (!listenersStarted) {
        listenersStarted = true;
        window.addEventListener('online', () => {
            online.value = true;
            if (account.value) void syncNow();
        });
        window.addEventListener('offline', () => {
            online.value = false;
        });
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && account.value)
                void syncNow();
        });
    }
    if (!identity) {
        account.value = null;
        warmed = false;
        warmedAccountId = null;
        refreshOfflineStatus();
        return;
    }
    if (warmedAccountId !== identity.id) warmed = false;
    account.value = identity;
    saveIdentity(localStorage, identity);
    if (warmPages) void warmOfflinePages(identity);
    void syncNow();
}
