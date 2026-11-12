<!-- Mockup: none (shifts list, APP_FLOW §8.1 and §8.2: the table of screen 43) -->
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import EmptyState from '@/Components/EmptyState.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { shortName } from '@/Composables/useShared';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

/**
 * Shift reports: every shift by business date, newest first, with its Z number and, once closed,
 * its frozen net sales and short or over. Each row opens the shift report (37), the Z-report
 * (37b) or rooms by floor (37c); an open shift opens as a preview.
 */
interface Row {
    id: number;
    business_date: string;
    period_name: string;
    status: string;
    z_no: number | null;
    opened_at: string;
    closed_at: string | null;
    members: string[];
    net_sales: number | null;
    variance: number | null;
    is_force_closed: boolean;
}

const props = defineProps<{ list: { rows: Row[]; page: number; per_page: number; total: number } }>();

const z = (n: number | null) => (n === null ? '—' : `Z-${String(n).padStart(4, '0')}`);
const first = () => (props.list.page - 1) * props.list.per_page + 1;
const last = () => Math.min(props.list.total, props.list.page * props.list.per_page);
const page = (p: number) => router.get(route('reports.shifts'), { page: p }, { preserveScroll: true });
</script>

<template>
    <BackOfficeLayout current="shift-reports" title="Shift reports">
        <Head title="Shift reports" />
        <div class="rounded-card border border-default bg-default">
            <EmptyState v-if="list.rows.length === 0" title="No shifts yet" text="A shift appears here once it is opened." />
            <div v-else class="overflow-x-auto">
                <table class="dtable">
                    <thead>
                        <tr>
                            <th>Business date</th>
                            <th>Shift</th>
                            <th>Front desk</th>
                            <th class="text-right">Net sales</th>
                            <th class="text-right">Short / over</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in list.rows" :key="row.id">
                            <td>{{ formatDateTime(`${row.business_date}T12:00:00+08:00`, 'businessDate') }}</td>
                            <td>
                                <span class="font-semibold text-highlighted">{{ row.period_name }}</span>
                                <span class="ms-2 num">{{ z(row.z_no) }}</span>
                                <StatusLabel v-if="row.status !== 'closed'" class="ms-2" tone="warn" :label="row.status === 'closing' ? 'Closing' : 'Open'" />
                                <StatusLabel v-if="row.is_force_closed" class="ms-2" tone="bad" label="Force closed" />
                            </td>
                            <td class="!whitespace-normal text-muted">{{ row.members.map(shortName).join(', ') }}</td>
                            <td class="r">{{ row.net_sales === null ? '—' : formatMoney(row.net_sales, 'column') }}</td>
                            <td class="r" :class="row.variance !== null && row.variance < 0 ? '!text-bad' : ''">{{ row.variance === null ? '—' : formatMoney(row.variance, 'column') }}</td>
                            <td class="text-right whitespace-nowrap">
                                <Link :href="route('reports.shift', row.id)" class="hm-focus text-sm font-medium text-primary hover:underline">Report</Link>
                                <Link :href="route('reports.z', row.id)" class="hm-focus ms-3 text-sm font-medium text-primary hover:underline">{{ row.status === 'closed' ? 'Z-report' : 'Preview' }}</Link>
                                <Link :href="route('reports.z', { shift: row.id, tab: 'rooms' })" class="hm-focus ms-3 text-sm font-medium text-primary hover:underline">Rooms</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="list.total > list.per_page" class="flex items-center justify-between border-t border-default px-4 py-3">
                <span class="text-sm text-muted">{{ first() }}–{{ last() }} of {{ list.total }}</span>
                <div class="flex gap-2">
                    <UButton color="neutral" variant="outline" icon="i-lucide-chevron-left" aria-label="Previous page" :disabled="list.page <= 1" @click="page(list.page - 1)" />
                    <UButton color="neutral" variant="outline" icon="i-lucide-chevron-right" aria-label="Next page" :disabled="last() >= list.total" @click="page(list.page + 1)" />
                </div>
            </div>
        </div>
    </BackOfficeLayout>
</template>
