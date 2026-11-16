<!-- Mockup 39 -->
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import VBars from '@/Components/Charts/VBars.vue';
import DateNav from '@/Components/DateNav.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import type { StatCardProps } from '@/Components/StatCard.vue';
import StatCards from '@/Components/StatCards.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { formatDateRange, formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';

/**
 * Owner summary (39): sales, check-ins, occupancy, short and over across branches for a week
 * (the 7 days ending the chosen date) or a month. Built from the closed shifts' frozen totals,
 * so it equals the sum of the daily reports; each branch row opens that branch's daily sales (38).
 */
interface SummaryRow {
    branch_id?: number;
    name: string;
    sales: number;
    checkins: number;
    room_days: number;
    occupied: number;
    short: number;
    over: number;
    shifts: number;
}

const props = defineProps<{
    range: 'week' | 'month';
    date: string;
    branch: number | 'all';
    branches: { id: number; name: string }[];
    summary: { from: string; to: string; rows: SummaryRow[]; total: SummaryRow };
    days: { date: string; sales: number }[];
}>();

const range = ref(props.range);
const date = ref(props.date);
const branch = ref(String(props.branch));
watch([range, date, branch], ([r, d, b]) => router.get(route('owner.summary'), { range: r === 'week' ? undefined : r, date: d, branch: b === 'all' ? undefined : b } as never, { preserveScroll: true }));

const occ = (r: SummaryRow) => (r.room_days === 0 ? '—' : `${Math.round((r.occupied * 100) / r.room_days)}%`);
const t = computed(() => props.summary.total);
const cards = computed<StatCardProps[]>(() => [
    { label: 'Sales', value: formatMoney(t.value.sales, 'whole') },
    { label: 'Check-ins', value: t.value.checkins },
    { label: 'Occupancy', value: occ(t.value) },
    { label: 'Short', value: formatMoney(t.value.short === 0 ? 0 : -t.value.short, 'whole'), sub: `Over: ${formatMoney(t.value.over, 'whole')}`, tone: t.value.short > 0 ? 'bad' : 'default' },
]);

const labels = computed(() => props.days.map((d) => (props.range === 'week' ? formatDateTime(`${d.date}T12:00:00+08:00`, 'businessDate').slice(0, 3) : String(Number(d.date.slice(8, 10))))));
const titles = computed(() => props.days.map((d) => formatDateTime(`${d.date}T12:00:00+08:00`, 'businessDate')));
const sales = computed(() => props.days.map((d) => d.sales));
const heading = computed(() => formatDateRange(`${props.summary.from}T12:00:00+08:00`, `${props.summary.to}T12:00:00+08:00`));
const picker = computed(() => [{ value: 'all', label: 'All branches' }, ...props.branches.map((b) => ({ value: String(b.id), label: b.name }))]);
const short = (c: number) => (c === 0 ? formatMoney(0, 'wholeColumn') : formatMoney(-c, 'wholeColumn'));
</script>

<template>
    <Head title="Owner summary" />
    <OwnerLayout current="summary" :title="`Owner summary · ${heading}`">
        <PageHeader>
            <PeriodSegments v-model="branch" label="Branch" :items="picker" />
        </PageHeader>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <PeriodSegments
                v-model="range"
                label="Range"
                :items="[
                    { value: 'week', label: '7 days' },
                    { value: 'month', label: 'Month' },
                ]"
            />
            <DateNav v-model="date" :step="range === 'week' ? 7 : 30" />
        </div>

        <StatCards :items="cards" />

        <div class="mt-4 grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,.75fr)_minmax(0,1.25fr)]">
            <section class="rounded-card border border-default bg-default p-4">
                <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Sales per day</h2>
                <VBars class="mt-2" :labels="labels" :titles="titles" :series="sales.some((v) => v !== 0) ? [{ name: 'Sales', values: sales }] : []" money empty-text="No closed shifts in this period" caption="Sales per day" />
            </section>
            <section class="overflow-x-auto rounded-card border border-default bg-default px-2 py-1">
                <table class="dtable" data-summary>
                    <thead>
                        <tr>
                            <th scope="col">Branch</th>
                            <th scope="col" class="r">Sales</th>
                            <th scope="col" class="r">Check-ins</th>
                            <th scope="col" class="r">Occ.</th>
                            <th scope="col" class="r">Short</th>
                            <th scope="col" class="r">Over</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in summary.rows" :key="r.branch_id">
                            <td>
                                <a class="hm-focus font-medium text-primary" :href="route('reports.daily', { branch: r.branch_id, date: summary.to })" @click.prevent="router.get(route('reports.daily'), { branch: r.branch_id, date: summary.to } as never)">{{ r.name }}</a>
                            </td>
                            <td class="r">{{ formatMoney(r.sales, 'wholeColumn') }}</td>
                            <td class="r">{{ r.checkins }}</td>
                            <td class="r">{{ occ(r) }}</td>
                            <td class="r">{{ short(r.short) }}</td>
                            <td class="r">{{ formatMoney(r.over, 'wholeColumn') }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>All</td>
                            <td class="r">{{ formatMoney(t.sales, 'wholeColumn') }}</td>
                            <td class="r">{{ t.checkins }}</td>
                            <td class="r">{{ occ(t) }}</td>
                            <td class="r">{{ short(t.short) }}</td>
                            <td class="r">{{ formatMoney(t.over, 'wholeColumn') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>
        </div>
    </OwnerLayout>
</template>
