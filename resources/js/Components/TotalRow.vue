<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/Composables/useMoney';

/** The total under money rows: 1.5 px ink rule above, 16 px / 600 (DESIGN_SYSTEM §5.2). */
const props = withDefaults(
    defineProps<{
        label: string;
        amount?: number | null;
        tone?: 'default' | 'ok' | 'bad' | 'primary';
        sign?: 'auto' | 'none';
    }>(),
    { amount: undefined, tone: 'default', sign: 'auto' },
);

const value = computed(() => formatMoney(props.amount, props.sign === 'none' ? 'column' : 'standard'));
const toneClass = computed(() => ({ default: 'text-highlighted', ok: 'text-ok', bad: 'text-bad', primary: 'text-primary' })[props.tone]);
</script>

<template>
    <div class="mt-2 flex items-baseline justify-between gap-4 border-t-[1.5px] border-(--hm-ink) pt-2.5 text-base leading-6 font-semibold">
        <span class="text-highlighted">{{ label }}</span>
        <span class="num" :class="toneClass" data-amount>{{ value }}</span>
    </div>
</template>
