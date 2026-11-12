<script setup lang="ts">
import { computed } from 'vue';
import EmptyState from '../EmptyState.vue';
import { hideTip, num, showTip } from './chart';

/**
 * Weekdays down, hours across, one hue (DESIGN_SYSTEM §5.2 Charts): opacity 0.04 for zero and
 * 0.12 + 0.88 × value / max otherwise.
 */
export type Weekday = 'Mon' | 'Tue' | 'Wed' | 'Thu' | 'Fri' | 'Sat' | 'Sun';

const props = withDefaults(
    defineProps<{
        hours: string[];
        grid: Partial<Record<Weekday, number[]>>;
        caption?: string;
        unit?: string;
        /** Full hour names for the tooltip ("9 PM") when the axis uses short ones ("9p"). */
        hourTitles?: string[];
        emptyText?: string;
        loading?: boolean;
    }>(),
    { caption: '8 AM to 10 PM · darker is busier', unit: 'check-ins', hourTitles: undefined, emptyText: 'No check-ins in this period', loading: false },
);

const days: Weekday[] = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const max = computed(() => Math.max(0, ...days.flatMap((d) => props.grid[d] ?? [])));
const isEmpty = computed(() => props.hours.length === 0 || max.value === 0);

function opacity(value: number): number {
    return value > 0 ? 0.12 + (0.88 * value) / max.value : 0.04;
}

function cellText(value: number): string {
    return `${num(value)} ${props.unit}`;
}
</script>

<template>
    <div>
        <USkeleton v-if="loading" class="h-[136px] w-full" />
        <EmptyState v-else-if="isEmpty" compact :title="emptyText" />
        <template v-else>
            <div class="grid gap-0.5 text-[11px] text-muted" :style="{ gridTemplateColumns: `2.25rem repeat(${hours.length}, minmax(0, 1fr))` }" role="grid" :aria-label="caption">
                <template v-for="day in days" :key="day">
                    <span class="self-center" role="rowheader">{{ day }}</span>
                    <span
                        v-for="(hour, h) in hours"
                        :key="hour"
                        role="gridcell"
                        tabindex="0"
                        class="block h-4 rounded-[2px] outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary"
                        :style="{ background: 'var(--hm-primary)', opacity: opacity(grid[day]?.[h] ?? 0) }"
                        :aria-label="`${day} ${hourTitles?.[h] ?? hour}: ${cellText(grid[day]?.[h] ?? 0)}`"
                        data-heat-cell
                        @mousemove="showTip($event, `${day} ${hourTitles?.[h] ?? hour}`, cellText(grid[day]?.[h] ?? 0))"
                        @mouseleave="hideTip"
                        @focus="showTip($event, `${day} ${hourTitles?.[h] ?? hour}`, cellText(grid[day]?.[h] ?? 0))"
                        @blur="hideTip"
                    />
                </template>
                <span aria-hidden="true" />
                <span v-for="(hour, h) in hours" :key="`l${hour}`" class="text-center" aria-hidden="true">{{ h % 2 === 0 ? hour : '' }}</span>
            </div>
            <p class="mt-1.5 text-xs text-muted">{{ caption }}</p>
        </template>
    </div>
</template>
