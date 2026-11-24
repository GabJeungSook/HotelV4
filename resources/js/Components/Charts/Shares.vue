<script setup lang="ts">
import { computed } from 'vue';
import EmptyState from '../EmptyState.vue';
import { hideTip, num, otherColor, peso, seriesColors, showTip } from './chart';

/**
 * Shares of one whole (DESIGN_SYSTEM §5.2 Charts): one 10 px stacked bar with 2 px gaps and a
 * legend under it. Up to five parts; a sixth and more fold into "Other" — unless every row has
 * its own colour (room status), where all parts are kept.
 */
export interface ShareRow {
    name: string;
    value: number;
    color?: string;
}

const props = withDefaults(
    defineProps<{
        rows: ShareRow[];
        money?: boolean;
        /** Legend shows the value ("Vacant 14") or the percent ("6 hrs 38%"). */
        legend?: 'value' | 'percent';
        emptyText?: string;
        loading?: boolean;
        caption?: string;
    }>(),
    { money: false, legend: 'value', emptyText: 'No data in this period', loading: false, caption: 'Shares' },
);

const parts = computed(() => {
    const rows = props.rows.filter((r) => r.value > 0);
    const fixedColours = rows.length > 0 && rows.every((r) => r.color);
    let kept = rows;

    if (!fixedColours && rows.length > 5) {
        const sorted = [...rows].sort((a, b) => b.value - a.value);
        kept = [...sorted.slice(0, 4), { name: 'Other', value: sorted.slice(4).reduce((sum, r) => sum + r.value, 0), color: otherColor }];
    }

    const total = kept.reduce((sum, r) => sum + r.value, 0);

    // Whole-number percents that always add up to 100 (largest-remainder method), so the legend
    // never reads 99% or 101% from rounding each share on its own.
    const exact = kept.map((r) => (total > 0 ? (r.value / total) * 100 : 0));
    const rounded = exact.map((p) => Math.floor(p));
    let left = (total > 0 ? 100 : 0) - rounded.reduce((sum, f) => sum + f, 0);
    const byRemainder = exact.map((p, i) => ({ i, frac: p - Math.floor(p) })).sort((a, b) => b.frac - a.frac);
    for (let k = 0; k < byRemainder.length && left > 0; k++, left--) {
        rounded[byRemainder[k].i]++;
    }

    return kept.map((r, i) => ({
        ...r,
        color: r.color ?? seriesColors[i % seriesColors.length],
        percent: exact[i],
        percentText: `${rounded[i]}%`,
        valueText: props.money ? peso(r.value) : num(r.value),
    }));
});

const isEmpty = computed(() => parts.value.length === 0);
</script>

<template>
    <div>
        <USkeleton v-if="loading" class="my-1.5 h-2.5 w-full rounded-full" />
        <EmptyState v-else-if="isEmpty" compact :title="emptyText" />
        <template v-else>
            <div class="my-1.5 flex h-2.5 gap-0.5 overflow-hidden rounded-full" role="img" :aria-label="`${caption}: ${parts.map((p) => `${p.name} ${legend === 'percent' ? p.percentText : p.valueText}`).join(', ')}`">
                <span
                    v-for="part in parts"
                    :key="part.name"
                    tabindex="0"
                    class="block h-full outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary"
                    :style="{ width: `${part.percent}%`, background: part.color }"
                    :aria-label="`${part.name}: ${part.valueText}, ${part.percentText}`"
                    data-share
                    @mousemove="showTip($event, part.name, `${part.valueText} · ${part.percentText}`)"
                    @mouseleave="hideTip"
                    @focus="showTip($event, part.name, `${part.valueText} · ${part.percentText}`)"
                    @blur="hideTip"
                />
            </div>
            <ul class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted">
                <li v-for="part in parts" :key="part.name" class="inline-flex items-center gap-1.5">
                    <i class="inline-block size-[7px] rounded-[2px]" :style="{ background: part.color }" aria-hidden="true" />{{ part.name }}
                    <span class="tabular">{{ legend === 'percent' ? part.percentText : part.valueText }}</span>
                </li>
            </ul>
        </template>
    </div>
</template>
