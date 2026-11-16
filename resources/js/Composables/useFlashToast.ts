import { usePage } from '@inertiajs/vue3';
import { useToast } from '@nuxt/ui/composables';
import { watch } from 'vue';
import type { FlashData } from './useShared';

/** The flash object last turned into a toast, shared by every layout instance. */
let lastShown: unknown = null;

/**
 * Turns the flash `toast` into a Nuxt UI toast, once per flash (SYSTEM_DESIGN §4.3). Layouts
 * are mounted again on every visit, so the flash already shown is remembered here. A blocking
 * domain error is not a toast: pages show `flash.error` inline or as a dialog.
 */
export function useFlashToast(): void {
    const page = usePage();
    const toast = useToast();

    watch(
        () => page.flash,
        (flash) => {
            const t = (flash as FlashData | undefined)?.toast;

            if (!flash || !t || flash === lastShown) {
                return;
            }

            lastShown = flash;
            toast.add({
                title: t.title ?? t.message,
                description: t.title ? t.message : undefined,
                color: t.type === 'error' ? 'error' : t.type === 'info' ? 'info' : 'success',
                icon: t.type === 'error' ? 'i-solar-danger-triangle-bold-duotone' : t.type === 'info' ? 'i-solar-info-circle-bold-duotone' : 'i-solar-check-circle-linear',
            });
        },
        { immediate: true },
    );
}
