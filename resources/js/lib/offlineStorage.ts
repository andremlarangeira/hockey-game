import type { Player } from '@/types/hockey';

export interface StorageLike {
    getItem(key: string): string | null;
    setItem(key: string, value: string): void;
    removeItem(key: string): void;
}

export interface PresenceChange {
    player_id: number;
    present: boolean;
    changed_at: string;
}

interface PresenceState {
    players: Player[];
    pending: Record<string, PresenceChange>;
    saved_at: string;
}

export const presenceKey = (accountId: number | string) =>
    `hockey-presenca:${accountId}`;
export const identityKey = 'hockey-identidade';

const parse = (storage: StorageLike, key: string): PresenceState | null => {
    try {
        const value: unknown = JSON.parse(storage.getItem(key) ?? 'null');
        if (
            value &&
            typeof value === 'object' &&
            Array.isArray((value as PresenceState).players)
        )
            return value as PresenceState;
    } catch {
        // Um dado local inválido nunca deve impedir a tela de abrir.
    }
    return null;
};

export function loadPlayers(
    storage: StorageLike,
    accountId: number | string,
    serverPlayers: Player[],
): Player[] {
    const state = parse(storage, presenceKey(accountId));
    if (!state) {
        savePlayers(storage, accountId, serverPlayers, {});
        return serverPlayers;
    }
    const pending = state.pending ?? {};
    const merged = serverPlayers.map((player) => {
        const change = pending[String(player.id)];
        const local = state.players.find((saved) => saved.id === player.id);
        if (change) return { ...player, present: change.present };
        if (
            local &&
            (!player.presence_changed_at ||
                Date.parse(local.presence_changed_at ?? '') >=
                    Date.parse(player.presence_changed_at))
        )
            return {
                ...player,
                present: local.present,
                presence_changed_at: local.presence_changed_at,
            };
        return player;
    });
    const onlyLocal = state.players.filter(
        (player) => !serverPlayers.some((server) => server.id === player.id),
    );
    return [
        ...merged,
        ...onlyLocal.map((player) => {
            const change = pending[String(player.id)];
            return change ? { ...player, present: change.present } : player;
        }),
    ];
}

export function savePlayers(
    storage: StorageLike,
    accountId: number | string,
    players: Player[],
    pending: Record<string, PresenceChange> = {},
): void {
    storage.setItem(
        presenceKey(accountId),
        JSON.stringify({
            players,
            pending,
            saved_at: new Date().toISOString(),
        }),
    );
}

export function changePresence(
    storage: StorageLike,
    accountId: number | string,
    players: Player[],
    playerId: number,
    present: boolean,
    changedAt = new Date().toISOString(),
): Player[] {
    const state = parse(storage, presenceKey(accountId));
    const next = players.map((player) =>
        player.id === playerId ? { ...player, present } : player,
    );
    savePlayers(storage, accountId, next, {
        ...state?.pending,
        [playerId]: { player_id: playerId, present, changed_at: changedAt },
    });
    return next;
}

export function pendingPresence(
    storage: StorageLike,
    accountId: number | string,
): PresenceChange[] {
    return Object.values(parse(storage, presenceKey(accountId))?.pending ?? {});
}

export function acknowledgePresence(
    storage: StorageLike,
    accountId: number | string,
    processed: number[] | PresenceChange[],
): void {
    const state = parse(storage, presenceKey(accountId));
    if (!state) return;
    const changes = new Map(
        processed
            .filter((item): item is PresenceChange => typeof item !== 'number')
            .map((item) => [item.player_id, item]),
    );
    const ids = processed.map((item) =>
        typeof item === 'number' ? item : item.player_id,
    );
    for (const id of ids) {
        const change = state.pending[String(id)];
        const player = state.players.find((candidate) => candidate.id === id);
        const acknowledged = changes.get(id);
        if (acknowledged && change?.changed_at !== acknowledged.changed_at)
            continue;
        if (change && player) {
            player.present = change.present;
            player.presence_changed_at = change.changed_at;
        }
        delete state.pending[String(id)];
    }
    savePlayers(storage, accountId, state.players, state.pending);
}

export function saveIdentity(storage: StorageLike, identity: unknown): void {
    storage.setItem(identityKey, JSON.stringify(identity));
}

export function clearOfflineAccount(
    storage: StorageLike,
    accountId?: number | string,
): void {
    const keys = [identityKey];
    if (accountId !== undefined) {
        keys.push(
            presenceKey(accountId),
            `hockey-sorteio:${accountId}`,
            `hockey-rascunho:${accountId}`,
        );
    }
    keys.forEach((key) => storage.removeItem(key));
}

export function clearOfflineSnapshots(
    storage: StorageLike,
    accountId: number | string,
): void {
    [
        identityKey,
        presenceKey(accountId),
        `hockey-sorteio:${accountId}`,
        `hockey-rascunho:${accountId}`,
    ].forEach((key) => storage.removeItem(key));
}
