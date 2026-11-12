import { enqueueMatch, type QueuedMatch } from '@/lib/matchQueue';
import type { StorageLike } from '@/lib/offlineStorage';

export function enqueueMatchBeforeClearingDraft(
    storage: StorageLike,
    accountId: number | string,
    payload: Record<string, unknown>,
    draftKey: string,
): QueuedMatch {
    const queued = enqueueMatch(storage, accountId, payload);
    storage.removeItem(draftKey);

    return queued;
}
