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
    enqueueMatch,
    extractMatchError,
    flushQueue,
    isPermanentFailure,
    isPermanentFailureStatus,
    loadQueue,
    queueKey,
    removeQueuedMatch,
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
