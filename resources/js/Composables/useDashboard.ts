import { router } from '@inertiajs/vue3';
import { computed, ref, watch, type Ref } from 'vue';
import { formatDateTime } from './useDateTime';
import { usePolling } from './usePolling';

/**
 * Shared behaviour of the dashboards and Stats (60, 61, 63; TRD §4.11): the figures are deferred
 * props reloaded every `seconds` with a partial reload (skipped while the tab is hidden or a
 * request is in flight); a failed reload keeps the last figures with "Could not refresh. Retry".
 * Filters live in the query string, so Back steps through them.
 */
export function useDashboard(groups: string[], seconds: number, at: Ref<string | undefined>) {
    const { refresh, failed, inFlight } = usePolling(groups, seconds);
    const updated = ref<string | undefined>(at.value);

    watch(at, (value) => {
        if (value) {
            updated.value = value;
        }
    });

    const updatedText = computed(() => (updated.value ? `Updated ${formatDateTime(updated.value, 'time')}` : 'Loading…'));

    function go(name: string, query: Record<string, unknown>): void {
        const clean = Object.fromEntries(Object.entries(query).filter(([, v]) => v !== null && v !== undefined && v !== ''));
        router.get(route(name), clean as never, { preserveScroll: true });
    }

    return { refresh, failed, inFlight, updatedText, go };
}

/** "▲ 8% vs yesterday by this time"; nothing without earlier data (TRD §4.11: never "+100%"). */
export function changeCard(change: number | null | undefined, comparedWith: string): { sub?: string; trend?: 'up' | 'down' } {
    if (change === null || change === undefined) {
        return { sub: 'No earlier figure to compare' };
    }

    return { sub: `${Math.abs(change)}% ${comparedWith}`, trend: change >= 0 ? 'up' : 'down' };
}
