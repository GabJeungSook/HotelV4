<!-- Mockup 38 -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CheckResult, { type Difference } from '@/Components/CheckResult.vue';
import DateNav from '@/Components/DateNav.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import LedgerSheet, { type Ledger } from '@/Components/LedgerSheet.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { useReport } from '@/Composables/useReport';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

/**
 * Daily sales (38): a day as its shifts side by side, so the total is visibly their sum; a week
 * as its days, a month as its weeks (Gab, 12 Nov). Figures are the shifts' frozen totals (an open
 * shift is read live and marked). The check line is the reconcile check of the day: the day
 * recomputed from the ledger and compared with its closed shifts.
 */
interface Column {
    key: string;
    label: string;
    sub?: string | null;
    date?: string | null;
    shift_id?: number | null;
    shift_ids: number[];
    open?: boolean;
    cells: Record<string, number>;
    total: number;
}

const props = defineProps<{
    report: {
        date: string;
        range: 'day' | 'week' | 'month';
        from: string;
        to: string;
        rows: { key: string; label: string }[];
        columns: Column[];
        total: Column;
        checks: string[];
        reconcile: { date: string | null; differences: Difference[]; checked: boolean };
    };
    can_close: boolean;
    ledger?: Ledger | null;
}>();

const r = useReport();
const date = ref(props.report.date);
const range = ref(props.report.range);
watch([date, range], ([d, g]) => r.go('reports.daily', {}, { date: d, range: g === 'day' ? null : g }));

const all = computed(() => [...props.report.columns, props.report.total]);
const m = (c: number) => formatMoney(c, 'column');
const day = (iso: string) => formatDateTime(`${iso}T12:00:00+08:00`, 'businessDate').replace(',', '');
const heading = computed(() => (props.report.range === 'day' ? day(props.report.date) : `${formatDateTime(`${props.report.from}T12:00:00+08:00`, 'date')} – ${formatDateTime(`${props.report.to}T12:00:00+08:00`, 'date')}`));
const step = computed(() => ({ day: 1, week: 7, month: 30 })[props.report.range]);
const sumText = computed(() => {
    const parts = props.report.columns.map((c) => `${c.label.replace(' shift', '')} ${formatMoney(c.total)}`);

    return `${parts.join(' + ')} = ${formatMoney(props.report.total.total)}.`;
});
const passText = computed(() => `${sumText.value} Matches the ledger for the business day.`);

function openCell(col: Column, rowKey: string, label: string): void {
    r.drill(`sales:${rowKey}`, `${label} · ${col.label}`, { column: col.key });
}
</script>

<template>
    <BackOfficeLayout current="daily" :title="`Daily sales · ${heading}`">
        <Head title="Daily sales" />
        <PageHeader>
            <UButton v-if="can_close && report.range === 'day'" color="neutral" variant="outline" label="Close the day" icon="i-solar-lock-bold-duotone" :to="route('reports.daily.close', { date: report.date })" />
            <ExportMenu csv :print="false" @csv="r.download(route('reports.daily', { date: report.date, range: report.range, export: 'csv' }))" />
        </PageHeader>

        <div class="no-print mb-4 flex flex-wrap items-center gap-3">
            <DateNav v-model="date" :step="step" />
            <PeriodSegments
                v-model="range"
                label="Range"
                :items="[
                    { value: 'day', label: 'Day' },
                    { value: 'week', label: 'Week' },
                    { value: 'month', label: 'Month' },
                ]"
            />
        </div>

        <div class="print-a4">
            <div class="overflow-x-auto rounded-card border border-default bg-default p-4">
                <p v-if="report.columns.length === 0" class="px-3 py-6 text-sm text-muted">No shifts on this {{ report.range === 'day' ? 'date' : report.range }}.</p>
                <table v-else class="dtable">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th v-for="col in all" :key="col.key" class="text-right">
                                {{ col.label }}<span v-if="col.sub" class="ms-1 text-xs">{{ col.sub }}</span
                                ><span v-if="col.open" class="ms-1 text-xs text-warn">open</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in report.rows" :key="row.key">
                            <td>{{ row.label }}</td>
                            <td v-for="col in all" :key="col.key" class="r">
                                <button v-if="col.cells[row.key] !== 0" type="button" class="hm-focus cursor-pointer hover:underline" @click="openCell(col, row.key, row.label)">{{ m(col.cells[row.key]) }}</button>
                                <span v-else>{{ m(0) }}</span>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Sales</td>
                            <td v-for="col in all" :key="col.key" class="r">{{ m(col.total) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <CheckResult :problems="report.checks" />
            <CheckResult
                v-if="report.range === 'day'"
                :checked="report.reconcile.checked && report.columns.every((c) => !c.open)"
                :differences="report.reconcile.differences"
                :pass-text="passText"
                :idle-text="report.columns.length ? `${sumText} A shift of this date is still open, so the day is not checked yet.` : undefined"
            />
            <CheckResult v-else :differences="report.reconcile.differences" :pass-text="`${sumText} The nightly check found no mismatch for these days.`" />
        </div>

        <LedgerSheet v-model:open="r.sheetOpen.value" :ledger="ledger ?? null" :label="r.sheetLabel.value" :loading="r.sheetLoading.value" />
    </BackOfficeLayout>
</template>
