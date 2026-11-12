<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import EmptyState from '../EmptyState.vue';
import { barPath, hideTip, niceTop, num, peakOf, peso, pesoShort, seriesColors, showTip } from './chart';

/**
 * Vertical bars over time (DESIGN_SYSTEM §5.2 Charts). One series: soft bars with the peak in
 * primary. Two or more: side by side with a legend. Four light grid lines; value on hover/focus;
 * a visually hidden table carries every value as text.
 */
export interface BarSeries {
    name: string;
    values: number[];
    color?: string;
}

const props = withDefaults(
    defineProps<{
        labels: string[];
        series: BarSeries[];
        /** Values are centavos, shown as whole pesos. */
        money?: boolean;
        unit?: string;
        wide?: boolean;
        /** Tooltip titles per bar, e.g. "Sun 8 Nov" for the axis label "Sun". */
        titles?: string[];
        height?: number;
        /** Show every n-th axis label (auto when omitted). */
        labelEvery?: number;
        emptyText?: string;
        loading?: boolean;
        caption?: string;
    }>(),
    { money: false, unit: '', wide: false, titles: undefined, height: 140, labelEvery: undefined, emptyText: 'No data in this period', loading: false, caption: 'Bar chart' },
);

const box = ref<HTMLElement | null>(null);
const measured = ref(0);
let observer: ResizeObserver | undefined;

// The chart is drawn in real pixels so text never stretches; it follows its card's width.
onMounted(() => {
    if (!box.value) {
        return;
    }

    measured.value = box.value.clientWidth;

    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver((entries) => {
            measured.value = Math.floor(entries[0]?.contentRect.width ?? measured.value);
        });
        observer.observe(box.value);
    }
});
onBeforeUnmount(() => observer?.disconnect());
const width = computed(() => (measured.value > 0 ? measured.value : 560));

const axisW = 44;
const axisH = 20;
const top = 6;

const isEmpty = computed(() => props.labels.length === 0 || props.series.every((s) => s.values.every((v) => !v)));
const max = computed(() => niceTop(Math.max(0, ...props.series.flatMap((s) => s.values))));
const plotH = computed(() => props.height - axisH - top);
const plotW = computed(() => width.value - axisW);
const slot = computed(() => plotW.value / Math.max(1, props.labels.length));
const single = computed(() => props.series.length === 1);
const peak = computed(() => (single.value ? peakOf(props.series[0].values) : null));
const every = computed(() => props.labelEvery ?? (props.labels.length > 12 ? 2 : 1));

function fmt(v: number): string {
    return props.money ? peso(v) : `${num(v)}${props.unit ? ` ${props.unit}` : ''}`;
}

const grid = computed(() =>
    [0, 1, 2, 3, 4].map((i) => {
        const value = (max.value / 4) * i;

        return { y: top + plotH.value - (plotH.value * i) / 4, label: props.money ? pesoShort(Math.round(value)) : num(value) };
    }),
);

const bars = computed(() => {
    const groupW = slot.value * (props.wide ? 0.78 : 0.62);
    const n = props.series.length;
    const gap = n > 1 ? 2 : 0;
    const w = Math.max(1, (groupW - gap * (n - 1)) / n);

    return props.labels.flatMap((label, i) =>
        props.series.map((s, k) => {
            const value = s.values[i] ?? 0;
            const h = (value / max.value) * plotH.value;
            const x = axisW + i * slot.value + (slot.value - groupW) / 2 + k * (w + gap);
            const color = single.value ? (peak.value?.index === i ? 'var(--hm-primary)' : 'var(--hm-bar-soft)') : (s.color ?? seriesColors[k % seriesColors.length]);

            return {
                key: `${i}-${k}`,
                d: barPath(x, top + plotH.value - h, w, h, 3),
                hit: { x: axisW + i * slot.value, w: slot.value },
                color,
                peak: single.value && peak.value?.index === i,
                title: `${props.titles?.[i] ?? label}${n > 1 ? ` · ${s.name}` : ''}`,
                text: fmt(value),
                k,
            };
        }),
    );
});
</script>

<template>
    <div ref="box" class="relative w-full">
        <USkeleton v-if="loading" class="w-full" :style="{ height: `${height}px` }" />
        <EmptyState v-else-if="isEmpty" compact :title="emptyText" />
        <template v-else>
            <svg :width="width" :height="height" class="block max-w-full overflow-visible" role="img" :aria-label="caption">
                <g aria-hidden="true">
                    <line v-for="g in grid" :key="g.y" :x1="axisW" :x2="width" :y1="g.y" :y2="g.y" stroke="var(--hm-row)" stroke-width="1" />
                    <text v-for="g in grid" :key="`t${g.y}`" :x="axisW - 8" :y="g.y + 4" text-anchor="end" class="fill-(--hm-muted) text-[11px] tabular">{{ g.label }}</text>
                    <template v-for="(label, i) in labels" :key="label + i">
                        <text v-if="i % every === 0" :x="axisW + i * slot + slot / 2" :y="height - 4" text-anchor="middle" class="fill-(--hm-muted) text-[11px]">{{ label }}</text>
                    </template>
                </g>
                <g>
                    <path
                        v-for="bar in bars"
                        :key="bar.key"
                        :d="bar.d"
                        :fill="bar.color"
                        tabindex="0"
                        class="outline-none focus-visible:stroke-(--hm-primary) focus-visible:stroke-2"
                        :aria-label="`${bar.title}: ${bar.text}`"
                        :data-peak="bar.peak || undefined"
                        data-bar
                        @mousemove="showTip($event, bar.title, bar.text)"
                        @mouseleave="hideTip"
                        @focus="showTip($event, bar.title, bar.text)"
                        @blur="hideTip"
                    />
                </g>
            </svg>
            <div v-if="series.length > 1" class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted">
                <span v-for="(s, k) in series" :key="s.name" class="inline-flex items-center gap-1.5">
                    <i class="inline-block size-[7px] rounded-[2px]" :style="{ background: s.color ?? seriesColors[k % seriesColors.length] }" aria-hidden="true" />{{ s.name }}
                </span>
            </div>
            <table class="sr-table">
                <caption>{{ caption }}</caption>
                <thead>
                    <tr>
                        <th scope="col">Period</th>
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
