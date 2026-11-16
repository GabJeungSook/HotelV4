<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * One KPI (DESIGN_SYSTEM §0, §5.2; mockup v6 `.stat`): a label naming its period, one number with
 * a trend arrow, and a comparison line with the change on the right in its own colour.
 */
export interface StatCardProps {
    label: string;
    value: string | number;
    /** Muted comparison line: "vs ₱8,720 yesterday", "23 of 46 rooms". */
    sub?: string;
    /** The change on the right of the sub-line: "+8%", "2 to review". Coloured by `trend`. */
    change?: string;
    trend?: 'up' | 'down';
    tone?: 'default' | 'bad' | 'primary';
    to?: string;
}

const props = withDefaults(defineProps<StatCardProps>(), { sub: undefined, change: undefined, trend: undefined, tone: 'default', to: undefined });

const valueClass = computed(() => ({ default: 'text-highlighted', bad: 'text-bad', primary: 'text-primary' })[props.tone]);
const trendClass = computed(() => (props.trend === 'up' ? 'text-ok' : props.trend === 'down' ? 'text-bad' : 'text-muted'));
</script>

<template>
    <component
        :is="to ? Link : 'div'"
        :href="to"
        class="block min-w-0 rounded-card border border-default bg-default px-[18px] py-4"
        :class="to ? 'hm-focus transition-colors duration-120 ease-out hover:bg-elevated' : ''"
        data-stat-card
    >
        <span class="block truncate text-[12.5px] leading-[18px] font-medium text-muted">{{ label }}</span>
        <b class="mt-1.5 flex items-center gap-2 text-[28px] leading-[1.1] font-semibold tracking-[-0.025em] tabular stat-value" :class="valueClass">
            <span class="truncate">{{ value }}</span>
            <UIcon v-if="trend" :name="trend === 'up' ? 'i-solar-arrow-right-up-linear' : 'i-solar-arrow-right-down-linear'" class="size-[18px] shrink-0" :class="trendClass" :aria-label="trend === 'up' ? 'up' : 'down'" />
        </b>
        <small v-if="sub || change" class="mt-2.5 flex flex-wrap items-center gap-x-1.5 text-[11.5px] leading-[1.4] text-muted">
            <span v-if="sub">{{ sub }}</span>
            <em v-if="change" class="ms-auto font-semibold not-italic" :class="trendClass">{{ change }}</em>
        </small>
    </component>
</template>
