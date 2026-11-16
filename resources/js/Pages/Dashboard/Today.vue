<!-- Mockup 62 -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import FilterSelect from '@/Components/FilterSelect.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PeriodSegments from '@/Components/PeriodSegments.vue';
import PersonAvatar from '@/Components/PersonAvatar.vue';
import type { StatCardProps } from '@/Components/StatCard.vue';
import StaysTimeline, { type TimelineFloor } from '@/Components/StaysTimeline.vue';
import StatCards from '@/Components/StatCards.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatDateTime, formatDuration, formatStayLength } from '@/Composables/useDateTime';
import { usePolling } from '@/Composables/usePolling';
import { useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';

/**
 * Front desk Today (62, mockup v6): what needs doing in the next half hour. Five counts, the stays
 * timeline (rooms down, hours across, a now-line), Leaving soon and Arriving. Counts and names
 * only, never sales or cash (TRD §4.11), so the blind count at close stays blind. Refreshes every 30 s.
 */
interface Row {
    stay_id: number;
    room: string;
    guest: string;
    minutes: number;
}

const props = defineProps<{
    today: {
        counts: { arriving: number; due_soon: number; overdue: number; unpaid: number; holds: number };
        due_soon_rooms: string[];
        overdue_rooms: string[];
        unpaid_rooms: string[];
        unpaid_stay_id: number | null;
        first_hold_expires_at: string | null;
        leaving: Row[];
        arriving: { id: number; at: string | null; guest: string; room: string; kind: string; quantity: number | null }[];
        rooms: { vacant: number; to_clean: number; cleaning: number };
        due_soon_minutes: number;
        timeline: TimelineFloor[];
        now: string;
    };
    poll: number;
}>();

usePolling(['today'], props.poll);

const tick = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => (timer = setInterval(() => (tick.value = Date.now()), 1000)));
onBeforeUnmount(() => timer && clearInterval(timer));

const holdLeft = computed(() => {
    const at = props.today.first_hold_expires_at;

    return at ? Math.max(0, Math.round((new Date(at).getTime() - tick.value) / 1000)) : null;
});

const list = (rooms: string[], max = 3) => (rooms.length > max ? `${rooms.slice(0, max).join(', ')} +${rooms.length - max}` : rooms.join(', '));

const { props: sharedProps } = useShared();
// Rows and the Arriving card open the reservations list (57) when the module is on.
const reservations = computed(() => !!sharedProps.value.branch?.modules?.reservations_enabled);

const cards = computed<StatCardProps[]>(() => {
    const c = props.today.counts;

    return [
        { label: 'Arriving', value: c.arriving, sub: 'reservations', to: reservations.value ? route('reservations.index') : undefined },
        { label: 'Due out soon', value: c.due_soon, sub: c.due_soon ? list(props.today.due_soon_rooms) : `next ${props.today.due_soon_minutes} min`, to: route('board') },
        { label: 'Overdue', value: c.overdue, sub: c.overdue ? `Room ${list(props.today.overdue_rooms, 2)}` : 'none', tone: c.overdue ? 'bad' : 'default', to: route('board') },
        {
            label: 'Unpaid',
            value: c.unpaid,
            sub: c.unpaid ? `Room ${list(props.today.unpaid_rooms, 2)}` : 'all settled',
            to: props.today.unpaid_stay_id ? route('stays.show', props.today.unpaid_stay_id) : undefined,
        },
        { label: 'Kiosk holds', value: c.holds, sub: holdLeft.value !== null ? `${formatDuration(holdLeft.value, 'countdown')} left` : 'none', to: route('board') },
    ];
});

/** The timeline shows one floor at a time (segments) and can narrow to one kind of room. */
const floorItems = computed(() => props.today.timeline.map((f) => ({ value: f.label, label: f.label })));
const floor = ref(props.today.timeline[0]?.label ?? '');
watch(floorItems, (items) => {
    if (!items.some((i) => i.value === floor.value)) {
        floor.value = items[0]?.value ?? '';
    }
});
const show = ref<string>('all');
const showItems = [
    { value: 'all', label: 'All rooms' },
    { value: 'in_house', label: 'Guests in' },
    { value: 'leaving', label: 'Due out and overdue' },
    { value: 'vacant', label: 'Vacant' },
    { value: 'cleaning', label: 'To clean and cleaning' },
];
const keep: Record<string, string[]> = {
    in_house: ['occupied', 'due_out', 'overdue'],
    leaving: ['due_out', 'overdue'],
    vacant: ['vacant', 'held'],
    cleaning: ['to_clean', 'cleaning'],
};
const shown = computed<TimelineFloor[]>(() =>
    props.today.timeline
        .filter((f) => f.label === floor.value || floorItems.value.length < 2)
        .map((f) => ({ label: f.label, rooms: show.value === 'all' ? f.rooms : f.rooms.filter((r) => keep[show.value]?.includes(r.kind)) })),
);
const shownCount = computed(() => shown.value.reduce((n, f) => n + f.rooms.length, 0));
const floorTotal = computed(() => props.today.timeline.find((f) => f.label === floor.value)?.rooms.length ?? 0);

