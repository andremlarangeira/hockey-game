import type { DrawResult, Player } from '@/types/hockey';

export const DEFAULT_PRIMARY_COLOR = '#2563eb';
export const DEFAULT_SECONDARY_COLOR = '#dc2626';

export const DEFAULT_DRAW_COLORS = [
    DEFAULT_PRIMARY_COLOR,
    DEFAULT_SECONDARY_COLOR,
    '#eab308',
    '#16a34a',
    '#9333ea',
    '#ea580c',
    '#0891b2',
    '#db2777',
] as const;

export type TeamColorInput =
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

export function isValidHex(color: unknown): color is string {
    return typeof color === 'string' && /^#[0-9a-fA-F]{6}$/.test(color);
}

export function normalizeHex(
    color: unknown,
    fallback: string = DEFAULT_PRIMARY_COLOR,
): string {
    return isValidHex(color) ? color : fallback;
}

function extractColorPair(
    primaryColor?: TeamColorInput,
    secondaryColor?: string | null,
): {
    primary: string | null | undefined;
    secondary: string | null | undefined;
} {
    if (Array.isArray(primaryColor)) {
        return {
            primary: primaryColor[0],
            secondary: primaryColor[1] ?? secondaryColor,
        };
    }
    if (typeof primaryColor === 'string') {
        return {
            primary: primaryColor,
            secondary: secondaryColor,
        };
    }
    if (primaryColor && typeof primaryColor === 'object') {
        return {
            primary: primaryColor.primary_color ?? primaryColor.primary,
            secondary:
                primaryColor.secondary_color ??
                primaryColor.secondary ??
                secondaryColor,
        };
    }
    return {
        primary: undefined,
        secondary: secondaryColor,
    };
}

export function drawPalette(
    primaryColor?: TeamColorInput,
    secondaryColor?: string | null,
): string[] {
    const { primary, secondary } = extractColorPair(
        primaryColor,
        secondaryColor,
    );
    const first = normalizeHex(primary, DEFAULT_PRIMARY_COLOR);
    const second = normalizeHex(secondary, DEFAULT_SECONDARY_COLOR);
    const used = new Set([first.toLowerCase(), second.toLowerCase()]);
    const candidates = [
        ...DEFAULT_DRAW_COLORS.slice(2),
        DEFAULT_DRAW_COLORS[0],
        DEFAULT_DRAW_COLORS[1],
    ];
    const rest = candidates.filter((color) => !used.has(color.toLowerCase()));

    return [first, second, ...rest].slice(0, DEFAULT_DRAW_COLORS.length);
}

function rgb(hex: string): [number, number, number] {
    const valid = normalizeHex(hex, DEFAULT_PRIMARY_COLOR);
    return [
        Number.parseInt(valid.slice(1, 3), 16) / 255,
        Number.parseInt(valid.slice(3, 5), 16) / 255,
        Number.parseInt(valid.slice(5, 7), 16) / 255,
    ];
}

function luminance(color: string): number {
    const channels = rgb(color).map((channel) =>
        channel <= 0.04045
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4,
    );

    return (
        0.2126 * channels[0]! + 0.7152 * channels[1]! + 0.0722 * channels[2]!
    );
}

export function contrastRatio(a: string, b: string): number {
    const lumA = luminance(a);
    const lumB = luminance(b);
    const light = Math.max(lumA, lumB);
    const dark = Math.min(lumA, lumB);

    return (light + 0.05) / (dark + 0.05);
}

export function readableText(background: string): '#ffffff' | '#0f172a' {
    return contrastRatio(background, '#ffffff') >= 4.5 ? '#ffffff' : '#0f172a';
}

function parseTeamIdSlot(id: unknown): number | null {
    if (typeof id !== 'string') return null;
    const match = /^equipe-(\d+)$/i.exec(id.trim());
    if (!match) return null;
    const slot = Number.parseInt(match[1]!, 10) - 1;
    return slot >= 0 ? slot : null;
}

export function applyTeamColorsToTeams<
    T extends { id?: string; name?: string; color: string; players?: Player[] },
>(
    teams: T[],
    primaryColor?: TeamColorInput,
    secondaryColor?: string | null,
): T[] {
    const palette = drawPalette(primaryColor, secondaryColor);
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
            ...(team.id ? {} : { id: `equipe-${index + 1}` }),
            color,
        };
    });
}

function resolveRosterTeamSlot(
    team: {
        id?: string;
        name?: string;
        players?: ReadonlyArray<{ id: number }>;
    },
    side: 'home' | 'away',
    drawTeams?: ReadonlyArray<{
        id?: string;
        name?: string;
        players?: ReadonlyArray<{ id: number }>;
    }>,
): number | null {
    const directSlot = parseTeamIdSlot(team.id);
    if (directSlot !== null) return directSlot;

    if (drawTeams && drawTeams.length > 0) {
        if (typeof team.id === 'string' && team.id !== '') {
            const byId = drawTeams.findIndex(
                (candidate) => candidate.id === team.id,
            );
            if (byId !== -1) {
                return parseTeamIdSlot(drawTeams[byId]!.id) ?? byId;
            }
        }
        if (Array.isArray(team.players) && team.players.length > 0) {
            const playerIds = new Set(team.players.map((player) => player.id));
            const byPlayers = drawTeams.findIndex(
                (candidate) =>
                    Array.isArray(candidate.players) &&
                    candidate.players.length > 0 &&
                    candidate.players.some((player) =>
                        playerIds.has(player.id),
                    ),
            );
            if (byPlayers !== -1) {
                return parseTeamIdSlot(drawTeams[byPlayers]!.id) ?? byPlayers;
            }
        }
        if (typeof team.name === 'string' && team.name !== '') {
            const byName = drawTeams.findIndex(
                (candidate) => candidate.name === team.name,
            );
            if (byName !== -1) {
                return parseTeamIdSlot(drawTeams[byName]!.id) ?? byName;
            }
        }
        return null;
    }

    if (typeof team.name === 'string') {
        const match = /^\s*equipe\s+(\d+)\s*$/i.exec(team.name);
        if (match) {
            const slot = Number.parseInt(match[1]!, 10) - 1;
            if (slot >= 0) return slot;
        }
    }

    return side === 'home' ? 0 : 1;
}

