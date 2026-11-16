<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatDateTime } from '@/Composables/useDateTime';
import type { Tone } from '@/types/ui';
import { toneBlock } from '@/types/ui';
import PersonAvatar from './PersonAvatar.vue';

/**
 * Rooms down, hours across (DESIGN_SYSTEM §0, mockup v6 Today 62): a blue now-line with the time,
 * each stay a block from check-in to expected check-out coloured by its state, cleaning and kiosk
 * holds as their own blocks, and "Check in" on a vacant room. Counts and names only, never an
 * amount. The window is now −2 h to now +6 h; a block that started earlier is cut with a dashed
 * left edge.
 */
export type TimelineKind = 'occupied' | 'due_out' | 'overdue' | 'to_clean' | 'cleaning' | 'held';

export interface TimelineRoom {
    id: number;
    number: string;
    kind: TimelineKind | 'vacant' | 'blocked';
    /** ISO times of the block (check-in to expected check-out, or the cleaning window). */
    start?: string | null;
    end?: string | null;
    label?: string | null;
    sub?: string | null;
    /** A guest's name: draws their avatar on the block. */
    person?: string | null;
    href?: string | null;
    checkInHref?: string | null;
}

export interface TimelineFloor {
    label: string;
    rooms: TimelineRoom[];
}

const props = withDefaults(defineProps<{ floors: TimelineFloor[]; now: string; hours?: number; before?: number }>(), { hours: 8, before: 2 });

const tones: Record<TimelineKind, Tone> = { occupied: 'primary', due_out: 'warn', overdue: 'bad', to_clean: 'clay', cleaning: 'violet', held: 'primary' };

const nowMs = computed(() => new Date(props.now).getTime());
/** The window starts on the hour, `before` hours before now. */
const startMs = computed(() => {
    const t = nowMs.value - props.before * 3600_000;

    return t - (t % 3600_000);
});
const spanMs = computed(() => props.hours * 3600_000);
const hourLabels = computed(() =>
    Array.from({ length: props.hours }, (_, i) => formatDateTime(new Date(startMs.value + i * 3600_000).toISOString(), 'time').replace(':00', '')),
);
const pct = (ms: number) => Math.max(0, Math.min(100, ((ms - startMs.value) / spanMs.value) * 100));
const nowPct = computed(() => pct(nowMs.value));
const nowText = computed(() => formatDateTime(props.now, 'time'));

interface Placed extends TimelineRoom {
    left: string;
    width: string;
    cut: boolean;
    tone: string;
}

function placed(room: TimelineRoom): Placed | null {
    if (room.kind === 'vacant' || room.kind === 'blocked' || !room.start || !room.end) {
        return null;
    }

    const s = new Date(room.start).getTime();
    const e = Math.max(new Date(room.end).getTime(), s);
    const left = pct(s);
    const right = pct(e);
    const tone = room.kind === 'held' ? 'border-dashed border-held-line bg-default' : toneBlock[tones[room.kind]];

    return { ...room, left: `${left}%`, width: `max(96px, ${Math.max(0, right - left)}%)`, cut: s < startMs.value, tone };
}

const rows = computed(() => props.floors.map((f) => ({ label: f.label, rooms: f.rooms.map((r) => ({ room: r, block: placed(r) })) })));
</script>

<template>
    <div class="relative mt-[22px]" data-stays-timeline>
        <div class="pointer-events-none absolute inset-y-0 z-[3] w-0.5 bg-primary" :style="{ left: `calc(84px + (100% - 84px) * ${nowPct / 100})` }" aria-hidden="true" data-now-line>
            <span class="absolute -top-px left-1/2 -translate-x-1/2 -translate-y-full rounded-[5px] bg-primary px-1.5 text-[10.5px] leading-[18px] font-semibold whitespace-nowrap text-on-primary">{{
                nowText
            }}</span>
        </div>
        <div class="grid grid-cols-[84px_minmax(0,1fr)] border-y border-default bg-thead">
            <div class="px-5 py-[9px] text-xs font-medium text-muted">Room</div>
            <div class="grid" :style="{ gridTemplateColumns: `repeat(${hours}, minmax(0, 1fr))` }">
                <span v-for="h in hourLabels" :key="h" class="border-l border-muted py-[9px] ps-1.5 text-[11px] font-medium whitespace-nowrap text-muted">{{ h }}</span>
            </div>
        </div>
        <template v-for="floor in rows" :key="floor.label">
            <div class="border-b border-muted bg-group-row px-5 py-1.5 text-[11.5px] font-semibold text-muted">{{ floor.label }}</div>
            <div v-for="{ room, block } in floor.rooms" :key="room.id" class="grid h-11 grid-cols-[84px_minmax(0,1fr)] border-b border-muted" :data-room="room.number">
                <div class="flex items-center px-5 text-[13px] font-semibold whitespace-nowrap text-highlighted">{{ room.number }}</div>
                <div class="relative overflow-hidden">
                    <i v-for="h in hours" :key="h" class="pointer-events-none absolute inset-y-0 border-l border-muted" :style="{ left: `${((h - 1) * 100) / hours}%` }" aria-hidden="true" />
                    <component
                        :is="block.href ? Link : 'span'"
                        v-if="block"
                        :href="block.href ?? undefined"
                        class="absolute inset-y-[7px] flex min-w-0 items-center gap-[7px] overflow-hidden rounded-lg border py-[5px] ps-[5px] pe-2.5 text-[11.5px] leading-tight"
                        :class="[block.tone, block.cut ? 'rounded-l-[2px] border-l-[1px] [border-left-style:dashed]' : '', block.href ? 'hm-focus' : '', block.person ? '' : 'ps-2']"
                        :style="{ left: block.left, width: block.width }"
                        :data-kind="block.kind"
                        data-block
                    >
                        <PersonAvatar v-if="block.person" :name="block.person" :size="22" />
                        <span class="min-w-0">
                            <b class="block truncate font-semibold text-highlighted">{{ block.label }}</b>
                            <small
                                v-if="block.sub"
                                class="block truncate text-[10.5px]"
                                :class="block.kind === 'overdue' ? 'font-semibold text-bad' : block.kind === 'due_out' ? 'font-semibold text-warn' : 'text-muted'"
                                >{{ block.sub }}</small
                            >
                        </span>
                    </component>
                    <span v-else class="absolute top-1/2 left-2 -translate-y-1/2 text-[11.5px] whitespace-nowrap text-faint">
                        {{ room.kind === 'blocked' ? (room.label ?? 'Blocked') : 'Vacant' }}
                        <Link v-if="room.kind === 'vacant' && room.checkInHref" :href="room.checkInHref" class="hm-focus ms-2 font-semibold text-primary hover:underline">Check in</Link>
                    </span>
                </div>
            </div>
        </template>
    </div>
</template>