const stayText = (kind: string, q: number | null) => (q === null ? '' : formatStayLength(q, kind === 'hourly' ? 'hour' : kind === 'overnight' ? 'night' : 'day'));
</script>

<template>
    <FrontDeskLayout current="today" title="Today">
        <PageHeader>
            <UButton :to="route('board')" color="neutral" variant="outline" icon="i-solar-bed-bold-duotone" label="Room board" />
            <UButton :to="route('board', { tab: 'vacant' })" icon="i-solar-add-square-linear" label="Walk-in check-in" />
        </PageHeader>
        <StatCards :items="cards" />
        <div class="mt-4 grid grid-cols-1 items-start gap-4 min-[1100px]:grid-cols-[minmax(0,1fr)_300px]">
            <section class="min-w-0 overflow-hidden rounded-card border border-default bg-default" data-rooms-today>
                <div class="flex min-h-[30px] flex-wrap items-center gap-2.5 px-5 pt-4">
                    <div>
                        <h2 class="text-[14.5px] leading-[1.3] font-semibold text-highlighted">Rooms today</h2>
                        <small class="block text-xs text-muted">Who is in each room, and when they leave</small>
                    </div>
                    <div class="ms-auto flex flex-wrap items-center gap-2">
                        <FilterSelect v-model="show" :items="showItems" label="Which rooms" />
                        <PeriodSegments v-if="floorItems.length > 1" v-model="floor" :items="floorItems" label="Floor" />
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <div class="min-w-[640px]">
                        <StaysTimeline v-if="shownCount" :floors="shown" :now="today.now" />
                        <p v-else class="mt-4 border-t border-default px-5 py-10 text-center text-[12.5px] text-muted">No rooms match.</p>
                    </div>
                </div>
                <div class="flex items-center gap-1.5 border-t border-default px-5 py-3 text-[12.5px] text-muted">
                    <span>{{ shownCount }} of {{ floorItems.length > 1 ? floorTotal : shownCount }} rooms{{ floorItems.length > 1 ? ` on ${floor}` : '' }}</span>
                    <UButton :to="route('board')" variant="ghost" size="sm" label="Open the room board" class="ms-auto" />
                </div>
            </section>

            <div class="flex flex-col gap-4">
                <section class="rounded-card border border-default bg-default px-5 py-[18px]" data-leaving>
                    <div class="mb-3.5 flex min-h-[30px] items-center gap-2.5">
                        <h2 class="text-[14.5px] font-semibold text-highlighted">Leaving soon</h2>
                        <span class="ms-auto text-xs text-muted">next {{ today.due_soon_minutes }} min</span>
                    </div>
                    <ul v-if="today.leaving.length" class="-mt-1">
                        <li v-for="row in today.leaving" :key="row.stay_id" class="border-b border-muted last:border-b-0">
                            <Link :href="route('stays.show', row.stay_id)" class="hm-focus flex items-center gap-2.5 py-2.5">
                                <b class="w-[38px] text-[13px] font-semibold tabular">{{ row.room }}</b>
                                <span class="min-w-0 flex-1">
                                    <b class="block truncate text-[12.5px] font-medium">{{ row.guest }}</b>
                                    <small class="block text-[11.5px] text-muted">{{ row.minutes < 0 ? 'was due' : 'due' }} {{ formatDateTime(new Date(new Date(today.now).getTime() + row.minutes * 60000).toISOString(), 'time') }}</small>
                                </span>
                                <StatusLabel :tone="row.minutes < 0 ? 'bad' : 'warn'" :label="formatDuration(row.minutes, 'min')" />
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="text-[12.5px] text-muted">Nobody is due out in the next {{ today.due_soon_minutes }} minutes.</p>
                </section>
                <section class="rounded-card border border-default bg-default px-5 py-[18px]" data-arriving>
                    <div class="mb-3.5 flex min-h-[30px] items-center gap-2.5">
                        <h2 class="text-[14.5px] font-semibold text-highlighted">Arriving</h2>
                        <span class="ms-auto text-xs text-muted">today</span>
                    </div>
                    <ul v-if="today.arriving.length" class="-mt-1">
                        <li v-for="a in today.arriving" :key="a.id" class="border-b border-muted last:border-b-0">
                            <component :is="reservations ? Link : 'div'" :href="reservations ? route('reservations.show', a.id) : undefined" class="hm-focus flex items-center gap-2.5 py-2.5">
                                <PersonAvatar :name="a.guest" :size="32" />
                                <span class="min-w-0 flex-1">
                                    <b class="block truncate text-[12.5px] font-medium">{{ a.guest }}</b>
                                    <small class="block truncate text-[11.5px] text-muted">{{ formatDateTime(a.at, 'time') }} · {{ a.room }} · {{ stayText(a.kind, a.quantity) }}</small>
                                </span>
                            </component>
                        </li>
                    </ul>
                    <p v-else class="text-[12.5px] text-muted">No reservations arrive today.</p>
                </section>
            </div>
        </div>
        <p class="mt-3.5 text-xs text-muted">Counts and names only. Sales and drawer amounts are never shown to the front desk while a shift is open.</p>
    </FrontDeskLayout>
</template>
