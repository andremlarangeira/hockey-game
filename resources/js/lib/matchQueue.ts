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

export const championshipQueueKey = (accountId: number | string) =>
    `hockey-campeonato-eventos-fila:${accountId}`;

export type ChampionshipEventActionType = 'goal' | 'remove_event' | 'finish';

export interface QueuedChampionshipEventMeta {
    championship_name?: string;
    home_name?: string;
    away_name?: string;
    home_color?: string;
    away_color?: string;
    summary?: string;
    target_client_uuid?: string | null;
    target_event_id?: number | null;
    side?: 'home' | 'away';
    team_id?: number | null;
    scorer_id?: number | null;
    scorer_name?: string | null;
    first_assist_id?: number | null;
    first_assist_name?: string | null;
    second_assist_id?: number | null;
    second_assist_name?: string | null;
    period?: number;
    is_overtime?: boolean;
    elapsed_seconds?: number;
    remaining_seconds?: number;
}

export interface QueuedChampionshipEvent {
    client_uuid: string;
    championship_id: number;
    game_id: number;
    action: ChampionshipEventActionType;
    payload: Record<string, unknown>;
    meta?: QueuedChampionshipEventMeta;
    queued_at: string;
    status?: QueuedMatchStatus;
    permanent_failure?: boolean;
    http_status?: number;
    error?: string;
    attempts?: number;
    last_attempt_at?: string;
}

