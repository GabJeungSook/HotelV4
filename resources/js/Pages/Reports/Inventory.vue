<!-- Mockup 42 -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import CheckResult from '@/Components/CheckResult.vue';
import DateNav from '@/Components/DateNav.vue';
import ExportMenu from '@/Components/ExportMenu.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { useReport } from '@/Composables/useReport';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

/**
 * Inventory report (42): stock per product for a business day with the sales value next to it.
 * Closing = opening + in − sold − wastage, and one day's closing is the next day's opening, as
 * on the front desk stock screen (20).
 */
const props = defineProps<{
    report: {
        date: string;
        has_shifts: boolean;
        rows: { product_id: number; name: string; opening: number; in: number; sold: number; wastage: number; closing: number; low_at: number; sales: number }[];
        checks: string[];
    };
}>();

const r = useReport();
const date = ref(props.report.date);
watch(date, (d) => r.go('reports.inventory', {}, { date: d }));
const title = computed(() => `Inventory · ${formatDateTime(`${props.report.date}T12:00:00+08:00`, 'businessDate').replace(',', '')}`);
const next = computed(() => {
    const d = new Date(`${props.report.date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + 1);

    return formatDateTime(`${d.toISOString().slice(0, 10)}T12:00:00+08:00`, 'date').replace(/ \d{4}$/, '');
});
</script>

<template>
    <BackOfficeLayout current="inventory" :title="title">
        <Head title="Inventory" />
        <PageHeader>
            <ExportMenu csv :print="false" @csv="r.download(route('reports.inventory', { date: report.date, export: 'csv' }))" />
        </PageHeader>
        <div class="no-print mb-4"><DateNav v-model="date" /></div>

        <div class="print-a4">
            <div class="overflow-x-auto rounded-card border border-default bg-default p-4">
                <table class="dtable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="text-right">Opening</th>
                            <th class="text-right">In</th>
                            <th class="text-right">Sold</th>
                            <th class="text-right">Wastage</th>
                            <th class="text-right">Closing</th>
                            <th class="text-right">Sales ₱</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in report.rows" :key="p.product_id">
                            <td>{{ p.name }}</td>
                            <td class="r">{{ p.opening }}</td>
                            <td class="r">{{ p.in }}</td>
                            <td class="r">{{ p.sold }}</td>
                            <td class="r">{{ p.wastage }}</td>
                            <td class="r" :class="p.closing <= p.low_at ? '!text-warn' : ''">{{ p.closing }}</td>
                            <td class="r">{{ formatMoney(p.sales, 'column') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-[13px] text-muted">
                <template v-if="!report.has_shifts">No shift on this date, so nothing moved. </template>Closing on {{ formatDateTime(`${report.date}T12:00:00+08:00`, 'date').replace(/ \d{4}$/, '') }} is the opening on {{ next }}.
            </p>
            <CheckResult :problems="report.checks" />
        </div>
    </BackOfficeLayout>
</template>
