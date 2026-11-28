import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const cwd = new URL('../..', import.meta.url).pathname;
const temp = mkdtempSync(join(tmpdir(), 'match-queue-'));
const source = readFileSync(
    `${cwd}/resources/js/lib/matchQueue.ts`,
    'utf8',
).replace(
    "import type { StorageLike } from '@/lib/offlineStorage';",
    'interface StorageLike { getItem(key: string): string | null; setItem(key: string, value: string): void; removeItem(key: string): void; }',
);
writeFileSync(`${temp}/matchQueue.ts`, source);
execFileSync(`${cwd}/node_modules/typescript/bin/tsc`, [
    `${temp}/matchQueue.ts`,
    '--target',
    'ES2022',
    '--module',
    'commonjs',
    '--outDir',
    temp,
]);
writeFileSync(`${temp}/package.json`, '{"type":"commonjs"}');
const {
    championshipQueueKey,
    enqueueChampionshipEvent,
    enqueueMatch,
    extractMatchError,
    flushChampionshipQueue,
    flushQueue,
    generateClientUuid,
    isPermanentFailure,
    isPermanentFailureStatus,
    loadChampionshipQueue,
    loadQueue,
    projectChampionshipGameWithQueue,
    queueKey,
    removeQueuedChampionshipEvent,
    removeQueuedMatch,
    retryQueuedChampionshipEvent,
    retryQueuedMatch,
} = await import(`${temp}/matchQueue.js`);

class MemoryStorage {
    data = new Map();
    getItem(key) {
        return this.data.get(key) ?? null;
    }
    setItem(key, value) {
        this.data.set(key, value);
    }
    removeItem(key) {
        this.data.delete(key);
    }
}

// 1. Classificação de status HTTP
for (const status of [400, 403, 404, 405, 409, 410, 422]) {
    assert.equal(
        isPermanentFailureStatus(status),
        true,
        `status ${status} deve ser falha permanente`,
    );
}
for (const status of [0, 200, 201, 401, 419, 429, 500, 502, 503, 504]) {
    assert.equal(
        isPermanentFailureStatus(status),
        false,
        `status ${status} deve continuar com retry`,
    );
}

// 2. Extração de mensagem de erro em pt-BR das validações do Laravel
assert.equal(
    extractMatchError(
        {
            message: 'The given data was invalid.',
            errors: {
                roster: [
                    'O elenco contém um jogador que não pertence ao seu time.',
                ],
            },
        },
        422,
    ),
    'O elenco contém um jogador que não pertence ao seu time.',
);
assert.equal(
    extractMatchError({ message: 'The given data was invalid.' }, 422),
    'Os dados da partida foram recusados pela validação do servidor.',
);

// 3. Fluxo de envio: rede, 401/419/429, 5xx fazem retry; 4xx (exceto 401/419/429) vira falha permanente
const storage = new MemoryStorage();
enqueueMatch(storage, 9, { home_score: 2 });
assert.equal(loadQueue(storage, 9).length, 1);

await flushQueue(storage, 9, async () => ({
    status: 0,
    ok: false,
    error: 'Sem conexão',
}));
assert.equal(
    loadQueue(storage, 9).length,
    1,
    'uma falha de rede nunca apaga a partida',
);
assert.equal(isPermanentFailure(loadQueue(storage, 9)[0]), false);
assert.equal(loadQueue(storage, 9)[0].attempts, 1);

let result = await flushQueue(storage, 9, async () => ({
    status: 419,
    ok: false,
    error: 'Sessão expirada',
}));
assert.equal(result.relogin, true);
assert.equal(loadQueue(storage, 9).length, 1);
assert.equal(isPermanentFailure(loadQueue(storage, 9)[0]), false);
assert.equal(loadQueue(storage, 9)[0].attempts, 2);

result = await flushQueue(storage, 9, async () => ({
    status: 429,
    ok: false,
    error: 'Muitas requisições',
}));
assert.equal(result.remaining, 1);
assert.equal(isPermanentFailure(loadQueue(storage, 9)[0]), false);
assert.equal(loadQueue(storage, 9)[0].attempts, 3);

