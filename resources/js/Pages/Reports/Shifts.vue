<!-- Mockup 37a -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { DropdownMenuItem } from '@nuxt/ui';
import { computed, ref, watch } from 'vue';
import DateRangeChip from '@/Components/DateRangeChip.vue';
import EmptyState from '@/Components/EmptyState.vue';
import FilterBar from '@/Components/FilterBar.vue';
import FilterSelect from '@/Components/FilterSelect.vue';
import Pager from '@/Components/Pager.vue';
import PersonPill from '@/Components/PersonPill.vue';
import ShiftCalendar, { type CalendarBand, type CalendarDay } from '@/Components/ShiftCalendar.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatDateRange, formatDateTime } from '@/Composables/useDateTime';
import { formatMoney } from '@/Composables/useMoney';
import { shortName } from '@/Composables/useShared';
import BackOfficeLayout from '@/Layouts/BackOfficeLayout.vue';
import type { Tone } from '@/types/ui';

/**
 * Shift reports (37a, mockup v6): every shift of the branch, newest first, as a list or a week
 * calendar. Filters for shift, front desk, status and dates, search for a Z number or a name, and
 * pages. A row opens the shift report (37); its menu opens the Z-report (37b) or rooms by floor
 * (37c). The open shift is listed with its money columns empty. Short and over are shown apart.
 */
interface Row {
    id: number;
    business_date: string;
    period_name: string;
    status: string;
    z_no: number | null;
    opened_at: string;
    closed_at: string | null;
    members: string[];
    net_sales: number | null;
    variance: number | null;
    is_force_closed: boolean;
}

interface Filters {
    period: string | null;
    desk: number | null;
    status: string | null;
    from: string | null;
    to: string | null;
    q: string | null;
    week: string | null;
}

const props = defineProps<{
    view: 'list' | 'calendar';
    filters: Filters;
    branch: { id: number; name: string };
    options: { desks: { value: number; label: string }[]; periods: string[] };
    list: { rows: Row[]; page: number; per_page: number; total: number } | null;
    calendar: { days: CalendarDay[]; bands: CalendarBand[]; range: { from: string; to: string }; previous: string; next: string } | null;
}>();

const loading = ref(false);

function visit(changes: Partial<Filters> & { view?: string; page?: number }): void {
    const query: Record<string, unknown> = { view: props.view === 'calendar' ? 'calendar' : null, ...props.filters, page: null, ...changes };
    const clean = Object.fromEntries(Object.entries(query).filter(([, v]) => v !== null && v !== undefined && v !== '' && v !== 'all' && v !== 'list'));
    router.get(route('reports.shifts'), clean as never, { preserveScroll: true, preserveState: true, onStart: () => (loading.value = true), onFinish: () => (loading.value = false) });
}

const view = ref<string>(props.view);
watch(view, (v) => visit({ view: v }));
const search = ref(props.filters.q ?? '');
watch(search, (q) => visit({ q }));
const period = ref<string>(props.filters.period ?? 'all');
watch(period, (p) => visit({ period: p }));
const desk = ref<string | number>(props.filters.desk ?? 'all');
watch(desk, (d) => visit({ desk: d === 'all' ? null : Number(d) }));
const status = ref<string>(props.filters.status ?? 'all');
watch(status, (s) => visit({ status: s }));

const periodItems = computed(() => [{ value: 'all', label: 'All shifts' }, ...props.options.periods.map((p) => ({ value: p, label: `${p} shift` }))]);
const deskItems = computed(() => [{ value: 'all', label: 'All front desks' }, ...props.options.desks]);
const statusItems = [
    { value: 'all', label: 'Any status' },
    { value: 'open', label: 'Open' },
    { value: 'closed', label: 'Closed' },
    { value: 'short', label: 'Short' },
    { value: 'force', label: 'Force closed' },
];

const z = (n: number | null) => (n === null ? '—' : `Z-${String(n).padStart(4, '0')}`);
const day = (d: string) => formatDateTime(`${d}T12:00:00+08:00`, 'businessDate').replace(/ \d{4}$/, '');
const periodTone = (name: string, i: number): Tone => (i % 2 === 0 ? 'primary' : 'violet');
const periodIndex = (name: string) => Math.max(0, props.options.periods.indexOf(name));

function state(row: Row): { label: string; tone: Tone } {
    if (row.status === 'open') {
        return { label: 'Open', tone: 'ok' };
    }
    if (row.status === 'closing') {
        return { label: 'Closing', tone: 'warn' };
    }
    if (row.is_force_closed) {
        return { label: 'Force closed', tone: 'bad' };
    }
    if (row.variance !== null && row.variance < 0) {
        return { label: 'Short', tone: 'warn' };
    }

    return { label: 'Closed', tone: 'neutral' };
}

function times(row: Row): string {
    return `${formatDateTime(row.opened_at, 'time')} – ${row.closed_at ? formatDateTime(row.closed_at, 'time') : 'now'}`;
}

function menu(row: Row): DropdownMenuItem[] {
    return [
        { label: 'Shift report', icon: 'i-lucide-file-text', onSelect: () => router.visit(route('reports.shift', row.id)) },
        { label: row.status === 'closed' ? 'Z-report' : 'Z-report preview', icon: 'i-lucide-list', onSelect: () => router.visit(route('reports.z', row.id)) },
        { label: 'Rooms by floor', icon: 'i-lucide-bed', onSelect: () => router.visit(route('reports.z', { shift: row.id, tab: 'rooms' })) },
    ];
}

