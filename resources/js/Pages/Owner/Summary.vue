<!-- Mockup 39 -->
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import VBars from '@/Components/Charts/VBars.vue';
import DateNav from '@/Components/DateNav.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import SalesOverview, { type Overview } from '@/Components/SalesOverview.vue';
import type { StatCardProps } from '@/Components/StatCard.vue';
import StatCards from '@/Components/StatCards.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { changeCard } from '@/Composables/useDashboard';
import { formatDateRange, formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';

/**
 * Owner summary (39): sales, check-ins, occupancy, short and over across branches for a week
 * (the 7 days ending the chosen date) or a month. Built from the closed shifts' frozen totals,
 * so it equals the sum of the daily reports; each branch row opens that branch's daily sales (38).
 * Every figure is compared with the matching window just before it; the sales split (rooms vs
 * extras), payment mix and expenses/net come from the same frozen totals. Rooms and Extras open
 * the breakdown of which room types and which items make them up.
 */
interface SummaryRow {
    branch_id?: number;
    name: string;
    sales: number;
    rooms: number;
    extras: number;
    cash: number;
    gcash: number;
    card: number;
    expenses: number;
    net: number;
    checkins: number;
    room_days: number;
    occupied: number;
    short: number;
    over: number;
    shifts: number;
}

interface BreakdownRow {
    name: string;
    amount: number;
}

const props = defineProps<{
    range: 'week' | 'month';
    date: string;
    branch: number | 'all';
    branches: { id: number; name: string }[];
    summary: { from: string; to: string; rows: SummaryRow[]; total: SummaryRow };
    compare: SummaryRow;
    breakdown: { rooms: BreakdownRow[]; extras: BreakdownRow[] };
    days: { date: string; sales: number }[];
}>();

const range = ref(props.range);
const date = ref(props.date);
const branch = ref(String(props.branch));
watch([range, date, branch], ([r, d, b]) => router.get(route('owner.summary'), { range: r === 'week' ? undefined : r, date: d, branch: b === 'all' ? undefined : b } as never, { preserveScroll: true }));

const occ = (r: SummaryRow) => (r.room_days === 0 ? '—' : `${Math.round((r.occupied * 100) / r.room_days)}%`);
const occNum = (r: SummaryRow) => (r.room_days === 0 ? 0 : (r.occupied * 100) / r.room_days);
const pct = (cur: number, prev: number) => (prev === 0 ? null : Math.round(((cur - prev) / prev) * 100));
const t = computed(() => props.summary.total);
const cmpLabel = computed(() => (range.value === 'week' ? 'vs previous 7 days' : 'vs previous month'));

const cards = computed<StatCardProps[]>(() => {
    const c = t.value;
    const p = props.compare;

    return [
        { label: 'Sales', value: formatMoney(c.sales, 'whole'), ...changeCard(pct(c.sales, p.sales), cmpLabel.value) },
        { label: 'Expenses', value: formatMoney(c.expenses, 'whole'), sub: 'Paid out this period' },
        { label: 'Net', value: formatMoney(c.net, 'whole'), ...changeCard(pct(c.net, p.net), cmpLabel.value) },
        { label: 'Check-ins', value: c.checkins, ...changeCard(pct(c.checkins, p.checkins), cmpLabel.value) },
        { label: 'Occupancy', value: occ(c), ...changeCard(pct(Math.round(occNum(c)), Math.round(occNum(p))), cmpLabel.value) },
        { label: 'Short', value: formatMoney(c.short === 0 ? 0 : -c.short, 'whole'), sub: `Over: ${formatMoney(c.over, 'whole')}`, tone: c.short > 0 ? 'bad' : 'default' },
    ];
});

const overview = computed<Overview>(() => ({
    period: '',
    from: props.summary.from,
    to: props.summary.to,
    sales: t.value.sales,
    rooms: t.value.rooms,
    extras: t.value.extras,
    methods: [
        { method: 'cash', amount: t.value.cash, count: 0 },
        { method: 'gcash', amount: t.value.gcash, count: 0 },
        { method: 'card', amount: t.value.card, count: 0 },
    ],
}));

const drill = ref<'rooms' | 'extras' | null>(null);
const drillOpen = computed({ get: () => drill.value !== null, set: (v) => (v ? undefined : (drill.value = null)) });
const drillRows = computed<BreakdownRow[]>(() => (drill.value === 'rooms' ? props.breakdown.rooms : drill.value === 'extras' ? props.breakdown.extras : []));
const drillTotal = computed(() => drillRows.value.reduce((a, r) => a + r.amount, 0));
const drillTitle = computed(() => (drill.value === 'rooms' ? 'Room revenue by type' : 'Extras by item'));

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

        <div class="mt-4 grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <section class="flex flex-col rounded-card border border-default bg-default p-4">
                <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Sales per day</h2>
                <VBars class="mt-3 flex-1" :height="236" wide :labels="labels" :titles="titles" :series="sales.some((v) => v !== 0) ? [{ name: 'Sales', values: sales }] : []" money empty-text="No closed shifts in this period" caption="Sales per day" />
            </section>
            <SalesOverview :overview="overview" title="Sales mix & payments" :show-range="false" clickable @drill="(k) => (drill = k)" />
        </div>

        <section class="mt-4 overflow-x-auto rounded-card border border-default bg-default p-4">
            <table class="dtable" data-summary>
                <thead>
                    <tr>
                        <th scope="col">Branch</th>
                        <th scope="col" class="r">Sales</th>
                        <th scope="col" class="r">Rooms</th>
                        <th scope="col" class="r">Extras</th>
                        <th scope="col" class="r">Expenses</th>
                        <th scope="col" class="r">Net</th>
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
                        <td class="r">{{ formatMoney(r.rooms, 'wholeColumn') }}</td>
                        <td class="r">{{ formatMoney(r.extras, 'wholeColumn') }}</td>
                        <td class="r">{{ formatMoney(r.expenses, 'wholeColumn') }}</td>
                        <td class="r">{{ formatMoney(r.net, 'wholeColumn') }}</td>
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
                        <td class="r">{{ formatMoney(t.rooms, 'wholeColumn') }}</td>
                        <td class="r">{{ formatMoney(t.extras, 'wholeColumn') }}</td>
                        <td class="r">{{ formatMoney(t.expenses, 'wholeColumn') }}</td>
                        <td class="r">{{ formatMoney(t.net, 'wholeColumn') }}</td>
                        <td class="r">{{ t.checkins }}</td>
                        <td class="r">{{ occ(t) }}</td>
                        <td class="r">{{ short(t.short) }}</td>
                        <td class="r">{{ formatMoney(t.over, 'wholeColumn') }}</td>
                    </tr>
                </tfoot>
            </table>
        </section>

        <UModal v-model:open="drillOpen" :title="`${drillTitle} · ${heading}`" :ui="{ content: 'max-w-[460px]' }">
            <template #body>
                <table v-if="drillRows.length" class="dtable">
                    <tbody>
                        <tr v-for="r in drillRows" :key="r.name">
                            <td>{{ r.name }}</td>
                            <td class="r">{{ formatMoney(r.amount, 'wholeColumn') }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>Total</td>
                            <td class="r">{{ formatMoney(drillTotal, 'wholeColumn') }}</td>
                        </tr>
                    </tfoot>
                </table>
                <p v-else class="py-6 text-center text-sm text-muted">Nothing sold in this period.</p>
            </template>
        </UModal>
    </OwnerLayout>
</template>
