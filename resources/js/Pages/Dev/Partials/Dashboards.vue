<script setup lang="ts">
import { computed, ref } from 'vue';
import AttentionList, { type AttentionRow } from '@/Components/AttentionList.vue';
import HBars from '@/Components/Charts/HBars.vue';
import HeatGrid, { type Weekday } from '@/Components/Charts/HeatGrid.vue';
import Shares from '@/Components/Charts/Shares.vue';
import Spark from '@/Components/Charts/Spark.vue';
import VBars from '@/Components/Charts/VBars.vue';
import { peakOf, peso } from '@/Components/Charts/chart';
import MoneyRow from '@/Components/MoneyRow.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import StatCards from '@/Components/StatCards.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatAxisHour, formatHour } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { toneFill } from '@/types/ui';

const props = defineProps<{ state: 'data' | 'loading' | 'empty' }>();

const loading = computed(() => props.state === 'loading');
const empty = computed(() => props.state === 'empty');

const ownerPeriod = ref('today');
const branchPeriod = ref('today');
const statsPeriod = ref('month');

/* ---- 60 Owner dashboard ---- */
const ownerKpis = [
    { label: 'Sales today', value: formatMoney(942000, 'whole'), sub: '8% vs yesterday by this time', trend: 'up' as const },
    { label: 'Occupied now', value: '32 of 74', sub: '43% · 2 branches' },
    { label: 'Check-ins today', value: 14, sub: '10 check-outs' },
    { label: 'Short · 7 days', value: formatMoney(-18000, 'whole'), sub: '3 shifts · over ₱0', tone: 'bad' as const },
];
const weekLabels = ['Wed', 'Thu', 'Fri', 'Sat', 'Sun', 'Mon', 'Tue'];
const weekTitles = ['Wed 4 Nov', 'Thu 5 Nov', 'Fri 6 Nov', 'Sat 7 Nov', 'Sun 8 Nov', 'Mon 9 Nov', 'Tue 10 Nov'];
const weekSales = [4420600, 4135400, 4991000, 6274400, 7130000, 5775300, 4705800];
const weekPeak = computed(() => peakOf(weekSales));
const payRows = [
    { name: 'Cash', value: 930500, text: formatMoney(930500, 'column') },
    { name: 'GCash', value: 0, text: formatMoney(0, 'column'), total: 930500 },
    { name: 'Card', value: 0, text: formatMoney(0, 'column'), total: 930500 },
];
const ownerAttention: AttentionRow[] = [
    { severity: 'high', title: 'Polomolok has no open shift', detail: 'Since 8:04 AM · 9 guests in house', route: '#dash' },
    { severity: 'high', title: 'Room 117 overdue 42 min', detail: 'GenSan · Dennis Abella', route: '#dash' },
    { severity: 'normal', title: 'AM shift 10 Nov was short ₱80', detail: 'GenSan · reason not yet reviewed', route: '#dash' },
    { severity: 'normal', title: '10 Nov is not closed', detail: 'GenSan · both shifts are closed', route: '#dash' },
];

/* ---- 61 Branch dashboard ---- */
const branchKpis = [
    { label: 'Sales today', value: formatMoney(942000, 'whole'), sub: '8% vs yesterday by this time', trend: 'up' as const },
    { label: 'Occupancy now', value: '50%', sub: '23 of 46 rooms' },
    { label: 'In / out today', value: '14 / 10', sub: '19 from last night' },
    { label: 'Unpaid balances', value: formatMoney(11500, 'whole'), sub: '1 folio' },
];
const roomsNow = [
    { name: 'Vacant', value: 14, color: toneFill.ok },
    { name: 'Occupied', value: 23, color: toneFill.info },
    { name: 'To clean', value: 5, color: toneFill.clay },
    { name: 'Cleaning', value: 2, color: toneFill.violet },
    { name: 'Held', value: 1, color: toneFill.primary },
    { name: 'Blocked', value: 1, color: toneFill.neutral },
];
const hours = Array.from({ length: 12 }, (_, i) => 8 + i);
const hourSales = [180200, 265000, 196100, 137800, 0, 0, 0, 0, 0, 0, 0, 0];
const hourPeak = computed(() => peakOf(hourSales));
const branchAttention: AttentionRow[] = [
    { severity: 'high', title: 'Room 117 overdue 42 min', detail: 'Dennis Abella · open folio', route: '#dash' },
    { severity: 'normal', title: 'Coke 1.5 L is out of stock', detail: 'Chips low: 4 left', route: '#dash' },
    { severity: 'normal', title: 'Liza Gonzaga has no PIN', detail: 'She cannot join a shift', route: '#dash' },
];

