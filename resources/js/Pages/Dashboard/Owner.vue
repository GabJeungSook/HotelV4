<!-- Mockup 60 -->
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AttentionList, { type AttentionRow } from '@/Components/AttentionList.vue';
import HBars from '@/Components/Charts/HBars.vue';
import VBars from '@/Components/Charts/VBars.vue';
import { peakOf, peso } from '@/Components/Charts/chart';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import type { StatCardProps } from '@/Components/StatCard.vue';
import StatCards from '@/Components/StatCards.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { changeCard, useDashboard } from '@/Composables/useDashboard';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';

/**
 * Owner dashboard (60): all branches or one. The period switch sets every card and each card
 * says the period it covers; the change compares today with yesterday by this time. "Branches
 * now" is live; "How guests paid" and the week of sales come from the summary tables. Short
 * and over are shown as two sums, never netted.
 */
const props = defineProps<{
    period: string;
    branch: number | 'all';
    branches: { id: number; name: string }[];
    polling: number;
    kpis?: {
        totals: { period: string; compared_with: string; sales: number; change: number | null; checkins: number; checkouts: number; short: number; over: number };
        rooms: { total: number; occupied: number };
        branches: number;
        at: string;
    };
    charts?: { week: { date: string; sales: number }[]; methods: { method: string; amount: number; count: number }[] };
    now?: {
        branches: { id: number; name: string; status: string; shift: { period_name: string; opened_at: string } | null; on_duty: string[]; occupied: number; rooms: number; sales: number }[];
    };
    attention?: { rows: AttentionRow[]; more: string | null };
}>();

const at = computed(() => props.kpis?.at);
const { failed, refresh, updatedText, go } = useDashboard(['kpis', 'charts', 'now', 'attention'], props.polling, at);

const period = ref(props.period);
const branch = ref<string>(String(props.branch));
watch([period, branch], ([p, b]) => go('owner.dashboard', { period: p === 'today' ? null : p, branch: b === 'all' ? null : b }));

const periods = [
    { value: 'today', label: 'Today' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: 'week', label: 'This week' },
    { value: 'month', label: 'This month' },
    { value: 'year', label: 'This year' },
];
const branchItems = computed(() => [{ label: 'All branches', value: 'all' }, ...props.branches.map((b) => ({ label: b.name, value: String(b.id) }))]);

const cards = computed<StatCardProps[]>(() => {
    const k = props.kpis;
    if (!k) {
        return [];
    }

    const t = k.totals;
    const occ = k.rooms.total === 0 ? 0 : Math.round((k.rooms.occupied * 100) / k.rooms.total);

    return [
        { label: `Sales ${t.period}`, value: formatMoney(t.sales, 'whole'), ...changeCard(t.change, t.compared_with) },
        { label: 'Occupied now', value: `${k.rooms.occupied} of ${k.rooms.total}`, sub: `${occ}% · ${k.branches} ${k.branches === 1 ? 'branch' : 'branches'}` },
        { label: `Check-ins ${t.period}`, value: t.checkins, sub: `${t.checkouts} check-outs` },
        { label: `Short · ${t.period}`, value: t.short === 0 ? formatMoney(0, 'whole') : formatMoney(-t.short, 'whole'), sub: `Over ${formatMoney(t.over, 'whole')}`, tone: t.short > 0 ? 'bad' : 'default' },
    ];
});

const weekLabels = computed(() => (props.charts?.week ?? []).map((d) => formatDateTime(`${d.date}T12:00:00+08:00`, 'businessDate').slice(0, 3)));
const weekTitles = computed(() => (props.charts?.week ?? []).map((d) => formatDateTime(`${d.date}T12:00:00+08:00`, 'businessDate')));
const weekSales = computed(() => (props.charts?.week ?? []).map((d) => d.sales));
const weekPeak = computed(() => peakOf(weekSales.value));
const hasWeek = computed(() => weekSales.value.some((v) => v !== 0));

const methodNames: Record<string, string> = { cash: 'Cash', gcash: 'GCash', card: 'Card', deposit: 'From deposit' };
const payRows = computed(() => {
    const rows = props.charts?.methods ?? [];
    const total = rows.reduce((a, r) => a + r.amount, 0);
    if (total === 0) {
        return [];
    }

    return rows.map((r) => ({ name: methodNames[r.method] ?? r.method, value: r.amount, text: formatMoney(r.amount, 'column'), total }));
});

function firstNames(names: string[]): string {
    return names.map((n) => n.split(' ').slice(0, n.split(' ').length > 2 ? 2 : 1).join(' ')).join(', ');
}
</script>

<template>
    <Head title="Dashboard" />
    <OwnerLayout current="owner" title="Dashboard">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <PeriodSegments v-model="period" :items="periods" />
                <USelect v-if="branches.length > 1" v-model="branch" :items="branchItems" class="w-52" aria-label="Branch" />
            </div>
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
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Sales · last 7 days</h2>
                        <span v-if="charts && hasWeek && weekPeak" class="text-xs text-muted">Busiest: {{ weekLabels[weekPeak.index] }} {{ peso(weekPeak.value) }}</span>
                    </div>
                    <VBars
                        class="mt-2"
                        :labels="weekLabels"
                        :titles="weekTitles"
                        :series="hasWeek ? [{ name: 'Sales', values: weekSales }] : []"
                        money
                        :loading="!charts"
                        empty-text="No sales in the last 7 days"
                        caption="Sales, last 7 days"
                    />
                </section>
                <section class="rounded-card border border-default bg-default p-4" data-methods>
                    <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">How guests paid · {{ kpis?.totals.period ?? 'today' }}</h2>
                    <HBars class="mt-1" :rows="payRows" :loading="!charts" :empty-text="`No payments ${kpis?.totals.period ?? 'today'}`" />
                </section>
            </div>
            <AttentionList class="min-[1100px]:sticky min-[1100px]:top-4" :rows="attention?.rows ?? []" :loading="!attention" :limit="5" :more-route="attention?.more ?? undefined" />
        </div>

        <section class="mt-4 rounded-card border border-default bg-default p-4">
            <h2 class="text-[15px] leading-[22px] font-semibold text-highlighted">Branches now</h2>
            <div v-if="!now" class="mt-2" aria-hidden="true">
                <USkeleton v-for="i in 2" :key="i" class="mt-2 h-8 w-full" />
            </div>
            <div v-else class="mt-1 overflow-x-auto">
                <table class="dtable" data-branches-now>
                    <thead>
                        <tr>
                            <th scope="col">Branch</th>
                            <th scope="col">Shift</th>
                            <th scope="col" class="r">Rooms</th>
                            <th scope="col" class="r">Sales today</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="b in now.branches" :key="b.id">
                            <td class="whitespace-normal">
                                {{ b.name }}
                                <div class="text-xs text-muted">
                                    <template v-if="b.on_duty.length"><span class="live" aria-hidden="true" />{{ firstNames(b.on_duty) }} on duty</template>
                                    <template v-else>Nobody on duty</template>
                                </div>
                            </td>
                            <td>
                                <StatusLabel v-if="b.status === 'setting_up'" label="Setting up" />
                                <StatusLabel v-else-if="b.shift" tone="ok" :label="`${b.shift.period_name} · ${formatDateTime(b.shift.opened_at, 'time')}`" />
                                <StatusLabel v-else tone="warn" label="No open shift" />
                            </td>
                            <td class="r">{{ b.occupied }} / {{ b.rooms }}</td>
                            <td class="r">{{ formatMoney(b.sales, 'column') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </OwnerLayout>
</template>
