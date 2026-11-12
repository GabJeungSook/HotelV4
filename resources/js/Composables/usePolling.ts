import { router } from '@inertiajs/vue3';
import { useDocumentVisibility } from '@vueuse/core';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { connection } from './useConnection';

/**
 * Partial reload of `only` every `seconds` (SYSTEM_DESIGN §4.3 "Polling"). Skips while the tab
 * is hidden, while a request is in flight and while the connection bar shows. Polls do not
 * extend the session (the server does not touch the session for partial reloads it serves
 * from GET). Exposes `failed` for "Could not refresh" and `refresh()` for an immediate reload.
 */
export function usePolling(only: string[], seconds: number) {
    const visibility = useDocumentVisibility();
    const inFlight = ref(false);
    const failed = ref(false);
    let timer: ReturnType<typeof setInterval> | undefined;

    function refresh(): void {
        if (inFlight.value || visibility.value === 'hidden' || !connection.online.value) {
            return;
        }

        inFlight.value = true;
        router.reload({
            only,
            async: true,
            onSuccess: () => (failed.value = false),
            onError: () => (failed.value = true),
            onHttpException: () => {
                failed.value = true;

                return false;
            },
            onNetworkError: () => {
                failed.value = true;

                return false;
            },
            onFinish: () => (inFlight.value = false),
        });
    }

    onMounted(() => {
        timer = setInterval(refresh, seconds * 1000);
    });

    onBeforeUnmount(() => timer && clearInterval(timer));

    return { refresh, failed, inFlight };
}
