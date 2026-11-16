<script setup lang="ts">
import { computed } from 'vue';
import { type Tone, toneClasses } from '@/types/ui';

/**
 * A status is a small soft pill (DESIGN_SYSTEM §0, §5.2): one word in sentence case, the tone's
 * text on its fill with a 1 px border in a lighter tint of the same hue, fully rounded, no dot.
 */
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
    props.size === 'lg' ? 'rounded-[10px] px-3.5 py-1.5 text-[28px] leading-9 font-semibold tabular' : 'h-[22px] rounded-full px-2 text-[11.5px] leading-none font-medium',
]);
</script>

<template>
    <span data-status-label :data-tone="tone" class="inline-flex items-center gap-[5px] border whitespace-nowrap" :class="classes"><slot>{{ label }}</slot></span>
</template>
