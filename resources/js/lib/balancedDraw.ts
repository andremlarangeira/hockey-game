import type { DrawResult, Player } from '@/types/hockey';

const colors = [
    '#2563eb',
    '#dc2626',
    '#eab308',
    '#16a34a',
    '#9333ea',
    '#ea580c',
    '#0891b2',
    '#db2777',
];

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
    return {
        teams: best.map((players, index) => {
            const total = players.reduce(
                (sum, player) => sum + Number(player.level),
                0,
            );
            return {
                id: `equipe-${index + 1}`,
                name: `Equipe ${index + 1}`,
                color: colors[index]!,
                players,
                total,
                average: Number((total / players.length).toFixed(2)),
            };
        }),
        reserves,
    };
}
