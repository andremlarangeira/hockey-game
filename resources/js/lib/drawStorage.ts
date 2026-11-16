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

export type StoredTeamColors =
    | [string | null | undefined, (string | null | undefined)?]
    | (string | null | undefined)[]
    | {
          primary?: string | null;
          secondary?: string | null;
          primary_color?: string | null;
          secondary_color?: string | null;
      }
    | string
    | null
    | undefined;

const DEFAULT_DRAW_COLORS = [
    '#2563eb',
    '#dc2626',
    '#eab308',
    '#16a34a',
    '#9333ea',
    '#ea580c',
    '#0891b2',
    '#db2777',
] as const;

function isValidHex(color: unknown): color is string {
    return typeof color === 'string' && /^#[0-9a-fA-F]{6}$/.test(color);
}

function resolvePalette(
    primaryColor?: StoredTeamColors,
    secondaryColor?: string | null,
): string[] {
    let rawPrimary: string | null | undefined;
    let rawSecondary: string | null | undefined;
    if (Array.isArray(primaryColor)) {
        rawPrimary = primaryColor[0];
        rawSecondary = primaryColor[1] ?? secondaryColor;
    } else if (typeof primaryColor === 'string') {
        rawPrimary = primaryColor;
        rawSecondary = secondaryColor;
    } else if (primaryColor && typeof primaryColor === 'object') {
        rawPrimary = primaryColor.primary_color ?? primaryColor.primary;
        rawSecondary =
            primaryColor.secondary_color ??
            primaryColor.secondary ??
            secondaryColor;
    } else {
        rawPrimary = undefined;
        rawSecondary = secondaryColor;
    }
    const first = isValidHex(rawPrimary) ? rawPrimary : DEFAULT_DRAW_COLORS[0];
    const second = isValidHex(rawSecondary)
        ? rawSecondary
        : DEFAULT_DRAW_COLORS[1];
    const used = new Set([first.toLowerCase(), second.toLowerCase()]);
    const candidates = [
        ...DEFAULT_DRAW_COLORS.slice(2),
        DEFAULT_DRAW_COLORS[0],
        DEFAULT_DRAW_COLORS[1],
    ];
    const rest = candidates.filter((color) => !used.has(color.toLowerCase()));

    return [first, second, ...rest].slice(0, DEFAULT_DRAW_COLORS.length);
}

function parseTeamIdSlot(id: unknown): number | null {
    if (typeof id !== 'string') return null;
    const match = /^equipe-(\d+)$/i.exec(id.trim());
    if (!match) return null;
    const slot = Number.parseInt(match[1]!, 10) - 1;
    return slot >= 0 ? slot : null;
}

export function applyDrawColors(
    teams: DrawTeam[],
    primaryColor?: StoredTeamColors,
    secondaryColor?: string | null,
): DrawTeam[] {
    const palette = resolvePalette(primaryColor, secondaryColor);
    const hasStableIds = teams.some(
        (team) => parseTeamIdSlot(team.id) !== null,
    );

    return teams.map((team, index) => {
        const idSlot = parseTeamIdSlot(team.id);
        const slot = idSlot ?? (!hasStableIds ? index : null);
        const color =
            slot !== null && slot < palette.length
                ? palette[slot]!
                : team.color;

        return {
            ...team,
            id: team.id || `equipe-${index + 1}`,
            color,
        };
    });
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
        (typeof team.id === 'string' || typeof team.id === 'undefined') &&
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
    primaryColor?: StoredTeamColors,
    secondaryColor?: string | null,
): SavedDraw | null {
    const key = keyFor(accountId);
    const raw = storage.getItem(key);
    if (!raw) return null;

    try {
        const draw: unknown = JSON.parse(raw);
        if (isSavedDraw(draw)) {
            const teams =
                primaryColor !== undefined || secondaryColor !== undefined
                    ? applyDrawColors(draw.teams, primaryColor, secondaryColor)
                    : draw.teams.map((team, index) => ({
                          ...team,
                          id: team.id || `equipe-${index + 1}`,
                      }));

            return { ...draw, teams };
        }
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
    primaryColor?: StoredTeamColors,
    secondaryColor?: string | null,
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

    const refreshedTeams = saved.teams.map((team, index) => {
        const players = refresh(team.players);

        return {
            ...team,
            id: team.id || `equipe-${index + 1}`,
            players,
            ...totals(players),
        };
    });
    const teams =
        primaryColor !== undefined || secondaryColor !== undefined
            ? applyDrawColors(refreshedTeams, primaryColor, secondaryColor)
            : refreshedTeams;
    const reserves = refresh(saved.reserves);
    const added = presentPlayers.filter((player) => !included.has(player.id));

    return {
        draw: { ...saved, teams, reserves: [...reserves, ...added] },
        removedCount,
        addedCount: added.length,
    };
}
