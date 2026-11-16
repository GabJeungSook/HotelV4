<script setup lang="ts">
import { ref } from 'vue';
import StackedBars from '@/Components/Charts/StackedBars.vue';
import DateRangeChip from '@/Components/DateRangeChip.vue';
import FilterBar from '@/Components/FilterBar.vue';
import FilterSelect from '@/Components/FilterSelect.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pager from '@/Components/Pager.vue';
import PersonPill from '@/Components/PersonPill.vue';
import PersonStack from '@/Components/PersonStack.vue';
import SalesOverview from '@/Components/SalesOverview.vue';
import ShiftCalendar, { type CalendarBand, type CalendarDay } from '@/Components/ShiftCalendar.vue';
import StaysTimeline, { type TimelineFloor } from '@/Components/StaysTimeline.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import StepBar from '@/Components/StepBar.vue';
import Block from './Block.vue';

/**
 * The components mockup v6 added (DESIGN_SYSTEM §0): page header, filter row, people, pager,
 * stacked bars, the week of shifts and the stays timeline, plus the restyled status pill and
 * step strip. Fixed sample data; "now" is Wed 11 Nov 2026, 11:44 AM Manila as in the mockup.
 */
defineProps<{ state: 'data' | 'loading' | 'empty' }>();

const view = ref('list');
const search = ref('');
const desk = ref<string | number>('all');
const page = ref(1);
const range = ref<{ from: string | null; to: string | null }>({ from: '2026-11-05', to: '2026-11-12' });

const people = ['Jessa Mae D.', 'Rhea B.', 'Mark Anthony S.', 'Bong Ong', 'Teresa Aquino', 'Kevin Uy', 'Maria Ferrer'];

const days: CalendarDay[] = [9, 10, 11, 12, 13, 14, 15].map((d, i) => ({ date: `2026-11-${String(d).padStart(2, '0')}`, weekday: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][i], day: d, today: d === 11 }));
const bands: CalendarBand[] = [
    {
        label: 'AM shift',
        rows: [
            [
                { key: 'jd', start: 1, span: 3, name: 'Jessa Mae D.', sub: '3 shifts · open now', tone: 'ok', live: true },
                { key: 'f1', start: 4, span: 4, sub: 'Not opened yet', future: true },
            ],
            [{ key: 'rb', start: 2, span: 2, name: 'Rhea B.', sub: 'joined 9:02 AM', tone: 'violet', live: true }],
        ],
    },
    {
        label: 'PM shift',
        rows: [
            [
                { key: 'ms', start: 1, span: 2, name: 'Mark Anthony S.', sub: 'Z-0626 · Z-0628', tone: 'primary' },
                { key: 'f2', start: 3, span: 5, sub: 'Opens 8 PM', future: true },
            ],
        ],
    },
];

const now = '2026-11-11T03:44:00Z';
const at = (h: number, m = 0) => new Date(Date.UTC(2026, 10, 11, h - 8, m)).toISOString();
const floors: TimelineFloor[] = [
    {
        label: 'Floor 1 · Standard',
        rooms: [
            { id: 1, number: '101', kind: 'vacant', checkInHref: '#' },
            { id: 2, number: '102', kind: 'occupied', start: at(8, 54), end: at(14, 54), label: 'M. Ferrer', person: 'M. Ferrer', sub: 'out 2:54 PM' },
            { id: 3, number: '105', kind: 'cleaning', start: at(11, 38), end: at(12, 0), label: 'Cleaning', sub: '6 min so far' },
            { id: 4, number: '106', kind: 'due_out', start: at(6, 6), end: at(12, 6), label: 'L. Ramos', person: 'L. Ramos', sub: 'out in 22 min' },
            { id: 5, number: '110', kind: 'to_clean', start: at(11, 26), end: at(12, 9), label: 'To clean', sub: 'waiting 18 min' },
            { id: 6, number: '115', kind: 'blocked', label: 'Blocked · maintenance' },
            { id: 7, number: '117', kind: 'overdue', start: at(4, 2), end: at(11, 44), label: 'D. Abella', person: 'D. Abella', sub: '42 min over' },
            { id: 8, number: '210', kind: 'held', start: at(11, 44), end: at(12, 44), label: 'Kiosk hold', sub: '6:42 left' },
        ],
    },
];

const overview = {
    period: 'this week',
    from: '2026-11-09',
    to: '2026-11-11',
    sales: 3698000,
    rooms: 3341000,
    extras: 357000,
    methods: [
        { method: 'cash', amount: 2995000, count: 61 },
        { method: 'gcash', amount: 592000, count: 12 },
        { method: 'card', amount: 111000, count: 2 },
    ],
};
const occupied = [24, 28, 34, 38, 32, 26, 22, 27, 30, 36, 40, 33, 26, 23];
const toClean = [4, 3, 4, 3, 5, 4, 3, 3, 4, 4, 2, 4, 5, 7];
const blocked = Array(14).fill(1);
const labels = ['29', '30', '31', '1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11'];
</script>

