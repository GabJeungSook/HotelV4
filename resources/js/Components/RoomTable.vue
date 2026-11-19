<script setup lang="ts">
import { computed } from 'vue';
import type { RoomDisplayStatus } from '@/types/ui';
import EmptyState from './EmptyState.vue';
import RoomRow, { type BoardRoom } from './RoomRow.vue';

/**
 * The room board as one table in one card, grouped by floor (DESIGN_SYSTEM §5.2).
 * Filter, sort and limit work on rows the server already sent; nothing is computed about status here.
 */
export interface BoardFloor {
    label: string;
    rooms: BoardRoom[];
}

export type RoomFilter = 'all' | 'vacant' | 'occupied' | 'due_out' | 'overdue' | 'housekeeping' | 'held';

const props = withDefaults(
    defineProps<{
        floors?: BoardFloor[];
        filter?: RoomFilter;
        sort?: 'number' | 'attention';
        limit?: number;
        loading?: boolean;
        now?: string | number | Date;
    }>(),
    { floors: () => [], filter: 'all', sort: 'number', limit: undefined, loading: false, now: () => Date.now() },
);

const emit = defineEmits<{ select: [room: BoardRoom] }>();

const filters: Record<RoomFilter, RoomDisplayStatus[] | null> = {
    all: null,
    vacant: ['vacant'],
    occupied: ['occupied', 'due_out', 'overdue'],
    due_out: ['due_out'],
    overdue: ['overdue'],
    housekeeping: ['to_clean', 'cleaning'],
    held: ['held'],
};

// Urgency order (sort = attention): about to be over first, then overdue, then occupied, then
// vacant, then the rooms that are not in play (held, housekeeping, blocked).
const urgencyRank: Record<RoomDisplayStatus, number> = {
    due_out: 0,
    overdue: 1,
    occupied: 2,
    vacant: 3,
    held: 4,
    to_clean: 5,
    cleaning: 6,
    blocked: 7,
};
const timed = (s: RoomDisplayStatus): boolean => s === 'due_out' || s === 'overdue' || s === 'occupied';

function byNumber(a: BoardRoom, b: BoardRoom): number {
    return a.number.localeCompare(b.number, undefined, { numeric: true });
}

// Within a group, the room closest to being over (or the most over) comes first.
function byUrgency(a: BoardRoom, b: BoardRoom): number {
    const rank = urgencyRank[a.status] - urgencyRank[b.status];

    if (rank !== 0) {
        return rank;
    }

    if (timed(a.status) && a.minutes != null && b.minutes != null) {
        return a.minutes - b.minutes;
    }

    return byNumber(a, b);
}

const groups = computed(() => {
    const allowed = filters[props.filter];
    const pick = (rooms: BoardRoom[]): BoardRoom[] => (allowed ? rooms.filter((r) => allowed.includes(r.status)) : [...rooms]);

    // Urgency is a single list across floors, so the most pressing rooms sit at the very top.
    if (props.sort === 'attention') {
        let rooms = pick(props.floors.flatMap((f) => f.rooms)).sort(byUrgency);
        const total = rooms.length;

        if (props.limit !== undefined) {
            rooms = rooms.slice(0, props.limit);
        }

        return total === 0 ? [] : [{ label: '', heading: false, rooms, total, hidden: total - rooms.length }];
    }

    return props.floors
        .map((floor) => {
            let rooms = pick(floor.rooms).sort(byNumber);
            const total = rooms.length;

            if (props.limit !== undefined) {
                rooms = rooms.slice(0, props.limit);
            }

            return { label: floor.label, heading: props.floors.length > 1, rooms, total, hidden: total - rooms.length };
        })
        .filter((g) => g.total > 0);
});
</script>

<template>
    <div class="overflow-hidden rounded-card border border-default bg-default">
        <div v-if="loading" class="flex flex-col gap-3 p-4" aria-hidden="true" data-skeleton>
            <USkeleton v-for="i in 10" :key="i" class="h-5" :class="i % 3 === 0 ? 'w-4/5' : 'w-full'" />
        </div>
        <EmptyState v-else-if="groups.length === 0" icon="i-solar-bed-bold-duotone" title="No rooms to show" text="No room matches this filter." />
        <div v-else class="relative overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="border-y border-default bg-thead text-left text-xs leading-[18px] text-muted">
                        <th scope="col" class="py-2.5 ps-5 pe-2 font-medium">Room</th>
                        <th scope="col" class="px-2 py-2.5 font-medium">Type</th>
                        <th scope="col" class="px-2 py-2.5 font-medium">Status</th>
                        <th scope="col" class="px-2 py-2.5 font-medium">Guest</th>
                        <th scope="col" class="px-2 py-2.5 font-medium whitespace-nowrap">Due out</th>
                        <th scope="col" class="px-2 py-2.5 font-medium">Time</th>
                        <th scope="col" class="px-2 py-2.5 text-right font-medium">Balance</th>
                        <th scope="col" class="py-2.5 ps-2 pe-5"><span class="sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody v-for="group in groups" :key="group.label || 'all'" class="[&>tr]:border-t [&>tr]:border-(--hm-row-line)">
                    <tr v-if="group.heading" class="bg-group-row">
                        <th scope="rowgroup" colspan="8" class="py-[7px] ps-5 text-left text-[11.5px] font-semibold text-muted">{{ group.label }} · {{ group.total }} {{ group.total === 1 ? 'room' : 'rooms' }}</th>
                    </tr>
                    <RoomRow v-for="room in group.rooms" :key="room.id" :room="room" :now="now" @select="emit('select', $event)" />
                    <tr v-if="group.hidden > 0">
                        <td colspan="8" class="py-2 ps-5 text-xs text-muted">{{ group.hidden }} more {{ group.hidden === 1 ? 'room' : 'rooms' }}<template v-if="group.label"> on {{ group.label }}</template></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
