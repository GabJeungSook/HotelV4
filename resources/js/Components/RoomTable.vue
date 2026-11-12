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

const attentionRank: Partial<Record<RoomDisplayStatus, number>> = { overdue: 0, due_out: 1, held: 2 };

function byNumber(a: BoardRoom, b: BoardRoom): number {
    return a.number.localeCompare(b.number, undefined, { numeric: true });
}

const groups = computed(() => {
    const allowed = filters[props.filter];

    return props.floors
        .map((floor) => {
            let rooms = allowed ? floor.rooms.filter((r) => allowed.includes(r.status)) : [...floor.rooms];
            rooms.sort(props.sort === 'attention' ? (a, b) => (attentionRank[a.status] ?? 3) - (attentionRank[b.status] ?? 3) || byNumber(a, b) : byNumber);
            const total = rooms.length;

            if (props.limit !== undefined) {
                rooms = rooms.slice(0, props.limit);
            }

            return { label: floor.label, rooms, total, hidden: total - rooms.length };
        })
        .filter((g) => g.total > 0);
});
</script>

<template>
    <div class="overflow-hidden rounded-card border border-default bg-default">
        <div v-if="loading" class="flex flex-col gap-3 p-4" aria-hidden="true" data-skeleton>
            <USkeleton v-for="i in 10" :key="i" class="h-5" :class="i % 3 === 0 ? 'w-4/5' : 'w-full'" />
        </div>
        <EmptyState v-else-if="groups.length === 0" icon="i-lucide-bed" title="No rooms to show" text="No room matches this filter." />
        <div v-else class="relative overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-sidebar text-left text-xs leading-[18px] text-muted">
                        <th scope="col" class="py-2.5 ps-3 pe-2 font-medium">Room</th>
                        <th scope="col" class="px-2 py-2.5 font-medium">Type</th>
                        <th scope="col" class="px-2 py-2.5 font-medium">Status</th>
                        <th scope="col" class="px-2 py-2.5 font-medium">Guest</th>
                        <th scope="col" class="px-2 py-2.5 font-medium whitespace-nowrap">Due out</th>
                        <th scope="col" class="px-2 py-2.5 font-medium">Time</th>
                        <th scope="col" class="px-2 py-2.5 text-right font-medium">Balance</th>
                        <th scope="col" class="py-2.5 ps-2 pe-3"><span class="sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody v-for="group in groups" :key="group.label" class="[&>tr]:border-t [&>tr]:border-(--hm-row-line)">
                    <tr v-if="floors.length > 1" class="bg-sidebar">
                        <th scope="rowgroup" colspan="8" class="py-1.5 ps-3 text-left text-xs font-medium text-muted">{{ group.label }} · {{ group.total }} {{ group.total === 1 ? 'room' : 'rooms' }}</th>
                    </tr>
                    <RoomRow v-for="room in group.rooms" :key="room.id" :room="room" :now="now" @select="emit('select', $event)" />
                    <tr v-if="group.hidden > 0">
                        <td colspan="8" class="py-2 ps-3 text-xs text-muted">{{ group.hidden }} more {{ group.hidden === 1 ? 'room' : 'rooms' }} on {{ group.label }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