export function generateClientUuid(): string {
    if (
        typeof crypto !== 'undefined' &&
        typeof crypto.randomUUID === 'function'
    ) {
        return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

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
        client_uuid: generateClientUuid(),
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

export function loadChampionshipQueue(
    storage: StorageLike,
    accountId: number | string,
    gameId?: number,
): QueuedChampionshipEvent[] {
    try {
        const raw: unknown = JSON.parse(
            storage.getItem(championshipQueueKey(accountId)) ?? '[]',
        );
        const list = Array.isArray(raw)
            ? (raw as QueuedChampionshipEvent[])
            : [];
        return typeof gameId === 'number'
            ? list.filter((item) => item.game_id === gameId)
            : list;
    } catch {
        return [];
    }
}

export function enqueueChampionshipEvent(
    storage: StorageLike,
    accountId: number | string,
    input: {
        client_uuid?: string;
        championship_id: number;
        game_id: number;
        action: ChampionshipEventActionType;
        payload?: Record<string, unknown>;
        meta?: QueuedChampionshipEventMeta;
    },
): QueuedChampionshipEvent {
    const queue = loadChampionshipQueue(storage, accountId);
    const clientUuid =
        typeof input.client_uuid === 'string' && input.client_uuid.trim() !== ''
            ? input.client_uuid.trim()
            : generateClientUuid();

    const existing = queue.find((item) => item.client_uuid === clientUuid);
    if (existing) {
        return existing;
    }

    if (input.action === 'finish') {
        const existingFinish = queue.find(
            (item) =>
                item.game_id === input.game_id &&
                item.action === 'finish' &&
                !isPermanentFailure(item),
        );
        if (existingFinish) {
            return existingFinish;
        }
    }

    const item: QueuedChampionshipEvent = {
        client_uuid: clientUuid,
        championship_id: input.championship_id,
        game_id: input.game_id,
        action: input.action,
        payload: input.payload ?? {},
        meta: input.meta,
        queued_at: new Date().toISOString(),
        status: 'pending',
        permanent_failure: false,
        attempts: 0,
    };
    storage.setItem(
        championshipQueueKey(accountId),
        JSON.stringify([...queue, item]),
    );
    return item;
}

function updateQueuedChampionshipEvent(
    storage: StorageLike,
    accountId: number | string,
    clientUuid: string,
    update: (item: QueuedChampionshipEvent) => QueuedChampionshipEvent | null,
): void {
    const queue = loadChampionshipQueue(storage, accountId);
    const next = queue.flatMap((item) => {
        if (item.client_uuid !== clientUuid) return [item];
        const updated = update(item);
        return updated ? [updated] : [];
    });
    storage.setItem(championshipQueueKey(accountId), JSON.stringify(next));
}

export function retryQueuedChampionshipEvent(
    storage: StorageLike,
    accountId: number | string,
    clientUuid: string,
): QueuedChampionshipEvent | null {
    let updatedItem: QueuedChampionshipEvent | null = null;
    updateQueuedChampionshipEvent(
        storage,
        accountId,
        clientUuid,
        (current) => {
            updatedItem = {
                ...current,
                status: 'pending',
                permanent_failure: false,
            };
            return updatedItem;
        },
    );
    return updatedItem;
}

export function removeQueuedChampionshipEvent(
    storage: StorageLike,
    accountId: number | string,
    clientUuid: string,
): void {
    updateQueuedChampionshipEvent(storage, accountId, clientUuid, () => null);
}

function resolveQueuedRemoveEventTarget(
    storage: StorageLike,
    accountId: number | string,
    gameId: number,
    goalClientUuid: string,
    serverEventId: number,
): void {
    const queue = loadChampionshipQueue(storage, accountId);
    let changed = false;
    const next = queue.map((item) => {
        if (
            item.game_id === gameId &&
            item.action === 'remove_event' &&
            (item.meta?.target_client_uuid === goalClientUuid ||
                item.payload.target_client_uuid === goalClientUuid)
        ) {
            changed = true;
            return {
                ...item,
                payload: {
                    ...item.payload,
                    event_id: serverEventId,
                },
                meta: {
                    ...item.meta,
                    target_event_id: serverEventId,
                },
            };
        }
        return item;
    });
    if (changed) {
        storage.setItem(championshipQueueKey(accountId), JSON.stringify(next));
    }
}

export async function flushChampionshipQueue(
    storage: StorageLike,
    accountId: number | string,
    send: (
        item: QueuedChampionshipEvent,
    ) => Promise<{
        status: number;
        ok: boolean;
        error?: string;
        event_id?: number;
        duplicate?: boolean;
    }>,
    options?: { onlyClientUuid?: string; gameId?: number },
): Promise<{
    remaining: number;
    pending: number;
    failed: number;
    relogin: boolean;
    error?: string;
    uploaded: Array<{
        client_uuid: string;
        championship_id: number;
        game_id: number;
        action: ChampionshipEventActionType;
        event_id?: number;
        duplicate?: boolean;
    }>;
}> {
    const queue = loadChampionshipQueue(storage, accountId);
    let relogin = false;
    let error: string | undefined;
    const blockedGames = new Set<number>();
    const uploaded: Array<{
        client_uuid: string;
        championship_id: number;
        game_id: number;
        action: ChampionshipEventActionType;
        event_id?: number;
        duplicate?: boolean;
    }> = [];

    for (let index = 0; index < queue.length; index++) {
        const item = queue[index]!;
        if (
            typeof options?.gameId === 'number' &&
            item.game_id !== options.gameId
        ) {
            continue;
        }
        const currentInStorage = loadChampionshipQueue(storage, accountId).find(
            (current) => current.client_uuid === item.client_uuid,
        );
        if (!currentInStorage) continue;

        if (blockedGames.has(currentInStorage.game_id)) {
            continue;
        }

        if (isPermanentFailure(currentInStorage)) {
            blockedGames.add(currentInStorage.game_id);
            continue;
        }

        if (
            options?.onlyClientUuid &&
            currentInStorage.client_uuid !== options.onlyClientUuid
        ) {
            blockedGames.add(currentInStorage.game_id);
            continue;
        }

        const attemptedAt = new Date().toISOString();
        const response = await send(currentInStorage);
        if (response.ok && response.status >= 200 && response.status < 300) {
            updateQueuedChampionshipEvent(
                storage,
                accountId,
                item.client_uuid,
                () => null,
            );
            if (
                currentInStorage.action === 'goal' &&
                typeof response.event_id === 'number' &&
                response.event_id > 0
            ) {
                resolveQueuedRemoveEventTarget(
                    storage,
                    accountId,
                    currentInStorage.game_id,
                    currentInStorage.client_uuid,
                    response.event_id,
                );
            }
            uploaded.push({
                client_uuid: item.client_uuid,
                championship_id: currentInStorage.championship_id,
                game_id: currentInStorage.game_id,
                action: currentInStorage.action,
                event_id: response.event_id,
                duplicate: response.duplicate,
            });
            continue;
        }

        const message =
            response.error && response.error.trim() !== ''
                ? response.error
                : defaultHttpErrorMessage(response.status);

        if (response.status === 401 || response.status === 419) {
            relogin = true;
            updateQueuedChampionshipEvent(
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
        blockedGames.add(currentInStorage.game_id);
        updateQueuedChampionshipEvent(
            storage,
            accountId,
            item.client_uuid,
            (current) => ({
                ...current,
                status: permanent ? 'failed' : 'pending',
                permanent_failure: permanent,
                http_status: response.status,
                error: message,
                attempts: (current.attempts ?? 0) + 1,
                last_attempt_at: attemptedAt,
            }),
        );
    }

    const finalQueue = loadChampionshipQueue(storage, accountId);
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

export interface ProjectableGameEvent {
    id: number;
    championship_game_id: number;
    client_uuid?: string | null;
    type: 'goal' | 'shootout_attempt' | 'period_mark';
    side: 'home' | 'away' | null;
    team_id: number | null;
    primary_player_id: number | null;
    primary_player_name: string | null;
    first_assist_player_id: number | null;
    first_assist_player_name: string | null;
    second_assist_player_id: number | null;
    second_assist_player_name: string | null;
    period: number | null;
    is_overtime: boolean;
    elapsed_seconds: number;
    remaining_seconds: number;
    shootout_round: number | null;
    shootout_scored: boolean | null;
    period_mark_type: string | null;
    recorded_at: string | null;
    pending?: boolean;
    failed?: boolean;
    error?: string | null;
}

export function projectChampionshipGameWithQueue(
    baseGame: {
        id: number;
        status: 'scheduled' | 'live' | 'finished';
        home_regular_score: number;
        away_regular_score: number;
        went_to_overtime: boolean;
    },
    baseEvents: ProjectableGameEvent[],
    queuedEvents: QueuedChampionshipEvent[],
): {
    status: 'scheduled' | 'live' | 'finished';
    home_regular_score: number;
    away_regular_score: number;
    went_to_overtime: boolean;
    has_queued_finish: boolean;
    events: ProjectableGameEvent[];
} {
    const gameQueue = queuedEvents.filter(
        (item) => item.game_id === baseGame.id,
    );
    if (gameQueue.length === 0) {
        return {
            status: baseGame.status,
            home_regular_score: baseGame.home_regular_score,
            away_regular_score: baseGame.away_regular_score,
            went_to_overtime: baseGame.went_to_overtime,
            has_queued_finish: false,
            events: baseEvents.map((ev) => ({
                ...ev,
                pending: false,
                failed: false,
                error: null,
            })),
        };
    }

    let events: ProjectableGameEvent[] = baseEvents.map((ev) => ({
        ...ev,
        pending: false,
        failed: false,
        error: null,
    }));
    const seenGoalUuids = new Set<string>();
    for (const ev of events) {
        if (ev.client_uuid) {
            seenGoalUuids.add(ev.client_uuid);
        }
    }

    let hasQueuedFinish = false;

    for (let idx = 0; idx < gameQueue.length; idx++) {
        const item = gameQueue[idx]!;
        if (item.action === 'goal') {
            if (seenGoalUuids.has(item.client_uuid)) {
                continue;
            }
            seenGoalUuids.add(item.client_uuid);
            const side =
                item.meta?.side ??
                (item.payload.side === 'away' ? 'away' : 'home');
            events.push({
                id: -(idx + 1),
                championship_game_id: baseGame.id,
                client_uuid: item.client_uuid,
                type: 'goal',
                side,
                team_id: item.meta?.team_id ?? null,
                primary_player_id:
                    Number(
                        item.meta?.scorer_id ?? item.payload.scorer_id ?? 0,
                    ) || null,
                primary_player_name: item.meta?.scorer_name ?? 'Atleta',
                first_assist_player_id:
                    (item.meta?.first_assist_id ??
                        (item.payload.first_assist_id as
                            | number
                            | null
                            | undefined)) ??
                    null,
                first_assist_player_name: item.meta?.first_assist_name ?? null,
                second_assist_player_id:
                    (item.meta?.second_assist_id ??
                        (item.payload.second_assist_id as
                            | number
                            | null
                            | undefined)) ??
                    null,
                second_assist_player_name:
                    item.meta?.second_assist_name ?? null,
                period:
                    Number(item.meta?.period ?? item.payload.period ?? 1) || 1,
                is_overtime: Boolean(
                    item.meta?.is_overtime ?? item.payload.is_overtime,
                ),
                elapsed_seconds:
                    Number(
                        item.meta?.elapsed_seconds ??
                            item.payload.elapsed_seconds ??
                            0,
                    ) || 0,
                remaining_seconds:
                    Number(
                        item.meta?.remaining_seconds ??
                            item.payload.remaining_seconds ??
                            0,
                    ) || 0,
                shootout_round: null,
                shootout_scored: null,
                period_mark_type: null,
                recorded_at: item.queued_at,
                pending: !isPermanentFailure(item),
                failed: isPermanentFailure(item),
                error: item.error ?? null,
            });
        } else if (item.action === 'remove_event') {
            const targetEventId =
                item.meta?.target_event_id ??
                (typeof item.payload.event_id === 'number'
                    ? item.payload.event_id
                    : null);
            const targetClientUuid =
                item.meta?.target_client_uuid ??
                (typeof item.payload.target_client_uuid === 'string'
                    ? item.payload.target_client_uuid
                    : null);
            events = events.filter((ev) => {
                if (
                    typeof targetEventId === 'number' &&
                    targetEventId > 0 &&
                    ev.id === targetEventId
                ) {
                    return false;
                }
                if (
                    typeof targetClientUuid === 'string' &&
                    targetClientUuid !== '' &&
                    ev.client_uuid === targetClientUuid
                ) {
                    return false;
                }
                return true;
            });
        } else if (item.action === 'finish') {
            hasQueuedFinish = true;
        }
    }

    const homeRegular = events.filter(
        (ev) => ev.type === 'goal' && ev.side === 'home',
    ).length;
    const awayRegular = events.filter(
        (ev) => ev.type === 'goal' && ev.side === 'away',
    ).length;
    const wentToOvertime =
        baseGame.went_to_overtime ||
        events.some((ev) => Boolean(ev.is_overtime));

    return {
        status: hasQueuedFinish ? 'finished' : baseGame.status,
        home_regular_score: homeRegular,
        away_regular_score: awayRegular,
        went_to_overtime: wentToOvertime,
        has_queued_finish: hasQueuedFinish,
        events,
    };
}
