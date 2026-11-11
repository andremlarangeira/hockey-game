import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue';
import { createRefreshLoop, type RefreshResult } from '@/lib/publicRefreshLoop';

interface UsePublicAutoRefreshOptions {
    only: string[];
    intervalMs: () => number | null;
}

interface PublicAutoRefreshState {
    lastUpdatedAt: Ref<Date>;
    failing: Ref<boolean>;
    unavailable: Ref<boolean>;
}

export function usePublicAutoRefresh({
    only,
    intervalMs,
}: UsePublicAutoRefreshOptions): PublicAutoRefreshState {
    const lastUpdatedAt = ref(new Date());
    const failing = ref(false);
    const unavailable = ref(false);

    const loop = createRefreshLoop({
        intervalMs,
        isHidden: () =>
            typeof document !== 'undefined' &&
            document.visibilityState === 'hidden',
        refresh: () =>
            new Promise<RefreshResult>((resolve) => {
                let result: RefreshResult = 'retry';

                try {
                    router.reload({
                        only,
                        onSuccess: () => {
                            lastUpdatedAt.value = new Date();
                            failing.value = false;
                            result = 'ok';
                        },
                        onHttpException: (response) => {
                            if (
                                response.status === 404 ||
                                response.status === 410
                            ) {
                                unavailable.value = true;
                                failing.value = false;
                                result = 'stop';
                            } else {
                                failing.value = true;
                            }

                            return false;
                        },
                        onNetworkError: () => {
                            failing.value = true;
                            return false;
                        },
                        onFinish: () => resolve(result),
                    });
                } catch {
                    failing.value = true;
                    resolve('retry');
                }
            }),
    });

    const handleVisibilityChange = () => loop.onVisibilityChange();

    onMounted(() => {
        document.addEventListener('visibilitychange', handleVisibilityChange);
        loop.start();
    });

    onBeforeUnmount(() => {
        document.removeEventListener(
            'visibilitychange',
            handleVisibilityChange,
        );
        loop.stop();
    });

    return { lastUpdatedAt, failing, unavailable };
}