result = await flushQueue(storage, 9, async () => ({
    status: 503,
    ok: false,
    error: 'Servidor indisponível',
}));
assert.equal(result.remaining, 1);
assert.equal(isPermanentFailure(loadQueue(storage, 9)[0]), false);
assert.equal(loadQueue(storage, 9)[0].attempts, 4);

// Agora recebe 422 -> marca como falha permanente
result = await flushQueue(storage, 9, async () => ({
    status: 422,
    ok: false,
    error: 'O placar precisa ser igual ao número de gols registrados.',
}));
assert.equal(result.remaining, 1);
assert.equal(result.failed, 1);
assert.equal(result.pending, 0);
const failedItem = loadQueue(storage, 9)[0];
assert.equal(isPermanentFailure(failedItem), true);
assert.equal(failedItem.status, 'failed');
assert.equal(failedItem.permanent_failure, true);
assert.equal(failedItem.http_status, 422);
assert.equal(
    failedItem.error,
    'O placar precisa ser igual ao número de gols registrados.',
);
assert.equal(failedItem.attempts, 5);
assert.equal(typeof failedItem.last_attempt_at, 'string');

// Nova chamada automática de flushQueue NÃO reenvia item com falha permanente
let sendCallsAfterPermanentFailure = 0;
result = await flushQueue(storage, 9, async () => {
    sendCallsAfterPermanentFailure++;
    return { status: 201, ok: true };
});
assert.equal(
    sendCallsAfterPermanentFailure,
    0,
    'item com falha permanente não é reenviado automaticamente',
);
assert.equal(result.remaining, 1);

// Tentar de novo manual volta o item para pendente e permite o envio
const retried = retryQueuedMatch(storage, 9, failedItem.client_uuid);
assert.ok(retried);
assert.equal(isPermanentFailure(retried), false);
assert.equal(retried.status, 'pending');
assert.equal(retried.permanent_failure, false);

result = await flushQueue(storage, 9, async () => ({ status: 201, ok: true }));
assert.equal(
    result.remaining,
    0,
    'a confirmação remove a fila após retry manual',
);

// 4. Compatibilidade com itens antigos já gravados sem campos novos (sem status/permanent_failure/attempts = pendente)
storage.setItem(
    queueKey(9),
    JSON.stringify([
        {
            client_uuid: 'legacy-uuid-1',
            payload: { home_score: 4, away_score: 2 },
            queued_at: '2026-11-01T10:00:00.000Z',
        },
    ]),
);
const legacyLoaded = loadQueue(storage, 9);
assert.equal(legacyLoaded.length, 1);
assert.equal(
    isPermanentFailure(legacyLoaded[0]),
    false,
    'item no formato antigo é tratado como pendente',
);
result = await flushQueue(storage, 9, async () => ({ status: 201, ok: true }));
assert.equal(result.remaining, 0, 'item legado é enviado normalmente');

// 5. Descartar item da fila
const toDiscard = enqueueMatch(storage, 9, { home_score: 3 });
assert.equal(loadQueue(storage, 9).length, 1);
removeQueuedMatch(storage, 9, toDiscard.client_uuid);
assert.equal(loadQueue(storage, 9).length, 0);

// 6. Corrida: novo item enfileirado durante o envio de outro não é sobrescrito
const first = enqueueMatch(storage, 9, { home_score: 1 });
let release;
const sending = flushQueue(storage, 9, async () => {
    await new Promise((resolve) => {
        release = resolve;
    });
    return { status: 201, ok: true };
});
await new Promise((resolve) => setImmediate(resolve));
const second = enqueueMatch(storage, 9, { home_score: 2 });
release();
await sending;
const queuedAfterRace = loadQueue(storage, 9);
assert.equal(queuedAfterRace.length, 1, 'um envio não sobrescreve novos itens');
assert.equal(queuedAfterRace[0].client_uuid, second.client_uuid);
assert.notEqual(first.client_uuid, second.client_uuid);

