import type { DrawResult, DrawTeam, Player } from '@/types/hockey';

export interface StorageLike {
    getItem(key: string): string | null;
    setItem(key: string, value: string): void;
    removeItem(key: string): void;
}

export interface SavedDraw extends DrawResult {
    mode: 'players' | 'teams';
    size: number;
    saved_at: string;
}

export function keyFor(accountId: number | string): string {
    return `hockey-sorteio:${accountId}`;
}

function isPlayer(value: unknown): value is Player {
    if (!value || typeof value !== 'object') return false;
    const player = value as Record<string, unknown>;

    return (
        typeof player.id === 'number' &&
        typeof player.name === 'string' &&
        typeof player.jersey_number === 'number' &&
        (typeof player.level === 'number' ||
            typeof player.level === 'string') &&
        (typeof player.photo_url === 'string' || player.photo_url === null) &&
        typeof player.present === 'boolean'
    );
}

function isTeam(value: unknown): value is DrawTeam {
    if (!value || typeof value !== 'object') return false;
    const team = value as Record<string, unknown>;

    return (
        typeof team.id === 'string' &&
        typeof team.name === 'string' &&
        typeof team.color === 'string' &&
        Array.isArray(team.players) &&
        team.players.every(isPlayer) &&
        typeof team.total === 'number' &&
        typeof team.average === 'number'
    );
}

function isSavedDraw(value: unknown): value is SavedDraw {
    if (!value || typeof value !== 'object') return false;
    const draw = value as Record<string, unknown>;

    return (
        Array.isArray(draw.teams) &&
        draw.teams.every(isTeam) &&
        Array.isArray(draw.reserves) &&
        draw.reserves.every(isPlayer) &&
        (draw.mode === 'players' || draw.mode === 'teams') &&
        typeof draw.size === 'number' &&
        Number.isFinite(draw.size) &&
        typeof draw.saved_at === 'string'
    );
}

export function save(
    storage: StorageLike,
    accountId: number | string,
    draw: Omit<SavedDraw, 'saved_at'>,
): void {
    storage.setItem(
        keyFor(accountId),
        JSON.stringify({ ...draw, saved_at: new Date().toISOString() }),
    );
}

export function load(
    storage: StorageLike,
    accountId: number | string,
): SavedDraw | null {
    const key = keyFor(accountId);
    const raw = storage.getItem(key);
    if (!raw) return null;

    try {
        const draw: unknown = JSON.parse(raw);
        if (isSavedDraw(draw)) return draw;
    } catch {
        // A entrada inválida é descartada abaixo.
    }

    storage.removeItem(key);
    return null;
}

function totals(players: Player[]): Pick<DrawTeam, 'total' | 'average'> {
    const total = players.reduce(
        (sum, player) => sum + Number(player.level),
        0,
    );

    return {
        total,
        average: players.length
            ? Number((total / players.length).toFixed(2))
            : 0,
    };
}

export function reconcile(
    saved: SavedDraw,
    presentPlayers: Player[],
): { draw: SavedDraw; removedCount: number; addedCount: number } {
    const current = new Map(
        presentPlayers.map((player) => [player.id, player]),
    );
    const included = new Set<number>();
    let removedCount = 0;

    const refresh = (players: Player[]): Player[] =>
        players.flatMap((player) => {
            const currentPlayer = current.get(player.id);
            if (!currentPlayer || included.has(player.id)) {
                removedCount++;
                return [];
            }
            included.add(player.id);
            return [currentPlayer];
        });

    const teams = saved.teams.map((team) => {
        const players = refresh(team.players);

        return { ...team, players, ...totals(players) };
    });
    const reserves = refresh(saved.reserves);
    const added = presentPlayers.filter((player) => !included.has(player.id));

    return {
        draw: { ...saved, teams, reserves: [...reserves, ...added] },
        removedCount,
        addedCount: added.length,
    };
}
