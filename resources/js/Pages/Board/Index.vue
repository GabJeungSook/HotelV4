<!-- Mockup 4 · 4c (nothing found) · 4b in Skeleton.vue · 5 in KioskHolds.vue -->
<script setup lang="ts">
import { Deferred, router } from '@inertiajs/vue3';
import { useLocalStorage, useNow, watchDebounced } from '@vueuse/core';
import { computed, ref } from 'vue';
import EmptyState from '@/Components/EmptyState.vue';
import type { BoardRoom } from '@/Components/RoomRow.vue';
import RoomTable, { type BoardFloor, type RoomFilter } from '@/Components/RoomTable.vue';
import { usePolling } from '@/Composables/usePolling';
import { useShared } from '@/Composables/useShared';
import FrontDeskLayout from '@/Layouts/FrontDeskLayout.vue';
import KioskHolds, { type KioskHold } from './KioskHolds.vue';
import Skeleton from './Skeleton.vue';

/**
 * The room board (4): one row per room, grouped by floor, from BoardQuery. The display status is
 * the server's; the page only filters, sorts and counts the rows it was sent. Refreshes every
 * 15 s by a partial reload, rows updated in place. Search and floor are in the query string.
 * Kiosk holds sit in a permanent rail (5) that reloads on its own 5 s timer, so a guest who just
 * booked at the kiosk appears without a refresh; a hold code typed or scanned into the search
 * opens that hold's confirm screen (7) on the server.
 */
type Row = BoardRoom & { stayId: number | null };

const props = defineProps<{
    board?: { floors: (BoardFloor & { rooms: Row[] })[]; total: number; floorOptions: { value: string; label: string }[]; now: string };
    holds: KioskHold[];
    filters: { q: string; floor: string };
    poll: number;
    holdsPoll: number;
}>();

const { flash, props: shared } = useShared();
const liveHolds = computed(() => props.holds.filter((h) => !h.expired).length);
const kiosk = computed(() => !!shared.value.branch?.modules?.kiosk_enabled || props.holds.length > 0);
// The countdown runs live off this clock (one 1 s tick for the whole board, no per-row timers);
// the server reload below only keeps the data fresh (new check-ins, housekeeping) in the background.
const now = useNow({ interval: 1000 });
usePolling(['board'], props.poll);
// Kiosk holds reload on their own timer, so a new hold shows on the rail without a refresh.
usePolling(['holds'], props.holdsPoll);

/** "46 rooms on 2 floors" (mockup v6); the times tick live, so no "updates every N s" line. */
const boardSub = computed(() => {
    const b = props.board;

    return b ? `${b.total} rooms on ${b.floorOptions.length} ${b.floorOptions.length === 1 ? 'floor' : 'floors'}` : undefined;
});

const initialTab = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('tab');
const tab = ref<RoomFilter>((['all', 'vacant', 'occupied', 'due_out', 'overdue', 'housekeeping', 'held'] as RoomFilter[]).includes(initialTab as RoomFilter) ? (initialTab as RoomFilter) : 'all');
const sort = ref<'number' | 'attention'>('attention');
const q = ref(props.filters.q);
const lastFloor = useLocalStorage('homi.board.floor', 'all');
const floor = ref(props.filters.floor || lastFloor.value || 'all');

if (floor.value !== (props.filters.floor || 'all')) {
    visit();
}

const rooms = computed<Row[]>(() => props.board?.floors.flatMap((f) => f.rooms) ?? []);
const count = (statuses: string[]) => rooms.value.filter((r) => statuses.includes(r.status)).length;
const tabs = computed(() => [
    { value: 'all', label: 'All', n: rooms.value.length },
    { value: 'vacant', label: 'Vacant', n: count(['vacant']) },
    { value: 'occupied', label: 'Occupied', n: count(['occupied', 'due_out', 'overdue']) },
    { value: 'due_out', label: 'Due out', n: count(['due_out']) },
    { value: 'overdue', label: 'Overdue', n: count(['overdue']) },
    { value: 'housekeeping', label: 'Housekeeping', n: count(['to_clean', 'cleaning']) },
    { value: 'held', label: 'Held', n: count(['held']) },
]);
const floorItems = computed(() => [{ value: 'all', label: 'All floors' }, ...(props.board?.floorOptions ?? [])]);
const sortItems = [
    { value: 'attention', label: 'Sort: most urgent first' },
    { value: 'number', label: 'Sort: room no.' },
];

