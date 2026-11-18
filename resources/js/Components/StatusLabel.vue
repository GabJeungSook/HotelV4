<script setup lang="ts">
import { computed } from 'vue';
import { type Tone, toneFill, toneTextClass } from '@/types/ui';

/**
 * A status shown as a small coloured dot + coloured text, not a pill (DESIGN_SYSTEM §5.2, v7): the
 * tone's colour on the dot and the label, no fill, no border, no rounded container. One word in
 * sentence case. The dot shows at the default size and is dropped at `lg` (a big focal label on the
 * kiosk reads cleaner as colour alone); pass `:dot` to force it either way.
 */
const props = withDefaults(
    defineProps<{
        label?: string;
        tone?: Tone;
        size?: 'md' | 'lg';
        dot?: boolean;
    }>(),
    { label: undefined, tone: 'neutral', size: 'md', dot: undefined },
);

const showDot = computed(() => props.dot ?? props.size === 'md');

const classes = computed(() => [
    toneTextClass[props.tone],
    props.size === 'lg' ? 'gap-2 text-[28px] leading-9 font-semibold tabular' : 'gap-1.5 text-[11.5px] leading-none font-medium',
]);
</script>

<template>
    <span data-status-label :data-tone="tone" class="inline-flex items-center whitespace-nowrap" :class="classes">
        <span v-if="showDot" class="shrink-0 rounded-full" :class="size === 'lg' ? 'size-2.5' : 'size-1.5'" :style="{ backgroundColor: toneFill[tone] }" aria-hidden="true" />
        <slot>{{ label }}</slot>
    </span>
</template>
