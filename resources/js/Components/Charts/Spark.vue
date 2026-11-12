<script setup lang="ts">
import { computed } from 'vue';

/** A 64 × 20 px line with no axes, for a table row or a stat card sub-line (DESIGN_SYSTEM §5.2). */
const props = withDefaults(defineProps<{ values: number[]; tone?: 'default' | 'ok' | 'bad'; label?: string }>(), { tone: 'default', label: 'Trend' });

const W = 64;
const H = 20;

const points = computed(() => {
    const v = props.values;

    if (v.length < 2) {
        return '';
    }

    const min = Math.min(...v);
    const span = Math.max(...v) - min || 1;

    return v.map((value, i) => `${((i / (v.length - 1)) * (W - 2) + 1).toFixed(1)},${(H - 2 - ((value - min) / span) * (H - 4)).toFixed(1)}`).join(' ');
});

const stroke = computed(() => ({ default: 'var(--hm-primary)', ok: 'var(--hm-ok)', bad: 'var(--hm-bad)' })[props.tone]);
</script>

<template>
    <svg v-if="points" :width="W" :height="H" :viewBox="`0 0 ${W} ${H}`" class="inline-block align-middle" role="img" :aria-label="`${label}: ${values.join(', ')}`" data-spark>
        <polyline :points="points" fill="none" :stroke="stroke" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" />
    </svg>
    <span v-else class="inline-block text-xs text-muted" data-spark-empty>No trend yet</span>
</template>
