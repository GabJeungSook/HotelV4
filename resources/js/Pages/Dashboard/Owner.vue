<!-- Mockup 60 -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AttentionList, { type AttentionRow } from '@/Components/AttentionList.vue';
import StackedBars from '@/Components/Charts/StackedBars.vue';
import DateRange from '@/Components/DateRange.vue';
import FilterSelect from '@/Components/FilterSelect.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import PersonStack from '@/Components/PersonStack.vue';
import SalesOverview, { type Overview } from '@/Components/SalesOverview.vue';
import type { StatCardProps } from '@/Components/StatCard.vue';
import StatCards from '@/Components/StatCards.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { changeCard, useDashboard } from '@/Composables/useDashboard';
import { formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import OwnerLayout from '@/Layouts/OwnerLayout.vue';

/**
 * Owner dashboard (60, mockup v6): all branches or one. The period switch sets every card and each
 * card says the period it covers; the change compares today with yesterday by this time. Sales by
 * branch per day as stacked bars, "Branches now" with who is on duty, Needs attention and how
 * guests paid. Short and over are shown as two sums, never netted.
 */
const props = defineProps<{
    period: string;
    range: { from: string; to: string };
    branch: number | 'all';
    branches: { id: number; name: string }[];
    polling: number;
    kpis?: {
        totals: { period: string; compared_with: string; sales: number; previous: number | null; change: number | null; checkins: number; checkouts: number; short: number; over: number };
        rooms: { total: number; occupied: number };
        branches: number;
        at: string;
    };
    charts?: {
        branches: { dates: string[]; branches: { id: number; name: string; sales: number[] }[] };
        methods: { method: string; amount: number; count: number }[];
        overview: Overview;
    };
    now?: {
        branches: { id: number; name: string; status: string; shift: { period_name: string; opened_at: string } | null; on_duty: string[]; occupied: number; rooms: number; sales: number }[];
    };
    attention?: { rows: AttentionRow[]; more: string | null };
}>();

const at = computed(() => props.kpis?.at);
const { failed, refresh, updatedText, go } = useDashboard(['kpis', 'charts', 'now', 'attention'], props.polling, at);

const period = ref(props.period);
const branch = ref<string>(String(props.branch));

/** A custom range passes from+to and clears the named period; a preset does the opposite. */
function navigate(from: string | null, to: string | null): void {
    go('owner.dashboard', {
        branch: branch.value === 'all' ? null : branch.value,
        period: from && to ? null : period.value === 'today' ? null : period.value,
        from,
        to,
    });
}
watch(branch, () => navigate(period.value === 'custom' ? props.range.from : null, period.value === 'custom' ? props.range.to : null));
watch(period, (p) => p !== 'custom' && navigate(null, null));

function applyRange(from: string, to: string): void {
    period.value = 'custom';
    navigate(from, to);
}

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
        {
            label: `Sales ${t.period}`,
            value: formatMoney(t.sales, 'whole'),
            ...changeCard(t.change, t.compared_with, t.previous === null ? undefined : `vs ${formatMoney(t.previous, 'whole')} ${t.compared_with.replace(/^vs /, '')}`),
        },
        { label: 'Occupied now', value: `${k.rooms.occupied} of ${k.rooms.total}`, sub: `${occ}% across ${k.branches} ${k.branches === 1 ? 'branch' : 'branches'}` },
        {
            label: `Short · ${t.period}`,
            value: t.short === 0 ? formatMoney(0, 'whole') : formatMoney(-t.short, 'whole'),
            sub: `Over ${formatMoney(t.over, 'whole')} · never netted`,
            tone: t.short > 0 ? 'bad' : 'default',
            trend: t.short > 0 ? 'down' : undefined,
        },
    ];
});

const salesSeries = computed(() => (props.charts?.branches.branches ?? []).map((b) => ({ name: b.name.replace(/^Hotel\s+/i, ''), values: b.sales })));
const salesLabels = computed(() => (props.charts?.branches.dates ?? []).map((d) => String(Number(d.slice(8, 10)))));
const salesTitles = computed(() => (props.charts?.branches.dates ?? []).map((d) => formatDateTime(`${d}T12:00:00+08:00`, 'businessDate')));
const sub = computed(() => `${props.branch === 'all' ? 'All branches' : (props.branches.find((b) => b.id === props.branch)?.name ?? '')} · ${updatedText.value.replace(/^Updated/, 'updated')}, refreshes every ${props.polling} s`);
</script>