export function applyTeamColorsToRoster<
    T extends { id?: string; name: string; color: string; players: Player[] },
>(
    roster: { home: T; away: T },
    primaryColor?: TeamColorInput,
    secondaryColor?: string | null,
    drawTeams?: ReadonlyArray<{
        id?: string;
        name?: string;
        players?: ReadonlyArray<{ id: number }>;
    }>,
): { home: T; away: T } {
    const palette = drawPalette(primaryColor, secondaryColor);
    const homeSlot = resolveRosterTeamSlot(roster.home, 'home', drawTeams);
    const awaySlot = resolveRosterTeamSlot(roster.away, 'away', drawTeams);

    return {
        home: {
            ...roster.home,
            color:
                homeSlot !== null && homeSlot < palette.length
                    ? palette[homeSlot]!
                    : roster.home.color,
        },
        away: {
            ...roster.away,
            color:
                awaySlot !== null && awaySlot < palette.length
                    ? palette[awaySlot]!
                    : roster.away.color,
        },
    };
}

export type RandomInt = (min: number, max: number) => number;

const randomInt = (min: number, max: number, random?: RandomInt) =>
    random
        ? random(min, max)
        : Math.floor(Math.random() * (max - min + 1)) + min;

function shuffle<T>(items: T[], random?: RandomInt): T[] {
    const shuffled = [...items];
    for (let index = shuffled.length - 1; index > 0; index--) {
        const swap = randomInt(0, index, random);
        [shuffled[index], shuffled[swap]] = [shuffled[swap]!, shuffled[index]!];
    }
    return shuffled;
}

export function balancedDraw(
    players: Player[],
    mode: 'players' | 'teams' = 'players',
    size = 4,
    random?: RandomInt,
    primaryColor?: TeamColorInput,
    secondaryColor?: string | null,
): DrawResult {
    const present = players.filter((player) => player.present);
    if (present.length < 2 || !['players', 'teams'].includes(mode) || size < 1)
        throw new Error(
            'Marque pelo menos 2 jogadores presentes e escolha um tamanho válido.',
        );

    let teamCount: number;
    let participantCount: number;
    if (mode === 'players') {
        teamCount = Math.floor(present.length / size);
        if (teamCount < 2)
            throw new Error(
                'São necessários jogadores para pelo menos 2 equipes completas.',
            );
        participantCount = teamCount * size;
    } else {
        teamCount = size;
        const minimum = Math.floor(present.length / teamCount);
        participantCount = minimum === 1 ? teamCount : present.length;
    }
    if (
        teamCount < 2 ||
        teamCount > 8 ||
        teamCount > Math.floor(present.length / 2)
    )
        throw new Error(
            'Escolha de 2 a 8 equipes, com pelo menos 2 jogadores em cada. Ajuste o número de equipes ou use o modo por jogadores.',
        );

    let best: Player[][] | null = null;
    let bestScore = Number.POSITIVE_INFINITY;
    let reserves: Player[] = [];
    for (let attempt = 0; attempt < 240; attempt++) {
        const participants = shuffle(present, random)
            .slice(0, participantCount)
            .map((player) => ({
                player,
                score:
                    Number(player.level) + randomInt(0, 100, random) / 100000,
            }))
            .sort((a, b) => b.score - a.score)
            .map(({ player }) => player);
        const teams: Player[][] = Array.from({ length: teamCount }, () => []);
        let direction = 1;
        let index = 0;
        for (const player of participants) {
            teams[index]!.push(player);
            if (direction === 1 && index === teamCount - 1) direction = -1;
            else if (direction === -1 && index === 0) direction = 1;
            else index += direction;
        }
        const totals = teams.map((team) =>
            team.reduce((sum, player) => sum + Number(player.level), 0),
        );
        const score = Math.max(...totals) - Math.min(...totals);
        if (score < bestScore) {
            best = shuffle(teams, random);
            const drawn = new Set(participants.map((player) => player.id));
            reserves = present.filter((player) => !drawn.has(player.id));
            bestScore = score;
        }
    }
    if (!best) throw new Error('Não foi possível sortear as equipes.');
    const palette = drawPalette(primaryColor, secondaryColor);
    return {
        teams: best.map((players, index) => {
            const total = players.reduce(
                (sum, player) => sum + Number(player.level),
                0,
            );
            return {
                id: `equipe-${index + 1}`,
                name: `Equipe ${index + 1}`,
                color: palette[index]!,
                players,
                total,
                average: Number((total / players.length).toFixed(2)),
            };
        }),
        reserves,
    };
}
