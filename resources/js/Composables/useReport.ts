import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Ledger } from '@/Components/LedgerSheet.vue';
import { usePrintLog } from '@/Composables/usePrintLog';

/**
 * Shared behaviour of the report pages (36–43): the filters live in the query string, so Back
 * steps through them and a link can be shared (SYSTEM_DESIGN §7); a figure opens its ledger rows
 * with a partial reload of the optional `ledger` prop; Print is logged before the dialog opens.
 */
export function useReport() {
    function go(name: string, params: Record<string, unknown> = {}, query: Record<string, unknown> = {}): void {
        const clean = Object.fromEntries(Object.entries(query).filter(([, v]) => v !== null && v !== undefined && v !== ''));
        router.get(route(name, params as never), clean as never, { preserveScroll: true });
    }

    const sheetOpen = ref(false);
    const sheetLabel = ref<string | undefined>(undefined);
    const sheetLoading = ref(false);

    function drill(figure: string, label: string, extra: Record<string, unknown> = {}): void {
        sheetLabel.value = label;
        sheetOpen.value = true;
        sheetLoading.value = true;
        router.reload({
            only: ['ledger'],
            data: { figure, ...extra },
            onFinish: () => (sheetLoading.value = false),
        });
    }

    function download(url: string): void {
        window.location.href = url;
    }

    return { go, drill, sheetOpen, sheetLabel, sheetLoading, download, log: usePrintLog() };
}

export type { Ledger };
