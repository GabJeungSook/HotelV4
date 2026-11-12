<script setup lang="ts">
import { computed } from 'vue';
import EmptyState from '../EmptyState.vue';
import { hideTip, showTip } from './chart';

/**
 * Ranked horizontal bars (DESIGN_SYSTEM §5.2 Charts): name left (cut at 22 characters), a 7 px bar
 * on a grey-50 track, the value text right. `total` draws the track to that length.
 */
export interface HBarRow {
    name: string;
    value: number;
    /** The value as text, already formatted ("168,300", "₱9,305"). */
    text: string;
    total?: number;
}

const props = withDefaults(defineProps<{ rows: HBarRow[]; color?: string; emptyText?: string; loading?: boolean }>(), {
    color: 'var(--hm-primary)',
    emptyText: 'No data in this period',
    loading: false,
});

const max = computed(() => Math.max(0, ...props.rows.map((r) => r.total ?? r.value)));
const isEmpty = computed(() => props.rows.length === 0 || max.value === 0);

function short(name: string): string {
    return name.length > 22 ? `${name.slice(0, 21)}…` : name;
}

function pct(row: HBarRow): number {
    const whole = row.total ?? max.value;

    return whole > 0 ? Math.min(100, (row.value / whole) * 100) : 0;
}
</script>

<template>
    <div>
        <div v-if="loading" class="flex flex-col gap-3 py-1" aria-hidden="true">
            <USkeleton v-for="i in 4" :key="i" class="h-4 w-full" />
        </div>
        <EmptyState v-else-if="isEmpty" compact :title="emptyText" />
        <ul v-else class="flex flex-col">
            <li
                v-for="row in rows"
                :key="row.name"
                tabindex="0"
                class="grid grid-cols-[minmax(0,7.5rem)_minmax(0,1fr)_auto] items-center gap-3 rounded-sm py-1.5 text-sm outline-none focus-visible:outline-2 focus-visible:outline-primary"
                :aria-label="`${row.name}: ${row.text}`"
                data-hbar
                @mousemove="showTip($event, row.name, row.text)"
                @mouseleave="hideTip"
                @focus="showTip($event, row.name, row.text)"
                @blur="hideTip"
            >
                <span class="truncate text-default" :title="row.name">{{ short(row.name) }}</span>
                <span class="block h-[7px] overflow-hidden rounded-full bg-grey-50" aria-hidden="true">
                    <span class="block h-full rounded-full" :style="{ width: `${pct(row)}%`, background: color }" />
                </span>
                <span class="min-w-16 text-right num">{{ row.text }}</span>
            </li>
        </ul>
    </div>
</template>
