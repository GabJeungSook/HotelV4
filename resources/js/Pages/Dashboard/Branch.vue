<!-- Mockup 61 -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AttentionList, { type AttentionRow } from '@/Components/AttentionList.vue';
import StackedBars from '@/Components/Charts/StackedBars.vue';
import FilterSelect from '@/Components/FilterSelect.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import PersonPill from '@/Components/PersonPill.vue';
import SalesOverview, { type Overview } from '@/Components/SalesOverview.vue';
import ShiftCalendar, { type CalendarBand, type CalendarDay } from '@/Components/ShiftCalendar.vue';
import type { StatCardProps } from '@/Components/StatCard.vue';
import StatCards from '@/Components/StatCards.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { changeCard, useDashboard } from '@/Composables/useDashboard';
import { formatAgo, formatDateRange, formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';

/**
 * Branch dashboard (61, mockup v6): three stat cards, rooms in use per night as stacked bars, the
 * sales overview with how guests paid, the latest check-ins with people, and on the right the week
 * of shifts (ShiftCalendar) and Needs attention. Every figure is a deferred prop that arrives on
 * its own (a skeleton until then) and is reloaded every 30 seconds. Managers only.
 */
const props = defineProps<{
    period: string;
    overviewPeriod: string;
    filters: { week: string | null; desk: number | null; band: string | null };
    branch: { id: number; name: string; status: string };
    polling: number;
    kpis?: {
        totals: { period: string; compared_with: string; sales: number; previous: number | null; change: number | null; checkins: number; checkouts: number; from_last_night: number | null };
        rooms: { total: number; occupied: number };
        at: string;
    };
    charts?: { nights: { total: number; days: { date: string; occupied: number }[] }; period: string };
    overview?: Overview;
    recent?: { rows: { stay_id: number; room: string; guest: string; at: string }[] };
    calendar?: {
        days: CalendarDay[];
        bands: CalendarBand[];
        range: { from: string; to: string };
        previous: string;
        next: string;
        desks: { value: number; label: string }[];
        periods: string[];
        open: string | null;
    };
    attention?: { rows: AttentionRow[]; more: string | null };
}>();

const groups = ['kpis', 'charts', 'overview', 'recent', 'calendar', 'attention'];
const at = computed(() => props.kpis?.at);
const { failed, refresh, updatedText } = useDashboard(groups, props.polling, at);

/** Every control writes the query string, so Back steps through them; only the affected cards reload. */
function visit(changes: Record<string, unknown>, only?: string[]): void {
    const query = { period: props.period === 'today' ? null : props.period, overview: props.overviewPeriod === 'week' ? null : props.overviewPeriod, ...props.filters, ...changes };
    const clean = Object.fromEntries(Object.entries(query).filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== 'all'));
    router.get(route('dashboard'), clean as never, { preserveScroll: true, preserveState: true, only });
}

const period = ref(props.period);
watch(period, (p) => visit({ period: p === 'today' ? null : p }));
const periods = [
    { value: 'today', label: 'Today' },
    { value: 'yesterday', label: 'Yesterday' },
    { value: 'week', label: 'This week' },
    { value: 'month', label: 'This month' },
];

const overviewPeriod = ref(props.overviewPeriod);
watch(overviewPeriod, (p) => visit({ overview: p === 'week' ? null : p }, ['overview', 'overviewPeriod']));

const desk = ref<string | number>(props.filters.desk ?? 'all');
watch(desk, (d) => visit({ desk: d === 'all' ? null : d }, ['calendar', 'filters']));
const band = ref<string>(props.filters.band ?? 'all');
watch(band, (b) => visit({ band: b === 'all' ? null : b }, ['calendar', 'filters']));
const deskItems = computed(() => [{ value: 'all', label: 'All front desks' }, ...(props.calendar?.desks ?? [])]);
const bandItems = computed(() => [{ value: 'all', label: 'All' }, ...(props.calendar?.periods ?? []).map((p) => ({ value: p, label: p }))]);

const cards = computed<StatCardProps[]>(() => {
    const k = props.kpis;
    if (!k) {
        return [];
    }

    const t = k.totals;
    const occ = k.rooms.total === 0 ? 0 : Math.round((k.rooms.occupied * 100) / k.rooms.total);
    const since = t.from_last_night === null ? null : k.rooms.occupied - t.from_last_night;

    return [
        {
            label: `Sales ${t.period}`,
            value: formatMoney(t.sales, 'whole'),
            ...changeCard(t.change, t.compared_with, t.previous === null ? undefined : `vs ${formatMoney(t.previous, 'whole')} ${t.compared_with.replace(/^vs /, '')}`),
        },
        {
            label: 'Occupancy now',
            value: `${occ}%`,
            sub: `${k.rooms.occupied} of ${k.rooms.total} rooms`,
            ...(since === null || since === 0 ? {} : { change: `${since > 0 ? '+' : '−'}${Math.abs(since)} since last night`, trend: since > 0 ? ('up' as const) : ('down' as const) }),
        },
        {
            label: `In · out ${t.period}`,
            value: `${t.checkins} · ${t.checkouts}`,
            sub: t.from_last_night === null ? undefined : `${t.from_last_night} from last night`,
        },
    ];
});

const nights = computed(() => props.charts?.nights.days ?? []);
const nightLabels = computed(() => nights.value.map((d) => String(Number(d.date.slice(8, 10)))));
const nightTitles = computed(() => nights.value.map((d) => formatDateTime(`${d.date}T12:00:00+08:00`, 'businessDate')));

