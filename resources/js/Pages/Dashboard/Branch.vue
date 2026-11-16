<!-- Mockup 61 -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AttentionList, { type AttentionRow } from '@/Components/AttentionList.vue';
import Shares from '@/Components/Charts/Shares.vue';
import VBars from '@/Components/Charts/VBars.vue';
import { peakOf, peso } from '@/Components/Charts/chart';
import MoneyRow from '@/Components/MoneyRow.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import type { StatCardProps } from '@/Components/StatCard.vue';
import StatCards from '@/Components/StatCards.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { changeCard, useDashboard } from '@/Composables/useDashboard';
import { formatAxisHour, formatDateTime, formatHour } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';
import { toneFill } from '@/types/ui';

/**
 * Branch dashboard (61): sales with the same-time comparison, occupancy now, guests in and out,
 * unpaid balances; rooms by status, sales by hour, the open shift for managers, housekeeping
 * today and the top of "Needs attention". Every figure is a deferred prop that arrives on its
 * own (a skeleton until then) and is reloaded every 30 seconds.
 */
interface Rooms {
    total: number;
    occupied: number;
    vacant: number;
    to_clean: number;
    cleaning: number;
    held: number;
    blocked: number;
}

const props = defineProps<{
    period: string;
    branch: { id: number; name: string; status: string };
    polling: number;
    kpis?: {
        totals: { period: string; compared_with: string; sales: number; change: number | null; checkins: number; checkouts: number; short: number; over: number; from_last_night: number | null };
        rooms: Rooms;
        unpaid: { amount: number; folios: number };
        at: string;
    };
    charts?: { rooms: Rooms; hours: { start_hour: number; hours: number[]; sales: number[] }; period: string };
    shift?: {
        shift: { id: number; period_name: string; status: string; opened_at: string; members: { id: number; name: string; checkins: number; collected: number }[] } | null;
        housekeeping: { cleans: number; late: number; average_minutes: number | null } | null;
    };
    attention?: { rows: AttentionRow[]; more: string | null };
}>();

const groups = ['kpis', 'charts', 'shift', 'attention'];
const at = computed(() => props.kpis?.at);
const { failed, refresh, updatedText, go } = useDashboard(groups, props.polling, at);

const period = ref(props.period);
watch(period, (p) => go('dashboard', { period: p === 'today' ? null : p }));

const periods = [
    { value: 'today', label: 'Today' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: 'week', label: 'This week' },
    { value: 'month', label: 'This month' },
];

const cards = computed<StatCardProps[]>(() => {
    const k = props.kpis;
    if (!k) {
        return [];
    }

    const t = k.totals;
    const occ = k.rooms.total === 0 ? 0 : Math.round((k.rooms.occupied * 100) / k.rooms.total);

    return [
        { label: `Sales ${t.period}`, value: formatMoney(t.sales, 'whole'), ...changeCard(t.change, t.compared_with) },
        { label: 'Occupancy now', value: `${occ}%`, sub: `${k.rooms.occupied} of ${k.rooms.total} rooms` },
        { label: `In / out ${t.period}`, value: `${t.checkins} / ${t.checkouts}`, sub: t.from_last_night === null ? undefined : `${t.from_last_night} from last night` },
        { label: 'Unpaid balances', value: formatMoney(k.unpaid.amount, 'whole'), sub: `${k.unpaid.folios} ${k.unpaid.folios === 1 ? 'folio' : 'folios'} in house` },
    ];
});

const roomsNow = computed(() => {
    const r = props.charts?.rooms;
    if (!r || r.total === 0) {
        return [];
    }

    return [
        { name: 'Vacant', value: r.vacant, color: toneFill.ok },
        { name: 'Occupied', value: r.occupied, color: toneFill.info },
        { name: 'To clean', value: r.to_clean, color: toneFill.clay },
        { name: 'Cleaning', value: r.cleaning, color: toneFill.violet },
        { name: 'Held', value: r.held, color: toneFill.primary },
        { name: 'Blocked', value: r.blocked, color: toneFill.neutral },
    ].filter((row) => row.value > 0);
});

