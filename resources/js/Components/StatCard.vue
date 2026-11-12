<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/** One KPI: label naming its period, one number, one sub-line (DESIGN_SYSTEM §5.2). */
export interface StatCardProps {
    label: string;
    value: string | number;
    sub?: string;
    trend?: 'up' | 'down';
    tone?: 'default' | 'bad' | 'primary';
    to?: string;
}

const props = withDefaults(defineProps<StatCardProps>(), { sub: undefined, trend: undefined, tone: 'default', to: undefined });

const valueClass = computed(() => ({ default: 'text-highlighted', bad: 'text-bad', primary: 'text-primary' })[props.tone]);
const subClass = computed(() => (props.trend === 'up' ? 'text-ok' : props.trend === 'down' ? 'text-bad' : 'text-muted'));
const arrow = computed(() => (props.trend === 'up' ? '▲ ' : props.trend === 'down' ? '▼ ' : ''));
</script>

<template>
    <component
        :is="to ? Link : 'div'"
        :href="to"
        class="block min-w-0 rounded-card border border-default bg-default px-[18px] py-4"
        :class="to ? 'hm-focus transition-colors duration-120 ease-out hover:bg-elevated' : ''"
        data-stat-card
    >
        <span class="block truncate text-[13px] leading-[18px] text-muted">{{ label }}</span>
        <b class="mt-0.5 block truncate text-[26px] leading-8 font-semibold tracking-[-0.01em] tabular" :class="valueClass">{{ value }}</b>
        <small v-if="sub" class="mt-1 block truncate text-xs leading-4" :class="subClass">{{ arrow }}{{ sub }}</small>
    </component>
</template>
