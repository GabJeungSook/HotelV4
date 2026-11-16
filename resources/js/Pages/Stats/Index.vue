<!-- Mockup 63 -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, shallowRef, watch } from 'vue';
import HBars from '@/Components/Charts/HBars.vue';
import HeatGrid, { type Weekday } from '@/Components/Charts/HeatGrid.vue';
import Shares from '@/Components/Charts/Shares.vue';
import VBars from '@/Components/Charts/VBars.vue';
import { seriesColors } from '@/Components/Charts/chart';
import ExportMenu from '@/Components/ExportMenu.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import type { StatCardProps } from '@/Components/StatCard.vue';
import StatCards from '@/Components/StatCards.vue';
import { changeCard, useDashboard } from '@/Composables/useDashboard';
import { formatAxisHour, formatDateRange, formatDateTime, formatHour } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';
import { useShared } from '@/Composables/useShared';

/**
 * Stats (63): a date range with quick choices sets everything; the grain follows the range
 * (hour up to 2 days, day up to 62, month beyond). Cards compare with the same-length period
 * just before and show no change without earlier data. Below: check-ins by weekday and hour,
 * the stay options guests chose, sales by room type, then sales over the range, top products,
 * payment methods, occupancy by day and the front desk table. Summary tables only.
 */
interface Line {
    label: string;
    quantity: number;
    amount: number;
}

const props = defineProps<{
    from: string;
    to: string;
    today: string;
    grain: 'hour' | 'day' | 'month';
    branch: number | 'all';
    branches: { id: number; name: string }[];
    totals?: {
        from: string;
        to: string;
        days: number;
        previous_from: string;
        previous_to: string;
        sales: number;
        previous_sales: number | null;
        change: number | null;
        stays: number;
        per_day: number;
        average: number | null;
        busiest_hour: number | null;
        busiest_weekday: string | null;
    };
    series?: { grain: string; points: { key: string; sales: number; checkins: number }[] };
    heat?: Record<Weekday, number[]>;
    chose?: Line[];
    room_types?: { rows: Line[]; other: number };
    products?: Line[];
    methods?: { method: string; count: number; amount: number; refunds: number }[];
    occupancy?: { date: string; in_house: number; rooms: number }[];
    front_desk?: { user_id: number; name: string; count: number; amount: number; refunds: number }[];
}>();

const { props: shared } = useShared();
const isOwner = computed(() => shared.value.auth?.role === 'owner');
const { go } = useDashboard([], 3600, ref(undefined));

/* ---- range ---- */
function addDays(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);

    return d.toISOString().slice(0, 10);
}
const monthStart = (date: string) => `${date.slice(0, 8)}01`;
const lastMonth = computed(() => {
    const start = addDays(monthStart(props.today), -1);

    return { from: monthStart(start), to: start };
});
const quick = computed(() => {
    if (props.from === addDays(props.today, -6) && props.to === props.today) return '7d';
    if (props.from === monthStart(props.today) && props.to === props.today) return 'month';
    if (props.from === lastMonth.value.from && props.to === lastMonth.value.to) return 'last';

    return '';
});
const quickModel = ref(quick.value);
watch(quickModel, (q) => {
    if (q === quick.value) return;
    if (q === '7d') load(addDays(props.today, -6), props.today);
    if (q === 'month') load(monthStart(props.today), props.today);
    if (q === 'last') load(lastMonth.value.from, lastMonth.value.to);
});

const branchModel = ref(String(props.branch));
watch(branchModel, () => load(props.from, props.to));

function load(from: string, to: string): void {
    go('stats', { from, to, branch: isOwner.value ? branchModel.value : null });
}

const length = computed(() => (totals.value?.days ?? 1));
function step(direction: 1 | -1): void {
    load(addDays(props.from, direction * length.value), addDays(props.to, direction * length.value));
}

const picked = shallowRef<{ start?: { toString(): string }; end?: { toString(): string } } | undefined>(undefined);
watch(picked, (r) => r?.start && r?.end && load(r.start.toString(), r.end.toString()));

const branchItems = computed(() => [{ label: 'All branches', value: 'all' }, ...props.branches.map((b) => ({ label: b.name, value: String(b.id) }))]);
const totals = computed(() => props.totals);
const rangeText = computed(() => formatDateRange(`${props.from}T12:00:00+08:00`, `${props.to}T12:00:00+08:00`));

