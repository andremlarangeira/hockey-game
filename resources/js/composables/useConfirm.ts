import { ref } from 'vue';

export interface ConfirmOptions {
    title: string;
    message: string;
    confirmLabel?: string;
    cancelLabel?: string;
    variant?: 'danger' | 'primary';
}

interface ActiveConfirm extends Required<ConfirmOptions> {
    resolve: (accepted: boolean) => void;
}

const activeConfirm = ref<ActiveConfirm | null>(null);

export function confirmAction(options: ConfirmOptions): Promise<boolean> {
    if (activeConfirm.value) {
        activeConfirm.value.resolve(false);
    }

    return new Promise<boolean>((resolve) => {
        activeConfirm.value = {
            title: options.title,
            message: options.message,
            confirmLabel: options.confirmLabel ?? 'Confirmar',
            cancelLabel: options.cancelLabel ?? 'Cancelar',
            variant: options.variant ?? 'danger',
            resolve,
        };
    });
}

export function settleConfirm(accepted: boolean): void {
    const current = activeConfirm.value;
    if (!current) return;
    activeConfirm.value = null;
    current.resolve(accepted);
}

export function useConfirm() {
    return {
        activeConfirm,
        confirm: confirmAction,
        accept: () => settleConfirm(true),
        cancel: () => settleConfirm(false),
    };
}