function visit(): void {
    lastFloor.value = floor.value;
    router.get(route('board'), { ...(q.value ? { q: q.value } : {}), ...(floor.value && floor.value !== 'all' ? { floor: floor.value } : {}) }, { preserveState: true, preserveScroll: true, replace: true, only: ['board', 'filters'] });
}

watchDebounced(q, visit, { debounce: 300 });

const emptyTitle = computed(() => `No room or guest matches "${props.filters.q}"`);

function clearSearch(): void {
    q.value = '';
}

function open(room: BoardRoom): void {
    const row = room as Row;

    if (row.status === 'vacant') {
        router.visit(route('stays.create', { room: row.id }));
    } else if (row.stayId && ['occupied', 'due_out', 'overdue'].includes(row.status)) {
        router.visit(route('stays.show', row.stayId));
    }
}
</script>

<template>
    <FrontDeskLayout current="board" title="Room board" :subtitle="boardSub">
        <UAlert v-if="flash.error" color="error" variant="subtle" icon="i-solar-danger-triangle-bold-duotone" class="mb-3" :description="flash.error.message" />

        <div class="flex flex-col gap-5 lg:flex-row lg:items-start">
            <div class="min-w-0 flex-1">
                <Deferred data="board">
                    <template #fallback><Skeleton /></template>

                    <div role="tablist" aria-label="Filter rooms by status" class="mb-3 flex flex-wrap gap-1 border-b border-default">
                        <button
                            v-for="t in tabs"
                            :key="t.value"
                            type="button"
                            role="tab"
                            :aria-selected="tab === t.value"
                            class="hm-focus -mb-px flex cursor-pointer items-center gap-1.5 border-b-2 px-2.5 py-2 text-sm"
                            :class="tab === t.value ? 'border-primary font-medium text-highlighted' : 'border-transparent text-muted hover:text-default'"
                            @click="tab = t.value as RoomFilter"
                        >
                            {{ t.label }}<span class="rounded-label bg-grey-50 px-1.5 text-xs text-muted tabular">{{ t.n }}</span>
                        </button>
                    </div>

                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <UInput v-model="q" icon="i-solar-magnifer-linear" placeholder="Room or guest" class="w-52" aria-label="Search rooms and guests" />
                        <USelect v-model="floor" :items="floorItems" class="w-36" aria-label="Floor" @update:model-value="visit" />
                        <USelect v-model="sort" :items="sortItems" class="w-56" aria-label="Sort" />
                        <span class="flex-1" />
                        <UButton icon="i-solar-add-square-linear" label="Walk-in check-in" @click="tab = 'vacant'" />
                    </div>

                    <div v-if="board && board.floors.length === 0 && filters.q" class="rounded-card border border-default bg-default">
                        <EmptyState
                            icon="i-solar-bed-bold-duotone"
                            :title="emptyTitle"
                            :text="`Check the spelling, or clear the search to see all ${board.total} rooms.`"
                            action-label="Clear search"
                            @action="clearSearch"
                        />
                    </div>
                    <RoomTable v-else :floors="board?.floors ?? []" :filter="tab" :sort="sort" :now="now" @select="open" />
                </Deferred>
            </div>

            <aside v-if="kiosk" class="order-first w-full lg:order-last lg:w-[320px] lg:shrink-0">
                <div class="rounded-card border border-default bg-default lg:sticky lg:top-4">
                    <div class="flex items-center justify-between gap-2 border-b border-default px-4 py-3">
                        <div class="flex items-center gap-2">
                            <UIcon name="i-solar-monitor-bold-duotone" class="size-5 text-primary" />
                            <h2 class="text-sm font-semibold text-highlighted">Kiosk holds</h2>
                        </div>
                        <span class="rounded-label bg-primary-50 px-1.5 text-xs text-primary-ink tabular">{{ liveHolds }}</span>
                    </div>
                    <div class="max-h-[calc(100vh-11rem)] overflow-y-auto p-4">
                        <KioskHolds :holds="holds" />
                    </div>
                </div>
            </aside>
        </div>
    </FrontDeskLayout>
</template>