// 7. Fila de eventos de campeonato: idempotência por client_uuid, projeção otimista e envio em ordem (gol -> desfazer -> finalizar)
const champStorage = new MemoryStorage();
const fixedGoal1Uuid = generateClientUuid();
const fixedGoal2Uuid = generateClientUuid();
const fixedRemoveUuid = generateClientUuid();
const fixedFinishUuid = generateClientUuid();

const g1 = enqueueChampionshipEvent(champStorage, 7, {
    client_uuid: fixedGoal1Uuid,
    championship_id: 3,
    game_id: 15,
    action: 'goal',
    payload: { side: 'home', scorer_id: 101, period: 1 },
    meta: { side: 'home', scorer_id: 101, scorer_name: 'Atleta Casa 1', period: 1 },
});
// Mesmo client_uuid enfileirado duas vezes não duplica
const g1Dup = enqueueChampionshipEvent(champStorage, 7, {
    client_uuid: fixedGoal1Uuid,
    championship_id: 3,
    game_id: 15,
    action: 'goal',
    payload: { side: 'home', scorer_id: 101, period: 1 },
});
assert.equal(g1.client_uuid, g1Dup.client_uuid);
assert.equal(loadChampionshipQueue(champStorage, 7).length, 1);

// 2º gol (visitante)
enqueueChampionshipEvent(champStorage, 7, {
    client_uuid: fixedGoal2Uuid,
    championship_id: 3,
    game_id: 15,
    action: 'goal',
    payload: { side: 'away', scorer_id: 202, period: 2, is_overtime: true },
    meta: {
        side: 'away',
        scorer_id: 202,
        scorer_name: 'Atleta Fora 2',
        period: 2,
        is_overtime: true,
    },
});

// Projeção otimista após 2 gols offline
let projected = projectChampionshipGameWithQueue(
    {
        id: 15,
        status: 'live',
        home_regular_score: 0,
        away_regular_score: 0,
        went_to_overtime: false,
    },
    [],
    loadChampionshipQueue(champStorage, 7),
);
assert.equal(projected.home_regular_score, 1);
assert.equal(projected.away_regular_score, 1);
assert.equal(projected.went_to_overtime, true);
assert.equal(projected.status, 'live');
assert.equal(projected.events.length, 2);
assert.equal(projected.events[0].pending, true);

// Desfazer o 2º gol ainda offline (referenciando target_client_uuid)
enqueueChampionshipEvent(champStorage, 7, {
    client_uuid: fixedRemoveUuid,
    championship_id: 3,
    game_id: 15,
    action: 'remove_event',
    payload: {
        motivo: 'Gol anulado pela arbitragem',
        target_client_uuid: fixedGoal2Uuid,
    },
    meta: { target_client_uuid: fixedGoal2Uuid },
});

// Finalizar jogo offline (duplicado não cria segundo finish pendente)
const f1 = enqueueChampionshipEvent(champStorage, 7, {
    client_uuid: fixedFinishUuid,
    championship_id: 3,
    game_id: 15,
    action: 'finish',
    payload: { finished_at: '2026-11-28T15:00:00.000Z' },
});
const f1Dup = enqueueChampionshipEvent(champStorage, 7, {
    championship_id: 3,
    game_id: 15,
    action: 'finish',
    payload: { finished_at: '2026-11-28T15:00:05.000Z' },
});
assert.equal(f1.client_uuid, f1Dup.client_uuid);
assert.equal(loadChampionshipQueue(champStorage, 7).length, 4);

// Projeção otimista após desfazer o 2º gol e finalizar: placar 1x0 e status finished
projected = projectChampionshipGameWithQueue(
    {
        id: 15,
        status: 'live',
        home_regular_score: 0,
        away_regular_score: 0,
        went_to_overtime: false,
    },
    [],
    loadChampionshipQueue(champStorage, 7),
);
assert.equal(projected.home_regular_score, 1);
assert.equal(projected.away_regular_score, 0);
assert.equal(projected.status, 'finished');
assert.equal(projected.has_queued_finish, true);
assert.equal(projected.events.length, 1);

