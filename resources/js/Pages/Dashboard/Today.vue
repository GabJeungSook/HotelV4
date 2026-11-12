<!-- Mockup 62 -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import type { StatCardProps } from '@/Components/StatCard.vue';
import StatCards from '@/Components/StatCards.vue';
import StatusLabel from '@/Components/StatusLabel.vue';
import { formatDateTime, formatDuration, formatStayLength } from '@/Composables/useDateTime';
import { usePolling } from '@/Composables/usePolling';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';
import { toneFill } from '@/types/ui';

/**
 * Front desk Today (62): what needs doing in the next half hour. Counts and names only, never
 * sales or cash (TRD §4.11), so the blind count at close stays blind. Refreshes every 30 s.
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

const cards = computed<StatCardProps[]>(() => {
    const c = props.today.counts;

    return [
        { label: 'Arriving', value: c.arriving, sub: 'reservations' },
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

const stayText = (kind: string, q: number | null) => (q === null ? '' : formatStayLength(q, kind === 'hourly' ? 'hour' : kind === 'overnight' ? 'night' : 'day'));
</script>

<template>
    <FrontDeskLayout current="today" title="Today">
        <div class="flex flex-col gap-4">
            <StatCards :items="cards" />
            <div class="grid grid-cols-2 gap-4 max-[900px]:grid-cols-1">
                <section class="rounded-card border border-default bg-default p-5">
                    <h2 class="text-sm font-semibold text-highlighted">Leaving soon</h2>
                    <table v-if="today.leaving.length" class="dtable mt-2 w-full">
                        <tbody>
                            <tr v-for="row in today.leaving" :key="row.stay_id">
                                <td class="w-16 tabular">
                                    <Link :href="route('stays.show', row.stay_id)" class="hm-focus font-medium text-highlighted hover:underline">{{ row.room }}</Link>
                                </td>
                                <td>{{ row.guest }}</td>
                                <td class="text-right">
                                    <StatusLabel :tone="row.minutes < 0 ? 'bad' : 'warn'" :label="formatDuration(row.minutes, 'min')" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="mt-2 text-sm text-muted">Nobody is due out in the next {{ today.due_soon_minutes }} minutes.</p>
                </section>
                <section class="rounded-card border border-default bg-default p-5">
                    <h2 class="text-sm font-semibold text-highlighted">Arriving</h2>
                    <table v-if="today.arriving.length" class="dtable mt-2 w-full">
                        <tbody>
                            <tr v-for="a in today.arriving" :key="a.id">
                                <td class="w-20 tabular">{{ formatDateTime(a.at, 'time') }}</td>
                                <td>{{ a.guest }}</td>
                                <td>{{ a.room }} · {{ stayText(a.kind, a.quantity) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="mt-2 text-sm text-muted">No reservations arrive today.</p>

                    <h2 class="mt-5 text-sm font-semibold text-highlighted">Rooms</h2>
                    <ul class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-sm text-default">
                        <li class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm" :style="{ background: toneFill.ok }" />Vacant {{ today.rooms.vacant }}</li>
                        <li class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm" :style="{ background: toneFill.clay }" />To clean {{ today.rooms.to_clean }}</li>
                        <li class="flex items-center gap-1.5"><i class="size-2.5 rounded-sm" :style="{ background: toneFill.violet }" />Cleaning {{ today.rooms.cleaning }}</li>
                    </ul>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <UButton :to="route('board')" label="Open room board" />
                        <UButton :to="route('board', { tab: 'vacant' })" color="neutral" variant="outline" label="Walk-in check-in" />
                    </div>
                </section>
            </div>
            <p class="text-[13px] text-muted">Counts only. Sales and drawer amounts are never shown to the front desk during a shift.</p>
        </div>
    </FrontDeskLayout>
</template>