/* ---- 62 Today ---- */
const todayKpis = [
    { label: 'Arriving', value: 2, sub: 'reservations' },
    { label: 'Due out soon', value: 3, sub: '106, 116, 208' },
    { label: 'Overdue', value: 1, sub: 'Room 117', tone: 'bad' as const },
    { label: 'Unpaid', value: 1, sub: 'Room 205' },
    { label: 'Kiosk holds', value: 1, sub: '6:42 left' },
];

/* ---- 63 Stats ---- */
const statsKpis = [
    { label: 'Sales', value: formatMoney(37842000, 'whole'), sub: '5% vs 1–10 Oct', trend: 'up' as const },
    { label: 'Stays', value: 566, sub: '57 a day' },
    { label: 'Average per stay', value: formatMoney(66900, 'whole'), sub: 'incl. food and extras' },
    { label: 'Busiest hour', value: '9 PM', sub: 'Saturdays' },
];
const heatHours = Array.from({ length: 14 }, (_, i) => 8 + i);
const days: Weekday[] = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
/** Deterministic sample: busier in the evening and at the weekend. */
const heat = Object.fromEntries(days.map((d, di) => [d, heatHours.map((h, hi) => Math.max(0, Math.round((hi * 0.9 + (di >= 4 ? 4 : 0) + ((di * 7 + hi * 3) % 5)) * (h >= 20 ? 1.4 : 1)) - 3))])) as Record<Weekday, number[]>;
const chose = [
    { name: '6 hrs', value: 38 },
    { name: '12 hrs', value: 31 },
    { name: '18 hrs', value: 6 },
    { name: '24 hrs', value: 12 },
    { name: 'Overnight', value: 13 },
];
const byType = [
    { name: 'Standard', value: 16830000, text: formatMoney(16830000, 'wholeColumn') },
    { name: 'Deluxe', value: 14495000, text: formatMoney(14495000, 'wholeColumn') },
    { name: 'Suite', value: 3060000, text: formatMoney(3060000, 'wholeColumn') },
    { name: 'Food & extras', value: 3457000, text: formatMoney(3457000, 'wholeColumn') },
];
const twoSeries = [
    { name: 'This week', values: [4420600, 4135400, 4991000, 6274400, 7130000, 5775300, 4705800] },
    { name: 'Last week', values: [3900000, 4300000, 4500000, 5800000, 6600000, 5200000, 4400000] },
];
</script>