/* ---- cards ---- */
const cards = computed<StatCardProps[]>(() => {
    const t = totals.value;
    if (!t) return [];

    const prev = `vs ${formatDateRange(`${t.previous_from}T12:00:00+08:00`, `${t.previous_to}T12:00:00+08:00`)}`;

    return [
        { label: 'Sales', value: formatMoney(t.sales, 'whole'), ...changeCard(t.change, prev) },
        { label: 'Stays', value: t.stays, sub: `${t.per_day} a day` },
        { label: 'Average per stay', value: t.average === null ? '—' : formatMoney(t.average, 'whole'), sub: 'incl. food and extras' },
        { label: 'Busiest hour', value: t.busiest_hour === null ? '—' : formatHour(t.busiest_hour), sub: t.busiest_weekday ? `${weekdayLong[t.busiest_weekday] ?? t.busiest_weekday}s` : undefined },
    ];
});
const weekdayLong: Record<string, string> = { Mon: 'Monday', Tue: 'Tuesday', Wed: 'Wednesday', Thu: 'Thursday', Fri: 'Friday', Sat: 'Saturday', Sun: 'Sunday' };

/* ---- charts ---- */
const heatHours = Array.from({ length: 24 }, (_, i) => i);
const heatEmpty = computed(() => !props.heat || Object.values(props.heat).every((row) => row.every((v) => v === 0)));
const chose = computed(() => (props.chose ?? []).filter((r) => r.quantity > 0).map((r, i) => ({ name: r.label, value: r.quantity, color: seriesColors[i % seriesColors.length] })));
const byType = computed(() => {
    const rows = [...(props.room_types?.rows ?? []).map((r) => ({ name: r.label, value: r.amount })), { name: 'Food & extras', value: props.room_types?.other ?? 0 }].filter((r) => r.value !== 0);

    return rows.map((r) => ({ ...r, text: formatMoney(r.value, 'wholeColumn') }));
});
const seriesLabels = computed(() =>
    (props.series?.points ?? []).map((p) => {
        if (props.series?.grain === 'hour') return formatAxisHour(Number(p.key.slice(11, 13)));
        if (props.series?.grain === 'month') return formatDateTime(`${p.key}-15T12:00:00+08:00`, 'date').split(' ')[1];

        return String(Number(p.key.slice(8, 10)));
    }),
);
const seriesTitles = computed(() =>
    (props.series?.points ?? []).map((p) => {
        if (props.series?.grain === 'hour') return `${formatDateTime(`${p.key.slice(0, 10)}T12:00:00+08:00`, 'shiftDate')}, ${formatHour(Number(p.key.slice(11, 13)))}`;
        if (props.series?.grain === 'month') return formatDateTime(`${p.key}-15T12:00:00+08:00`, 'date').split(' ').slice(1).join(' ');

        return formatDateTime(`${p.key}T12:00:00+08:00`, 'businessDate');
    }),
);
const seriesSales = computed(() => (props.series?.points ?? []).map((p) => p.sales));
const hasSeries = computed(() => seriesSales.value.some((v) => v !== 0));
const grainWord = computed(() => ({ hour: 'hour', day: 'day', month: 'month' })[props.grain]);

const products = computed(() => (props.products ?? []).map((r) => ({ name: r.label, value: r.amount, text: `${r.quantity} · ${formatMoney(r.amount, 'wholeColumn')}` })));
const methodNames: Record<string, string> = { cash: 'Cash', gcash: 'GCash', card: 'Card', deposit: 'From deposit' };
const methods = computed(() => (props.methods ?? []).map((r) => ({ name: methodNames[r.method] ?? r.method, value: r.amount, text: formatMoney(r.amount, 'wholeColumn') })));
const occLabels = computed(() => (props.occupancy ?? []).map((d) => String(Number(d.date.slice(8, 10)))));
const occTitles = computed(() => (props.occupancy ?? []).map((d) => formatDateTime(`${d.date}T12:00:00+08:00`, 'businessDate')));
const occValues = computed(() => (props.occupancy ?? []).map((d) => (d.rooms === 0 ? 0 : Math.round((d.in_house * 100) / d.rooms))));

function exportCsv(): void {
    const query = new URLSearchParams({ from: props.from, to: props.to, export: 'csv', ...(isOwner.value ? { branch: branchModel.value } : {}) });
    window.location.href = `${route('stats')}?${query.toString()}`;
}

const Layout = computed(() => (isOwner.value ? OwnerLayout : BackOfficeLayout));
</script>