<template>
    <OwnerLayout current="owner" title="Dashboard" :subtitle="sub">
        <PageHeader>
            <span v-if="failed" class="flex items-center gap-2 text-xs text-default" role="status">
                Could not refresh.
                <UButton color="primary" variant="link" size="sm" icon="i-solar-refresh-linear" label="Retry" class="px-0" @click="refresh" />
            </span>
            <FilterSelect v-if="branches.length > 1" v-model="branch" :items="branchItems" label="Branch" />
            <PeriodSegments v-model="period" :items="periods" />
            <DateRange :from="range.from" :to="range.to" @apply="applyRange" />
        </PageHeader>

        <div class="grid grid-cols-1 items-start gap-4 min-[1180px]:grid-cols-[minmax(0,1fr)_384px]">
            <div class="flex min-w-0 flex-col gap-4">
                <StatCards :items="cards" :loading="!kpis" :count="3" />

                <section class="rounded-card border border-default bg-default px-5 py-[18px]" data-sales-by-branch>
                    <div class="mb-3.5 flex min-h-[30px] items-center gap-2.5">
                        <div>
                            <h2 class="text-[14.5px] leading-[1.3] font-semibold text-highlighted">Sales by branch</h2>
                            <small class="block text-xs text-muted">Net sales per business day · last 14 days</small>
                        </div>
                        <UButton :to="route('owner.summary')" variant="link" size="sm" color="neutral" trailing-icon="i-solar-alt-arrow-right-linear" label="Branch comparison" class="ms-auto text-primary" />
                    </div>
                    <StackedBars
                        :labels="salesLabels"
                        :titles="salesTitles"
                        :series="salesSeries"
                        :current="salesLabels.length - 1"
                        money
                        :loading="!charts"
                        empty-text="No sales in the last 14 days"
                        caption="Sales by branch per day"
                    />
                </section>

                <section class="overflow-hidden rounded-card border border-default bg-default">
                    <div class="mb-3 flex min-h-[30px] items-center gap-2.5 px-5 pt-4">
                        <h2 class="text-[14.5px] font-semibold text-highlighted">Branches now</h2>
                        <UButton :to="route('owner.branches.index')" variant="ghost" size="sm" label="Manage branches" class="ms-auto" />
                    </div>
                    <div v-if="!now" class="px-5 pb-5" aria-hidden="true">
                        <USkeleton v-for="i in 2" :key="i" class="mt-2 h-8 w-full" />
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="dtable flush" data-branches-now>
                            <thead>
                                <tr>
                                    <th scope="col">Branch</th>
                                    <th scope="col">On duty</th>
                                    <th scope="col">Shift</th>
                                    <th scope="col" class="r">Rooms</th>
                                    <th scope="col" class="r">Sales today</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="b in now.branches" :key="b.id">
                                    <td class="b">{{ b.name.replace(/^Hotel\s+/i, '') }}</td>
                                    <td>
                                        <PersonStack v-if="b.on_duty.length" :names="b.on_duty" />
                                        <span v-else class="text-muted">Nobody</span>
                                    </td>
                                    <td>
                                        <StatusLabel v-if="b.status === 'setting_up'" label="Setting up" />
                                        <StatusLabel v-else-if="b.shift" tone="ok" :label="`${b.shift.period_name} · ${formatDateTime(b.shift.opened_at, 'time')}`" />
                                        <StatusLabel v-else tone="bad" label="No open shift" />
                                    </td>
                                    <td class="r">{{ b.occupied }} / {{ b.rooms }}</td>
                                    <td class="r">{{ formatMoney(b.sales, 'column') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex items-center justify-between border-t border-default px-5 py-3 text-[12.5px] text-muted">
                        <span>Sales are paid amounts, not room nights</span>
                    </div>
                </section>
            </div>

            <div class="flex flex-col gap-4">
                <AttentionList :rows="attention?.rows ?? []" :loading="!attention" :limit="5" :more-route="attention?.more ?? undefined" />
                <SalesOverview :overview="charts?.overview" title="How guests paid" :show-range="false" data-methods>
                    <template #control>
                        <span class="text-xs text-muted">{{ charts?.overview.period ?? '' }}</span>
                    </template>
                </SalesOverview>
            </div>
        </div>
    </OwnerLayout>
</template>