<template>
    <div class="flex flex-col gap-8">
        <!-- 60 -->
        <div>
            <h3 class="mb-2 text-sm font-semibold text-highlighted">60 · Owner dashboard</h3>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <PeriodSegments
                    v-model="ownerPeriod"
                    :items="[
                        { value: 'today', label: 'Today' },
                        { value: 'yesterday', label: 'Yesterday' },
                        { value: 'week', label: 'This week' },
                        { value: 'month', label: 'This month' },
                        { value: 'year', label: 'This year' },
                    ]"
                />
                <span class="text-xs text-default" role="status">Updated 11:44 AM · refreshes every 30 s</span>
            </div>
            <StatCards :items="ownerKpis" :loading="loading" />
            <div class="mt-4 grid grid-cols-1 items-start gap-4 min-[1100px]:grid-cols-[minmax(0,1fr)_340px]">
                <div class="flex min-w-0 flex-col gap-4">
                    <div class="rounded-card border border-default bg-default p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">Sales · last 7 days</h4>
                            <span v-if="weekPeak && !empty && !loading" class="text-xs text-muted">Busiest: {{ weekLabels[weekPeak.index] }} {{ peso(weekPeak.value) }}</span>
                        </div>
                        <VBars class="mt-2" :labels="weekLabels" :titles="weekTitles" :series="[{ name: 'Sales', values: empty ? [0, 0, 0, 0, 0, 0, 0] : weekSales }]" money :loading="loading" empty-text="No sales in this period" caption="Sales, last 7 days" />
                    </div>
                    <div class="rounded-card border border-default bg-default p-4">
                        <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">How guests paid · today</h4>
                        <HBars class="mt-1" :rows="empty ? [] : payRows" :loading="loading" empty-text="No payments today" />
                    </div>
                </div>
                <AttentionList class="min-[1100px]:sticky min-[1100px]:top-4" :rows="empty ? [] : ownerAttention" :loading="loading" more-route="#dash" :limit="3" />
            </div>
            <div class="mt-4 rounded-card border border-default bg-default p-4">
                <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">Branches now</h4>
                <div class="mt-1 overflow-x-auto">
                    <table class="dtable">
                        <thead>
                            <tr>
                                <th scope="col">Branch</th>
                                <th scope="col">Shift</th>
                                <th scope="col" class="r">Rooms</th>
                                <th scope="col" class="r">Sales</th>
                                <th scope="col" class="r">7 days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="whitespace-normal">
                                    Dalisay GenSan
                                    <div class="text-xs text-muted"><span class="live" aria-hidden="true" />Jessa Mae, Rhea on duty</div>
                                </td>
                                <td><StatusLabel tone="ok" label="AM · 7:58 AM" /></td>
                                <td class="r">23 / 46</td>
                                <td class="r">{{ formatMoney(942000, 'column') }}</td>
                                <td class="r"><Spark :values="[44, 41, 50, 63, 71, 58, 47]" tone="ok" label="Sales, last 7 days" /></td>
                            </tr>
                            <tr>
                                <td class="whitespace-normal">
                                    Dalisay Polomolok*
                                    <div class="text-xs text-muted">Nobody on duty</div>
                                </td>
                                <td><StatusLabel tone="warn" label="No open shift" /></td>
                                <td class="r">9 / 28</td>
                                <td class="r">{{ formatMoney(0, 'column') }}</td>
                                <td class="r"><Spark :values="[12, 10, 9, 8, 8, 6, 5]" tone="bad" label="Sales, last 7 days" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <p class="mt-2 text-xs text-default">* Sample branch.</p>
        </div>

        <!-- 61 -->
        <div>
            <h3 class="mb-2 text-sm font-semibold text-highlighted">61 · Branch dashboard</h3>
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <PeriodSegments
                    v-model="branchPeriod"
                    :items="[
                        { value: 'today', label: 'Today' },
                        { value: 'yesterday', label: 'Yesterday' },
                        { value: 'week', label: 'This week' },
                        { value: 'month', label: 'This month' },
                    ]"
                />
                <span class="flex items-center gap-2 text-xs text-default" role="status">
                    Could not refresh.
                    <UButton color="primary" variant="link" size="sm" icon="i-lucide-refresh-cw" label="Retry" class="px-0" />
                </span>
            </div>
            <StatCards :items="branchKpis" :loading="loading" />
            <div class="mt-4 grid grid-cols-1 items-start gap-4 min-[1100px]:grid-cols-[minmax(0,1fr)_340px]">
                <div class="flex min-w-0 flex-col gap-4">
                    <div class="rounded-card border border-default bg-default p-4">
                        <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">Rooms now</h4>
                        <Shares :rows="empty ? [] : roomsNow" :loading="loading" caption="Rooms now" empty-text="No rooms set up yet" />
                    </div>
                    <div class="rounded-card border border-default bg-default p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">Sales by hour · today</h4>
                            <span v-if="hourPeak && !empty && !loading" class="text-xs text-muted">Busiest: {{ formatHour(hours[hourPeak.index]) }} {{ peso(hourPeak.value) }}</span>
                        </div>
                        <VBars
                            class="mt-2"
                            :labels="hours.map(formatAxisHour)"
                            :titles="hours.map(formatHour)"
                            :series="[{ name: 'Sales', values: empty ? hours.map(() => 0) : hourSales }]"
                            money
                            :height="120"
                            :loading="loading"
                            empty-text="No sales yet today"
                            caption="Sales by hour, today"
                        />
                    </div>
                    <div class="rounded-card border border-default bg-default p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">AM shift · open since 7:58 AM</h4>
                            <StatusLabel tone="ok" label="Open" />
                        </div>
                        <div class="mt-1 overflow-x-auto">