// Envio em ordem: goal1 -> goal2 (retorna event_id=888) -> remove_event (recebe event_id=888 resolvido!) -> finish
const sentOrder = [];
const flushChampResult = await flushChampionshipQueue(
    champStorage,
    7,
    async (item) => {
        sentOrder.push({
            action: item.action,
            client_uuid: item.client_uuid,
            resolved_event_id: item.payload.event_id ?? item.meta?.target_event_id ?? null,
        });
        if (item.client_uuid === fixedGoal1Uuid) {
            return { status: 201, ok: true, event_id: 777 };
        }
        if (item.client_uuid === fixedGoal2Uuid) {
            return { status: 201, ok: true, event_id: 888 };
        }
        return { status: 200, ok: true };
    },
);

assert.equal(flushChampResult.remaining, 0);
assert.equal(flushChampResult.uploaded.length, 4);
assert.deepEqual(
    sentOrder.map((s) => s.action),
    ['goal', 'goal', 'remove_event', 'finish'],
    'eventos de campeonato devem ser enviados exatamente na ordem',
);
assert.equal(
    sentOrder[2].resolved_event_id,
    888,
    'remove_event recebe o event_id retornado pelo gol criado anteriormente na fila',
);

// 8. Falha permanente em evento de campeonato bloqueia eventos seguintes do MESMO jogo mas permite envio de OUTRO jogo
const failGoal = enqueueChampionshipEvent(champStorage, 7, {
    championship_id: 3,
    game_id: 20,
    action: 'goal',
    payload: { side: 'home', scorer_id: 999 },
});
const blockedFinishSameGame = enqueueChampionshipEvent(champStorage, 7, {
    championship_id: 3,
    game_id: 20,
    action: 'finish',
    payload: {},
});
const otherGameGoal = enqueueChampionshipEvent(champStorage, 7, {
    championship_id: 3,
    game_id: 21,
    action: 'goal',
    payload: { side: 'away', scorer_id: 301 },
});

const sentInMixedFlush = [];
const mixedResult = await flushChampionshipQueue(
    champStorage,
    7,
    async (item) => {
        sentInMixedFlush.push(item.client_uuid);
        if (item.client_uuid === failGoal.client_uuid) {
            return {
                status: 422,
                ok: false,
                error: 'O autor do gol deve pertencer ao elenco da equipe.',
            };
        }
        return { status: 201, ok: true, event_id: 901 };
    },
);

assert.deepEqual(
    sentInMixedFlush,
    [failGoal.client_uuid, otherGameGoal.client_uuid],
    'finalização do jogo 20 não pode ser enviada antes do gol com falha do mesmo jogo 20, mas o jogo 21 é enviado',
);
assert.equal(mixedResult.remaining, 2);
assert.equal(mixedResult.failed, 1);
assert.equal(mixedResult.pending, 1);

// Novo flush automático não reenvia o item com falha permanente nem o item bloqueado atrás dele
let autoRetryCalls = 0;
await flushChampionshipQueue(champStorage, 7, async () => {
    autoRetryCalls++;
    return { status: 200, ok: true };
});
assert.equal(autoRetryCalls, 0);

// Retry manual desbloqueia o gol falho e em seguida envia o finish do jogo 20 na ordem
retryQueuedChampionshipEvent(champStorage, 7, failGoal.client_uuid);
const retryOrder = [];
const afterRetry = await flushChampionshipQueue(
    champStorage,
    7,
    async (item) => {
        retryOrder.push(item.client_uuid);
        return { status: 200, ok: true, event_id: 902 };
    },
);
assert.equal(afterRetry.remaining, 0);
assert.deepEqual(retryOrder, [
    failGoal.client_uuid,
    blockedFinishSameGame.client_uuid,
]);

// Descartar evento da fila de campeonato
const toDiscardChamp = enqueueChampionshipEvent(champStorage, 7, {
    championship_id: 3,
    game_id: 22,
    action: 'goal',
    payload: { side: 'home', scorer_id: 1 },
});
assert.equal(loadChampionshipQueue(champStorage, 7).length, 1);
removeQueuedChampionshipEvent(champStorage, 7, toDiscardChamp.client_uuid);
assert.equal(loadChampionshipQueue(champStorage, 7).length, 0);
assert.equal(
    champStorage.getItem(championshipQueueKey(7)),
    '[]',
);
