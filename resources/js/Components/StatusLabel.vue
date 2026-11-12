<script setup lang="ts">
import { computed } from 'vue';
import { type Tone, toneClasses } from '@/types/ui';

/** A status is a small soft label: one word, the tone's text on its fill, no dot (DESIGN_SYSTEM §5.2). */
const props = withDefaults(
    defineProps<{
        label?: string;
        tone?: Tone;
        size?: 'md' | 'lg';
    }>(),
    { label: undefined, tone: 'neutral', size: 'md' },
);

const classes = computed(() => [
    toneClasses[props.tone],
    props.size === 'lg' ? 'rounded-md px-3.5 py-1.5 text-[28px] leading-9 font-semibold tabular' : 'rounded-label px-2 py-0.5 text-xs leading-[18px] font-medium',
]);
</script>

<template>
    <span data-status-label :data-tone="tone" class="inline-flex items-center whitespace-nowrap" :class="classes"><slot>{{ label }}</slot></span>
</template>