const sub = computed(() => `${props.branch.name} · ${updatedText.value.replace(/^Updated/, 'updated')}, refreshes every ${props.polling} s`);

const weekLabel = computed(() => (props.calendar ? formatDateRange(`${props.calendar.range.from}T12:00:00+08:00`, `${props.calendar.range.to}T12:00:00+08:00`) : ''));
</script>

<template>
    <BackOfficeLayout current="dashboard" title="Dashboard" :subtitle="sub">
        <PageHeader>
            <span v-if="failed" class="flex items-center gap-2 text-xs text-default" role="status">
                Could not refresh.
                <UButton color="primary" variant="link" size="sm" icon="i-lucide-refresh-cw" label="Retry" class="px-0" @click="refresh" />
            </span>
            <PeriodSegments v-model="period" :items="periods" />
        </PageHeader>

        <div class="grid grid-cols-1 items-start gap-4 min-[1180px]:grid-cols-[minmax(0,1fr)_384px]">
            <div class="flex min-w-0 flex-col gap-4">
                <StatCards :items="cards" :loading="!kpis" :count="3" />

                <section class="rounded-card border border-default bg-default px-5 py-[18px]" data-occupancy>
                    <div class="mb-3.5 flex min-h-[30px] flex-wrap items-center gap-2.5">
                        <div>
                            <h2 class="text-[14.5px] leading-[1.3] font-semibold text-highlighted">Occupancy</h2>
                            <small class="block text-xs text-muted">Rooms in use at the end of each business day · last 14 days</small>
                        </div>
                    </div>
                    <StackedBars
                        :labels="nightLabels"
                        :titles="nightTitles"
                        :series="[{ name: 'Occupied', values: nights.map((d) => d.occupied) }]"
                        :current="nights.length - 1"
                        :max="charts?.nights.total || undefined"
                        unit="rooms"
                        :loading="!charts"
                        empty-text="No nights recorded yet"
                        caption="Rooms in use per night"
                    />
                </section>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <SalesOverview :overview="overview" title="Sales overview">
                        <template #control>
                            <FilterSelect
                                v-model="overviewPeriod"
                                :items="[
                                    { value: 'today', label: 'Today' },
                                    { value: 'week', label: 'This week' },
                                    { value: 'month', label: 'This month' },
                                ]"
                                label="Sales overview period"
                            />
                        </template>
                    </SalesOverview>

                    <section class="overflow-hidden rounded-card border border-default bg-default" data-recent>
                        <div class="mb-3 flex min-h-[30px] items-center gap-2.5 px-5 pt-4">
                            <h2 class="text-[14.5px] font-semibold text-highlighted">Recent check-ins</h2>
                            <UButton :to="route('reports.occupancy')" variant="ghost" size="sm" label="View all" class="ms-auto" />
                        </div>
                        <div v-if="!recent" class="px-5 pb-5" aria-hidden="true">
                            <USkeleton v-for="i in 5" :key="i" class="mt-3 h-6 w-full" />
                        </div>
                        <p v-else-if="recent.rows.length === 0" class="px-5 pb-6 text-[12.5px] text-muted">No check-ins yet.</p>
                        <table v-else class="dtable flush">
                            <thead>
                                <tr>
                                    <th scope="col">Room</th>
                                    <th scope="col">Guest</th>
                                    <th scope="col" class="r">Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in recent.rows" :key="r.stay_id">
                                    <td class="b">{{ r.room }}</td>
                                    <td class="w-full max-w-0 !whitespace-nowrap"><PersonPill :name="r.guest" /></td>
                                    <td class="r mn">{{ formatAgo(r.at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <section class="rounded-card border border-default bg-default px-5 py-[18px]" data-shifts>
                    <div class="mb-3.5 flex min-h-[30px] items-center gap-2.5">
                        <div>
                            <h2 class="text-[14.5px] leading-[1.3] font-semibold text-highlighted">Shifts</h2>
                            <small class="block text-xs text-muted">Who is on the desk this week</small>
                        </div>
                        <StatusLabel v-if="calendar?.open" class="ms-auto" tone="ok" :label="`${calendar.open} open`" />
                    </div>
                    <template v-if="calendar">
                        <div class="flex h-8 items-center justify-between rounded-control border border-default bg-default px-1 text-[12.5px] font-semibold text-highlighted">
                            <UButton color="neutral" variant="ghost" size="xs" icon="i-lucide-chevron-left" aria-label="Previous week" @click="visit({ week: calendar.previous }, ['calendar', 'filters'])" />
                            <span>{{ weekLabel }}</span>
                            <UButton color="neutral" variant="ghost" size="xs" icon="i-lucide-chevron-right" aria-label="Next week" @click="visit({ week: calendar.next }, ['calendar', 'filters'])" />
                        </div>
                        <div class="my-2.5 flex flex-wrap items-center justify-between gap-2">
                            <FilterSelect v-model="desk" :items="deskItems" label="Front desk" />
                            <PeriodSegments v-if="bandItems.length > 2" v-model="band" :items="bandItems" label="Shift" />
                        </div>
                        <ShiftCalendar :days="calendar.days" :bands="calendar.bands" />
                        <UButton :to="route('reports.shifts')" color="neutral" variant="outline" block class="mt-3" label="Open shift reports" />
                    </template>
                    <div v-else aria-hidden="true">
                        <USkeleton class="h-8 w-full" />
                        <USkeleton class="mt-3 h-40 w-full" />
                    </div>
                </section>
                <AttentionList :rows="attention?.rows ?? []" :loading="!attention" :limit="5" :more-route="attention?.more ?? undefined" />
            </div>
        </div>
    </BackOfficeLayout>
</template>