<template>
    <Head title="Stats" />
    <component :is="Layout" current="stats" title="Stats">
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="inline-flex items-center gap-1" role="group" aria-label="Date range">
                <UButton color="neutral" variant="ghost" icon="i-lucide-chevron-left" aria-label="Earlier" @click="step(-1)" />
                <UPopover>
                    <UButton color="neutral" variant="outline" icon="i-lucide-calendar" :label="rangeText" data-range />
                    <template #content>
                        <UCalendar v-model="picked" range :week-starts-on="1" class="p-2" />
                    </template>
                </UPopover>
                <UButton color="neutral" variant="ghost" icon="i-lucide-chevron-right" aria-label="Later" @click="step(1)" />
            </div>
            <PeriodSegments
                v-model="quickModel"
                label="Quick choice"
                :items="[
                    { value: '7d', label: '7 days' },
                    { value: 'month', label: 'This month' },
                    { value: 'last', label: 'Last month' },
                ]"
            />
            <USelect v-if="isOwner && branches.length > 1" v-model="branchModel" :items="branchItems" class="w-52" aria-label="Branch" />
            <ExportMenu class="ms-auto" :print="false" csv @csv="exportCsv" />
        </div>

        <StatCards :items="cards" :loading="!totals" />

        <div class="mt-4 grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
            <section class="rounded-card border border-default bg-default p-4">
                <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Check-ins by day and hour</h2>
                <HeatGrid class="mt-2" :hours="heatHours.map(formatAxisHour)" :hour-titles="heatHours.map(formatHour)" :grid="heatEmpty ? {} : (heat ?? {})" :loading="!heat" unit="check-ins" />
            </section>
            <div class="flex flex-col gap-4">
                <section class="rounded-card border border-default bg-default p-4">
                    <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">What guests chose</h2>
                    <Shares :rows="chose" legend="percent" :loading="!chose && !props.chose" caption="What guests chose" empty-text="No stays in this period" />
                </section>
                <section class="rounded-card border border-default bg-default p-4">
                    <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Sales by room type</h2>
                    <HBars class="mt-1" :rows="byType" :loading="!room_types" empty-text="No sales in this period" />
                </section>
            </div>
        </div>

        <section class="mt-4 rounded-card border border-default bg-default p-4">
            <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Sales by {{ grainWord }}</h2>
            <VBars class="mt-2" :labels="seriesLabels" :titles="seriesTitles" :series="hasSeries ? [{ name: 'Sales', values: seriesSales }] : []" money :loading="!series" empty-text="No sales in this period" :caption="`Sales by ${grainWord}`" />
        </section>

        <div class="mt-4 grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
            <section class="rounded-card border border-default bg-default p-4">
                <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Top products</h2>
                <HBars class="mt-1" :rows="products" :loading="!props.products" empty-text="No products sold in this period" />
            </section>
            <section class="rounded-card border border-default bg-default p-4">
                <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Payment methods</h2>
                <HBars class="mt-1" :rows="methods" :loading="!props.methods" empty-text="No payments in this period" />
            </section>
        </div>

        <section class="mt-4 rounded-card border border-default bg-default p-4">
            <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Occupancy by day</h2>
            <VBars class="mt-2" :labels="occLabels" :titles="occTitles" :series="occValues.some((v) => v > 0) ? [{ name: 'In house at day end', values: occValues }] : []" unit="%" :loading="!occupancy" empty-text="No guests in this period" caption="Guests in house at the end of each day, as a share of rooms" />
        </section>

        <section class="mt-4 rounded-card border border-default bg-default p-4">
            <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Front desk</h2>
            <div v-if="!front_desk" class="mt-2" aria-hidden="true"><USkeleton v-for="i in 3" :key="i" class="mt-2 h-6 w-full" /></div>
            <p v-else-if="front_desk.length === 0" class="mt-2 text-sm text-muted">No payments taken in this period.</p>
            <div v-else class="mt-1 overflow-x-auto">
                <table class="dtable">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col" class="r">Payments</th>
                            <th scope="col" class="r">Collected</th>
                            <th scope="col" class="r">Given back</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in front_desk" :key="p.user_id">
                            <td>{{ p.name }}</td>
                            <td class="r">{{ p.count }}</td>
                            <td class="r">{{ formatMoney(p.amount, 'column') }}</td>
                            <td class="r">{{ formatMoney(p.refunds, 'column') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </component>
</template>
