<script setup lang="ts">
import { computed } from 'vue';
import EmptyState from '../EmptyState.vue';
import { hideTip, niceTop, num, peso, pesoShort, showTip } from './chart';

/**
 * Stacked bars over days (DESIGN_SYSTEM §0, mockup v6 `.sbars`): one column per day, the series
 * stacked bottom-up with 2 px between them, five quiet grid lines with the scale on the left, the
 * current day's main segment in `bar-now` and its label in ink, and a legend underneath.
 * Hover or focus a column for its values; a visually hidden table carries every value as text.
 */
export interface StackSeries {
    name: string;
    values: number[];
    /** A token colour; defaults to bar-1, bar-2, bar-3 in order. */
    color?: string;
}

const props = withDefaults(
    defineProps<{
        labels: string[];
        series: StackSeries[];
        /** Index of the current day (today), drawn darker. */
        current?: number;
        /** The top of the scale (e.g. the number of rooms); rounded up to a nice number when omitted. */
        max?: number;
        money?: boolean;
        unit?: string;
        titles?: string[];
        height?: number;
        emptyText?: string;
        loading?: boolean;
        caption?: string;
        legend?: boolean;
    }>(),
    { current: undefined, max: undefined, money: false, unit: '', titles: undefined, height: 190, emptyText: 'No data in this period', loading: false, caption: 'Stacked bar chart', legend: true },
);

const defaults = ['var(--hm-bar-1)', 'var(--hm-bar-2)', 'var(--hm-bar-3)'];
const colorOf = (k: number) => props.series[k]?.color ?? defaults[k % defaults.length];

const totals = computed(() => props.labels.map((_, i) => props.series.reduce((sum, s) => sum + (s.values[i] ?? 0), 0)));
const isEmpty = computed(() => props.labels.length === 0 || totals.value.every((t) => t === 0));
const top = computed(() => (props.max && props.max > 0 ? props.max : niceTop(Math.max(0, ...totals.value))));

function fmt(v: number): string {
    return props.money ? peso(v) : `${num(v)}${props.unit ? ` ${props.unit}` : ''}`;
}

const ticks = computed(() => [4, 3, 2, 1, 0].map((i) => {
    const v = (top.value / 4) * i;

    return props.money ? pesoShort(Math.round(v)) : num(Math.round(v));
}));

const columns = computed(() =>
    props.labels.map((label, i) => {
        const title = props.titles?.[i] ?? label;
        const parts = props.series.map((s, k) => ({ k, name: s.name, value: s.values[i] ?? 0 }));

        return {
            key: `${label}-${i}`,
            label,
            title,
            now: props.current === i,
            text: parts.map((p) => `${p.name} ${fmt(p.value)}`).join(' · '),
            segs: parts
                .filter((p) => p.value > 0)
                .map((p) => ({ ...p, pct: Math.min(100, (p.value / top.value) * 100), color: props.current === i && p.k === 0 ? 'var(--hm-bar-now)' : colorOf(p.k) })),
        };
    }),
);
</script>

<template>
    <div class="w-full" data-stacked-bars>
        <USkeleton v-if="loading" class="w-full" :style="{ height: `${height + 26}px` }" />
        <EmptyState v-else-if="isEmpty" compact :title="emptyText" />
        <template v-else>
            <div class="grid grid-cols-[34px_minmax(0,1fr)] gap-2" role="img" :aria-label="caption">
                <div class="flex flex-col justify-between pb-5 text-right text-[10.5px] leading-none text-faint tabular" :style="{ height: `${height + 20}px` }" aria-hidden="true">
                    <span v-for="(t, i) in ticks" :key="i">{{ t }}</span>
                </div>
                <div class="min-w-0">
                    <div class="relative flex items-end gap-2 border-b border-default" :style="{ height: `${height}px` }">
                        <i v-for="g in 4" :key="g" class="pointer-events-none absolute inset-x-0 border-t border-muted" :style="{ bottom: `${(g * 100) / 4}%` }" aria-hidden="true" />
                        <div
                            v-for="col in columns"
                            :key="col.key"
                            class="relative flex h-full min-w-0 flex-1 flex-col-reverse gap-0.5 outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                            tabindex="0"
                            :aria-label="`${col.title}: ${col.text}`"
                            :data-now="col.now || undefined"
                            data-column
                            @mousemove="showTip($event, col.title, col.text)"
                            @mouseleave="hideTip"
                            @focus="showTip($event, col.title, col.text)"
                            @blur="hideTip"
                        >
                            <i v-for="seg in col.segs" :key="seg.k" class="block shrink-0 rounded-[3px]" :style="{ height: `${seg.pct}%`, background: seg.color }" data-seg />
                        </div>
                    </div>
                    <div class="mt-1.5 flex gap-2 text-[10.5px] text-faint" aria-hidden="true">
                        <span v-for="col in columns" :key="col.key" class="min-w-0 flex-1 truncate text-center" :class="col.now ? 'font-semibold text-highlighted' : ''">{{ col.label }}</span>
                    </div>
                </div>
            </div>
            <div v-if="legend" class="mt-3 flex flex-wrap gap-x-3.5 gap-y-1 text-xs text-muted">
                <span v-for="(s, k) in series" :key="s.name" class="inline-flex items-center gap-1.5">
                    <i class="inline-block size-2 rounded-[2px]" :style="{ background: colorOf(k) }" aria-hidden="true" />{{ s.name }}
                </span>
            </div>
            <table class="sr-table">
                <caption>{{ caption }}</caption>
                <thead>
                    <tr>
                        <th scope="col">Day</th>
                        <th v-for="s in series" :key="s.name" scope="col">{{ s.name }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(label, i) in labels" :key="label + i">
                        <th scope="row">{{ titles?.[i] ?? label }}</th>
                        <td v-for="s in series" :key="s.name">{{ fmt(s.values[i] ?? 0) }}</td>
                    </tr>
                </tbody>
            </table>
        </template>
    </div>
</template>
