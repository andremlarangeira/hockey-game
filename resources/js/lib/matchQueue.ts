import type { StorageLike } from '@/lib/offlineStorage';

export interface QueuedMatch {
    client_uuid: string;
    payload: Record<string, unknown>;
    queued_at: string;
    error?: string;
}

export const queueKey = (accountId: number | string) =>
    `hockey-partidas-fila:${accountId}`;

export function loadQueue(
    storage: StorageLike,
    accountId: number | string,
): QueuedMatch[] {
    try {
        const queue: unknown = JSON.parse(
            storage.getItem(queueKey(accountId)) ?? '[]',
        );
        return Array.isArray(queue) ? (queue as QueuedMatch[]) : [];
    } catch {
        return [];
    }
}

export function enqueueMatch(
    storage: StorageLike,
    accountId: number | string,
    payload: Record<string, unknown>,
): QueuedMatch {
    const item: QueuedMatch = {
        client_uuid: crypto.randomUUID(),
        payload,
        queued_at: new Date().toISOString(),
    };
    const queue = loadQueue(storage, accountId);
    storage.setItem(queueKey(accountId), JSON.stringify([...queue, item]));
    return item;
}

function updateQueuedMatch(
    storage: StorageLike,
    accountId: number | string,
    clientUuid: string,
    update: (item: QueuedMatch) => QueuedMatch | null,
): void {
    const queue = loadQueue(storage, accountId);
    const next = queue.flatMap((item) => {
        if (item.client_uuid !== clientUuid) return [item];
        const updated = update(item);
        return updated ? [updated] : [];
    });
    storage.setItem(queueKey(accountId), JSON.stringify(next));
}

export async function flushQueue(
    storage: StorageLike,
    accountId: number | string,
    send: (
        item: QueuedMatch,
    ) => Promise<{ status: number; ok: boolean; error?: string; url?: string }>,
): Promise<{
    remaining: number;
    relogin: boolean;
    error?: string;
    uploaded: Array<{ client_uuid: string; url?: string }>;
}> {
    const queue = loadQueue(storage, accountId);
    let relogin = false;
    let error: string | undefined;
    const uploaded: Array<{ client_uuid: string; url?: string }> = [];
    for (let index = 0; index < queue.length; index++) {
        const item = queue[index]!;
        if (
            !loadQueue(storage, accountId).some(
                (current) => current.client_uuid === item.client_uuid,
            )
        )
            continue;
        const response = await send(item);
        if (response.ok && response.status >= 200 && response.status < 300) {
            updateQueuedMatch(storage, accountId, item.client_uuid, () => null);
            uploaded.push({ client_uuid: item.client_uuid, url: response.url });
            continue;
        }
        if (response.status === 401 || response.status === 419) {
            relogin = true;
            break;
        }
        error = response.error ?? error;
        updateQueuedMatch(storage, accountId, item.client_uuid, (current) => ({
            ...current,
            error: response.error,
        }));
    }
    return {
        remaining: loadQueue(storage, accountId).length,
        relogin,
        error,
        uploaded,
    };
}
