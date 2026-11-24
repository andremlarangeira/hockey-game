import { ref } from 'vue';

export type ToastVariant = 'success' | 'error' | 'info';

export interface ToastItem {
    id: number;
    message: string;
    variant: ToastVariant;
}

const toasts = ref<ToastItem[]>([]);
let nextId = 1;

export function pushToast(
    message: string,
    variant: ToastVariant = 'success',
    durationMs = 3600,
): number {
    const trimmed = message.trim();
    if (!trimmed) return 0;

    const existing = toasts.value.find(
        (item) => item.message === trimmed && item.variant === variant,
    );
    if (existing) return existing.id;

    const id = nextId++;
    toasts.value = [
        ...toasts.value.slice(-3),
        { id, message: trimmed, variant },
    ];

    if (durationMs > 0 && typeof window !== 'undefined') {
        window.setTimeout(() => {
            dismissToast(id);
        }, durationMs);
    }

    return id;
}

export function dismissToast(id: number): void {
    toasts.value = toasts.value.filter((item) => item.id !== id);
}

export function useToast() {
    return {
        toasts,
        success: (message: string, durationMs = 3600) =>
            pushToast(message, 'success', durationMs),
        error: (message: string, durationMs = 5000) =>
            pushToast(message, 'error', durationMs),
        info: (message: string, durationMs = 3600) =>
            pushToast(message, 'info', durationMs),
        dismiss: dismissToast,
    };
}