const weekLabel = computed(() => (props.calendar ? formatDateRange(`${props.calendar.range.from}T12:00:00+08:00`, `${props.calendar.range.to}T12:00:00+08:00`) : ''));
const filtered = computed(() => !!(props.filters.period || props.filters.desk || props.filters.status || props.filters.from || props.filters.to || props.filters.q));
</script>

<template>
    <BackOfficeLayout current="shift-reports" title="Shift reports" :subtitle="`${branch.name} · closed shifts are read from their frozen totals`">
        <FilterBar
            v-model:view="view"
            v-model:search="search"
            :views="[
                { value: 'list', label: 'List' },
                { value: 'calendar', label: 'Calendar' },
            ]"
            :searchable="view === 'list'"
            search-placeholder="Z number or name"
            :loading="loading"
        >
            <FilterSelect v-if="options.periods.length > 1" v-model="period" :items="periodItems" label="Shift" />
            <FilterSelect v-model="desk" :items="deskItems" label="Front desk" />
            <template v-if="view === 'list'">
                <FilterSelect v-model="status" :items="statusItems" label="Status" />
                <DateRangeChip :from="filters.from" :to="filters.to" placeholder="Any date" @change="(r) => visit({ from: r.from, to: r.to })" />
            </template>
            <div v-else class="flex h-8 items-center gap-1 rounded-control border border-default bg-default px-1 text-[12.5px] font-semibold whitespace-nowrap text-highlighted shadow-xs">
                <UButton color="neutral" variant="ghost" size="xs" icon="i-lucide-chevron-left" aria-label="Previous week" @click="visit({ week: calendar?.previous ?? null })" />
                <span class="px-1">{{ weekLabel }}</span>
                <UButton color="neutral" variant="ghost" size="xs" icon="i-lucide-chevron-right" aria-label="Next week" @click="visit({ week: calendar?.next ?? null })" />
            </div>
        </FilterBar>

        <section v-if="view === 'list' && list" class="overflow-hidden rounded-card border border-default bg-default" data-shift-list>
            <EmptyState
                v-if="list.rows.length === 0"
                :title="filtered ? 'No shifts match' : 'No shifts yet'"
                :text="filtered ? 'Try another filter or clear the search.' : 'A shift appears here once it is opened.'"
            />
            <div v-else class="overflow-x-auto">
                <table class="dtable flush">
                    <thead>
                        <tr>
                            <th scope="col">Z no.</th>
                            <th scope="col">Business date</th>
                            <th scope="col">Shift</th>
                            <th scope="col">Front desk</th>
                            <th scope="col">Opened – closed</th>
                            <th scope="col" class="r">Net sales</th>
                            <th scope="col" class="r">Short / over</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in list.rows" :key="row.id" class="link" @click="router.visit(route('reports.shift', row.id))">
                            <td :class="row.z_no === null ? 'mn' : 'b'">{{ z(row.z_no) }}</td>
                            <td class="!whitespace-nowrap">{{ day(row.business_date) }}</td>
                            <td><StatusLabel :tone="periodTone(row.period_name, periodIndex(row.period_name))" :label="row.period_name" /></td>
                            <td class="!whitespace-nowrap">
                                <PersonPill v-if="row.members.length" :name="shortName(row.members[0])" :extra="row.members.length > 1 ? `+${row.members.length - 1}` : undefined" />
                                <span v-else class="text-muted">—</span>
                            </td>
                            <td class="mn !whitespace-nowrap">{{ times(row) }}</td>
                            <td class="r" :class="row.net_sales === null ? 'mn' : ''">{{ row.net_sales === null ? '—' : formatMoney(row.net_sales, 'column') }}</td>
                            <td class="r" :class="row.variance === null ? 'mn' : row.variance < 0 ? '!text-bad' : row.variance > 0 ? '!text-ok' : ''">
                                {{ row.variance === null ? '—' : `${row.variance > 0 ? '+' : ''}${formatMoney(row.variance, 'column')}` }}
                            </td>
                            <td><StatusLabel v-bind="state(row)" /></td>
                            <td class="text-right" @click.stop>
                                <UDropdownMenu :items="menu(row)" :content="{ align: 'end' }">
                                    <UButton color="neutral" variant="ghost" size="xs" icon="i-lucide-ellipsis" :aria-label="`More for ${z(row.z_no)} ${row.period_name} ${day(row.business_date)}`" />
                                </UDropdownMenu>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Pager :page="list.page" :per-page="list.per_page" :total="list.total" @update:page="(p) => visit({ page: p })" />
        </section>

        <section v-else-if="calendar" class="rounded-card border border-default bg-default px-5 py-[18px]" data-shift-calendar-tab>
            <ShiftCalendar :days="calendar.days" :bands="calendar.bands" />
        </section>

        <p class="mt-3.5 text-xs text-muted">The open shift shows no sales or cash here or anywhere the front desk can see; its figures appear once it closes. Short and over are shown apart, never netted.</p>
    </BackOfficeLayout>
</template>
