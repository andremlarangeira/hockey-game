import type { StorageLike } from '@/lib/offlineStorage';

export type QueuedMatchStatus = 'pending' | 'failed';

export interface QueuedMatch {
    client_uuid: string;
    payload: Record<string, unknown>;
    queued_at: string;
    status?: QueuedMatchStatus;
    permanent_failure?: boolean;
    http_status?: number;
    error?: string;
    attempts?: number;
    last_attempt_at?: string;
}

export const queueKey = (accountId: number | string) =>
    `hockey-partidas-fila:${accountId}`;

export function isPermanentFailureStatus(status: number): boolean {
    return (
        status >= 400 &&
        status < 500 &&
        status !== 401 &&
        status !== 419 &&
        status !== 429
    );
}

export function isPermanentFailure(
    item: Pick<QueuedMatch, 'status' | 'permanent_failure'>,
): boolean {
    return item.permanent_failure === true || item.status === 'failed';
}

export function defaultHttpErrorMessage(status: number): string {
    if (status === 0) return 'Sem conexão para enviar a partida.';
    if (status === 401 || status === 419)
        return 'Sessão expirada. Entre novamente para enviar a partida.';
    if (status === 403)
        return 'Você não tem permissão para salvar esta partida.';
    if (status === 404)
        return 'Endereço de envio não encontrado no servidor (HTTP 404).';
    if (status === 422)
        return 'Os dados da partida foram recusados pela validação do servidor.';
    if (status === 429)
        return 'Muitas tentativas em sequência. Aguarde alguns instantes.';
    if (status >= 400 && status < 500)
        return `A partida foi recusada pelo servidor (HTTP ${status}).`;
    if (status >= 500)
        return `Erro temporário no servidor (HTTP ${status}). Tentaremos novamente.`;
    return 'Não foi possível enviar a partida.';
}

export function extractMatchError(
    data:
        | {
              message?: string;
              errors?: Record<string, string[] | string>;
          }
        | null
        | undefined,
    status: number,
): string {
    if (data?.errors && typeof data.errors === 'object') {
        const messages: string[] = [];
        for (const value of Object.values(data.errors)) {
            if (Array.isArray(value)) {
                for (const item of value) {
                    if (typeof item === 'string' && item.trim() !== '') {
                        messages.push(item.trim());
                    }
                }
            } else if (typeof value === 'string' && value.trim() !== '') {
                messages.push(value.trim());
            }
        }
        if (messages.length > 0) {
            return messages.join(' ');
        }
    }

    if (typeof data?.message === 'string' && data.message.trim() !== '') {
        const msg = data.message.trim();
        const genericEnglish = [
            'The given data was invalid.',
            'Unprocessable Content',
            'Unprocessable Entity',
            'Server Error',
            'Too Many Attempts.',
            'CSRF token mismatch.',
            'Unauthenticated.',
            'This action is unauthorized.',
            'Not Found',
        ];
        if (!genericEnglish.includes(msg)) {
            return msg;
        }
    }

    return defaultHttpErrorMessage(status);
}

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
        status: 'pending',
        permanent_failure: false,
        attempts: 0,
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

export function retryQueuedMatch(
    storage: StorageLike,
    accountId: number | string,
    clientUuid: string,
): QueuedMatch | null {
    let updatedItem: QueuedMatch | null = null;
    updateQueuedMatch(storage, accountId, clientUuid, (current) => {
        updatedItem = {
            ...current,
            status: 'pending',
            permanent_failure: false,
        };
        return updatedItem;
    });
    return updatedItem;
}

export function removeQueuedMatch(
    storage: StorageLike,
    accountId: number | string,
    clientUuid: string,
): void {
    updateQueuedMatch(storage, accountId, clientUuid, () => null);
}

export async function flushQueue(
    storage: StorageLike,
    accountId: number | string,
    send: (
        item: QueuedMatch,
    ) => Promise<{ status: number; ok: boolean; error?: string; url?: string }>,
    options?: { onlyClientUuid?: string },
): Promise<{
    remaining: number;
    pending: number;
    failed: number;
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
            options?.onlyClientUuid &&
            item.client_uuid !== options.onlyClientUuid
        ) {
            continue;
        }
        const currentInStorage = loadQueue(storage, accountId).find(
            (current) => current.client_uuid === item.client_uuid,
        );
        if (!currentInStorage) continue;
        if (isPermanentFailure(currentInStorage)) continue;

        const attemptedAt = new Date().toISOString();
        const response = await send(currentInStorage);
        if (response.ok && response.status >= 200 && response.status < 300) {
            updateQueuedMatch(storage, accountId, item.client_uuid, () => null);
            uploaded.push({ client_uuid: item.client_uuid, url: response.url });
            continue;
        }
        const message =
            response.error && response.error.trim() !== ''
                ? response.error
                : defaultHttpErrorMessage(response.status);
        if (response.status === 401 || response.status === 419) {
            relogin = true;
            updateQueuedMatch(
                storage,
                accountId,
                item.client_uuid,
                (current) => ({
                    ...current,
                    status: 'pending',
                    permanent_failure: false,
                    http_status: response.status,
                    error: message,
                    attempts: (current.attempts ?? 0) + 1,
                    last_attempt_at: attemptedAt,
                }),
            );
            break;
        }
        const permanent = isPermanentFailureStatus(response.status);
        error = message ?? error;
        updateQueuedMatch(storage, accountId, item.client_uuid, (current) => ({
            ...current,
            status: permanent ? 'failed' : 'pending',
            permanent_failure: permanent,
            http_status: response.status,
            error: message,
            attempts: (current.attempts ?? 0) + 1,
            last_attempt_at: attemptedAt,
        }));
    }
    const finalQueue = loadQueue(storage, accountId);
    const failedCount = finalQueue.filter(isPermanentFailure).length;
    return {
        remaining: finalQueue.length,
        pending: finalQueue.length - failedCount,
        failed: failedCount,
        relogin,
        error,
        uploaded,
    };
}
