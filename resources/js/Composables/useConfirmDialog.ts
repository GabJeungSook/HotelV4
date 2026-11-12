import { useOverlay } from '@nuxt/ui/composables';
import ConfirmDialog, { type ConfirmReason, type ConfirmResult } from '@/Components/ConfirmDialog.vue';

export interface ConfirmOptions {
    title: string;
    description?: string;
    confirmLabel: string;
    cancelLabel: string;
    confirmColor?: 'primary' | 'error';
    reasons?: ConfirmReason[];
    needsApproval?: boolean;
    loggedAs?: string;
}

/**
 * Promise-based confirm (DESIGN_SYSTEM §5.2): `if (await confirm({ … })) { … }`.
 * Mounts ConfirmDialog through Nuxt UI's useOverlay(), so no page keeps an `open` flag.
 * Resolves false on cancel or Esc, otherwise { reasonId, note, needsApproval }.
 */
export function useConfirmDialog(): (options: ConfirmOptions) => Promise<ConfirmResult | false> {
    const overlay = useOverlay();

    return async (options) => {
        // A fresh overlay per question; destroyOnClose removes it after the leave transition.
        const result = await overlay.create(ConfirmDialog, { destroyOnClose: true }).open(options);

        return result ? (result as ConfirmResult) : false;
    };
}