<template>
    <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,330px),1fr))] gap-4" data-v6-gallery>
        <Block title="Page header — PageHeader" wide>
            <p class="mb-3 text-xs text-muted">The shell draws the ink icon tile, the title and the sub-line above every page. A page adds its actions, a status after the title and its own sub-line:</p>
            <div class="flex items-center gap-3.5 rounded-[10px] border border-dashed border-accented p-4">
                <span class="grid size-11 shrink-0 place-items-center rounded-[11px] bg-tile text-on-tile" aria-hidden="true"><UIcon name="i-solar-banknote-2-bold-duotone" class="size-[21px]" /></span>
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 text-[21px] leading-[1.2] font-semibold tracking-[-0.018em] text-highlighted">Shift report <StatusLabel tone="ok" label="Closed" /></p>
                    <small class="mt-[3px] block text-[12.5px] text-muted">Hotel Dalisay GenSan · AM shift, Wed 11 Nov</small>
                </div>
                <div class="flex gap-2">
                    <UButton color="neutral" variant="outline" icon="i-solar-download-minimalistic-bold-duotone" label="Export PDF" />
                    <UButton icon="i-solar-printer-2-bold-duotone" label="Print slip" />
                </div>
            </div>
            <PageHeader title="In-page title (wizard steps)">
                <template #status><StatusLabel tone="warn" label="Step 2 of 4" /></template>
                <UButton color="neutral" variant="outline" label="Back" />
            </PageHeader>
        </Block>

        <Block title="Filter row — FilterBar, FilterSelect, DateRangeChip" wide>
            <FilterBar
                v-model:view="view"
                v-model:search="search"
                :views="[
                    { value: 'list', label: 'List' },
                    { value: 'calendar', label: 'Calendar' },
                ]"
                search-placeholder="Z number or name"
            >
                <FilterSelect v-model="desk" :items="[{ value: 'all', label: 'All front desks' }, ...people.slice(0, 3).map((p, i) => ({ value: i + 1, label: p }))]" label="Front desk" />
                <DateRangeChip :from="range.from" :to="range.to" @change="(r) => (range = r)" />
            </FilterBar>
            <p class="text-xs text-muted">View: {{ view }} · search: “{{ search }}”</p>
        </Block>

        <Block title="People — PersonPill, PersonStack">
            <div class="flex flex-wrap gap-2">
                <PersonPill v-for="p in people" :key="p" :name="p" />
                <PersonPill name="Jessa Mae D." extra="+1" />
            </div>
            <div class="mt-3 flex items-center gap-2 text-[12.5px] text-muted"><PersonStack :names="people.slice(0, 3)" /> on duty</div>
        </Block>

        <Block title="Status — StatusLabel (pill)">
            <div class="flex flex-wrap gap-2">
                <StatusLabel tone="ok" label="Paid" />
                <StatusLabel tone="warn" label="Unpaid" />
                <StatusLabel tone="primary" label="Held" />
                <StatusLabel tone="info" label="Occupied" />
                <StatusLabel tone="bad" label="Overdue" />
                <StatusLabel tone="violet" label="Cleaning" />
                <StatusLabel tone="clay" label="To clean" />
                <StatusLabel label="Closed" />
            </div>
            <h4 class="mt-4 mb-2 text-xs font-semibold text-muted">Steps — StepBar (strip)</h4>
            <StepBar :steps="['Done', 'Current', 'Next']" :current="2" />
        </Block>

        <Block title="Pages — Pager">
            <div class="-mx-4 -mb-4">
                <Pager :page="page" :per-page="25" :total="200" @update:page="(p) => (page = p)" />
            </div>
        </Block>

        <Block title="Stacked bars — Charts/StackedBars" wide>
            <StackedBars
                :labels="labels"
                :series="
                    state === 'empty'
                        ? []
                        : [
                              { name: 'Occupied', values: occupied },
                              { name: 'To clean', values: toClean },
                              { name: 'Blocked', values: blocked },
                          ]
                "
                :current="13"
                :max="46"
                unit="rooms"
                :loading="state === 'loading'"
                caption="Rooms in use at 9 PM each night"
            />
        </Block>

        <Block title="Week of shifts — ShiftCalendar">
            <ShiftCalendar :days="days" :bands="state === 'empty' ? [] : bands" />
        </Block>

        <Block title="Sales overview — SalesOverview">
            <SalesOverview :overview="state === 'loading' ? undefined : overview" />
        </Block>

        <Block title="Stays timeline — StaysTimeline" wide>
            <div class="-mx-4 -mb-4 overflow-x-auto">
                <div class="min-w-[640px]"><StaysTimeline :floors="floors" :now="now" /></div>
            </div>
        </Block>
    </div>
</template>