<table class="dtable">
                            <thead>
                                <tr>
                                    <th scope="col">Front desk</th>
                                    <th scope="col" class="r">Check-ins</th>
                                    <th scope="col" class="r">Collected</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><span class="live" aria-hidden="true" />Jessa Mae Dela Cruz</td>
                                    <td class="r">9</td>
                                    <td class="r">{{ formatMoney(587000, 'column') }}</td>
                                </tr>
                                <tr>
                                    <td><span class="live" aria-hidden="true" />Rhea Bautista</td>
                                    <td class="r">5</td>
                                    <td class="r">{{ formatMoney(343500, 'column') }}</td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col gap-4">
                    <AttentionList :rows="empty ? [] : branchAttention" :loading="loading" />
                    <div class="rounded-card border border-default bg-default p-4">
                        <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">Housekeeping today</h4>
                        <div class="mt-1">
                            <MoneyRow label="Rooms cleaned" text="12" />
                            <MoneyRow label="Average" text="15 min" />
                            <MoneyRow label="Late" text="0" />
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 62 -->
        <div>
            <h3 class="mb-2 text-sm font-semibold text-highlighted">62 · Front desk — today</h3>
            <StatCards :items="todayKpis" :loading="loading" :count="5" />
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="rounded-card border border-default bg-default p-4">
                    <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">Leaving soon</h4>
                    <table class="dtable mt-1">
                        <tbody>
                            <tr>
                                <td class="num">116</td>
                                <td>E. Cruz</td>
                                <td class="text-right"><StatusLabel tone="warn" label="9 min" /></td>
                            </tr>
                            <tr>
                                <td class="num">106</td>
                                <td>L. Ramos</td>
                                <td class="text-right"><StatusLabel tone="warn" label="22 min" /></td>
                            </tr>
                            <tr>
                                <td class="num">117</td>
                                <td>D. Abella</td>
                                <td class="text-right"><StatusLabel tone="bad" label="42 min over" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="rounded-card border border-default bg-default p-4">
                    <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">Arriving</h4>
                    <table class="dtable mt-1">
                        <tbody>
                            <tr>
                                <td>2:00 PM</td>
                                <td>Maria Lourdes Fajardo</td>
                                <td>3A · 2 nights</td>
                            </tr>
                            <tr>
                                <td>6:00 PM</td>
                                <td>Jerome Pascual</td>
                                <td>Any Deluxe · 12 hrs</td>
                            </tr>
                        </tbody>
                    </table>
                    <h4 class="mt-3 text-[13px] font-semibold text-highlighted">Rooms</h4>
                    <Shares class="mt-1" :rows="roomsNow.filter((r) => ['Vacant', 'To clean', 'Cleaning'].includes(r.name))" caption="Rooms" />
                    <div class="mt-3 flex flex-wrap gap-2">
                        <UButton label="Open room board" />
                        <UButton color="neutral" variant="outline" label="Walk-in check-in" />
                    </div>
                </div>
            </div>
            <p class="mt-2 text-xs text-default">Counts only. Sales and drawer amounts are never shown to the front desk during a shift.</p>
        </div>

        <!-- 63 -->
        <div>
            <h3 class="mb-2 text-sm font-semibold text-highlighted">63 · Stats</h3>
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <UButton color="neutral" variant="outline" leading-icon="i-lucide-chevron-left" trailing-icon="i-lucide-chevron-right" label="1 – 10 Nov 2026" />
                <PeriodSegments
                    v-model="statsPeriod"
                    :items="[
                        { value: '7d', label: '7 days' },
                        { value: 'month', label: 'This month' },
                        { value: 'last', label: 'Last month' },
                    ]"
                />
                <UButton class="ms-auto" color="neutral" variant="outline" label="Export" trailing-icon="i-lucide-chevron-down" />
            </div>
            <StatCards :items="statsKpis" :loading="loading" />
            <div class="mt-4 grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
                <div class="rounded-card border border-default bg-default p-4">
                    <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">Check-ins by day and hour</h4>
                    <HeatGrid class="mt-2" :hours="heatHours.map(formatAxisHour)" :hour-titles="heatHours.map(formatHour)" :grid="empty ? {} : heat" :loading="loading" />
                </div>
                <div class="flex flex-col gap-4">
                    <div class="rounded-card border border-default bg-default p-4">
                        <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">What guests chose</h4>
                        <Shares :rows="empty ? [] : chose" legend="percent" :loading="loading" caption="What guests chose" empty-text="No stays in this period" />
                    </div>
                    <div class="rounded-card border border-default bg-default p-4">
                        <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">Sales by room type</h4>
                        <HBars class="mt-1" :rows="empty ? [] : byType" :loading="loading" empty-text="No sales in this period" />
                    </div>
                </div>
            </div>
            <div class="mt-4 rounded-card border border-default bg-default p-4">
                <h4 class="text-[15px] leading-[22px] font-semibold text-highlighted">Two series · this week and last</h4>
                <VBars class="mt-2" :labels="weekLabels" :titles="weekTitles" :series="empty ? [] : twoSeries" money :loading="loading" caption="Sales this week and last week" />
            </div>
        </div>
    </div>
</template>
