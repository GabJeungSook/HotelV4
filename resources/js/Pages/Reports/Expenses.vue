<!-- Expenses (new report; no mockup frame). Cash expenses by category over a day, week or month,
     with every expense listed. Exports to CSV. -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import DateNav from '@/Components/DateNav.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import MoneyRow from '@/Components/MoneyRow.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PersonPill from '@/Components/PersonPill.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { useReport } from '@/Composables/useReport';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

type Range = 'day' | 'week' | 'month';

const props = defineProps<{
    report: {
        date: string;
        range: Range;
        from: string;
        to: string;
        total: number;
        count: number;
        by_category: { category: string; count: number; amount: number }[];
        rows: { at: string; category: string; amount: number; note: string; by: string }[];
    };
}>();

const r = useReport();
const date = ref(props.report.date);
const range = ref<Range>(props.report.range);
watch([date, range], ([d, rg]) => r.go('reports.expenses', {}, { date: d, range: rg === 'day' ? null : rg }));

const heading = computed(() => (props.report.from === props.report.to ? formatDateTime(`${props.report.from}T12:00:00+08:00`, 'businessDate').replace(',', '') : `${props.report.from} to ${props.report.to}`));
</script>

<template>
    <BackOfficeLayout current="reports" :title="`Expenses · ${heading}`">
        <Head title="Expenses" />
        <PageHeader>
            <ExportMenu csv :print="false" @csv="r.download(route('reports.expenses', { date: report.date, range: report.range, export: 'csv' }))" />
        </PageHeader>

        <div class="no-print mb-4 flex flex-wrap items-center gap-3">
            <DateNav v-model="date" :step="range === 'month' ? 30 : range === 'week' ? 7 : 1" />
            <PeriodSegments v-model="range" label="Range" :items="[{ value: 'day', label: 'Day' }, { value: 'week', label: 'Week' }, { value: 'month', label: 'Month' }]" />
        </div>

        <div class="mb-4 grid gap-4 lg:grid-cols-[320px_minmax(0,1fr)]">
            <!-- Totals by category -->
            <div class="rounded-card border border-default bg-default p-4">
                <div class="mb-2 flex items-baseline justify-between">
                    <b class="text-highlighted">By category</b>
                    <span class="text-sm text-muted">{{ report.count }} expense(s)</span>
                </div>
                <EmptyState v-if="report.by_category.length === 0" title="No expenses" text="No expense in this range." />
                <template v-else>
                    <MoneyRow v-for="c in report.by_category" :key="c.category" :label="`${c.category} (${c.count})`" :amount="c.amount" />
                    <div class="mt-2 border-t border-default pt-2">
                        <MoneyRow label="Total" :amount="report.total" tone="bad" />
                    </div>
                </template>
            </div>

            <!-- Every expense -->
            <div class="print-a4 overflow-x-auto rounded-card border border-default bg-default p-4">
                <EmptyState v-if="report.rows.length === 0" title="No expenses" text="No expense matches this range." />
                <table v-else class="dtable">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Category</th>
                            <th>Note</th>
                            <th>By</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in report.rows" :key="i">
                            <td class="whitespace-nowrap">{{ formatDateTime(row.at, report.from === report.to ? 'time' : 'dayTime') }}</td>
                            <td class="whitespace-nowrap">{{ row.category }}</td>
                            <td class="!whitespace-normal">{{ row.note || '—' }}</td>
                            <td><PersonPill v-if="row.by" :name="row.by" /><span v-else class="text-muted">—</span></td>
                            <td class="text-right num">{{ formatMoney(row.amount) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </BackOfficeLayout>
</template>