const hours = computed(() => props.charts?.hours.hours ?? []);
const hourSales = computed(() => props.charts?.hours.sales ?? []);
const hourPeak = computed(() => peakOf(hourSales.value));
const hasSales = computed(() => hourSales.value.some((v) => v !== 0));

const shift = computed(() => props.shift?.shift ?? null);
const hk = computed(() => props.shift?.housekeeping ?? null);
</script>

<template>
    <Head title="Dashboard" />
    <BackOfficeLayout current="dashboard" title="Dashboard">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <PeriodSegments v-model="period" :items="periods" />
            <span v-if="failed" class="flex items-center gap-2 text-xs text-default" role="status">
                Could not refresh.
                <UButton color="primary" variant="link" size="sm" icon="i-lucide-refresh-cw" label="Retry" class="px-0" @click="refresh" />
            </span>
            <span v-else class="text-xs text-muted" role="status" data-updated>{{ updatedText }} · refreshes every {{ polling }} s</span>
        </div>

        <StatCards :items="cards" :loading="!kpis" />

        <div class="mt-4 grid grid-cols-1 items-start gap-4 min-[1100px]:grid-cols-[minmax(0,1fr)_340px]">
            <div class="flex min-w-0 flex-col gap-4">
                <section class="rounded-card border border-default bg-default p-4">
                    <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Rooms now</h2>
                    <Shares :rows="roomsNow" :loading="!charts" caption="Rooms now" empty-text="No rooms set up yet" />
                </section>

                <section class="rounded-card border border-default bg-default p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Sales by hour · {{ charts?.period ?? 'today' }}</h2>
                        <span v-if="charts && hasSales && hourPeak" class="text-xs text-muted">Busiest: {{ formatHour(hours[hourPeak.index]) }} {{ peso(hourPeak.value) }}</span>
                    </div>
                    <VBars
                        class="mt-2"
                        :labels="hours.map(formatAxisHour)"
                        :titles="hours.map(formatHour)"
                        :series="hasSales ? [{ name: 'Sales', values: hourSales }] : []"
                        money
                        :height="120"
                        :loading="!charts"
                        :empty-text="`No sales ${charts?.period ?? 'today'}`"
                        caption="Sales by hour"
                    />
                </section>

                <section v-if="!shift && !props.shift" class="rounded-card border border-default bg-default p-4" aria-hidden="true">
                    <USkeleton class="h-5 w-48" />
                    <USkeleton class="mt-3 h-4 w-full" />
                    <USkeleton class="mt-2 h-4 w-full" />
                </section>
                <section v-else-if="shift" class="rounded-card border border-default bg-default p-4" data-open-shift>
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">{{ shift.period_name }} shift · open since {{ formatDateTime(shift.opened_at, 'time') }}</h2>
                        <StatusLabel :tone="shift.status === 'open' ? 'ok' : 'warn'" :label="shift.status === 'open' ? 'Open' : 'Closing'" />
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
                                <tr v-for="m in shift.members" :key="m.id">
                                    <td><span class="live" aria-hidden="true" />{{ m.name }}</td>
                                    <td class="r">{{ m.checkins }}</td>
                                    <td class="r">{{ formatMoney(m.collected, 'column') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
                <section v-else class="rounded-card border border-default bg-default p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Shift</h2>
                        <StatusLabel tone="warn" label="No open shift" />
                    </div>
                    <p class="mt-1 text-sm text-muted">Nobody is on duty at the front desk right now.</p>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <AttentionList :rows="attention?.rows ?? []" :loading="!attention" :limit="5" :more-route="attention?.more ?? undefined" />
                <section v-if="!props.shift || hk" class="rounded-card border border-default bg-default p-4">
                    <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Housekeeping today</h2>
                    <div v-if="!props.shift" class="mt-2" aria-hidden="true">
                        <USkeleton v-for="i in 3" :key="i" class="mt-2 h-4 w-full" />
                    </div>
                    <div v-else-if="hk" class="mt-1">
                        <MoneyRow label="Rooms cleaned" :text="String(hk.cleans)" />
                        <MoneyRow label="Average" :text="hk.average_minutes === null ? '—' : `${hk.average_minutes} min`" />
                        <MoneyRow label="Late" :text="String(hk.late)" />
                    </div>
                </section>
            </div>
        </div>
    </BackOfficeLayout>
</template>
